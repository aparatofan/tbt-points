<?php
/**
 * The ledger table and every query on it.
 *
 * One row per change to a student's points; rows are never edited or
 * deleted, a correction is a new row. The balance is SUM(points), never a
 * cached number.
 *
 *   kind    earn     first time an activity earns (tool, object_ref set)
 *           repeat   the same activity again, at most once a day
 *           day      first activity of the day
 *           adjust   a teacher gave or took points (created_by = teacher)
 *           hold, spend, release   reserved for 0.2.0 (rewards)
 *
 * The unique key (activity_id, kind) means one Notes activity row can never
 * earn twice; adjustments have a NULL activity_id, which MySQL allows many of.
 */

defined( 'ABSPATH' ) || exit;

class TBTP_DB {

	/** Kinds that count as earned work: the daily cap and "earned" totals. */
	const EARNED_KINDS = array( 'earn', 'repeat', 'day' );

	/** Per-request answer to "does the table exist?". */
	private static $exists = null;

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tbtp_ledger';
	}

	/* ---------- Schema ---------- */

	/**
	 * Create or update the table. Safe to run more than once.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is particular: two spaces after PRIMARY KEY, one column per line.
		$sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  points int(11) NOT NULL DEFAULT 0,
  kind varchar(16) NOT NULL DEFAULT '',
  activity_id bigint(20) unsigned NULL DEFAULT NULL,
  tool varchar(32) NOT NULL DEFAULT '',
  object_ref varchar(64) NOT NULL DEFAULT '',
  title varchar(190) NOT NULL DEFAULT '',
  detail varchar(190) NOT NULL DEFAULT '',
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY user_activity (user_id,tool,object_ref),
  UNIQUE KEY activity_kind (activity_id,kind)
) {$charset};";

		dbDelta( $sql );
		self::$exists = null;
		update_option( 'tbtp_db_version', TBTP_DB_VERSION );
	}

	/**
	 * The upgrade check, from plugins_loaded: FTP deploys never fire the
	 * activation hook, so the table is created or updated here too.
	 */
	public static function maybe_upgrade() {
		if ( (string) get_option( 'tbtp_db_version', '' ) !== (string) TBTP_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		if ( null === self::$exists ) {
			$table        = self::table();
			$found        = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			self::$exists = ( $found === $table );
		}
		return self::$exists;
	}

	/* ---------- Writes ---------- */

	/**
	 * Insert one row. Returns the new ID, or 0 on any failure, including a
	 * duplicate (activity_id, kind), which is expected and not reported.
	 *
	 * @param array $row user_id, points, kind; optional activity_id, tool,
	 *                   object_ref, title, detail, created_by, created_at.
	 * @return int
	 */
	public static function insert( array $row ) {
		global $wpdb;

		$data = array(
			'user_id'    => (int) ( $row['user_id'] ?? 0 ),
			'points'     => (int) ( $row['points'] ?? 0 ),
			'kind'       => self::clip( (string) ( $row['kind'] ?? '' ), 16 ),
			'tool'       => self::clip( (string) ( $row['tool'] ?? '' ), 32 ),
			'object_ref' => self::clip( (string) ( $row['object_ref'] ?? '' ), 64 ),
			'title'      => self::clip( (string) ( $row['title'] ?? '' ), 190 ),
			'detail'     => self::clip( (string) ( $row['detail'] ?? '' ), 190 ),
			'created_by' => (int) ( $row['created_by'] ?? 0 ),
			'created_at' => (string) ( $row['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
		);
		$format = array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

		if ( ! empty( $row['activity_id'] ) ) {
			$data['activity_id'] = (int) $row['activity_id'];
			$format[]            = '%d';
		}

		if ( $data['user_id'] <= 0 || '' === $data['kind'] ) {
			return 0;
		}

		// A duplicate key is the normal answer to the same activity arriving
		// twice; keep it out of the error log and the page.
		$suppress = $wpdb->suppress_errors( true );
		$ok       = $wpdb->insert( self::table(), $data, $format );
		$wpdb->suppress_errors( $suppress );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/* ---------- Reads used by earning ---------- */

	/**
	 * Has this Notes activity row already been handled (any kind)?
	 *
	 * @param int $activity_id Notes activity row ID.
	 * @return bool
	 */
	public static function activity_seen( $activity_id ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare( "SELECT 1 FROM {$table} WHERE activity_id = %d LIMIT 1", (int) $activity_id )
		);
	}

	/**
	 * Is there a row of one of these kinds for user + tool + object_ref,
	 * optionally within [from, to) UTC?
	 *
	 * @param int         $user_id    Student.
	 * @param string      $tool       Tool slug.
	 * @param string      $object_ref Tool's own reference.
	 * @param string[]    $kinds      Kinds.
	 * @param string|null $from       UTC lower bound, inclusive.
	 * @param string|null $to         UTC upper bound, exclusive.
	 * @return bool
	 */
	public static function has_activity_row( $user_id, $tool, $object_ref, array $kinds, $from = null, $to = null ) {
		global $wpdb;
		$table = self::table();
		$sql   = "SELECT 1 FROM {$table} WHERE user_id = %d AND tool = %s AND object_ref = %s AND " . self::kinds_sql( $kinds );
		$args  = array_merge( array( (int) $user_id, (string) $tool, (string) $object_ref ), $kinds );
		if ( null !== $from && null !== $to ) {
			$sql   .= ' AND created_at >= %s AND created_at < %s';
			$args[] = $from;
			$args[] = $to;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( $sql . ' LIMIT 1', $args ) );
	}

	/**
	 * Is there a row of this kind for the user within [from, to) UTC?
	 *
	 * @param int    $user_id Student.
	 * @param string $kind    Kind.
	 * @param string $from    UTC, inclusive.
	 * @param string $to      UTC, exclusive.
	 * @return bool
	 */
	public static function has_kind_between( $user_id, $kind, $from, $to ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$table} WHERE user_id = %d AND kind = %s AND created_at >= %s AND created_at < %s LIMIT 1",
				(int) $user_id,
				(string) $kind,
				$from,
				$to
			)
		);
	}

	/**
	 * Sum of one user's points of these kinds within [from, to) UTC.
	 *
	 * @param int      $user_id Student.
	 * @param string[] $kinds   Kinds.
	 * @param string   $from    UTC, inclusive.
	 * @param string   $to      UTC, exclusive.
	 * @return int
	 */
	public static function sum_between( $user_id, array $kinds, $from, $to ) {
		global $wpdb;
		$table = self::table();
		$args  = array_merge( array( (int) $user_id ), $kinds, array( $from, $to ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE user_id = %d AND " . self::kinds_sql( $kinds ) . ' AND created_at >= %s AND created_at < %s',
				$args
			)
		);
	}

	/* ---------- Reads used by the pages ---------- */

	/**
	 * @param int $user_id Student.
	 * @return int
	 */
	public static function balance( $user_id ) {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(points), 0) FROM {$table} WHERE user_id = %d", (int) $user_id )
		);
	}

	/**
	 * Balances, earned points since a moment, and the last time each user
	 * earned, for many users at once.
	 *
	 * @param int[]  $user_ids Students.
	 * @param string $since    UTC lower bound for `earned`.
	 * @return array<int,array{balance:int,earned:int,last_earned:string}>
	 */
	public static function summaries( array $user_ids, $since ) {
		global $wpdb;
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$table  = self::table();
		$kinds  = self::EARNED_KINDS;
		$in_k   = implode( ',', array_fill( 0, count( $kinds ), '%s' ) );
		$in_u   = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$args   = array_merge( $kinds, array( $since ), $kinds, $user_ids );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id,
					COALESCE(SUM(points), 0) AS balance,
					COALESCE(SUM(CASE WHEN kind IN ({$in_k}) AND created_at >= %s THEN points ELSE 0 END), 0) AS earned,
					MAX(CASE WHEN kind IN ({$in_k}) AND points > 0 THEN created_at ELSE NULL END) AS last_earned
				FROM {$table} WHERE user_id IN ({$in_u}) GROUP BY user_id",
				$args
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['user_id'] ] = array(
				'balance'     => (int) $row['balance'],
				'earned'      => (int) $row['earned'],
				'last_earned' => (string) ( $row['last_earned'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Points of the earned kinds since a moment, for one user.
	 *
	 * @param int    $user_id Student.
	 * @param string $since   UTC.
	 * @return int
	 */
	public static function earned_since( $user_id, $since ) {
		return self::sum_between( $user_id, self::EARNED_KINDS, $since, '9999-12-31 23:59:59' );
	}

	/**
	 * One page of a user's rows, newest first.
	 *
	 * @param int $user_id  Student.
	 * @param int $page     From 1.
	 * @param int $per_page Rows per page.
	 * @return array{rows:array[],has_more:bool}
	 */
	public static function history( $user_id, $page, $per_page ) {
		global $wpdb;
		$table    = self::table();
		$per_page = max( 1, (int) $per_page );
		$offset   = ( max( 1, (int) $page ) - 1 ) * $per_page;
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, points, kind, tool, object_ref, title, detail, created_at FROM {$table}
				WHERE user_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				(int) $user_id,
				$per_page + 1,
				$offset
			),
			ARRAY_A
		);
		$rows     = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > $per_page;
		return array(
			'rows'     => array_slice( $rows, 0, $per_page ),
			'has_more' => $has_more,
		);
	}

	/**
	 * Every user with at least one ledger row (the administrator's view adds
	 * students who are in no class).
	 *
	 * @return int[]
	 */
	public static function user_ids() {
		global $wpdb;
		$table = self::table();
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT user_id FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- no values.
	}

	/* ---------- Helpers ---------- */

	/**
	 * "kind IN (%s, %s, ...)" for prepare().
	 *
	 * @param string[] $kinds Kinds (at least one).
	 * @return string
	 */
	private static function kinds_sql( array $kinds ) {
		return 'kind IN (' . implode( ',', array_fill( 0, max( 1, count( $kinds ) ), '%s' ) ) . ')';
	}

	/**
	 * @param string $value Value.
	 * @param int    $limit Characters.
	 * @return string
	 */
	private static function clip( $value, $limit ) {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $limit ) : substr( $value, 0, $limit );
	}
}
