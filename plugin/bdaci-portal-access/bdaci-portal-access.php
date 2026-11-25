<?php
/**
 * Plugin Name:       BDACI Portal Access
 * Description:       Restricts the BDACI Board &amp; Committee Governance Portal to authorized users. Email + password sign-in with an emailed verification code, Microsoft 365 and Google sign-in, an admin-controlled allowlist, portal roles, and private document storage.
 * Version:           2.11.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            BDACI
 * License:           GPL-2.0-or-later
 * Text Domain:       bdaci-portal-access
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

define( 'BDACI_PA_VERSION', '2.11.2' );
define( 'BDACI_PA_DB_VERSION', '6' ); // 3: portal layout, meetings, requests, sign-offs. 4: QA Testing record. 5: QA screenshots. 6: signatures on sign-offs.
define( 'BDACI_PA_FILE', __FILE__ );
define( 'BDACI_PA_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDACI_PA_URL', plugin_dir_url( __FILE__ ) );

require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-settings.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-log.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-roles.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-members.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-access.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-login.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-oidc.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-documents.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-sections.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-meetings.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-requests.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-profile.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-signoffs.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-screens.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-portal.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-admin.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-admin-portal.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-demo.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-qa.php';
require_once BDACI_PA_DIR . 'includes/class-bdaci-pa-updater.php';

/**
 * Activation: tables, roles, rewrite rules, private upload folder.
 */
function bdaci_pa_activate() {
	BDACI_PA_Log::install();
	BDACI_PA_Members::install();
	BDACI_PA_Roles::install();
	BDACI_PA_Meetings::install();
	BDACI_PA_Requests::install();
	BDACI_PA_Signoffs::install();
	BDACI_PA_QA::install();
	BDACI_PA_Documents::ensure_private_dir();
	BDACI_PA_Login::add_rewrite_rules();
	flush_rewrite_rules();
	update_option( 'bdaci_pa_db_version', BDACI_PA_DB_VERSION );
}
register_activation_hook( __FILE__, 'bdaci_pa_activate' );

/**
 * Deactivation: drop our rewrite rules. Data and roles are kept.
 */
function bdaci_pa_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'bdaci_pa_deactivate' );

/**
 * Boot all modules.
 */
function bdaci_pa_boot() {
	if ( get_option( 'bdaci_pa_db_version' ) !== BDACI_PA_DB_VERSION ) {
		// Upgrade after an update: run late on init, when rewrite rules can be rebuilt safely.
		add_action( 'init', 'bdaci_pa_activate', 99 );
	}
	BDACI_PA_Roles::init();
	BDACI_PA_Access::init();
	BDACI_PA_Login::init();
	BDACI_PA_OIDC::init();
	BDACI_PA_Documents::init();
	BDACI_PA_Portal::init();
	BDACI_PA_Profile::init();
	BDACI_PA_Signoffs::init();
	BDACI_PA_QA::init();
	BDACI_PA_Updater::init(); // Also outside wp-admin: automatic updates run from WP-Cron.
	if ( is_admin() ) {
		BDACI_PA_Admin::init();
		BDACI_PA_Admin_Portal::init();
		BDACI_PA_Demo::init();
	}
}
add_action( 'plugins_loaded', 'bdaci_pa_boot' );
