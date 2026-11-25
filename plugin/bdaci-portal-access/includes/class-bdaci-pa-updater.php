<?php
/**
 * Updates from the plugin's GitHub repository: shows new versions on the Plugins screen,
 * "Check for updates", "View details", and (optionally) installs them automatically.
 *
 * Each GitHub release whose tag is a version number (e.g. v2.10.0) and that has a
 * bdaci-portal-access.zip asset is an update. The repository is private, so a read-only
 * GitHub access key is needed (Settings › Updates, or BDACI_PA_UPDATE_TOKEN in wp-config.php).
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin updater.
 */
class BDACI_PA_Updater {

	const CACHE      = 'bdaci_pa_update_info';
	const LAST_CHECK = 'bdaci_pa_update_checked';
	const ASSET      = 'bdaci-portal-access.zip';
	const API        = 'https://api.github.com/repos/';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		// Also when the list is read, so the update shows even if WordPress.org could not be reached.
		add_filter( 'site_transient_update_plugins', array( __CLASS__, 'inject_read' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
		add_filter( 'plugin_action_links_' . self::basename(), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_bdaci_pa_check_updates', array( __CLASS__, 'handle_check' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_upgrade' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * This plugin's basename, e.g. bdaci-portal-access/bdaci-portal-access.php.
	 *
	 * @return string
	 */
	public static function basename() {
		return plugin_basename( BDACI_PA_FILE );
	}

	/**
	 * GitHub repository "owner/name".
	 *
	 * @return string
	 */
	public static function repo() {
		$repo = defined( 'BDACI_PA_UPDATE_REPO' ) ? (string) BDACI_PA_UPDATE_REPO : (string) BDACI_PA_Settings::get( 'update_repo' );
		return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
	}

	/**
	 * GitHub access key, preferring wp-config.php.
	 *
	 * @return string
	 */
	public static function token() {
		return defined( 'BDACI_PA_UPDATE_TOKEN' ) ? (string) BDACI_PA_UPDATE_TOKEN : (string) BDACI_PA_Settings::get( 'update_token' );
	}

	/**
	 * Request headers for the GitHub API.
	 *
	 * @param string $accept Accept header.
	 * @return array
	 */
	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'               => $accept,
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'BDACI-Portal-Access-Updater',
		);
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	/**
	 * Latest release info, cached for 6 hours.
	 *
	 * @param bool $force Skip the cache.
	 * @return array{version:string,package:string,notes:string,url:string,published:string}|WP_Error
	 */
	public static function latest( $force = false ) {
		$repo = self::repo();
		if ( ! $repo ) {
			return new WP_Error( 'no_repo', __( 'No GitHub repository is set for updates.', 'bdaci-portal-access' ) );
		}
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) && ( $cached['repo'] ?? '' ) === $repo ) {
			return $cached;
		}
		if ( ! $force && is_array( $cached ) && '__error__' === ( $cached['repo'] ?? '' ) ) {
			// The last check failed less than an hour ago: don't ask GitHub again on every page load.
			return new WP_Error( 'update_check', (string) get_site_option( 'bdaci_pa_update_error', '' ) );
		}
		$res = wp_remote_get(
			self::API . $repo . '/releases?per_page=20',
			array(
				'timeout' => 15,
				'headers' => self::headers(),
			)
		);
		update_site_option( self::LAST_CHECK, time() );
		if ( is_wp_error( $res ) ) {
			return self::fail( $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code || 403 === $code ) {
			return self::fail( __( 'GitHub refused the access key. Check that it is correct, not expired, and can read the repository.', 'bdaci-portal-access' ) );
		}
		if ( 404 === $code ) {
			return self::fail( __( 'GitHub could not find the repository. Check the name, and that the access key can read it.', 'bdaci-portal-access' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status */
			return self::fail( sprintf( __( 'GitHub answered with an error (%d). Try again later.', 'bdaci-portal-access' ), $code ) );
		}
		$list = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$best = null;
		foreach ( is_array( $list ) ? $list : array() as $rel ) {
			if ( ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
				continue;
			}
			$ver = ltrim( (string) ( $rel['tag_name'] ?? '' ), 'vV' );
			if ( ! preg_match( '/^\d+(\.\d+){1,3}$/', $ver ) ) {
				continue;
			}
			$asset = '';
			foreach ( (array) ( $rel['assets'] ?? array() ) as $a ) {
				if ( self::ASSET === ( $a['name'] ?? '' ) && ! empty( $a['url'] ) ) {
					$asset = (string) $a['url'];
				}
			}
			if ( ! $asset ) {
				continue;
			}
			if ( ! $best || version_compare( $ver, $best['version'], '>' ) ) {
				$best = array(
					'repo'      => $repo,
					'version'   => $ver,
					'package'   => $asset,
					'notes'     => (string) ( $rel['body'] ?? '' ),
					'url'       => esc_url_raw( (string) ( $rel['html_url'] ?? '' ) ),
					'published' => (string) ( $rel['published_at'] ?? '' ),
				);
			}
		}
		if ( ! $best ) {
			$best = array(
				'repo'      => $repo,
				'version'   => BDACI_PA_VERSION,
				'package'   => '',
				'notes'     => '',
				'url'       => '',
				'published' => '',
			);
		}
		delete_site_option( 'bdaci_pa_update_error' );
		set_site_transient( self::CACHE, $best, 6 * HOUR_IN_SECONDS );
		return $best;
	}

	/**
	 * Remember an error (shown in Settings) and return it.
	 *
	 * @param string $msg Message.
	 * @return WP_Error
	 */
	private static function fail( $msg ) {
		update_site_option( 'bdaci_pa_update_error', $msg );
		// Try again in an hour rather than on every page load.
		set_site_transient( self::CACHE, array( 'repo' => '__error__' ), HOUR_IN_SECONDS );
		return new WP_Error( 'update_check', $msg );
	}

	/**
	 * Is the package URL one of our release assets?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function ours( $url ) {
		$repo = self::repo();
		return $repo && 0 === strpos( (string) $url, self::API . $repo . '/releases/assets/' );
	}

	/**
	 * Add our update to WordPress's list of plugin updates.
	 *
	 * @param object $transient update_plugins transient.
	 * @return object
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new stdClass();
		}
		$info = self::latest();
		if ( is_wp_error( $info ) || empty( $info['version'] ) ) {
			return $transient;
		}
		$item = (object) array(
			'id'            => 'github.com/' . self::repo(),
			'slug'          => 'bdaci-portal-access',
			'plugin'        => self::basename(),
			'new_version'   => $info['version'],
			'url'           => $info['url'],
			'package'       => $info['package'],
			'requires_php'  => '7.4',
			'icons'         => array( '1x' => BDACI_PA_URL . 'assets/logo-badge.png' ),
		);
		if ( $info['package'] && version_compare( $info['version'], BDACI_PA_VERSION, '>' ) ) {
			$transient->response[ self::basename() ] = $item;
			unset( $transient->no_update[ self::basename() ] );
		} else {
			$item->new_version = BDACI_PA_VERSION;
			$item->package     = '';
			$transient->no_update[ self::basename() ] = $item; // Lets WordPress show the auto-update toggle.
			unset( $transient->response[ self::basename() ] );
		}
		return $transient;
	}

	/**
	 * Same as inject(), for a saved list that is being read.
	 *
	 * @param mixed $transient update_plugins transient, or false when there is none yet.
	 * @return mixed
	 */
	public static function inject_read( $transient ) {
		return is_object( $transient ) ? self::inject( $transient ) : $transient;
	}

	/**
	 * "View details" popup on the Plugins screen.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Args.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'bdaci-portal-access' !== $args->slug ) {
			return $result;
		}
		$info  = self::latest();
		$ver   = is_wp_error( $info ) ? BDACI_PA_VERSION : $info['version'];
		$notes = is_wp_error( $info ) ? '' : $info['notes'];
		$log   = '';
		$file  = BDACI_PA_DIR . 'readme.txt';
		if ( file_exists( $file ) ) {
			$txt = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$pos = strpos( $txt, '== Changelog ==' );
			$log = false !== $pos ? trim( substr( $txt, $pos + 15 ) ) : '';
		}
		$html = static function ( $md ) {
			$md = esc_html( $md );
			$md = preg_replace( '/^= (.+?) =$/m', '<h4>$1</h4>', $md );
			$md = preg_replace( '/^#{1,4} (.+)$/m', '<h4>$1</h4>', $md );
			$md = preg_replace( '/^[*-] (.+)$/m', '<li>$1</li>', $md );
			$md = preg_replace( '/\*\*(.+?)\*\*/', '<b>$1</b>', $md );
			$md = preg_replace( '/(?:<li>.*<\/li>\n?)+/', "<ul>\n$0</ul>\n", $md );
			return wpautop( $md );
		};
		$sections = array();
		if ( '' !== trim( $notes ) ) {
			/* translators: %s: version */
			$sections[ sprintf( __( 'What\'s new in %s', 'bdaci-portal-access' ), $ver ) ] = $html( $notes );
		}
		$sections['changelog'] = $html( $log ? $log : __( 'See the GitHub releases.', 'bdaci-portal-access' ) );
		return (object) array(
			'name'          => 'BDACI Portal Access',
			'slug'          => 'bdaci-portal-access',
			'version'       => $ver,
			'author'        => 'BDACI',
			'requires'      => '6.2',
			'requires_php'  => '7.4',
			'last_updated'  => is_wp_error( $info ) ? '' : $info['published'],
			'homepage'      => is_wp_error( $info ) ? '' : $info['url'],
			'download_link' => is_wp_error( $info ) ? '' : $info['package'],
			'sections'      => $sections,
			'banners'       => array(),
		);
	}

	/**
	 * Download our release asset: GitHub needs the access key for the asset, then sends a
	 * short-lived link that must be fetched without it.
	 *
	 * @param bool|string|WP_Error $reply    Reply.
	 * @param string               $package  Package URL.
	 * @param WP_Upgrader          $upgrader Upgrader.
	 * @return bool|string|WP_Error
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( false !== $reply || ! self::ours( $package ) ) {
			return $reply;
		}
		if ( $upgrader && isset( $upgrader->strings['downloading_package'] ) ) {
			$upgrader->skin->feedback( 'downloading_package', __( 'GitHub', 'bdaci-portal-access' ) );
		}
		$res = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => self::headers( 'application/octet-stream' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$loc  = (string) wp_remote_retrieve_header( $res, 'location' );
		if ( $code >= 300 && $code < 400 && $loc ) {
			return download_url( $loc, 300 );
		}
		if ( 200 === $code ) {
			$tmp = wp_tempnam( self::ASSET );
			if ( ! $tmp || false === file_put_contents( $tmp, wp_remote_retrieve_body( $res ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new WP_Error( 'update_save', __( 'Could not save the update file.', 'bdaci-portal-access' ) );
			}
			return $tmp;
		}
		/* translators: %d: HTTP status */
		return new WP_Error( 'update_download', sprintf( __( 'GitHub would not send the update file (%d). Check the access key in BDACI Portal › Settings › Updates.', 'bdaci-portal-access' ), $code ) );
	}

	/**
	 * Install our updates automatically when the setting is on.
	 *
	 * @param bool|null $update Update.
	 * @param object    $item   Item.
	 * @return bool|null
	 */
	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin && BDACI_PA_Settings::get( 'update_auto' ) ) {
			return true;
		}
		return $update;
	}

	/**
	 * "Check for updates" on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$links['bdaci_check'] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_check_updates' ), 'bdaci_pa_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'bdaci-portal-access' ) . '</a>';
		}
		if ( current_user_can( 'manage_options' ) ) {
			$links['bdaci_settings'] = '<a href="' . esc_url( admin_url( 'admin.php?page=bdaci-portal-settings#bdaci-updates' ) ) . '">' . esc_html__( 'Settings', 'bdaci-portal-access' ) . '</a>';
		}
		return $links;
	}

	/**
	 * Check GitHub now and go back to where the admin came from.
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to update plugins.', 'bdaci-portal-access' ), 403 );
		}
		check_admin_referer( 'bdaci_pa_check_updates' );
		delete_site_transient( self::CACHE );
		$info = self::latest( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		if ( is_wp_error( $info ) ) {
			$msg = array( $info->get_error_message(), 'error' );
		} elseif ( $info['package'] && version_compare( $info['version'], BDACI_PA_VERSION, '>' ) ) {
			/* translators: 1: new version, 2: current version */
			$msg = array( sprintf( __( 'BDACI Portal Access %1$s is available (you have %2$s). Use "update now" below.', 'bdaci-portal-access' ), $info['version'], BDACI_PA_VERSION ), 'warning' );
		} else {
			/* translators: %s: version */
			$msg = array( sprintf( __( 'BDACI Portal Access is up to date (%s).', 'bdaci-portal-access' ), BDACI_PA_VERSION ), 'success' );
		}
		set_transient( 'bdaci_pa_update_notice_' . get_current_user_id(), $msg, 120 );
		$back = wp_get_referer();
		wp_safe_redirect( $back && false === strpos( $back, 'admin-post.php' ) ? $back : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Show the check result once.
	 */
	public static function notice() {
		$key = 'bdaci_pa_update_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! is_array( $msg ) ) {
			return;
		}
		delete_transient( $key );
		echo '<div class="notice notice-' . esc_attr( $msg[1] ) . ' is-dismissible"><p>' . esc_html( $msg[0] ) . '</p></div>';
	}

	/**
	 * Forget the cached release after we were updated.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $extra    Details.
	 */
	public static function after_upgrade( $upgrader, $extra ) {
		if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( self::basename(), (array) ( $extra['plugins'] ?? array( $extra['plugin'] ?? '' ) ), true ) ) {
			delete_site_transient( self::CACHE );
			BDACI_PA_Log::add( 'plugin_updated', '', get_current_user_id() );
		}
	}

	/**
	 * Status line for the Settings screen.
	 *
	 * @return string HTML.
	 */
	public static function status_html() {
		$err  = (string) get_site_option( 'bdaci_pa_update_error', '' );
		$last = (int) get_site_option( self::LAST_CHECK, 0 );
		$info = get_site_transient( self::CACHE );
		$h    = '<p>' . esc_html( sprintf( /* translators: %s: version */ __( 'Installed version: %s.', 'bdaci-portal-access' ), BDACI_PA_VERSION ) ) . ' ';
		if ( $err ) {
			$h .= '<span style="color:#b32d2e">' . esc_html( $err ) . '</span> ';
		} elseif ( is_array( $info ) && ! empty( $info['version'] ) ) {
			$h .= esc_html( version_compare( $info['version'], BDACI_PA_VERSION, '>' ) ? sprintf( /* translators: %s: version */ __( 'Version %s is available.', 'bdaci-portal-access' ), $info['version'] ) : __( 'This is the latest version.', 'bdaci-portal-access' ) ) . ' ';
		}
		if ( $last ) {
			$h .= '<span class="description">' . esc_html( sprintf( /* translators: %s: date and time */ __( 'Last checked %s.', 'bdaci-portal-access' ), wp_date( 'M j, Y g:i A', $last ) ) ) . '</span> ';
		}
		$h .= '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bdaci_pa_check_updates' ), 'bdaci_pa_check_updates' ) ) . '">' . esc_html__( 'Check for updates now', 'bdaci-portal-access' ) . '</a></p>';
		return $h;
	}
}
