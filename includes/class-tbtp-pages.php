<?php
/**
 * The two shortcodes and their assets.
 *
 *   [tbt_my_points]  students: balance, earned this week, history
 *   [tbt_points]     teachers: Students tab; Rules tab for administrators
 *
 * No hero: the Divi page supplies its header. The server prints the
 * messages that need no data (Notes missing, logged out, wrong role);
 * everything else is drawn by assets/js/points.js from the REST routes.
 *
 * The design tokens are TBT Hub's, under the shared handle 'tbt-tokens'.
 * When Hub is inactive, the vendored copy in assets/css/tbt-tokens.css is
 * registered under that same handle (the TBT Results pattern).
 */

defined( 'ABSPATH' ) || exit;

class TBTP_Pages {

	const TEACHER = 'tbt_points';
	const STUDENT = 'tbt_my_points';

	public function __construct() {
		add_shortcode( self::TEACHER, array( $this, 'teacher' ) );
		add_shortcode( self::STUDENT, array( $this, 'student' ) );
		// After Hub registers 'tbt-tokens' at priority 5.
		add_action( 'wp_enqueue_scripts', array( $this, 'early_styles' ), 20 );
		add_action( 'template_redirect', array( $this, 'maybe_nocache' ) );
		add_action( 'wp_ajax_tbtp_nonce', array( $this, 'fresh_nonce' ) );
	}

	/* ---------- Assets ---------- */

	private static function register() {
		if ( ! wp_style_is( 'tbt-tokens', 'registered' ) ) {
			wp_register_style( 'tbt-tokens', TBTP_URL . 'assets/css/tbt-tokens.css', array(), self::file_version( 'assets/css/tbt-tokens.css' ) );
		}
		if ( ! wp_style_is( 'tbtp-fonts', 'registered' ) ) {
			wp_register_style( 'tbtp-fonts', TBTP_URL . 'assets/css/fonts.css', array(), TBTP_VERSION );
		}
		if ( ! wp_style_is( 'tbtp-points', 'registered' ) ) {
			wp_register_style( 'tbtp-points', TBTP_URL . 'assets/css/points.css', array( 'tbt-tokens', 'tbtp-fonts' ), TBTP_VERSION );
		}
	}

	private static function enqueue_styles() {
		self::register();
		wp_enqueue_style( 'tbtp-points' );
	}

	/**
	 * Styles in the head on a page holding either shortcode, so nothing
	 * flashes unstyled. The script is added by the shortcode itself.
	 */
	public function early_styles() {
		if ( self::page_has( self::TEACHER ) || self::page_has( self::STUDENT ) ) {
			self::enqueue_styles();
		}
	}

	/**
	 * A per-visitor page: keep it out of every cache.
	 */
	public function maybe_nocache() {
		if ( ! self::page_has( self::TEACHER ) && ! self::page_has( self::STUDENT ) ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * The script and its boot data.
	 *
	 * @param string $mode 'teacher' or 'student'.
	 */
	private static function enqueue_app( $mode ) {
		static $done = false;
		self::enqueue_styles();
		if ( $done ) {
			return;
		}
		$done = true;
		wp_enqueue_script( 'tbtp-points', TBTP_URL . 'assets/js/points.js', array(), TBTP_VERSION, true );
		wp_localize_script(
			'tbtp-points',
			'TBTP_POINTS',
			array(
				'rest'    => esc_url_raw( rest_url( TBTP_Rest::NS . '/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'ajax'    => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'mode'    => $mode,
				// UI only: the /rules routes check this again.
				'isAdmin' => 'teacher' === $mode && TBTP_Access::is_admin( get_current_user_id() ),
			)
		);
	}

	/**
	 * A fresh REST nonce for a page left open long enough for its nonce to
	 * expire. Logged-in users only (wp_ajax_, not wp_ajax_nopriv_).
	 */
	public function fresh_nonce() {
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * @param string $shortcode Shortcode tag.
	 * @return bool
	 */
	private static function page_has( $shortcode ) {
		if ( ! is_singular() ) {
			return false;
		}
		$post = get_post();
		return $post && has_shortcode( (string) $post->post_content, $shortcode );
	}

	/**
	 * Cache buster for the vendored token file: its modification time.
	 *
	 * @param string $relative Path relative to the plugin folder.
	 * @return string
	 */
	private static function file_version( $relative ) {
		$mtime = @filemtime( TBTP_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return $mtime ? TBTP_VERSION . '.' . $mtime : TBTP_VERSION;
	}

	/**
	 * Notes 1.22.0+ and the ledger table.
	 *
	 * @return bool
	 */
	private static function ready() {
		return TBTP_Access::notes_ready() && TBTP_DB::table_exists();
	}

	/* ---------- Shortcodes ---------- */

	/**
	 * [tbt_points]
	 *
	 * @return string
	 */
	public function teacher() {
		self::enqueue_styles();

		if ( ! self::ready() ) {
			return self::wrap( 'teacher', self::line( __( 'Points need TBT Notes 1.22.0 or later.', 'tbt-points' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return self::wrap(
				'teacher',
				self::login_card(
					__( 'Log in to see points', 'tbt-points' ),
					__( 'Points are for TBT teachers. Log in and they open straight away.', 'tbt-points' )
				)
			);
		}
		if ( ! TBTP_Access::is_teacher( get_current_user_id() ) ) {
			return self::wrap( 'teacher', self::line( __( 'This page is for teachers.', 'tbt-points' ) ) );
		}

		self::enqueue_app( 'teacher' );
		return self::app( 'teacher' );
	}

	/**
	 * [tbt_my_points]
	 *
	 * @return string
	 */
	public function student() {
		self::enqueue_styles();

		if ( ! self::ready() ) {
			return self::wrap( 'student', self::line( __( 'Points need TBT Notes 1.22.0 or later.', 'tbt-points' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return self::wrap(
				'student',
				self::login_card(
					__( 'Log in to see your points', 'tbt-points' ),
					__( 'Everything you earn on The Blue Tree is here. Log in and it opens straight away.', 'tbt-points' )
				)
			);
		}
		if ( TBTP_Access::is_teacher( get_current_user_id() ) ) {
			return self::wrap( 'student', self::line( __( "This page shows a student's own points.", 'tbt-points' ) ) );
		}

		self::enqueue_app( 'student' );
		return self::app( 'student' );
	}

	/* ---------- Markup ---------- */

	/**
	 * The mount point. The noscript line is the only content without JS.
	 *
	 * @param string $mode 'teacher' or 'student'.
	 * @return string
	 */
	private static function app( $mode ) {
		return sprintf(
			'<div id="tbtp-app-%1$s" class="tbtp tbtp--%1$s" data-tbtp-app="%1$s"><p class="tbtp-loading" role="status">%2$s</p><noscript><p class="tbtp-muted">%3$s</p></noscript></div>',
			esc_attr( $mode ),
			esc_html__( 'Loading points…', 'tbt-points' ),
			esc_html__( 'Points need JavaScript. Turn it on and reload the page.', 'tbt-points' )
		);
	}

	/**
	 * The wrapper for a message or the login card. It carries the same ID as
	 * the app would, so its heading is pinned by the same rules; only one of
	 * the two is ever on the page.
	 *
	 * @param string $mode  'teacher' or 'student'.
	 * @param string $inner Markup.
	 * @return string
	 */
	private static function wrap( $mode, $inner ) {
		return '<div id="tbtp-app-' . esc_attr( $mode ) . '" class="tbtp">' . $inner . '</div>';
	}

	/**
	 * One line on its own card.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function line( $text ) {
		return '<section class="tbtp-stage tbtp-message"><p class="tbtp-muted">' . esc_html( $text ) . '</p></section>';
	}

	/**
	 * The login card, as TBT Results shows it: heading, one line, Log in.
	 *
	 * @param string $title Heading.
	 * @param string $text  One line.
	 * @return string
	 */
	private static function login_card( $title, $text ) {
		$here = get_permalink();
		return sprintf(
			'<section class="tbtp-stage tbtp-message"><h2 class="tbtp-stage__title">%1$s</h2><p class="tbtp-muted">%2$s</p><p><a class="tbtp-btn tbtp-btn--primary" href="%3$s">%4$s</a></p></section>',
			esc_html( $title ),
			esc_html( $text ),
			esc_url( wp_login_url( $here ? $here : '' ) ),
			esc_html__( 'Log in', 'tbt-points' )
		);
	}
}
