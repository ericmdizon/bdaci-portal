<?php
/**
 * Plugin settings storage and helpers.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings accessor.
 */
class BDACI_PA_Settings {

	const OPTION = 'bdaci_pa_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'portal_root_page'        => 0,
			'portal_name'             => 'BDACI Board & Committee Portal',
			'welcome_text'            => 'Welcome. Please sign in to access board and committee materials.',
			'logo_url'                => '',
			'signin_image'            => '',
			'signin_quote'            => "We all have value.\nWe all have dreams.",
			'signin_blurb'            => 'Brockville and District Association for Community Involvement. Board and committee materials, meetings and sign-offs in one secure place.',
			'brand_color'             => '#1d3f72',
			'support_email'           => '',
			'mfa_password'            => 1,
			'mfa_sso'                 => 0,
			'board_sees_committee'    => 0,
			'allow_requests'          => 1,
			'notify_email'            => '',
			'min_password_length'     => 12,
			'ms_enabled'              => 0,
			'ms_client_id'            => '',
			'ms_client_secret'        => '',
			'ms_tenant'               => '',
			'google_enabled'          => 0,
			'google_client_id'        => '',
			'google_client_secret'    => '',
			'google_hosted_domain'    => '',
			'remove_data_on_uninstall' => 0,
			'portal_layout'           => 1,
			'portal_fonts'            => 1,
			'portal_keep_theme_css'   => 0,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Sanitize and save settings from a raw array (already unslashed).
	 *
	 * @param array $raw Raw input.
	 */
	public static function save( array $raw ) {
		$current = self::all();
		$clean   = $current;

		$clean['portal_root_page']     = absint( $raw['portal_root_page'] ?? 0 );
		$clean['portal_name']          = sanitize_text_field( $raw['portal_name'] ?? '' );
		$clean['welcome_text']         = sanitize_textarea_field( $raw['welcome_text'] ?? '' );
		$clean['logo_url']             = esc_url_raw( $raw['logo_url'] ?? '' );
		$clean['signin_image']         = esc_url_raw( $raw['signin_image'] ?? '' );
		$clean['signin_quote']         = sanitize_textarea_field( $raw['signin_quote'] ?? '' );
		$clean['signin_blurb']         = sanitize_textarea_field( $raw['signin_blurb'] ?? '' );
		$color                         = sanitize_hex_color( $raw['brand_color'] ?? '' );
		$clean['brand_color']          = $color ? $color : '#1d3f72';
		$clean['support_email']        = sanitize_email( $raw['support_email'] ?? '' );
		$clean['notify_email']         = sanitize_email( $raw['notify_email'] ?? '' );
		$clean['min_password_length']  = max( 8, min( 64, absint( $raw['min_password_length'] ?? 12 ) ) );

		foreach ( array( 'mfa_password', 'mfa_sso', 'board_sees_committee', 'allow_requests', 'ms_enabled', 'google_enabled', 'remove_data_on_uninstall', 'portal_layout', 'portal_fonts', 'portal_keep_theme_css' ) as $flag ) {
			$clean[ $flag ] = empty( $raw[ $flag ] ) ? 0 : 1;
		}

		$clean['ms_client_id']         = sanitize_text_field( $raw['ms_client_id'] ?? '' );
		$clean['ms_tenant']            = strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) ( $raw['ms_tenant'] ?? '' ) ) );
		$clean['google_client_id']     = sanitize_text_field( $raw['google_client_id'] ?? '' );
		$clean['google_hosted_domain'] = strtolower( preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) ( $raw['google_hosted_domain'] ?? '' ) ) );

		// Secrets: an empty field keeps the stored value so it is never echoed back into the form.
		foreach ( array( 'ms_client_secret', 'google_client_secret' ) as $secret ) {
			$value = trim( (string) ( $raw[ $secret ] ?? '' ) );
			if ( '' !== $value ) {
				$clean[ $secret ] = $value;
			}
			if ( ! empty( $raw[ $secret . '_clear' ] ) ) {
				$clean[ $secret ] = '';
			}
		}

		update_option( self::OPTION, $clean, false );
		BDACI_PA_Access::flush_cache();
	}

	/**
	 * Client secret, preferring a wp-config.php constant when defined.
	 *
	 * @param string $provider microsoft|google.
	 * @return string
	 */
	public static function secret( $provider ) {
		if ( 'microsoft' === $provider && defined( 'BDACI_PA_MS_CLIENT_SECRET' ) ) {
			return (string) BDACI_PA_MS_CLIENT_SECRET;
		}
		if ( 'google' === $provider && defined( 'BDACI_PA_GOOGLE_CLIENT_SECRET' ) ) {
			return (string) BDACI_PA_GOOGLE_CLIENT_SECRET;
		}
		return (string) self::get( 'microsoft' === $provider ? 'ms_client_secret' : 'google_client_secret' );
	}

	/**
	 * Portal home URL (the root page, or the site home).
	 *
	 * @return string
	 */
	public static function portal_home_url() {
		$root = (int) self::get( 'portal_root_page' );
		if ( $root && 'publish' === get_post_status( $root ) ) {
			return get_permalink( $root );
		}
		return home_url( '/' );
	}

	/**
	 * Address that receives access-request notifications.
	 *
	 * @return string
	 */
	public static function notify_address() {
		$email = self::get( 'notify_email' );
		return $email ? $email : get_option( 'admin_email' );
	}

	/**
	 * Address that receives Support form messages.
	 *
	 * @return string
	 */
	public static function support_email() {
		$email = self::get( 'support_email' );
		return $email ? $email : self::notify_address();
	}
}
