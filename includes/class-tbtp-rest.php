<?php
/**
 * REST routes, namespace tbt-points/v1.
 *
 *   GET  /me                      student  balance, week, history
 *   GET  /teacher/students        teacher  visible students and their totals
 *   GET  /teacher/student/{id}    teacher  one visible student's history
 *   POST /teacher/adjust          teacher  give or take points
 *   GET  /rules, POST /rules      admin    read / save the rules
 *
 * Every permission_callback checks Notes, login and role; the handlers
 * re-check that a student is visible through TBTP_Access. Cookie requests
 * are nonce-checked by WordPress core (X-WP-Nonce, action wp_rest).
 */

defined( 'ABSPATH' ) || exit;

class TBTP_Rest {

	const NS       = 'tbt-points/v1';
	const PER_PAGE = 50;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes() {
		register_rest_route(
			self::NS,
			'/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'me' ),
				'permission_callback' => array( $this, 'can_me' ),
			)
		);
		register_rest_route(
			self::NS,
			'/teacher/students',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'students' ),
				'permission_callback' => array( $this, 'can_teacher' ),
			)
		);
		register_rest_route(
			self::NS,
			'/teacher/student/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'student' ),
				'permission_callback' => array( $this, 'can_teacher' ),
			)
		);
		register_rest_route(
			self::NS,
			'/teacher/adjust',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'adjust' ),
				'permission_callback' => array( $this, 'can_teacher' ),
			)
		);
		register_rest_route(
			self::NS,
			'/rules',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rules' ),
					'permission_callback' => array( $this, 'can_admin' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_rules' ),
					'permission_callback' => array( $this, 'can_admin' ),
				),
			)
		);
	}

	/* ---------- Permissions ---------- */

	/**
	 * Notes ready, table there, logged in.
	 *
	 * @return true|WP_Error
	 */
	public function can_any() {
		self::nocache();
		if ( ! TBTP_Access::notes_ready() || ! TBTP_DB::table_exists() ) {
			return new WP_Error( 'tbtp_notes_missing', __( 'Points need TBT Notes 1.22.0 or later.', 'tbt-points' ), array( 'status' => 503 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'tbtp_logged_out', __( 'Log in to see points.', 'tbt-points' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/**
	 * A logged-in student (not a teacher).
	 *
	 * @return true|WP_Error
	 */
	public function can_me() {
		$base = $this->can_any();
		if ( true !== $base ) {
			return $base;
		}
		if ( TBTP_Access::is_teacher( get_current_user_id() ) ) {
			return new WP_Error( 'tbtp_not_student', __( "This page shows a student's own points.", 'tbt-points' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public function can_teacher() {
		$base = $this->can_any();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! TBTP_Access::is_teacher( get_current_user_id() ) ) {
			return new WP_Error( 'tbtp_forbidden', __( 'This page is for teachers.', 'tbt-points' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * @return true|WP_Error
	 */
	public function can_admin() {
		$base = $this->can_teacher();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! TBTP_Access::is_admin( get_current_user_id() ) ) {
			return new WP_Error( 'tbtp_forbidden', __( 'Only an administrator can change the rules.', 'tbt-points' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/* ---------- Routes ---------- */

	/**
	 * GET /me
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function me( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$page    = TBTP_DB::history( $user_id, self::page_param( $request ), self::PER_PAGE );

		return self::respond(
			array(
				'balance'  => TBTP_DB::balance( $user_id ),
				'week'     => TBTP_DB::earned_since( $user_id, self::days_back( 7 ) ),
				'history'  => self::shape_history( $page['rows'] ),
				'has_more' => $page['has_more'],
			)
		);
	}

	/**
	 * GET /teacher/students
	 *
	 * @return WP_REST_Response
	 */
	public function students() {
		$viewer   = get_current_user_id();
		$visible  = TBTP_Access::students( $viewer );
		$names    = TBTP_Access::names( array_keys( $visible ) );
		$sums     = TBTP_DB::summaries( array_keys( $names ), self::days_back( 30 ) );
		$tz       = wp_timezone();
		$now      = time();
		$rows     = array();
		$total_30 = 0;
		$earning  = 0;

		foreach ( $names as $id => $name ) {
			$sum = $sums[ $id ] ?? array(
				'balance'     => 0,
				'earned'      => 0,
				'last_earned' => '',
			);
			$total_30 += $sum['earned'];
			if ( $sum['earned'] > 0 ) {
				++$earning;
			}
			$rows[] = array(
				'id'          => (int) $id,
				'name'        => $name,
				'class_title' => $visible[ $id ]['class_title'],
				'balance'     => $sum['balance'],
				'earned_30'   => $sum['earned'],
				'last_earned' => '' === $sum['last_earned'] ? null : array(
					'when'      => self::iso( $sum['last_earned'], $tz ),
					'when_text' => self::when_text( $sum['last_earned'], $tz, $now ),
				),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strnatcasecmp( $a['name'], $b['name'] );
			}
		);

		return self::respond(
			array(
				'students' => $rows,
				'stats'    => array(
					'earned_30' => $total_30,
					'earning'   => $earning,
					'of'        => count( $rows ),
				),
			)
		);
	}

	/**
	 * GET /teacher/student/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function student( WP_REST_Request $request ) {
		$viewer     = get_current_user_id();
		$student_id = absint( $request->get_param( 'id' ) );
		if ( ! TBTP_Access::can_see_student( $viewer, $student_id ) ) {
			return self::not_yours();
		}
		$names = TBTP_Access::names( array( $student_id ) );
		$page  = TBTP_DB::history( $student_id, self::page_param( $request ), self::PER_PAGE );

		return self::respond(
			array(
				'student'  => array(
					'id'          => $student_id,
					'name'        => $names[ $student_id ] ?? '',
					'class_title' => TBTP_Access::students( $viewer )[ $student_id ]['class_title'],
					'balance'     => TBTP_DB::balance( $student_id ),
				),
				'history'  => self::shape_history( $page['rows'] ),
				'has_more' => $page['has_more'],
			)
		);
	}

	/**
	 * POST /teacher/adjust { user_id, points, reason }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function adjust( WP_REST_Request $request ) {
		$viewer     = get_current_user_id();
		$student_id = absint( $request->get_param( 'user_id' ) );
		if ( ! TBTP_Access::can_see_student( $viewer, $student_id ) ) {
			return self::not_yours();
		}

		$errors = array();
		$raw    = $request->get_param( 'points' );
		$points = null;
		if ( is_int( $raw ) || ( is_string( $raw ) && preg_match( '/^\s*[+-]?\d{1,4}\s*$/', $raw ) ) ) {
			$points = (int) trim( (string) $raw );
		}
		if ( null === $points || 0 === $points || $points < -1000 || $points > 1000 ) {
			$errors['points'] = __( 'A whole number from −1000 to 1000, not 0.', 'tbt-points' );
		}

		$reason = sanitize_text_field( (string) $request->get_param( 'reason' ) );
		$len    = function_exists( 'mb_strlen' ) ? mb_strlen( $reason ) : strlen( $reason );
		if ( $len < 3 || $len > 190 ) {
			$errors['reason'] = __( 'Write a reason, 3 to 190 characters.', 'tbt-points' );
		}

		if ( $errors ) {
			return self::field_errors( $errors );
		}

		$id = TBTP_DB::insert(
			array(
				'user_id'    => $student_id,
				'points'     => $points,
				'kind'       => 'adjust',
				'detail'     => $reason,
				'created_by' => $viewer,
			)
		);
		if ( ! $id ) {
			return new WP_Error( 'tbtp_write_failed', __( 'The points could not be saved. Please try again.', 'tbt-points' ), array( 'status' => 500 ) );
		}

		return self::respond(
			array(
				'saved'   => true,
				'balance' => TBTP_DB::balance( $student_id ),
			)
		);
	}

	/**
	 * GET /rules
	 *
	 * @return WP_REST_Response
	 */
	public function rules() {
		return self::respond( self::rules_body( TBTP_Rules::get() ) );
	}

	/**
	 * POST /rules (JSON body, same shape as GET's `rules`)
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_rules( WP_REST_Request $request ) {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			$input = $request->get_body_params();
		}
		$result = TBTP_Rules::save( is_array( $input ) ? $input : array() );
		if ( $result['errors'] ) {
			return self::field_errors( $result['errors'] );
		}
		return self::respond( self::rules_body( TBTP_Rules::get() ) + array( 'saved' => true ) );
	}

	/* ---------- Shaping ---------- */

	/**
	 * @param array $rules Rules.
	 * @return array
	 */
	private static function rules_body( array $rules ) {
		return array(
			'rules'  => $rules,
			'labels' => TBTP_Rules::tool_labels(),
		);
	}

	/**
	 * Ledger rows as history items:
	 * { when, when_text, kind, label, title, detail, points }.
	 *
	 * @param array[] $rows Ledger rows.
	 * @return array[]
	 */
	private static function shape_history( array $rows ) {
		$tz  = wp_timezone();
		$now = time();
		$out = array();
		foreach ( $rows as $row ) {
			$kind   = (string) $row['kind'];
			$title  = (string) $row['title'];
			$detail = (string) $row['detail'];
			switch ( $kind ) {
				case 'earn':
				case 'repeat':
					$label = TBTP_Rules::tool_label( $row['tool'] );
					if ( '' === $title ) {
						$title = $label;
					}
					break;
				case 'day':
					$label = __( 'Bonus', 'tbt-points' );
					$title = __( 'First activity today', 'tbt-points' );
					break;
				case 'adjust':
					$label  = __( 'From your teacher', 'tbt-points' );
					$title  = $detail;
					$detail = '';
					break;
				default:
					$label = __( 'Reward', 'tbt-points' );
			}
			$out[] = array(
				'when'      => self::iso( $row['created_at'], $tz ),
				'when_text' => self::when_text( $row['created_at'], $tz, $now ),
				'kind'      => $kind,
				'label'     => $label,
				'title'     => $title,
				'detail'    => $detail,
				'points'    => (int) $row['points'],
			);
		}
		return $out;
	}

	/* ---------- Dates (site time zone; stored UTC) ---------- */

	/**
	 * The UTC moment N days back on the site's wall clock (as TBT Results).
	 *
	 * @param int $days Days.
	 * @return string
	 */
	private static function days_back( $days ) {
		$local = ( new \DateTimeImmutable( '@' . time() ) )->setTimezone( wp_timezone() );
		return $local->modify( '-' . (int) $days . ' days' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @return \DateTimeImmutable|null
	 */
	private static function local( $utc, \DateTimeZone $tz ) {
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $utc, new \DateTimeZone( 'UTC' ) );
		return $dt ? $dt->setTimezone( $tz ) : null;
	}

	/**
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @return string ISO 8601 in the site time zone.
	 */
	private static function iso( $utc, \DateTimeZone $tz ) {
		$dt = self::local( $utc, $tz );
		return $dt ? $dt->format( 'c' ) : '';
	}

	/**
	 * "Today 18:40", "Yesterday 09:12", "11 Nov", "11 Nov 2025" (TBT Results).
	 *
	 * @param string        $utc MySQL datetime, UTC.
	 * @param \DateTimeZone $tz  Site time zone.
	 * @param int           $now Unix timestamp.
	 * @return string
	 */
	private static function when_text( $utc, \DateTimeZone $tz, $now ) {
		$dt = self::local( $utc, $tz );
		if ( ! $dt ) {
			return '';
		}
		$today = ( new \DateTimeImmutable( '@' . (int) $now ) )->setTimezone( $tz );
		$day   = $dt->format( 'Y-m-d' );
		if ( $day === $today->format( 'Y-m-d' ) ) {
			return 'Today ' . $dt->format( 'H:i' );
		}
		if ( $day === $today->modify( '-1 day' )->format( 'Y-m-d' ) ) {
			return 'Yesterday ' . $dt->format( 'H:i' );
		}
		return $dt->format( $dt->format( 'Y' ) === $today->format( 'Y' ) ? 'j M' : 'j M Y' );
	}

	/* ---------- Helpers ---------- */

	/**
	 * @param WP_REST_Request $request Request.
	 * @return int From 1.
	 */
	private static function page_param( WP_REST_Request $request ) {
		return min( 10000, max( 1, absint( $request->get_param( 'page' ) ) ) );
	}

	/**
	 * @return WP_Error
	 */
	private static function not_yours() {
		return new WP_Error( 'tbtp_not_yours', __( 'This student is not in your classes.', 'tbt-points' ), array( 'status' => 404 ) );
	}

	/**
	 * 400 with an error per field.
	 *
	 * @param array<string,string> $errors Field => message.
	 * @return WP_Error
	 */
	private static function field_errors( array $errors ) {
		return new WP_Error(
			'tbtp_invalid',
			__( 'Please check the highlighted fields.', 'tbt-points' ),
			array(
				'status' => 400,
				'errors' => $errors,
			)
		);
	}

	/**
	 * No-cache headers for this request, sent from the permission callback so
	 * even a refusal is never cached (the Notes / Swipe approach).
	 */
	private static function nocache() {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}

	/**
	 * A response that no cache keeps.
	 *
	 * @param array $data Body.
	 * @return WP_REST_Response
	 */
	private static function respond( array $data ) {
		$response = new WP_REST_Response( $data, 200 );
		foreach ( wp_get_nocache_headers() as $name => $value ) {
			if ( $value ) {
				$response->header( $name, $value );
			}
		}
		$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		return $response;
	}
}
