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
 * Points never reads Notes' activity table: everything comes from $row and
 * the ledger.
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

		// The row's user, never the current user: homework is recorded inside
		// the teacher's request.
		if ( ! TBTP_Access::notes_ready() || TBT_Notes_Capabilities::user_can_manage( $user_id ) ) {
			return;
		}
		if ( ! TBTP_DB::table_exists() ) {
			return;
		}

		$utc        = new \DateTimeZone( 'UTC' );
		$created_at = (string) ( $row['created_at'] ?? '' );
		$created    = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $created_at, $utc );
		if ( ! $created || $created->format( 'Y-m-d H:i:s' ) !== $created_at ) {
			$created_at = gmdate( 'Y-m-d H:i:s' );
			$created    = new \DateTimeImmutable( $created_at, $utc );
		}

		// "Today" is the site-time-zone day of the row, so a backdated row is
		// counted on the day it happened.
		$tz    = wp_timezone();
		$day   = $created->setTimezone( $tz )->format( 'Y-m-d' );
		$rules = TBTP_Rules::get();
		if ( '' === $rules['start'] || $day < $rules['start'] ) {
			return;
		}

		// The same Notes row a second time: the unique key would stop a second
		// `earn`, but not a `repeat` worked out against the first one.
		if ( TBTP_DB::activity_seen( $id ) ) {
			return;
		}

		list( $from, $to ) = TBTP_Rules::day_bounds( $day, $tz );

		$score     = self::nullable_int( $row['score'] ?? null );
		$score_max = self::nullable_int( $row['score_max'] ?? null );
		$percent   = ( null !== $score && null !== $score_max && $score_max > 0 )
			? (int) round( $score / $score_max * 100 )
			: null;

		$is_first = ! TBTP_DB::has_activity_row( $user_id, $tool, $ref, array( 'earn', 'repeat' ) );

		$award = TBTP_Rules::award(
			$rules,
			array(
				'tool'         => $tool,
				'percent'      => $percent,
				'is_first'     => $is_first,
				'repeat_today' => ! $is_first && TBTP_DB::has_activity_row( $user_id, $tool, $ref, array( 'repeat' ), $from, $to ),
				'day_given'    => TBTP_DB::has_kind_between( $user_id, 'day', $from, $to ),
				'earned_today' => TBTP_DB::sum_between( $user_id, TBTP_DB::EARNED_KINDS, $from, $to ),
			)
		);

		$title = (string) ( $row['object_title'] ?? '' );

		if ( $award['earn'] > 0 ) {
			$score_text = self::score_text( $tool, $score, $score_max );
			$detail     = implode( ' · ', array_filter( array( $award['earn_detail'], $score_text ), 'strlen' ) );
			TBTP_DB::insert(
				array(
					'user_id'     => $user_id,
					'points'      => $award['earn'],
					'kind'        => $is_first ? 'earn' : 'repeat',
					'activity_id' => $id,
					'tool'        => $tool,
					'object_ref'  => $ref,
					'title'       => $title,
					'detail'      => $detail,
					'created_at'  => $created_at,
				)
			);
		}

		if ( $award['day'] > 0 ) {
			TBTP_DB::insert(
				array(
					'user_id'     => $user_id,
					'points'      => $award['day'],
					'kind'        => 'day',
					'activity_id' => $id,
					'title'       => 'First activity today',
					'created_at'  => $created_at,
				)
			);
		}
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
