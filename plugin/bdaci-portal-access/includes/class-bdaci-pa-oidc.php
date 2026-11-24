<?php
/**
 * Microsoft 365 (Entra ID) and Google sign-in using OpenID Connect
 * (authorization code flow with PKCE, state and nonce).
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * OIDC client.
 */
class BDACI_PA_OIDC {

	const STATE_COOKIE = 'bdaci_pa_oidc';
	const STATE_TTL    = 600;
	const MSA_TENANT   = '9188040d-6c67-4c5b-b112-36a304b66dad'; // Personal Microsoft accounts.

	/**
	 * Hooks (none needed; routing lives in BDACI_PA_Login).
	 */
	public static function init() {}

	/**
	 * Providers that are switched on and configured.
	 *
	 * @return array provider => label
	 */
	public static function enabled_providers() {
		$s   = BDACI_PA_Settings::all();
		$out = array();
		if ( $s['ms_enabled'] && $s['ms_client_id'] && $s['ms_tenant'] && BDACI_PA_Settings::secret( 'microsoft' ) ) {
			$out['microsoft'] = __( 'Sign in with Microsoft 365', 'bdaci-portal-access' );
		}
		if ( $s['google_enabled'] && $s['google_client_id'] && BDACI_PA_Settings::secret( 'google' ) ) {
			$out['google'] = __( 'Sign in with Google', 'bdaci-portal-access' );
		}
		return $out;
	}

	/**
	 * Endpoint configuration.
	 *
	 * @param string $provider Provider.
	 * @return array|null
	 */
	private static function config( $provider ) {
		$s = BDACI_PA_Settings::all();
		if ( 'microsoft' === $provider ) {
			$tenant = rawurlencode( $s['ms_tenant'] );
			return array(
				'client_id' => $s['ms_client_id'],
				'authorize' => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/authorize",
				'token'     => "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
			);
		}
		if ( 'google' === $provider ) {
			return array(
				'client_id' => $s['google_client_id'],
				'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token'     => 'https://oauth2.googleapis.com/token',
			);
		}
		return null;
	}

	/**
	 * Redirect the browser to the provider.
	 *
	 * @param string $provider Provider.
	 * @param string $redirect Where to go after sign-in.
	 */
	public static function start( $provider, $redirect ) {
		if ( ! isset( self::enabled_providers()[ $provider ] ) ) {
			wp_safe_redirect( BDACI_PA_Login::url( array( 'msg' => 'ssodisabled' ) ) );
			exit;
		}
		$config   = self::config( $provider );
		$state    = self::random();
		$nonce    = self::random();
		$verifier = self::random() . self::random();

		set_transient(
			self::state_key( $state ),
			array(
				'provider' => $provider,
				'nonce'    => $nonce,
				'verifier' => $verifier,
				'redirect' => $redirect,
			),
			self::STATE_TTL
		);
		BDACI_PA_Login::set_cookie( self::STATE_COOKIE, $state, time() + self::STATE_TTL );

		$params = array(
			'client_id'             => $config['client_id'],
			'response_type'         => 'code',
			'redirect_uri'          => BDACI_PA_Login::callback_url( $provider ),
			'scope'                 => 'openid email profile',
			'state'                 => $state,
			'nonce'                 => $nonce,
			'code_challenge'        => self::b64url( hash( 'sha256', $verifier, true ) ),
			'code_challenge_method' => 'S256',
			'prompt'                => 'select_account',
		);
		if ( 'microsoft' === $provider ) {
			$params['response_mode'] = 'query';
		}
		if ( 'google' === $provider && BDACI_PA_Settings::get( 'google_hosted_domain' ) ) {
			$params['hd'] = BDACI_PA_Settings::get( 'google_hosted_domain' );
		}
		// The provider URL is external, so wp_safe_redirect() cannot be used; the host is fixed above.
		wp_redirect( $config['authorize'] . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Handle the provider's redirect back to us.
	 *
	 * @param string $provider Provider.
	 */
	public static function handle_callback( $provider ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state parameter + cookie binding is the CSRF protection here.
		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code   = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error  = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$cookie = isset( $_COOKIE[ self::STATE_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STATE_COOKIE ] ) ) : '';
		// phpcs:enable

		BDACI_PA_Login::set_cookie( self::STATE_COOKIE, '', time() - HOUR_IN_SECONDS );

		if ( '' === $state || '' === $cookie || ! hash_equals( $cookie, $state ) ) {
			self::fail( 'state_mismatch', $provider );
		}
		$saved = get_transient( self::state_key( $state ) );
		delete_transient( self::state_key( $state ) ); // Single use.
		if ( ! is_array( $saved ) || $saved['provider'] !== $provider ) {
			self::fail( 'state_expired', $provider );
		}
		if ( $error ) {
			self::fail( 'provider_error_' . $error, $provider );
		}
		if ( '' === $code || ! isset( self::enabled_providers()[ $provider ] ) ) {
			self::fail( 'no_code', $provider );
		}

		$config   = self::config( $provider );
		$response = wp_remote_post(
			$config['token'],
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => BDACI_PA_Login::callback_url( $provider ),
					'client_id'     => $config['client_id'],
					'client_secret' => BDACI_PA_Settings::secret( $provider ),
					'code_verifier' => $saved['verifier'],
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::fail( 'token_http_' . $response->get_error_code(), $provider );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || empty( $body['id_token'] ) ) {
			$detail = is_array( $body ) && isset( $body['error'] ) ? sanitize_key( $body['error'] ) : 'http_' . (int) wp_remote_retrieve_response_code( $response );
			self::fail( 'token_' . $detail, $provider );
		}

		// The ID token came straight from the provider's token endpoint over verified TLS
		// using our client secret, so per OpenID Connect Core 3.1.3.7 the TLS server check
		// stands in for the signature check. The claims are still validated below.
		$claims = self::decode_jwt( $body['id_token'] );
		if ( ! $claims ) {
			self::fail( 'bad_id_token', $provider );
		}
		$identity = self::validate_claims( $provider, $claims, $config['client_id'], $saved['nonce'] );
		if ( is_wp_error( $identity ) ) {
			self::fail( $identity->get_error_code(), $provider, isset( $claims['email'] ) ? (string) $claims['email'] : '', 'unverified' === $identity->get_error_message() ? 'unverified' : 'ssofailed' );
		}

		self::sign_in( $provider, $identity, (string) $saved['redirect'] );
	}

	/**
	 * Validate ID token claims and pull out the identity.
	 *
	 * @param string $provider  Provider.
	 * @param array  $claims    Claims.
	 * @param string $client_id Our client ID.
	 * @param string $nonce     Expected nonce.
	 * @return array|WP_Error { subject, email, name }
	 */
	private static function validate_claims( $provider, array $claims, $client_id, $nonce ) {
		$now = time();
		$aud = isset( $claims['aud'] ) ? (array) $claims['aud'] : array();
		if ( ! in_array( $client_id, $aud, true ) ) {
			return new WP_Error( 'bad_audience' );
		}
		if ( empty( $claims['exp'] ) || $now > (int) $claims['exp'] + 120 ) {
			return new WP_Error( 'expired_token' );
		}
		if ( ! empty( $claims['iat'] ) && (int) $claims['iat'] > $now + 300 ) {
			return new WP_Error( 'future_token' );
		}
		if ( empty( $claims['nonce'] ) || ! hash_equals( $nonce, (string) $claims['nonce'] ) ) {
			return new WP_Error( 'bad_nonce' );
		}
		if ( empty( $claims['sub'] ) ) {
			return new WP_Error( 'no_subject' );
		}

		if ( 'google' === $provider ) {
			if ( ! in_array( $claims['iss'] ?? '', array( 'https://accounts.google.com', 'accounts.google.com' ), true ) ) {
				return new WP_Error( 'bad_issuer' );
			}
			$verified = isset( $claims['email_verified'] ) && ( true === $claims['email_verified'] || 'true' === $claims['email_verified'] );
			if ( empty( $claims['email'] ) || ! $verified ) {
				return new WP_Error( 'email_unverified', 'unverified' );
			}
			$hd = BDACI_PA_Settings::get( 'google_hosted_domain' );
			if ( $hd && strtolower( (string) ( $claims['hd'] ?? '' ) ) !== $hd ) {
				return new WP_Error( 'wrong_domain', 'unverified' );
			}
			return array(
				'subject' => 'google|' . $claims['sub'],
				'email'   => BDACI_PA_Members::normalize( $claims['email'] ),
				'name'    => sanitize_text_field( $claims['name'] ?? '' ),
			);
		}

		// Microsoft.
		$tid = (string) ( $claims['tid'] ?? '' );
		if ( ! preg_match( '/^[0-9a-f-]{36}$/', $tid ) || ( $claims['iss'] ?? '' ) !== "https://login.microsoftonline.com/{$tid}/v2.0" ) {
			return new WP_Error( 'bad_issuer' );
		}
		$tenant       = BDACI_PA_Settings::get( 'ms_tenant' );
		$multi_tenant = in_array( $tenant, array( 'common', 'organizations', 'consumers' ), true );
		if ( ! $multi_tenant && preg_match( '/^[0-9a-f-]{36}$/', $tenant ) && $tid !== $tenant ) {
			return new WP_Error( 'wrong_tenant' );
		}
		$email = '';
		if ( ! empty( $claims['email'] ) && is_email( $claims['email'] ) ) {
			$email = $claims['email'];
		} elseif ( ! empty( $claims['preferred_username'] ) && is_email( $claims['preferred_username'] ) ) {
			$email = $claims['preferred_username'];
		}
		if ( ! $email ) {
			return new WP_Error( 'no_email', 'unverified' );
		}
		// In multi-tenant mode another organisation could put any address on its users, so only accept
		// addresses Microsoft has verified: personal Microsoft accounts, or the xms_edov optional claim.
		if ( $multi_tenant && self::MSA_TENANT !== $tid ) {
			$edov = $claims['xms_edov'] ?? false;
			if ( true !== $edov && '1' !== (string) $edov && 'true' !== (string) $edov ) {
				return new WP_Error( 'email_domain_unverified', 'unverified' );
			}
		}
		$oid = (string) ( $claims['oid'] ?? $claims['sub'] );
		return array(
			'subject' => 'microsoft|' . $tid . '|' . $oid,
			'email'   => BDACI_PA_Members::normalize( $email ),
			'name'    => sanitize_text_field( $claims['name'] ?? '' ),
		);
	}

	/**
	 * Match the verified identity to the allowlist and sign in, or file an access request.
	 *
	 * @param string $provider Provider.
	 * @param array  $identity Identity.
	 * @param string $redirect Destination.
	 */
	private static function sign_in( $provider, array $identity, $redirect ) {
		$email = $identity['email'];
		$row   = BDACI_PA_Members::get_by_email( $email );

		if ( ! $row ) {
			if ( BDACI_PA_Settings::get( 'allow_requests' ) ) {
				BDACI_PA_Members::add( $email, $identity['name'], array(), BDACI_PA_Members::PENDING, $provider );
				BDACI_PA_Log::add( 'access_requested', $email, 0, $provider );
				self::notify_admin_of_request( $email, $identity['name'], $provider );
				self::go( 'requested' );
			}
			BDACI_PA_Log::add( 'login_not_authorized', $email, 0, $provider );
			self::go( 'notallowed' );
		}
		if ( BDACI_PA_Members::PENDING === $row->status ) {
			self::go( 'pending' );
		}
		if ( BDACI_PA_Members::ACTIVE !== $row->status ) {
			BDACI_PA_Log::add( 'login_not_authorized', $email, (int) $row->user_id, $provider . ' revoked' );
			self::go( 'notallowed' );
		}

		$user = $row->user_id ? get_userdata( (int) $row->user_id ) : get_user_by( 'email', $email );
		if ( ! $user ) {
			$user_id = BDACI_PA_Members::insert_user( $email, $row->display_name ? $row->display_name : $identity['name'] );
			if ( is_wp_error( $user_id ) ) {
				self::fail( 'create_user_' . $user_id->get_error_code(), $provider, $email );
			}
			$user = get_userdata( $user_id );
			BDACI_PA_Log::add( 'account_created', $email, $user_id, $provider );
		}
		if ( (int) $row->user_id !== (int) $user->ID ) {
			BDACI_PA_Members::update( (int) $row->id, array( 'user_id' => (int) $user->ID ) );
		}
		BDACI_PA_Members::apply_to_account( (int) $row->id );

		// Staff/admin accounts keep using their password, so a provider can never be used to take one over.
		if ( user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) ) {
			BDACI_PA_Log::add( 'sso_blocked_privileged', $email, $user->ID, $provider );
			self::go( 'notallowed' );
		}

		// Bind the account to this provider identity the first time; reject a different one later.
		$meta_key = '_bdaci_pa_sub_' . $provider;
		$bound    = (string) get_user_meta( $user->ID, $meta_key, true );
		if ( '' === $bound ) {
			update_user_meta( $user->ID, $meta_key, $identity['subject'] );
		} elseif ( ! hash_equals( $bound, $identity['subject'] ) ) {
			BDACI_PA_Log::add( 'sso_subject_mismatch', $email, $user->ID, $provider );
			self::go( 'ssomismatch' );
		}

		$redirect = BDACI_PA_Login::safe_target( $redirect );
		if ( BDACI_PA_Settings::get( 'mfa_sso' ) ) {
			$view = BDACI_PA_Login::begin_mfa(
				array(
					'step'     => 'code',
					'error'    => '',
					'notice'   => '',
					'email'    => '',
					'redirect' => $redirect,
				),
				$user,
				false,
				$provider
			);
			BDACI_PA_Login::render( $view );
			exit;
		}
		BDACI_PA_Login::complete( $user, false, $provider, $redirect );
	}

	/**
	 * Email the administrator about a new access request.
	 *
	 * @param string $email    Requester.
	 * @param string $name     Name.
	 * @param string $provider Provider.
	 */
	private static function notify_admin_of_request( $email, $name, $provider ) {
		$portal = BDACI_PA_Settings::get( 'portal_name' );
		wp_mail(
			BDACI_PA_Settings::notify_address(),
			/* translators: %s: portal name */
			sprintf( __( '[%s] New access request', 'bdaci-portal-access' ), $portal ),
			sprintf(
				/* translators: 1: name, 2: email, 3: provider, 4: admin URL */
				__( "%1\$s <%2\$s> signed in with %3\$s and is asking for portal access.\n\nReview the request: %4\$s\n", 'bdaci-portal-access' ),
				$name ? $name : __( 'Someone', 'bdaci-portal-access' ),
				$email,
				'microsoft' === $provider ? 'Microsoft 365' : 'Google',
				admin_url( 'admin.php?page=bdaci-portal' )
			)
		);
	}

	/**
	 * Log a failure and send the visitor back to the sign-in page.
	 *
	 * @param string $reason   Reason.
	 * @param string $provider Provider.
	 * @param string $email    Email.
	 * @param string $msg      Message key.
	 */
	private static function fail( $reason, $provider, $email = '', $msg = 'ssofailed' ) {
		BDACI_PA_Log::add( 'sso_failed', $email, 0, $provider . ': ' . $reason );
		self::go( $msg );
	}

	/**
	 * Redirect to the sign-in page with a message.
	 *
	 * @param string $msg Message key.
	 */
	private static function go( $msg ) {
		wp_safe_redirect( BDACI_PA_Login::url( array( 'msg' => $msg ) ) );
		exit;
	}

	/**
	 * Decode a JWT payload (no signature check; see handle_callback()).
	 *
	 * @param string $jwt Token.
	 * @return array|null
	 */
	private static function decode_jwt( $jwt ) {
		$parts = explode( '.', (string) $jwt );
		if ( 3 !== count( $parts ) ) {
			return null;
		}
		$header = json_decode( self::b64url_decode( $parts[0] ), true );
		if ( ! is_array( $header ) || empty( $header['alg'] ) || 'none' === strtolower( $header['alg'] ) ) {
			return null;
		}
		$payload = json_decode( self::b64url_decode( $parts[1] ), true );
		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Random URL-safe string (256 bits).
	 *
	 * @return string
	 */
	private static function random() {
		return self::b64url( random_bytes( 32 ) );
	}

	/**
	 * Transient key for a state value.
	 *
	 * @param string $state State.
	 * @return string
	 */
	private static function state_key( $state ) {
		return 'bdaci_pa_st_' . substr( hash( 'sha256', $state ), 0, 40 );
	}

	/**
	 * Base64url encode.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private static function b64url( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	private static function b64url_decode( $data ) {
		$data = strtr( $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return (string) base64_decode( $data, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}
}
