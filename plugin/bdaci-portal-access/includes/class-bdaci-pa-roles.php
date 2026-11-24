<?php
/**
 * Portal roles and capabilities.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers roles and keeps portal-only users out of wp-admin.
 */
class BDACI_PA_Roles {

	const BOARD     = 'bdaci_board_member';
	const COMMITTEE = 'bdaci_committee_member';
	const FINANCE   = 'bdaci_finance_member';
	const ADMIN     = 'bdaci_portal_admin';

	/**
	 * Map of short keys (stored on the allowlist) to role slugs.
	 *
	 * @return array
	 */
	public static function map() {
		return array(
			'board'     => self::BOARD,
			'committee' => self::COMMITTEE,
			'finance'   => self::FINANCE,
			'admin'     => self::ADMIN,
		);
	}

	/**
	 * Human labels for short keys.
	 *
	 * @return array
	 */
	public static function labels() {
		return array(
			'board'     => __( 'Board Member', 'bdaci-portal-access' ),
			'committee' => __( 'Education Committee Member', 'bdaci-portal-access' ),
			'finance'   => __( 'Finance Committee Member', 'bdaci-portal-access' ),
			'admin'     => __( 'Portal Admin', 'bdaci-portal-access' ),
		);
	}

	/**
	 * Create roles and give administrators the portal capabilities.
	 */
	public static function install() {
		$base = array(
			'read'              => true,
			'bdaci_view_portal' => true,
		);
		remove_role( self::BOARD );
		remove_role( self::COMMITTEE );
		remove_role( self::FINANCE );
		remove_role( self::ADMIN );
		add_role( self::BOARD, 'Board Member', $base + array( 'bdaci_view_board' => true ) );
		add_role( self::COMMITTEE, 'Education Committee Member', $base + array( 'bdaci_view_committee' => true ) );
		add_role( self::FINANCE, 'Finance Committee Member', $base + array( 'bdaci_view_finance' => true ) );
		add_role(
			self::ADMIN,
			'Portal Admin',
			$base + array(
				'bdaci_view_board'     => true,
				'bdaci_view_committee' => true,
				'bdaci_view_finance'   => true,
				'bdaci_manage_portal'  => true,
			)
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( array( 'bdaci_view_portal', 'bdaci_view_board', 'bdaci_view_committee', 'bdaci_view_finance', 'bdaci_manage_portal' ) as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'keep_out_of_admin' ), 1 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'admin_bar' ) );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'no_app_passwords' ), 10, 2 );
	}

	/**
	 * Whether the user is a portal-only account (no normal editing rights).
	 *
	 * @param WP_User|int $user User.
	 * @return bool
	 */
	public static function is_portal_only( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) ) {
			return false;
		}
		return (bool) array_intersect( array_values( self::map() ), (array) $user->roles );
	}

	/**
	 * Send portal-only users (except Portal Admins) back to the portal from wp-admin.
	 */
	public static function keep_out_of_admin() {
		if ( wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		global $pagenow;
		if ( 'admin-post.php' === $pagenow ) {
			return;
		}
		$user = wp_get_current_user();
		if ( self::is_portal_only( $user ) && ! user_can( $user, 'bdaci_manage_portal' ) ) {
			wp_safe_redirect( BDACI_PA_Settings::portal_home_url() );
			exit;
		}
	}

	/**
	 * Hide the toolbar for portal-only users.
	 *
	 * @param bool $show Current value.
	 * @return bool
	 */
	public static function admin_bar( $show ) {
		if ( is_user_logged_in() && self::is_portal_only( wp_get_current_user() ) && ! current_user_can( 'bdaci_manage_portal' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Application passwords would bypass the verification code, so disable them for portal users.
	 *
	 * @param bool    $available Current value.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	public static function no_app_passwords( $available, $user ) {
		return self::is_portal_only( $user ) ? false : $available;
	}

	/**
	 * Replace a user's portal roles with the given short keys. Other roles are untouched.
	 *
	 * @param int   $user_id User ID.
	 * @param array $keys    Short keys (board, committee, finance, admin).
	 */
	public static function sync_user( $user_id, array $keys ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		$map = self::map();
		foreach ( $map as $key => $role ) {
			if ( in_array( $key, $keys, true ) ) {
				$user->add_role( $role );
			} else {
				$user->remove_role( $role );
			}
		}
	}

	/**
	 * Portal role keys a user holds.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function user_keys( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return array();
		}
		$out = array();
		foreach ( self::map() as $key => $role ) {
			if ( in_array( $role, (array) $user->roles, true ) ) {
				$out[] = $key;
			}
		}
		return $out;
	}
}
