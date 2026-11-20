<?php
/**
 * The earning rules: stored in the `tbtp_rules` option, and the pure award
 * calculation.
 *
 * defaults(), validate(), award(), award_direct(), day_bounds() and tool_label() use no
 * WordPress, so tests/test-rules.php runs them with plain PHP. get(), save()
 * and ensure_start() are the only methods that touch the option.
 */

defined( 'ABSPATH' ) || exit;

class TBTP_Rules {

	const OPTION = 'tbtp_rules';

	/** Scalar fields and their allowed range. */
	const RANGES = array(
		'unknown'   => array( 0, 1000 ),
		'bonus'     => array( 0, 1000 ),
		'bonus_at'  => array( 1, 100 ),
		'repeat'    => array( 0, 1000 ),
		'day'       => array( 0, 1000 ),
		'daily_cap' => array( 0, 1000 ),
	);

	/**
	 * The built-in tool labels, keyed by slug (TBT Results' labels).
	 *
	 * @return array<string,string>
	 */
	public static function tool_labels() {
		return array(
			'test'     => 'Test',
			'homework' => 'Homework',
			'swipe'    => 'Swipe',
			'matching' => 'Matching Game',
			'dragdrop' => 'Drag & Drop',
			'drill'    => 'Translation Drill',
			'fill'     => 'Fill',
			'lesson'   => 'Lesson',
			'poll'     => 'Poll',
			'dictionary_word' => 'Dictionary: new word',
		);
	}

	/**
	 * The label for a tool slug; an unknown slug shows as itself, capitalised.
	 *
	 * @param string $slug Tool slug.
	 * @return string
	 */
	public static function tool_label( $slug ) {
		$labels = self::tool_labels();
		$slug   = (string) $slug;
		return $labels[ $slug ] ?? ucfirst( $slug );
	}

	/**
	 * The prototype's numbers. `start` is empty until the plugin is activated.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'tools'     => array(
				'test'     => 10,
				'homework' => 15,
				'swipe'    => 5,
				'matching' => 5,
				'dragdrop' => 5,
				'drill'    => 5,
				'fill'     => 5,
				'lesson'   => 5,
				'poll'     => 3,
				'dictionary_word' => 1,
			),
			'unknown'   => 5,
			'bonus'     => 5,
			'bonus_at'  => 80,
			'repeat'    => 1,
			'day'       => 2,
			'daily_cap' => 50,
			'start'     => '',
		);
	}

	/**
	 * Stored values merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		return self::merge( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Stored values over the defaults, keeping only known keys and shapes.
	 *
	 * @param array $stored Stored option.
	 * @return array
	 */
	public static function merge( array $stored ) {
		$rules = self::defaults();
		if ( isset( $stored['tools'] ) && is_array( $stored['tools'] ) ) {
			foreach ( $rules['tools'] as $slug => $points ) {
				if ( isset( $stored['tools'][ $slug ] ) ) {
					$rules['tools'][ $slug ] = (int) $stored['tools'][ $slug ];
				}
			}
		}
		foreach ( array_keys( self::RANGES ) as $key ) {
			if ( isset( $stored[ $key ] ) ) {
				$rules[ $key ] = (int) $stored[ $key ];
			}
		}
		if ( isset( $stored['start'] ) && is_string( $stored['start'] ) && self::is_date( $stored['start'] ) ) {
			$rules['start'] = $stored['start'];
		}
		return $rules;
	}

	/**
	 * Validate submitted rules. Field names in `errors`: `tools.{slug}`,
	 * `unknown`, `bonus`, `bonus_at`, `repeat`, `day`, `daily_cap`, `start`.
	 *
	 * @param array $input Submitted values (same shape as get()).
	 * @return array{rules:array,errors:array<string,string>}
	 */
	public static function validate( array $input ) {
		$rules  = self::defaults();
		$errors = array();
		$tools  = isset( $input['tools'] ) && is_array( $input['tools'] ) ? $input['tools'] : array();

		foreach ( array_keys( $rules['tools'] ) as $slug ) {
			$value = self::int_in( $tools[ $slug ] ?? null, 0, 1000 );
			if ( null === $value ) {
				$errors[ 'tools.' . $slug ] = 'A whole number from 0 to 1000.';
			} else {
				$rules['tools'][ $slug ] = $value;
			}
		}

		foreach ( self::RANGES as $key => $range ) {
			$value = self::int_in( $input[ $key ] ?? null, $range[0], $range[1] );
			if ( null === $value ) {
				$errors[ $key ] = sprintf( 'A whole number from %d to %d.', $range[0], $range[1] );
			} else {
				$rules[ $key ] = $value;
			}
		}

		$start = isset( $input['start'] ) && is_string( $input['start'] ) ? trim( $input['start'] ) : '';
		if ( self::is_date( $start ) ) {
			$rules['start'] = $start;
		} else {
			$errors['start'] = 'A date, e.g. 2026-11-17.';
		}

		return array(
			'rules'  => $rules,
			'errors' => $errors,
		);
	}

	/**
	 * Validate and store. Nothing is stored when any field is wrong.
	 *
	 * @param array $input Submitted values.
	 * @return array{rules:array,errors:array<string,string>}
	 */
	public static function save( array $input ) {
		$result = self::validate( $input );
		if ( ! $result['errors'] ) {
			update_option( self::OPTION, $result['rules'], true );
		}
		return $result;
	}

	/**
	 * Set `start` to today (site time zone) when it has never been set: on
	 * activation, and from the upgrade check for FTP-only installs.
	 */
	public static function ensure_start() {
		$stored = get_option( self::OPTION, null );
		if ( is_array( $stored ) && isset( $stored['start'] ) && self::is_date( (string) $stored['start'] ) ) {
			return;
		}
		$stored          = is_array( $stored ) ? $stored : array();
		$stored['start'] = current_datetime()->format( 'Y-m-d' );
		update_option( self::OPTION, self::merge( $stored ), true );
	}

	/**
	 * How many points one finished activity earns. Pure.
	 *
	 * @param array $rules Rules, as get().
	 * @param array $in    tool (string), percent (int|null), is_first (bool),
	 *                     repeat_today (bool), day_given (bool),
	 *                     earned_today (int).
	 * @return array{earn:int,earn_detail:string,day:int}
	 */
	public static function award( array $rules, array $in ) {
		$rules = self::merge( $rules );
		$tool  = (string) ( $in['tool'] ?? '' );

		if ( ! empty( $in['is_first'] ) ) {
			$earn = $rules['tools'][ $tool ] ?? $rules['unknown'];
			$pct  = $in['percent'] ?? null;
			if ( null !== $pct && (int) $pct >= $rules['bonus_at'] ) {
				$earn += $rules['bonus'];
			}
			$detail = '';
		} else {
			$earn   = empty( $in['repeat_today'] ) ? $rules['repeat'] : 0;
			$detail = 'again';
		}

		$day = empty( $in['day_given'] ) ? $rules['day'] : 0;

		$room = max( 0, $rules['daily_cap'] - (int) ( $in['earned_today'] ?? 0 ) );
		$earn = max( 0, min( (int) $earn, $room ) );
		$day  = max( 0, min( (int) $day, $room - $earn ) );

		return array(
			'earn'        => $earn,
			'earn_detail' => $detail,
			'day'         => $day,
		);
	}

	/**
	 * How many points one direct event earns (tbt_points_record_event()).
	 * Pure. The same rules as a Notes row with no score, but once ever: an
	 * event that has earned before (is_first false) earns nothing at all,
	 * not repeat points and not the day bonus.
	 *
	 * @param array $rules Rules, as get().
	 * @param array $in    As award(); percent is ignored.
	 * @return array{earn:int,earn_detail:string,day:int}
	 */
	public static function award_direct( array $rules, array $in ) {
		if ( empty( $in['is_first'] ) ) {
			return array(
				'earn'        => 0,
				'earn_detail' => '',
				'day'         => 0,
			);
		}
		$in['percent'] = null;
		return self::award( $rules, $in );
	}

	/**
	 * The UTC bounds [from, to) of one site-time-zone calendar day. Pure.
	 *
	 * @param string        $ymd Local date, Y-m-d.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @return array{0:string,1:string}
	 */
	public static function day_bounds( $ymd, \DateTimeZone $tz ) {
		$utc   = new \DateTimeZone( 'UTC' );
		$start = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
		$end   = $start->modify( '+1 day' );
		return array(
			$start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			$end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
		);
	}

	/**
	 * A real Y-m-d date (no 30 February).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_date( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return $dt && $dt->format( 'Y-m-d' ) === $value;
	}

	/**
	 * An integer within [min, max], or null. Accepts ints and digit strings.
	 *
	 * @param mixed $value Value.
	 * @param int   $min   Minimum.
	 * @param int   $max   Maximum.
	 * @return int|null
	 */
	private static function int_in( $value, $min, $max ) {
		if ( is_int( $value ) ) {
			$n = $value;
		} elseif ( is_string( $value ) && preg_match( '/^-?\d{1,7}$/', trim( $value ) ) ) {
			$n = (int) trim( $value );
		} elseif ( is_float( $value ) && floor( $value ) === $value ) {
			$n = (int) $value;
		} else {
			return null;
		}
		return ( $n >= $min && $n <= $max ) ? $n : null;
	}
}
