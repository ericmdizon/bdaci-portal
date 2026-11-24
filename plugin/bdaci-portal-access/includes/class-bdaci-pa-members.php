<?php
/**
 * Authorized-user allowlist.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allowlist storage. Only people with an "active" row (or site administrators) can enter the portal.
 */
class BDACI_PA_Members {

	const ACTIVE  = 'active';
	const PENDING = 'pending';
	const REVOKED = 'revoked';

	/**
	 * Per-request cache of rows by user ID.
	 *
	 * @var array
	 */
	private static $user_cache = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bdaci_pa_members';
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
				email varchar(190) NOT NULL,
				display_name varchar(190) NOT NULL DEFAULT '',
				roles varchar(64) NOT NULL DEFAULT '',
				status varchar(16) NOT NULL DEFAULT 'active',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(32) NOT NULL DEFAULT 'admin',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				last_login datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY email (email),
				KEY user_id (user_id),
				KEY status (status)
			) {$charset};"
		);
	}

	/**
	 * Normalize an email for storage and comparison.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	public static function normalize( $email ) {
		return strtolower( trim( sanitize_email( $email ) ) );
	}

	/**
	 * Clean a list of role keys.
	 *
	 * @param array|string $roles Keys.
	 * @return array
	 */
	public static function clean_roles( $roles ) {
		if ( is_string( $roles ) ) {
			$roles = explode( ',', $roles );
		}
		$valid = array_keys( BDACI_PA_Roles::map() );
		return array_values( array_intersect( $valid, array_map( 'sanitize_key', (array) $roles ) ) );
	}

	/**
	 * Row by ID.
	 *
	 * @param int $id Row ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
	}

	/**
	 * Row by email.
	 *
	 * @param string $email Email.
	 * @return object|null
	 */
	public static function get_by_email( $email ) {
		global $wpdb;
		$email = self::normalize( $email );
		if ( ! $email ) {
			return null;
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) );
	}

	/**
	 * Row for a WordPress user. Rows are linked to the account by user ID on first use, so a later
	 * change to the account's email address cannot move it onto someone else's allowlist row.
	 *
	 * @param int $user_id User ID.
	 * @return object|null
	 */
	public static function for_user( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( array_key_exists( $user_id, self::$user_cache ) ) {
			return self::$user_cache[ $user_id ];
		}
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ) );
		if ( ! $row ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				$candidate = self::get_by_email( $user->user_email );
				if ( $candidate && 0 === (int) $candidate->user_id ) {
					self::update( (int) $candidate->id, array( 'user_id' => $user_id ) );
					$candidate->user_id = $user_id;
					$row                = $candidate;
				}
			}
		}
		self::$user_cache[ $user_id ] = $row ? $row : null;
		return self::$user_cache[ $user_id ];
	}

	/**
	 * Whether a user may enter the portal at all.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_authorized( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$row = self::for_user( $user_id );
		return $row && self::ACTIVE === $row->status;
	}

	/**
	 * Insert a row.
	 *
	 * @param string $email  Email.
	 * @param string $name   Display name.
	 * @param array  $roles  Role keys.
	 * @param string $status Status.
	 * @param string $source Where it came from (admin, microsoft, google).
	 * @return int|WP_Error Row ID.
	 */
	public static function add( $email, $name, array $roles, $status = self::ACTIVE, $source = 'admin' ) {
		global $wpdb;
		$email = self::normalize( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'bdaci_bad_email', __( 'Please enter a valid email address.', 'bdaci-portal-access' ) );
		}
		if ( self::get_by_email( $email ) ) {
			return new WP_Error( 'bdaci_exists', __( 'That email address is already on the list.', 'bdaci-portal-access' ) );
		}
		$now  = current_time( 'mysql', true );
		$user = get_user_by( 'email', $email );
		$ok   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'email'        => $email,
				'display_name' => sanitize_text_field( $name ),
				'roles'        => implode( ',', self::clean_roles( $roles ) ),
				'status'       => in_array( $status, array( self::ACTIVE, self::PENDING, self::REVOKED ), true ) ? $status : self::PENDING,
				'user_id'      => $user ? (int) $user->ID : 0,
				'source'       => sanitize_key( $source ),
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new WP_Error( 'bdaci_db', __( 'Could not save the entry.', 'bdaci-portal-access' ) );
		}
		$id = (int) $wpdb->insert_id;
		if ( $user && self::ACTIVE === $status ) {
			BDACI_PA_Roles::sync_user( (int) $user->ID, self::clean_roles( $roles ) );
		}
		return $id;
	}

	/**
	 * Update a row and keep the linked account's roles and sessions in step.
	 *
	 * @param int   $id     Row ID.
	 * @param array $fields Fields to change.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$allowed = array( 'display_name', 'roles', 'status', 'user_id', 'last_login' );
		$data    = array_intersect_key( $fields, array_flip( $allowed ) );
		if ( isset( $data['roles'] ) ) {
			$data['roles'] = implode( ',', self::clean_roles( $data['roles'] ) );
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$user_cache = array();

		if ( isset( $fields['status'] ) || isset( $fields['roles'] ) ) {
			self::apply_to_account( (int) $id );
		}
	}

	/**
	 * Push a row's status and roles onto its WordPress account.
	 *
	 * @param int $id Row ID.
	 */
	public static function apply_to_account( $id ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return;
		}
		$user_id = (int) $row->user_id;
		if ( ! $user_id ) {
			$user    = get_user_by( 'email', $row->email );
			$user_id = $user ? (int) $user->ID : 0;
		}
		if ( ! $user_id ) {
			return;
		}
		if ( self::ACTIVE === $row->status ) {
			BDACI_PA_Roles::sync_user( $user_id, self::clean_roles( $row->roles ) );
		} else {
			BDACI_PA_Roles::sync_user( $user_id, array() );
			// Sign the person out everywhere, unless it is a staff/admin account that keeps its own access.
			if ( ! user_can( $user_id, 'edit_posts' ) ) {
				WP_Session_Tokens::get_instance( $user_id )->destroy_all();
			}
		}
	}

	/**
	 * Delete a row and remove portal access from the linked account.
	 *
	 * @param int $id Row ID.
	 */
	public static function delete( $id ) {
		global $wpdb;
		self::update( $id, array( 'status' => self::REVOKED ) );
		$wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::$user_cache = array();
	}

	/**
	 * All rows, pending first.
	 *
	 * @return array
	 */
	public static function all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY FIELD(status, 'pending', 'active', 'revoked'), email ASC" );
	}

	/**
	 * Count of pending requests.
	 *
	 * @return int
	 */
	public static function pending_count() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", self::PENDING ) );
	}

	/**
	 * Create a WordPress account for an allowlisted email and email a "set your password" link.
	 *
	 * @param int  $id     Row ID.
	 * @param bool $notify Send the set-password email.
	 * @return int|WP_Error User ID.
	 */
	public static function create_account( $id, $notify = true ) {
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'bdaci_missing', __( 'Entry not found.', 'bdaci-portal-access' ) );
		}
		$user = get_user_by( 'email', $row->email );
		if ( $user ) {
			$user_id = (int) $user->ID;
		} else {
			$user_id = self::insert_user( $row->email, $row->display_name );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}
		self::update( (int) $row->id, array( 'user_id' => $user_id ) );
		self::apply_to_account( (int) $row->id );
		if ( $notify ) {
			wp_new_user_notification( $user_id, null, 'user' );
		}
		return $user_id;
	}

	/**
	 * Insert a WordPress user with a random password and no default role.
	 *
	 * @param string $email Email.
	 * @param string $name  Display name.
	 * @return int|WP_Error
	 */
	public static function insert_user( $email, $name ) {
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = $base ? $base : 'member';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ++$i;
		}
		$parts = preg_split( '/\s+/', trim( (string) $name ), 2 );
		return wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'display_name' => $name ? $name : $login,
				'first_name'   => $parts[0] ?? '',
				'last_name'    => $parts[1] ?? '',
				'role'         => '',
			)
		);
	}
}
