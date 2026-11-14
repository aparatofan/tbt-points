<?php
/**
 * Plugin Name:       TBT Points
 * Description:       Students earn points for the work they finish on The Blue Tree. Listens to the TBT Notes activity log.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            The Blue Tree
 * Text Domain:       tbt-points
 */

defined( 'ABSPATH' ) || exit;

define( 'TBTP_VERSION', '0.1.0' );
define( 'TBTP_DB_VERSION', '1' );
define( 'TBTP_FILE', __FILE__ );
define( 'TBTP_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBTP_URL', plugin_dir_url( __FILE__ ) );

require_once TBTP_DIR . 'includes/class-tbtp-db.php';
require_once TBTP_DIR . 'includes/class-tbtp-rules.php';
require_once TBTP_DIR . 'includes/class-tbtp-access.php';
require_once TBTP_DIR . 'includes/class-tbtp-earn.php';
require_once TBTP_DIR . 'includes/class-tbtp-rest.php';
require_once TBTP_DIR . 'includes/class-tbtp-pages.php';

/**
 * Activation: the table, and the start date when no rules are stored yet.
 */
function tbtp_activate() {
	TBTP_DB::install();
	TBTP_Rules::ensure_start();
}
register_activation_hook( __FILE__, 'tbtp_activate' );

/**
 * Register this plugin on the TBT Hub Overview page. A filter on Hub's own
 * hook: when Hub is absent nothing applies it.
 *
 * @param array $items Existing hub items.
 * @return array
 */
function tbtp_register_hub_item( $items ) {
	$items   = is_array( $items ) ? $items : array();
	$items[] = array(
		'slug'        => 'tbt-points',
		'title'       => 'TBT Points',
		'description' => 'Students earn points for their work and exchange them for rewards.',
		'capability'  => defined( 'TBT_NOTES_CAP' ) ? TBT_NOTES_CAP : 'manage_options',
	);
	return $items;
}
add_filter( 'tbt_hub_items', 'tbtp_register_hub_item' );

// Notes loads on its own schedule, so readiness is checked when used, never
// here: without Notes the shortcodes print one line, the routes answer 503
// and the listener is simply never called.
add_action(
	'plugins_loaded',
	static function () {
		// FTP deploys never fire activation: create or update the table here.
		TBTP_DB::maybe_upgrade();
		TBTP_Rules::ensure_start();
		TBTP_Earn::register();
		new TBTP_Rest();
		new TBTP_Pages();
	}
);
