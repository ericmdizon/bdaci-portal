<?php
/**
 * Access/activity log.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads the activity log table.
 */
class BDACI_PA_Log {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_log';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				event varchar(64) NOT NULL DEFAULT '',
				details text NULL,
				ip varchar(45) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY event (event)
			) {$charset};"
		);
	}

	/**
	 * Record an event.
	 *
	 * @param string $event   Event key.
	 * @param string $email   Email involved.
	 * @param int    $user_id User involved.
	 * @param string $details Free text.
	 */
	public static function add( $event, $email = '', $user_id = 0, $details = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'user_id'    => (int) $user_id,
				'email'      => substr( sanitize_email( $email ), 0, 190 ),
				'event'      => substr( sanitize_key( $event ), 0, 64 ),
				'details'    => sanitize_text_field( $details ),
				'ip'         => self::ip(),
			),
			array( '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Latest entries.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function latest( $limit = 200 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ) );
	}

	/**
	 * Remote IP (REMOTE_ADDR only; forwarded headers can be spoofed).
	 *
	 * @return string
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}
}
