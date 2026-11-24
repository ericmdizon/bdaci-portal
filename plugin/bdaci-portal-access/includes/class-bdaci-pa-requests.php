<?php
/**
 * "Request access" for the Education and Finance Committee sections.
 * Approved access is a committee role on the person's account, so it stays until a Portal Admin removes it.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access requests.
 */
class BDACI_PA_Requests {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_requests';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				area varchar(32) NOT NULL,
				reason text NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_at datetime NOT NULL,
				decided_by bigint(20) unsigned NOT NULL DEFAULT 0,
				decided_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY user_area (user_id,area)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/**
	 * Areas people can ask for: screen => [label, role key].
	 *
	 * @return array
	 */
	public static function areas() {
		return array(
			'education' => array( __( 'Education Committee', 'bdaci-portal-access' ), 'committee' ),
			'finance'   => array( __( 'Finance Committee', 'bdaci-portal-access' ), 'finance' ),
		);
	}

	/**
	 * A user's pending request for an area.
	 *
	 * @param int    $user_id User.
	 * @param string $area    Area.
	 * @return object|null
	 */
	public static function pending_for( $user_id, $area ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE user_id = %d AND area = %s AND status = 'pending' ORDER BY id DESC LIMIT 1", $user_id, $area ) );
	}

	/**
	 * Pending requests, oldest first.
	 *
	 * @return array
	 */
	public static function pending() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE status = 'pending' ORDER BY created_at ASC" );
	}

	/**
	 * Number of pending requests.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'pending'" );
	}

	/**
	 * Send or cancel a request from the portal.
	 *
	 * @param string $do      request|request_cancel.
	 * @param int    $user_id User.
	 */
	public static function handle_front( $do, $user_id ) {
		global $wpdb;
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BDACI_PA_Portal::handle().
		$area   = isset( $_POST['area'] ) ? sanitize_key( wp_unslash( $_POST['area'] ) ) : '';
		$reason = isset( $_POST['reason'] ) ? sanitize_textarea_field( wp_unslash( $_POST['reason'] ) ) : '';
		// phpcs:enable
		$areas = self::areas();
		if ( ! isset( $areas[ $area ] ) ) {
			return;
		}
		$existing = self::pending_for( $user_id, $area );
		if ( 'request_cancel' === $do ) {
			if ( $existing ) {
				$wpdb->update( self::table(), array( 'status' => 'cancelled', 'decided_at' => current_time( 'mysql' ), 'decided_by' => $user_id ), array( 'id' => $existing->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				BDACI_PA_Portal::flash( __( 'Your request was cancelled.', 'bdaci-portal-access' ) );
			}
			return;
		}
		$map = BDACI_PA_Portal::screen_map();
		if ( isset( $map[ $area ] ) && BDACI_PA_Access::can_view( $map[ $area ], $user_id ) ) {
			BDACI_PA_Portal::flash( __( 'You already have access to this section.', 'bdaci-portal-access' ) );
			return;
		}
		if ( $existing ) {
			BDACI_PA_Portal::flash( __( 'Your request is already with the portal administrator.', 'bdaci-portal-access' ) );
			return;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'user_id'    => $user_id,
				'area'       => $area,
				'reason'     => mb_substr( $reason, 0, 1000 ),
				'status'     => 'pending',
				'created_at' => current_time( 'mysql' ),
			)
		);
		$user = get_userdata( $user_id );
		BDACI_PA_Log::add( 'access_requested', $user->user_email, $user_id, $areas[ $area ][0] );
		wp_mail(
			BDACI_PA_Settings::notify_address(),
			/* translators: 1: person, 2: section */
			sprintf( __( '[%1$s] Access request: %2$s', 'bdaci-portal-access' ), BDACI_PA_Settings::get( 'portal_name' ), $areas[ $area ][0] ),
			/* translators: 1: person, 2: email, 3: section, 4: reason, 5: link */
			sprintf( __( "%1\$s (%2\$s) asked for access to the %3\$s section.\n\nTheir note: %4\$s\n\nApprove or decline: %5\$s", 'bdaci-portal-access' ), $user->display_name, $user->user_email, $areas[ $area ][0], $reason ? $reason : '-', admin_url( 'admin.php?page=bdaci-portal' ) )
		);
		BDACI_PA_Portal::flash( __( 'Your request has been sent. You will get an email when it is approved.', 'bdaci-portal-access' ) );
	}

	/**
	 * Approve or decline a request (admin).
	 *
	 * @param int  $id      Request.
	 * @param bool $approve Approve.
	 * @return string|WP_Error Message.
	 */
	public static function decide( $id, $approve ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		$areas = self::areas();
		if ( ! $r || 'pending' !== $r->status || ! isset( $areas[ $r->area ] ) ) {
			return new WP_Error( 'bdaci_req', __( 'That request is no longer waiting.', 'bdaci-portal-access' ) );
		}
		$user = get_userdata( $r->user_id );
		if ( ! $user ) {
			return new WP_Error( 'bdaci_req', __( 'That person no longer has an account.', 'bdaci-portal-access' ) );
		}
		$wpdb->update( self::table(), array( 'status' => $approve ? 'approved' : 'declined', 'decided_by' => get_current_user_id(), 'decided_at' => current_time( 'mysql' ) ), array( 'id' => $r->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$label = $areas[ $r->area ][0];
		if ( $approve ) {
			$key    = $areas[ $r->area ][1];
			$member = BDACI_PA_Members::for_user( $user->ID );
			$keys   = $member ? BDACI_PA_Members::clean_roles( explode( ',', (string) $member->roles ) ) : BDACI_PA_Roles::user_keys( $user->ID );
			$keys[] = $key;
			$keys   = array_values( array_unique( $keys ) );
			if ( $member ) {
				BDACI_PA_Members::update( $member->id, array( 'roles' => implode( ',', $keys ) ) );
			}
			BDACI_PA_Roles::sync_user( $user->ID, $keys );
			BDACI_PA_Log::add( 'access_request_approved', $user->user_email, $user->ID, $label );
			wp_mail(
				$user->user_email,
				/* translators: %s: section */
				sprintf( __( 'You now have access to the %s section', 'bdaci-portal-access' ), $label ),
				/* translators: 1: name, 2: section, 3: link */
				sprintf( __( "Hello %1\$s,\n\nYour request was approved. You can now open the %2\$s section of the BDACI portal:\n%3\$s\n\nThis access stays in place until a Portal Admin removes it.", 'bdaci-portal-access' ), $user->display_name, $label, BDACI_PA_Portal::url( $r->area ) )
			);
			/* translators: 1: name, 2: section */
			return sprintf( __( '%1$s can now open the %2$s section.', 'bdaci-portal-access' ), $user->display_name, $label );
		}
		BDACI_PA_Log::add( 'access_request_declined', $user->user_email, $user->ID, $label );
		/* translators: 1: name, 2: section */
		return sprintf( __( 'Declined %1$s\'s request for the %2$s section.', 'bdaci-portal-access' ), $user->display_name, $label );
	}
}
