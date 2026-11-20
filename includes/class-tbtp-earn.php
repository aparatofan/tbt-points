<?php
/**
 * Earning: listens to TBT Notes' `tbt_notes_activity_recorded` action.
 *
 * The action fires inside the request that recorded the row — a student's
 * POST /activity, or a teacher's homework save through
 * tbt_notes_record_activity(). So nothing here may throw or slow that
 * request noticeably: every failure is swallowed, and the work is a handful
 * of indexed queries and at most two inserts.
 *
 * Since 0.1.4 trusted plugins whose events are not finished work (TBT
 * Dictionary words) earn through tbt_points_record_event() → record_event().
 * Both entry points share earn(), so every check lives in one place.
 *
 * Points never reads Notes' activity table: everything comes from $row (or
 * the caller's arguments) and the ledger.
 */

defined( 'ABSPATH' ) || exit;

class TBTP_Earn {

	public static function register() {
		add_action( 'tbt_notes_activity_recorded', array( __CLASS__, 'on_recorded' ), 10, 2 );
	}

	/**
	 * The listener. Never throws.
	 *
	 * @param int   $id  Notes activity row ID.
	 * @param array $row The inserted row.
	 */
	public static function on_recorded( $id = 0, $row = array() ) {
		try {
			self::handle( (int) $id, is_array( $row ) ? $row : array() );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Earning must never break the request that recorded the work.
		}
	}

	/**
	 * A direct event from a trusted plugin (tbt_points_record_event()).
	 * Never throws.
	 *
	 * @param array $args user_id, tool, object_ref, object_title.
	 * @return int Points added (earn + day bonus).
	 */
	public static function record_event( array $args ) {
		try {
			$user_id = (int) ( $args['user_id'] ?? 0 );
			$tool    = substr( preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) ( $args['tool'] ?? '' ) ) ), 0, 32 );
			$ref     = mb_substr( sanitize_text_field( (string) ( $args['object_ref'] ?? '' ) ), 0, 64 );
			$title   = mb_substr( sanitize_text_field( (string) ( $args['object_title'] ?? '' ) ), 0, 190 );
			if ( $user_id <= 0 || '' === $tool || '' === $ref ) {
				return 0;
			}
			$created_at = gmdate( 'Y-m-d H:i:s' );
			return self::earn(
				array(
					'user_id'     => $user_id,
					'tool'        => $tool,
					'object_ref'  => $ref,
					'title'       => $title,
					'created_at'  => $created_at,
					'created'     => new \DateTimeImmutable( $created_at, new \DateTimeZone( 'UTC' ) ),
					'activity_id' => 0,
					'percent'     => null,
					'score_text'  => '',
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * @param int   $id  Notes activity row ID.
	 * @param array $row The inserted row.
	 */
	private static function handle( $id, array $row ) {
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$tool    = (string) ( $row['tool'] ?? '' );
		$ref     = (string) ( $row['object_ref'] ?? '' );
		if ( $id <= 0 || $user_id <= 0 || '' === $tool || '' === $ref ) {
			return;
		}

		$utc        = new \DateTimeZone( 'UTC' );
		$created_at = (string) ( $row['created_at'] ?? '' );
		$created    = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, $utc );
		if ( ! $created || $created->format( 'Y-m-d H:i:s' ) !== $created_at ) {
			$created_at = gmdate( 'Y-m-d H:i:s' );
			$created    = new \DateTimeImmutable( $created_at, $utc );
		}

		$score     = self::nullable_int( $row['score'] ?? null );
		$score_max = self::nullable_int( $row['score_max'] ?? null );
		$percent   = ( null !== $score && null !== $score_max && $score_max > 0 )
			? (int) round( $score / $score_max * 100 )
			: null;

		self::earn(
			array(
				'user_id'     => $user_id,
				'tool'        => $tool,
				'object_ref'  => $ref,
				'title'       => (string) ( $row['object_title'] ?? '' ),
				'created_at'  => $created_at,
				'created'     => $created,
				'activity_id' => $id,
				'percent'     => $percent,
				'score_text'  => self::score_text( $tool, $score, $score_max ),
			)
		);
	}

	/**
	 * The award path both entry points share: every check and both ledger
	 * inserts. A Notes row (activity_id > 0) may earn `repeat` points; a
	 * direct event (activity_id 0) earns once per user, tool and object_ref
	 * ever, and nothing after that.
	 *
	 * @param array $e user_id, tool, object_ref, title, created_at (UTC
	 *                 string), created (DateTimeImmutable), activity_id,
	 *                 percent (int|null), score_text.
	 * @return int Points added.
	 */
	private static function earn( array $e ) {
		$user_id     = (int) $e['user_id'];
		$tool        = (string) $e['tool'];
		$ref         = (string) $e['object_ref'];
		$activity_id = (int) $e['activity_id'];
		$direct      = $activity_id <= 0;
		$created_at  = (string) $e['created_at'];

		// The row's user, never the current user: homework is recorded inside
		// the teacher's request.
		if ( ! TBTP_Access::notes_ready() || TBT_Notes_Capabilities::user_can_manage( $user_id ) ) {
			return 0;
		}
		if ( ! TBTP_DB::table_exists() ) {
			return 0;
		}

		// "Today" is the site-time-zone day of the row, so a backdated row is
		// counted on the day it happened.
		$tz    = wp_timezone();
		$day   = $e['created']->setTimezone( $tz )->format( 'Y-m-d' );
		$rules = TBTP_Rules::get();
		if ( '' === $rules['start'] || $day < $rules['start'] ) {
			return 0;
		}

		// The same Notes row a second time: the unique key would stop a second
		// `earn`, but not a `repeat` worked out against the first one.
		if ( ! $direct && TBTP_DB::activity_seen( $activity_id ) ) {
			return 0;
		}

		$is_first = ! TBTP_DB::has_activity_row( $user_id, $tool, $ref, $direct ? array( 'earn' ) : array( 'earn', 'repeat' ) );

		// A direct event that has earned before earns nothing, not even the
		// day bonus: no ledger queries beyond this point.
		if ( $direct && ! $is_first ) {
			return 0;
		}

		list( $from, $to ) = TBTP_Rules::day_bounds( $day, $tz );

		$in = array(
			'tool'         => $tool,
			'percent'      => $e['percent'],
			'is_first'     => $is_first,
			'repeat_today' => ! $is_first && TBTP_DB::has_activity_row( $user_id, $tool, $ref, array( 'repeat' ), $from, $to ),
			'day_given'    => TBTP_DB::has_kind_between( $user_id, 'day', $from, $to ),
			'earned_today' => TBTP_DB::sum_between( $user_id, TBTP_DB::EARNED_KINDS, $from, $to ),
		);
		$award = $direct ? TBTP_Rules::award_direct( $rules, $in ) : TBTP_Rules::award( $rules, $in );

		$title = (string) $e['title'];
		$added = 0;

		if ( $award['earn'] > 0 ) {
			$detail = implode( ' · ', array_filter( array( $award['earn_detail'], (string) $e['score_text'] ), 'strlen' ) );
			if ( TBTP_DB::insert(
				array(
					'user_id'     => $user_id,
					'points'      => $award['earn'],
					'kind'        => $is_first ? 'earn' : 'repeat',
					'activity_id' => $activity_id,
					'tool'        => $tool,
					'object_ref'  => $ref,
					'title'       => $title,
					'detail'      => $detail,
					'created_at'  => $created_at,
				)
			) ) {
				$added += $award['earn'];
			}
		}

		if ( $award['day'] > 0 ) {
			if ( TBTP_DB::insert(
				array(
					'user_id'     => $user_id,
					'points'      => $award['day'],
					'kind'        => 'day',
					'activity_id' => $activity_id,
					'title'       => 'First activity today',
					'created_at'  => $created_at,
				)
			) ) {
				$added += $award['day'];
			}
		}

		return $added;
	}

	/**
	 * `18 / 20` for a scored row, `340 game points` for Fill, else ''.
	 *
	 * @param string   $tool      Tool slug.
	 * @param int|null $score     Score.
	 * @param int|null $score_max Maximum.
	 * @return string
	 */
	private static function score_text( $tool, $score, $score_max ) {
		if ( null !== $score && null !== $score_max && $score_max > 0 ) {
			return $score . ' / ' . $score_max;
		}
		if ( 'fill' === $tool && null !== $score ) {
			return $score . ' game points';
		}
		return '';
	}

	/**
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function nullable_int( $value ) {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}
		return (int) $value;
	}
}
