<?php
/**
 * Uninstall. Removes data only if the admin opted in on the Settings screen.
 *
 * @package BDACI_Portal_Access
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$bdaci_pa_settings = get_option( 'bdaci_pa_settings', array() );
if ( empty( $bdaci_pa_settings['remove_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdaci_pa_members" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdaci_pa_log" );
foreach ( array( 'meetings', 'rsvps', 'requests', 'signoff_events', 'qa_log' ) as $bdaci_pa_t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bdaci_pa_{$bdaci_pa_t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}
foreach ( array( '_bdaci_hidden', '_bdaci_screen', '_bdaci_menu', '_bdaci_section', '_bdaci_sections', '_bdaci_doc_date', '_bdaci_doc_changes' ) as $bdaci_pa_k ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $bdaci_pa_k ) );
}
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'bdaci_' ) . '%' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", '_bdaci_access' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_bdaci_pa_sub_' ) . '%' ) );
// phpcs:enable

delete_option( 'bdaci_pa_settings' );
delete_option( 'bdaci_pa_db_version' );
foreach ( array( 'bdaci_pa_sections', 'bdaci_pa_hidden_folders', 'bdaci_pa_signoffs', 'bdaci_pa_demo', 'bdaci_pa_demo_password', 'bdaci_pa_qa_users', 'bdaci_pa_qa_rounds', 'bdaci_pa_qa_current' ) as $bdaci_pa_o ) {
	delete_option( $bdaci_pa_o );
}
delete_transient( 'bdaci_pa_screen_map' );
delete_transient( 'bdaci_pa_protected_map' );

foreach ( array( 'bdaci_board_member', 'bdaci_committee_member', 'bdaci_finance_member', 'bdaci_portal_admin' ) as $bdaci_pa_role ) {
	remove_role( $bdaci_pa_role );
}
$bdaci_pa_admin = get_role( 'administrator' );
if ( $bdaci_pa_admin ) {
	foreach ( array( 'bdaci_view_portal', 'bdaci_view_board', 'bdaci_view_committee', 'bdaci_view_finance', 'bdaci_manage_portal' ) as $bdaci_pa_cap ) {
		$bdaci_pa_admin->remove_cap( $bdaci_pa_cap );
	}
}
// Portal pages, private files and the bdaci_pa_private_dir option are kept on purpose so documents are never lost by accident.
