<?php
/**
 * Who sees which students. One place decides; every route asks here.
 *
 *   Administrator  every student in every class, plus students in no class
 *                  who have ledger rows; edits the Rules
 *   Teacher        the students in their own classes
 *   Student        only themselves
 *   Logged out     nothing
 *
 * The TBT Results pattern (TBTR_Access). Points reads TBT Notes only through
 * its public static helpers; when Notes is missing or older than 1.22.0,
 * notes_ready() is false and nothing here touches a Notes class.
 */

defined( 'ABSPATH' ) || exit;

class TBTP_Access {

	/** The Notes release that introduced tbt_notes_activity_recorded. */
	const NOTES_MIN = '1.22.0';

	/** TBT_Notes_DB methods Points relies on. */
	const NOTES_METHODS = array(
		'get_all_classes',
		'get_classes_for_teacher',
		'get_student_ids_for_class',
	);

	/** Per-request caches, keyed by viewer ID. */
	private static $classes  = array();
	private static $students = array();

	/**
	 * Is TBT Notes 1.22.0+ active with everything Points uses?
	 *
	 * @return bool
	 */
	public static function notes_ready() {
		if ( ! class_exists( 'TBT_Notes_DB' ) || ! class_exists( 'TBT_Notes_Capabilities' ) ) {
			return false;
		}
		if ( ! defined( 'TBT_NOTES_VERSION' ) || version_compare( (string) TBT_NOTES_VERSION, self::NOTES_MIN, '<' ) ) {
			return false;
		}
		foreach ( self::NOTES_METHODS as $method ) {
			if ( ! method_exists( 'TBT_Notes_DB', $method ) ) {
				return false;
			}
		}
		return method_exists( 'TBT_Notes_Capabilities', 'user_can_manage' )
			&& method_exists( 'TBT_Notes_Capabilities', 'user_can_manage_all' );
	}

	/**
	 * Teacher or administrator.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_teacher( $user_id ) {
		$user_id = (int) $user_id;
		return $user_id > 0 && self::notes_ready() && TBT_Notes_Capabilities::user_can_manage( $user_id );
	}

	/**
	 * Administrator: every student, and the Rules.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_admin( $user_id ) {
		return self::is_teacher( $user_id ) && TBT_Notes_Capabilities::user_can_manage_all( (int) $user_id );
	}

	/**
	 * The classes a viewer may see: all for an administrator, their own for a
	 * teacher, none otherwise.
	 *
	 * @param int $user_id Viewer.
	 * @return array[] Notes class rows.
	 */
	private static function classes( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! isset( self::$classes[ $user_id ] ) ) {
			$classes = array();
			if ( self::is_admin( $user_id ) ) {
				$classes = TBT_Notes_DB::get_all_classes();
			} elseif ( self::is_teacher( $user_id ) ) {
				$classes = TBT_Notes_DB::get_classes_for_teacher( $user_id );
			}
			self::$classes[ $user_id ] = is_array( $classes ) ? $classes : array();
		}
		return self::$classes[ $user_id ];
	}

	/**
	 * The students a teacher or administrator may see, with their class.
	 * Teachers and administrators who happen to be in a class are left out:
	 * they never earn.
	 *
	 * @param int $viewer_id Viewer.
	 * @return array<int,array{class_id:int,class_title:string}> Keyed by student ID.
	 */
	public static function students( $viewer_id ) {
		$viewer_id = (int) $viewer_id;
		if ( isset( self::$students[ $viewer_id ] ) ) {
			return self::$students[ $viewer_id ];
		}

		$out = array();
		foreach ( self::classes( $viewer_id ) as $class ) {
			$class_id = (int) $class['id'];
			$ids      = TBT_Notes_DB::get_student_ids_for_class( $class_id );
			foreach ( is_array( $ids ) ? $ids : array() as $student_id ) {
				$student_id = (int) $student_id;
				if ( $student_id > 0 && ! isset( $out[ $student_id ] ) ) {
					$out[ $student_id ] = array(
						'class_id'    => $class_id,
						'class_title' => (string) ( $class['title'] ?? '' ),
					);
				}
			}
		}

		if ( self::is_admin( $viewer_id ) && TBTP_DB::table_exists() ) {
			foreach ( TBTP_DB::user_ids() as $student_id ) {
				if ( $student_id > 0 && ! isset( $out[ $student_id ] ) ) {
					$out[ $student_id ] = array(
						'class_id'    => 0,
						'class_title' => '',
					);
				}
			}
		}

		foreach ( array_keys( $out ) as $student_id ) {
			if ( TBT_Notes_Capabilities::user_can_manage( $student_id ) ) {
				unset( $out[ $student_id ] );
			}
		}

		self::$students[ $viewer_id ] = $out;
		return $out;
	}

	/**
	 * May this teacher or administrator see (and adjust) this student?
	 *
	 * @param int $viewer_id  Viewer.
	 * @param int $student_id Student.
	 * @return bool
	 */
	public static function can_see_student( $viewer_id, $student_id ) {
		$student_id = (int) $student_id;
		return $student_id > 0 && self::is_teacher( $viewer_id ) && isset( self::students( $viewer_id )[ $student_id ] );
	}

	/**
	 * Display names for user IDs (login when the name is empty).
	 *
	 * @param int[] $user_ids Users.
	 * @return array<int,string>
	 */
	public static function names( array $user_ids ) {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( ! $user_ids ) {
			return array();
		}
		$out = array();
		foreach ( get_users( array( 'include' => $user_ids, 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) as $user ) {
			$name                   = trim( (string) $user->display_name );
			$out[ (int) $user->ID ] = '' !== $name ? $name : (string) $user->user_login;
		}
		return $out;
	}
}
