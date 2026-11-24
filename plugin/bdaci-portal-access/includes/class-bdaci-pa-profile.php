<?php
/**
 * Profile pictures, contact details and the member directory.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Profiles.
 */
class BDACI_PA_Profile {

	const META_AVATAR = 'bdaci_avatar';

	/**
	 * Directory fields kept in user meta: key => label.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'bdaci_position'     => __( 'Board position (e.g. Chair, Treasurer, Director)', 'bdaci-portal-access' ),
			'bdaci_term'         => __( 'Term (e.g. 2025–2028)', 'bdaci-portal-access' ),
			'bdaci_edu_position' => __( 'Education Committee role (e.g. Chair, Member, Staff liaison)', 'bdaci-portal-access' ),
			'bdaci_fin_position' => __( 'Finance Committee role', 'bdaci-portal-access' ),
			'bdaci_affiliation'  => __( 'Committee note (e.g. Parent representative)', 'bdaci-portal-access' ),
			'bdaci_phone'        => __( 'Phone', 'bdaci-portal-access' ),
			'bdaci_dir_order'    => __( 'Order in lists (lower comes first)', 'bdaci-portal-access' ),
		);
	}

	/**
	 * Preset pictures: id => [skin tone 0-5, hair/head group, age group].
	 *
	 * @return array
	 */
	public static function presets() {
		return array(
			'p1' => array( 0, 'short', 'y' ),
			'p2' => array( 0, 'long', 'y' ),
			'p3' => array( 0, 'short', 'o' ),
			'p4' => array( 0, 'long', 'o' ),
			'p5' => array( 0, 'none', 'm' ),
			'p6' => array( 1, 'long', 'y' ),
			'p7' => array( 1, 'short', 'm' ),
			'p8' => array( 1, 'none', 'o' ),
			'p9' => array( 1, 'curly', 'y' ),
			'p10' => array( 1, 'cover', 'o' ),
			'p11' => array( 2, 'cover', 'y' ),
			'p12' => array( 2, 'cover', 'm' ),
			'p13' => array( 2, 'short', 'm' ),
			'p14' => array( 2, 'long', 'm' ),
			'p15' => array( 2, 'long', 'o' ),
			'p16' => array( 3, 'short', 'y' ),
			'p17' => array( 3, 'cover', 'm' ),
			'p18' => array( 3, 'curly', 'y' ),
			'p19' => array( 3, 'cover', 'o' ),
			'p20' => array( 3, 'long', 'y' ),
			'p21' => array( 4, 'curly', 'y' ),
			'p22' => array( 4, 'none', 'm' ),
			'p23' => array( 4, 'short', 'o' ),
			'p24' => array( 4, 'long', 'm' ),
			'p25' => array( 4, 'curly', 'o' ),
			'p26' => array( 5, 'curly', 'y' ),
			'p27' => array( 5, 'short', 'm' ),
			'p28' => array( 5, 'cover', 'm' ),
			'p29' => array( 5, 'short', 'o' ),
			'p30' => array( 5, 'long', 'y' )

		);
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve_photo' ), -6 );
	}

	/**
	 * Initials.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function initials( $name ) {
		$parts = preg_split( '/\s+/', trim( (string) $name ) );
		$first = $parts ? mb_substr( $parts[0], 0, 1 ) : '';
		$last  = count( $parts ) > 1 ? mb_substr( end( $parts ), 0, 1 ) : '';
		return mb_strtoupper( $first . $last );
	}

	/**
	 * The person's picture choice: [type, value].
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function choice( $user_id ) {
		$raw = (string) get_user_meta( $user_id, self::META_AVATAR, true );
		if ( preg_match( '/^preset:(p\d{1,2})$/', $raw, $m ) && isset( self::presets()[ $m[1] ] ) ) {
			return array( 'preset', $m[1] );
		}
		if ( preg_match( '/^upload:(\d+)$/', $raw, $m ) ) {
			return array( 'upload', (int) $m[1] );
		}
		return array( 'initials', '' );
	}

	/**
	 * Avatar HTML.
	 *
	 * @param int    $user_id User.
	 * @param string $cls     Extra classes (av-sm, av-xl).
	 * @return string
	 */
	public static function avatar( $user_id, $cls = '' ) {
		$user = get_userdata( $user_id );
		$name = $user ? $user->display_name : '';
		list( $type, $val ) = self::choice( $user_id );
		$inner = esc_html( self::initials( $name ) );
		if ( 'preset' === $type ) {
			$inner = '<img src="' . esc_url( BDACI_PA_URL . 'assets/avatars/' . $val . '.svg' ) . '" alt="">';
		} elseif ( 'upload' === $type ) {
			$inner = '<img src="' . esc_url( add_query_arg( array( 'bdaci_avatar' => (int) $user_id, 'v' => (int) $val ), home_url( '/' ) ) ) . '" alt="">';
		}
		return '<span class="avatar ' . esc_attr( $cls ) . '" aria-hidden="true">' . $inner . '</span>';
	}

	/**
	 * Stream an uploaded profile photo to signed-in portal members.
	 */
	public static function serve_photo() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uid = isset( $_GET['bdaci_avatar'] ) ? absint( $_GET['bdaci_avatar'] ) : 0;
		if ( ! $uid ) {
			return;
		}
		if ( ! is_user_logged_in() || ! BDACI_PA_Members::is_authorized( get_current_user_id() ) ) {
			status_header( 403 );
			exit;
		}
		list( $type, $att ) = self::choice( $uid );
		$file = 'upload' === $type ? get_attached_file( $att, true ) : '';
		$base = realpath( BDACI_PA_Documents::base_dir() );
		$real = $file ? realpath( $file ) : false;
		if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) || (int) get_post_meta( $att, '_bdaci_pa_avatar', true ) !== $uid ) {
			status_header( 404 );
			exit;
		}
		$type = wp_check_filetype( $real );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: ' . ( in_array( $type['type'], array( 'image/jpeg', 'image/png' ), true ) ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Length: ' . filesize( $real ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=3600' );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Remove a stored upload.
	 *
	 * @param int $user_id User.
	 */
	private static function drop_upload( $user_id ) {
		list( $type, $att ) = self::choice( $user_id );
		if ( 'upload' === $type && (int) get_post_meta( $att, '_bdaci_pa_avatar', true ) === (int) $user_id ) {
			wp_delete_attachment( $att, true );
		}
	}

	/**
	 * Save profile changes from the portal.
	 *
	 * @param string $do      photo|profile.
	 * @param int    $user_id User.
	 */
	public static function handle( $do, $user_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in BDACI_PA_Portal::handle().
		if ( 'profile' === $do ) {
			$phone = isset( $_POST['phone'] ) ? preg_replace( '/[^0-9+().\-\s x]/i', '', sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';
			update_user_meta( $user_id, 'bdaci_phone', mb_substr( trim( $phone ), 0, 40 ) );
			update_user_meta( $user_id, 'bdaci_show_phone', empty( $_POST['show_phone'] ) ? 0 : 1 );
			BDACI_PA_Portal::flash( __( 'Contact details saved.', 'bdaci-portal-access' ) );
			return;
		}
		$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		if ( 'preset' === $mode ) {
			$p = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : '';
			if ( ! isset( self::presets()[ $p ] ) ) {
				BDACI_PA_Portal::flash( __( 'Pick one of the pictures first.', 'bdaci-portal-access' ), 'err' );
				return;
			}
			self::drop_upload( $user_id );
			update_user_meta( $user_id, self::META_AVATAR, 'preset:' . $p );
			BDACI_PA_Portal::flash( __( 'Profile picture updated.', 'bdaci-portal-access' ) );
			return;
		}
		if ( 'initials' === $mode || 'remove' === $mode ) {
			self::drop_upload( $user_id );
			delete_user_meta( $user_id, self::META_AVATAR );
			BDACI_PA_Portal::flash( __( 'Your initials are shown instead of a picture.', 'bdaci-portal-access' ) );
			return;
		}
		if ( 'upload' === $mode ) {
			$f = isset( $_FILES['photo'] ) && is_array( $_FILES['photo'] ) ? $_FILES['photo'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( empty( $f['name'] ) || UPLOAD_ERR_OK !== (int) $f['error'] ) {
				BDACI_PA_Portal::flash( __( 'Choose a photo to upload.', 'bdaci-portal-access' ), 'err' );
				return;
			}
			$ext = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) || (int) $f['size'] > 5 * MB_IN_BYTES || ! @getimagesize( $f['tmp_name'] ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				BDACI_PA_Portal::flash( __( 'Use a JPG or PNG photo up to 5 MB.', 'bdaci-portal-access' ), 'err' );
				return;
			}
			$_FILES['bdaci_pa_single'] = $f;
			$id                        = BDACI_PA_Documents::upload( 'bdaci_pa_single', 'Profile photo ' . $user_id, 'portal' );
			unset( $_FILES['bdaci_pa_single'] );
			if ( is_wp_error( $id ) ) {
				BDACI_PA_Portal::flash( $id->get_error_message(), 'err' );
				return;
			}
			delete_post_meta( $id, BDACI_PA_Documents::META_PRIVATE ); // Not a portal document.
			update_post_meta( $id, '_bdaci_pa_avatar', (int) $user_id );
			self::drop_upload( $user_id );
			update_user_meta( $user_id, self::META_AVATAR, 'upload:' . $id );
			BDACI_PA_Portal::flash( __( 'Profile photo uploaded.', 'bdaci-portal-access' ) );
		}
		// phpcs:enable
	}

	/**
	 * Rank of a position for sorting.
	 *
	 * @param string $pos Position.
	 * @return int
	 */
	private static function rank( $pos ) {
		$p = strtolower( (string) $pos );
		foreach ( array( 'vice' => 2, 'chair' => 1, 'president' => 1, 'treasurer' => 3, 'secretary' => 4, 'director' => 6, 'member' => 7, 'staff' => 8, 'liaison' => 8 ) as $k => $r ) {
			if ( false !== strpos( $p, $k ) ) {
				return $r;
			}
		}
		return 9;
	}

	/**
	 * Directory of a group: active portal members with that role.
	 *
	 * @param string $group board|committee|finance.
	 * @return array of [WP_User, position, note]
	 */
	public static function directory( $group ) {
		$role  = BDACI_PA_Roles::map()[ $group ] ?? '';
		$field = array(
			'board'     => 'bdaci_position',
			'committee' => 'bdaci_edu_position',
			'finance'   => 'bdaci_fin_position',
		)[ $group ] ?? 'bdaci_position';
		if ( ! $role ) {
			return array();
		}
		$out = array();
		foreach ( get_users( array( 'role' => $role ) ) as $u ) {
			if ( ! BDACI_PA_Members::is_authorized( $u->ID ) || get_user_meta( $u->ID, 'bdaci_dir_hide', true ) ) {
				continue;
			}
			$pos  = (string) get_user_meta( $u->ID, $field, true );
			$note = 'board' === $group ? (string) get_user_meta( $u->ID, 'bdaci_term', true ) : (string) get_user_meta( $u->ID, 'bdaci_affiliation', true );
			if ( 'board' !== $group && '' === $note && user_can( $u, 'bdaci_view_board' ) ) {
				$note = __( 'Board Director', 'bdaci-portal-access' );
			}
			if ( '' === $pos ) {
				$pos = 'board' === $group ? __( 'Director', 'bdaci-portal-access' ) : __( 'Member', 'bdaci-portal-access' );
			}
			$order = get_user_meta( $u->ID, 'bdaci_dir_order', true );
			$out[] = array( $u, $pos, $note, '' === $order ? 100 : (int) $order );
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a[3], self::rank( $a[1] ), $a[0]->display_name ) <=> array( $b[3], self::rank( $b[1] ), $b[0]->display_name );
			}
		);
		return $out;
	}
}
