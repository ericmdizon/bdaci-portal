<?php
/**
 * Portal sign-in page: email + password with an emailed verification code, plus SSO buttons.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

/**
 * Login controller.
 */
class BDACI_PA_Login {

	const MFA_COOKIE    = 'bdaci_pa_mfa';
	const CODE_TTL      = 600;  // Verification codes last 10 minutes.
	const MAX_ATTEMPTS  = 5;    // Wrong codes before the code is thrown away.
	const MAX_SENDS     = 3;    // Codes that can be sent for one sign-in.
	const LOCK_WINDOW   = 900;  // 15 minutes.
	const LOCK_IP_LIMIT = 5;    // Failed passwords per email+IP in the window.
	const LOCK_ID_LIMIT = 10;   // Failed passwords per email (any IP) in the window.

	/**
	 * True while our own form is authenticating, so the wp-login.php block lets it through.
	 *
	 * @var bool
	 */
	private static $in_portal_flow = false;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), -10 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'block_core_login' ), 99 );
		add_action( 'validate_password_reset', array( __CLASS__, 'validate_reset_password' ), 10, 2 );
		add_action( 'user_profile_update_errors', array( __CLASS__, 'validate_profile_password' ), 10, 3 );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'brand_core_login' ) );
		add_filter( 'login_message', array( __CLASS__, 'core_login_message' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'core_login_header_url' ) );
	}

	/**
	 * Pretty URLs: /portal-login/ and /portal-login/callback/{provider}/.
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^portal-login/?$', 'index.php?bdaci_pa=login', 'top' );
		add_rewrite_rule( '^portal-login/callback/(microsoft|google)/?$', 'index.php?bdaci_pa=callback&bdaci_pa_provider=$matches[1]', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'bdaci_pa';
		$vars[] = 'bdaci_pa_provider';
		return $vars;
	}

	/**
	 * Sign-in page URL.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	public static function url( array $args = array() ) {
		$base = get_option( 'permalink_structure' ) ? home_url( '/portal-login/' ) : add_query_arg( 'bdaci_pa', 'login', home_url( '/' ) );
		return $args ? add_query_arg( array_map( 'rawurlencode', $args ), $base ) : $base;
	}

	/**
	 * OAuth redirect (callback) URL for a provider. This exact value is registered with Microsoft/Google.
	 *
	 * @param string $provider microsoft|google.
	 * @return string
	 */
	public static function callback_url( $provider ) {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/portal-login/callback/' . $provider . '/' );
		}
		return add_query_arg(
			array(
				'bdaci_pa'          => 'callback',
				'bdaci_pa_provider' => $provider,
			),
			home_url( '/' )
		);
	}

	/**
	 * Whether this request is for the sign-in page.
	 *
	 * @return bool
	 */
	public static function is_login_request() {
		return 'login' === get_query_var( 'bdaci_pa' );
	}

	/**
	 * Route sign-in and callback requests.
	 */
	public static function route() {
		$action = get_query_var( 'bdaci_pa' );
		if ( 'callback' === $action ) {
			nocache_headers();
			BDACI_PA_OIDC::handle_callback( sanitize_key( get_query_var( 'bdaci_pa_provider' ) ) );
			exit;
		}
		if ( 'login' !== $action ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		$redirect = self::requested_redirect();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Starting SSO only redirects to the identity provider; the state cookie protects the callback.
		$sso = isset( $_GET['sso'] ) ? sanitize_key( wp_unslash( $_GET['sso'] ) ) : '';
		if ( $sso ) {
			BDACI_PA_OIDC::start( $sso, $redirect );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview for signed-in admins.
		$preview = ! empty( $_GET['preview'] ) && current_user_can( 'bdaci_manage_portal' );
		if ( $preview ) {
			self::render( array( 'step' => 'password', 'error' => '', 'notice' => '', 'email' => '', 'redirect' => $redirect, 'preview' => true ) );
			exit;
		}
		if ( is_user_logged_in() && BDACI_PA_Members::is_authorized( get_current_user_id() ) ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$view = array(
			'step'     => 'password',
			'error'    => '',
			'notice'   => self::notice_from_query(),
			'email'    => '',
			'redirect' => $redirect,
		);

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$view = self::handle_post( $view );
		} elseif ( self::pending_mfa() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( empty( $_GET['restart'] ) ) {
				$view['step'] = 'code';
			} else {
				self::clear_mfa();
			}
		}

		self::render( $view );
		exit;
	}

	/**
	 * Safe post-login destination.
	 *
	 * @return string
	 */
	private static function requested_redirect() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$raw = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		return self::safe_target( $raw );
	}

	/**
	 * Same-site destination, falling back to the portal home (also for an empty value,
	 * which wp_validate_redirect() would otherwise pass through as '').
	 *
	 * @param string $url Requested URL.
	 * @return string
	 */
	public static function safe_target( $url ) {
		$home = BDACI_PA_Settings::portal_home_url();
		$url  = trim( (string) $url );
		if ( '' === $url ) {
			return $home;
		}
		$valid = wp_validate_redirect( $url, $home );
		return '' === $valid ? $home : $valid;
	}

	/**
	 * Message keyed by ?msg=.
	 *
	 * @return string
	 */
	private static function notice_from_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		$messages = array(
			'signedout'    => __( 'You have been signed out.', 'bdaci-portal-access' ),
			'revoked'      => __( 'Your portal access has ended. Please contact the portal administrator if you need access.', 'bdaci-portal-access' ),
			'requested'    => __( 'Thank you. Your request for portal access has been sent to the administrator. You will be able to sign in once it is approved.', 'bdaci-portal-access' ),
			'pending'      => __( 'Your request for portal access is still waiting for approval.', 'bdaci-portal-access' ),
			'notallowed'   => __( 'That account is not authorized for the portal. Please contact the portal administrator.', 'bdaci-portal-access' ),
			'ssofailed'    => __( 'We could not complete that sign-in. Please try again.', 'bdaci-portal-access' ),
			'ssomismatch'  => __( 'That sign-in does not match the account on file. Please contact the portal administrator.', 'bdaci-portal-access' ),
			'ssodisabled'  => __( 'That sign-in option is not available.', 'bdaci-portal-access' ),
			'unverified'   => __( 'Your email address could not be verified by the sign-in provider.', 'bdaci-portal-access' ),
		);
		return $messages[ $key ] ?? '';
	}

	/**
	 * Handle a form submission.
	 *
	 * @param array $view View state.
	 * @return array
	 */
	private static function handle_post( array $view ) {
		$step = isset( $_POST['bdaci_step'] ) ? sanitize_key( wp_unslash( $_POST['bdaci_step'] ) ) : '';

		if ( 'password' === $step ) {
			if ( ! isset( $_POST['_bdaci_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bdaci_nonce'] ) ), 'bdaci_pa_password' ) ) {
				$view['error'] = __( 'Your session expired. Please try again.', 'bdaci-portal-access' );
				return $view;
			}
			$login         = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
			$password      = isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered.
			$remember      = ! empty( $_POST['rememberme'] );
			$view['email'] = $login;
			return self::handle_password( $view, $login, $password, $remember );
		}

		if ( 'code' === $step || 'resend' === $step ) {
			if ( ! isset( $_POST['_bdaci_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_bdaci_nonce'] ) ), 'bdaci_pa_code' ) ) {
				$view['error'] = __( 'Your session expired. Please sign in again.', 'bdaci-portal-access' );
				self::clear_mfa();
				return $view;
			}
			if ( 'resend' === $step ) {
				return self::handle_resend( $view );
			}
			$code = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['code'] ) ) ) : '';
			return self::handle_code( $view, $code );
		}

		return $view;
	}

	/**
	 * Check email + password.
	 *
	 * @param array  $view     View.
	 * @param string $login    Email or username.
	 * @param string $password Password.
	 * @param bool   $remember Remember me.
	 * @return array
	 */
	private static function handle_password( array $view, $login, $password, $remember ) {
		$generic = __( 'The email or password is incorrect.', 'bdaci-portal-access' );
		if ( '' === $login || '' === $password ) {
			$view['error'] = __( 'Please enter your email and password.', 'bdaci-portal-access' );
			return $view;
		}
		if ( self::is_locked( $login ) ) {
			BDACI_PA_Log::add( 'login_locked', $login );
			$view['error'] = __( 'Too many failed attempts. Please wait 15 minutes and try again, or reset your password.', 'bdaci-portal-access' );
			return $view;
		}

		self::$in_portal_flow = true;
		$user                 = wp_authenticate( $login, $password );
		self::$in_portal_flow = false;

		if ( is_wp_error( $user ) ) {
			self::record_failure( $login );
			BDACI_PA_Log::add( 'login_failed', is_email( $login ) ? $login : '', 0, 'password' );
			$view['error'] = $generic;
			return $view;
		}
		self::clear_failures( $login );

		if ( ! BDACI_PA_Members::is_authorized( $user->ID ) ) {
			BDACI_PA_Log::add( 'login_not_authorized', $user->user_email, $user->ID, 'password' );
			$view['error'] = __( 'Your account is not authorized for the portal. Please contact the portal administrator.', 'bdaci-portal-access' );
			return $view;
		}

		if ( BDACI_PA_Settings::get( 'mfa_password' ) ) {
			return self::begin_mfa( $view, $user, $remember, 'password' );
		}
		self::complete( $user, $remember, 'password', $view['redirect'] );
		return $view; // Not reached.
	}

	/**
	 * Start the emailed-code step. Also used after SSO when "code after SSO" is on.
	 *
	 * @param array   $view     View.
	 * @param WP_User $user     User.
	 * @param bool    $remember Remember me.
	 * @param string  $method   password|microsoft|google.
	 * @return array
	 */
	public static function begin_mfa( array $view, WP_User $user, $remember, $method ) {
		$token   = wp_generate_password( 43, false, false );
		$pending = array(
			'user_id'  => (int) $user->ID,
			'remember' => (bool) $remember,
			'redirect' => $view['redirect'],
			'method'   => $method,
			'attempts' => 0,
			'sends'    => 0,
			'code'     => '',
			'expires'  => 0,
		);
		$pending = self::send_code( $user, $pending );
		set_transient( self::mfa_key( $token ), $pending, self::CODE_TTL );
		self::set_cookie( self::MFA_COOKIE, $token, time() + self::CODE_TTL );
		$_COOKIE[ self::MFA_COOKIE ] = $token;

		BDACI_PA_Log::add( 'code_sent', $user->user_email, $user->ID, $method );
		$view['step']   = 'code';
		$view['notice'] = '';
		return $view;
	}

	/**
	 * Generate and email a code.
	 *
	 * @param WP_User $user    User.
	 * @param array   $pending Pending state.
	 * @return array
	 */
	private static function send_code( WP_User $user, array $pending ) {
		$code                = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$pending['code']     = self::hash_code( $code, (int) $user->ID );
		$pending['expires']  = time() + self::CODE_TTL;
		$pending['attempts'] = 0;
		$pending['sends']    = (int) $pending['sends'] + 1;

		$portal  = BDACI_PA_Settings::get( 'portal_name' );
		$subject = sprintf( /* translators: %s: portal name */ __( '%s verification code', 'bdaci-portal-access' ), $portal );
		$body    = sprintf(
			/* translators: 1: name, 2: code, 3: portal name */
			__( "Hello %1\$s,\n\nYour verification code is: %2\$s\n\nIt expires in 10 minutes. If you did not try to sign in to the %3\$s, you can ignore this email, but you may want to change your password.\n", 'bdaci-portal-access' ),
			$user->display_name,
			$code,
			$portal
		);
		wp_mail( $user->user_email, $subject, $body );
		return $pending;
	}

	/**
	 * Check a submitted code.
	 *
	 * @param array  $view View.
	 * @param string $code Code.
	 * @return array
	 */
	private static function handle_code( array $view, $code ) {
		$token   = self::mfa_token();
		$pending = $token ? get_transient( self::mfa_key( $token ) ) : false;
		if ( ! is_array( $pending ) || time() > (int) $pending['expires'] ) {
			self::clear_mfa();
			$view['error'] = __( 'That code has expired. Please sign in again.', 'bdaci-portal-access' );
			return $view;
		}
		$user = get_userdata( (int) $pending['user_id'] );
		if ( ! $user ) {
			self::clear_mfa();
			return $view;
		}
		$view['step'] = 'code';

		if ( '' !== $code && hash_equals( (string) $pending['code'], self::hash_code( $code, (int) $user->ID ) ) ) {
			self::clear_mfa();
			if ( ! BDACI_PA_Members::is_authorized( $user->ID ) ) {
				$view['step']  = 'password';
				$view['error'] = __( 'Your account is not authorized for the portal.', 'bdaci-portal-access' );
				return $view;
			}
			self::complete( $user, (bool) $pending['remember'], (string) $pending['method'], self::safe_target( (string) $pending['redirect'] ) );
		}

		$pending['attempts'] = (int) $pending['attempts'] + 1;
		BDACI_PA_Log::add( 'code_failed', $user->user_email, $user->ID );
		if ( $pending['attempts'] >= self::MAX_ATTEMPTS ) {
			self::clear_mfa();
			$view['step']  = 'password';
			$view['error'] = __( 'Too many incorrect codes. Please sign in again.', 'bdaci-portal-access' );
			return $view;
		}
		set_transient( self::mfa_key( $token ), $pending, max( 1, (int) $pending['expires'] - time() ) );
		$view['error'] = __( 'That code is not correct. Please check the email and try again.', 'bdaci-portal-access' );
		return $view;
	}

	/**
	 * Send another code.
	 *
	 * @param array $view View.
	 * @return array
	 */
	private static function handle_resend( array $view ) {
		$token   = self::mfa_token();
		$pending = $token ? get_transient( self::mfa_key( $token ) ) : false;
		$user    = is_array( $pending ) ? get_userdata( (int) $pending['user_id'] ) : false;
		if ( ! $user ) {
			self::clear_mfa();
			$view['error'] = __( 'Please sign in again.', 'bdaci-portal-access' );
			return $view;
		}
		$view['step'] = 'code';
		if ( (int) $pending['sends'] >= self::MAX_SENDS ) {
			$view['error'] = __( 'No more codes can be sent for this sign-in. Please start again in a few minutes.', 'bdaci-portal-access' );
			return $view;
		}
		$pending = self::send_code( $user, $pending );
		set_transient( self::mfa_key( $token ), $pending, self::CODE_TTL );
		self::set_cookie( self::MFA_COOKIE, $token, time() + self::CODE_TTL );
		$view['notice'] = __( 'A new code has been sent.', 'bdaci-portal-access' );
		return $view;
	}

	/**
	 * Finish signing in and redirect.
	 *
	 * @param WP_User $user     User.
	 * @param bool    $remember Remember me.
	 * @param string  $method   How they signed in.
	 * @param string  $redirect Destination.
	 */
	public static function complete( WP_User $user, $remember, $method, $redirect ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, $remember, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$row = BDACI_PA_Members::for_user( $user->ID );
		if ( $row ) {
			BDACI_PA_Members::update( (int) $row->id, array( 'last_login' => current_time( 'mysql', true ) ) );
		}
		BDACI_PA_Log::add( 'login_success', $user->user_email, $user->ID, $method );
		wp_safe_redirect( self::safe_target( $redirect ) );
		exit;
	}

	/**
	 * Is there a code step in progress for this browser?
	 *
	 * @return bool
	 */
	private static function pending_mfa() {
		$token = self::mfa_token();
		return $token && is_array( get_transient( self::mfa_key( $token ) ) );
	}

	/**
	 * Code-step token from the cookie.
	 *
	 * @return string
	 */
	private static function mfa_token() {
		$token = isset( $_COOKIE[ self::MFA_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::MFA_COOKIE ] ) ) : '';
		return preg_match( '/^[A-Za-z0-9]{43}$/', $token ) ? $token : '';
	}

	/**
	 * Transient key for a token.
	 *
	 * @param string $token Token.
	 * @return string
	 */
	private static function mfa_key( $token ) {
		return 'bdaci_pa_mfa_' . substr( hash( 'sha256', $token ), 0, 40 );
	}

	/**
	 * Forget the code step.
	 */
	private static function clear_mfa() {
		$token = self::mfa_token();
		if ( $token ) {
			delete_transient( self::mfa_key( $token ) );
		}
		self::set_cookie( self::MFA_COOKIE, '', time() - HOUR_IN_SECONDS );
		unset( $_COOKIE[ self::MFA_COOKIE ] );
	}

	/**
	 * Keyed hash of a code so the plain code is never stored.
	 *
	 * @param string $code    Code.
	 * @param int    $user_id User ID.
	 * @return string
	 */
	private static function hash_code( $code, $user_id ) {
		return hash_hmac( 'sha256', $code . '|' . $user_id, wp_salt( 'auth' ) );
	}

	/**
	 * Set a secure, HTTP-only cookie.
	 *
	 * @param string $name    Name.
	 * @param string $value   Value.
	 * @param int    $expires Expiry timestamp.
	 */
	public static function set_cookie( $name, $value, $expires ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Lockout keys.
	 *
	 * @param string $login Login.
	 * @return array
	 */
	private static function lock_keys( $login ) {
		$id = strtolower( trim( $login ) );
		return array(
			'ip' => 'bdaci_pa_rl_' . md5( $id . '|' . BDACI_PA_Log::ip() ),
			'id' => 'bdaci_pa_rlu_' . md5( $id ),
		);
	}

	/**
	 * Whether this login is locked out.
	 *
	 * @param string $login Login.
	 * @return bool
	 */
	private static function is_locked( $login ) {
		$keys = self::lock_keys( $login );
		return (int) get_transient( $keys['ip'] ) >= self::LOCK_IP_LIMIT || (int) get_transient( $keys['id'] ) >= self::LOCK_ID_LIMIT;
	}

	/**
	 * Count a failure.
	 *
	 * @param string $login Login.
	 */
	private static function record_failure( $login ) {
		foreach ( self::lock_keys( $login ) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, self::LOCK_WINDOW );
		}
	}

	/**
	 * Reset failures after a good password.
	 *
	 * @param string $login Login.
	 */
	private static function clear_failures( $login ) {
		foreach ( self::lock_keys( $login ) as $key ) {
			delete_transient( $key );
		}
	}

	/**
	 * Portal-only accounts must use the portal page (which adds the verification code), not wp-login.php.
	 *
	 * @param WP_User|WP_Error $user User.
	 * @return WP_User|WP_Error
	 */
	public static function block_core_login( $user ) {
		if ( self::$in_portal_flow || ! ( $user instanceof WP_User ) ) {
			return $user;
		}
		if ( BDACI_PA_Roles::is_portal_only( $user ) ) {
			return new WP_Error(
				'bdaci_use_portal',
				sprintf(
					/* translators: %s: link */
					__( 'Board and committee members sign in on the %s page.', 'bdaci-portal-access' ),
					'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Portal sign-in', 'bdaci-portal-access' ) . '</a>'
				)
			);
		}
		return $user;
	}

	/**
	 * Password rules. Returns an error message or ''.
	 *
	 * @param string       $password Password.
	 * @param WP_User|null $user     User.
	 * @return string
	 */
	public static function password_problem( $password, $user = null ) {
		$min = (int) BDACI_PA_Settings::get( 'min_password_length' );
		if ( strlen( $password ) < $min ) {
			/* translators: %d: minimum length */
			return sprintf( __( 'Passwords must be at least %d characters long.', 'bdaci-portal-access' ), $min );
		}
		$classes = 0;
		foreach ( array( '/[a-z]/', '/[A-Z]/', '/\d/', '/[^A-Za-z0-9]/' ) as $re ) {
			$classes += preg_match( $re, $password ) ? 1 : 0;
		}
		if ( $classes < 3 ) {
			return __( 'Passwords must include at least three of: lowercase letters, uppercase letters, numbers, symbols.', 'bdaci-portal-access' );
		}
		if ( $user instanceof WP_User ) {
			$lower = strtolower( $password );
			foreach ( array( $user->user_login, current( explode( '@', $user->user_email ) ) ) as $part ) {
				if ( strlen( $part ) >= 4 && false !== strpos( $lower, strtolower( $part ) ) ) {
					return __( 'Passwords must not contain your username or email name.', 'bdaci-portal-access' );
				}
			}
		}
		return '';
	}

	/**
	 * Enforce rules on the "reset password" form.
	 *
	 * @param WP_Error         $errors Errors.
	 * @param WP_User|WP_Error $user   User.
	 */
	public static function validate_reset_password( $errors, $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Core verifies the reset key before this hook.
		if ( ! isset( $_POST['pass1'] ) || ! ( $user instanceof WP_User ) ) {
			return;
		}
		$problem = self::password_problem( (string) wp_unslash( $_POST['pass1'] ), $user ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $problem ) {
			$errors->add( 'bdaci_weak_password', $problem );
		}
	}

	/**
	 * Enforce rules on profile/user edits.
	 *
	 * @param WP_Error $errors Errors.
	 * @param bool     $update Updating.
	 * @param stdClass $user   User data.
	 */
	public static function validate_profile_password( $errors, $update, $user ) {
		if ( empty( $user->user_pass ) ) {
			return;
		}
		$existing = ! empty( $user->ID ) ? get_userdata( $user->ID ) : null;
		$problem  = self::password_problem( (string) $user->user_pass, $existing ? $existing : null );
		if ( $problem ) {
			$errors->add( 'bdaci_weak_password', $problem );
		}
	}

	/**
	 * Is this wp-login.php screen part of password reset?
	 *
	 * @return bool
	 */
	private static function is_reset_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$checkemail = isset( $_GET['checkemail'] ) ? sanitize_key( wp_unslash( $_GET['checkemail'] ) ) : '';
		return in_array( $action, array( 'lostpassword', 'retrievepassword', 'rp', 'resetpass' ), true ) || 'confirm' === $checkemail;
	}

	/**
	 * Portal branding on the password-reset screens.
	 */
	public static function brand_core_login() {
		if ( ! self::is_reset_screen() ) {
			return;
		}
		$logo  = BDACI_PA_Settings::get( 'logo_url' );
		$color = BDACI_PA_Settings::get( 'brand_color' );
		$css   = 'body.login{background:#f4f6fa}.login .button-primary{background:' . $color . ';border-color:' . $color . '}';
		if ( $logo ) {
			$css .= '.login h1 a{background-image:url("' . esc_url( $logo ) . '");background-size:contain;width:100%;height:90px}';
		}
		wp_register_style( 'bdaci-pa-login', false, array(), BDACI_PA_VERSION );
		wp_enqueue_style( 'bdaci-pa-login' );
		wp_add_inline_style( 'bdaci-pa-login', wp_strip_all_tags( $css ) );
	}

	/**
	 * "Back to portal sign-in" note on reset screens.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function core_login_message( $message ) {
		if ( ! self::is_reset_screen() ) {
			return $message;
		}
		return $message . '<p class="message">' . sprintf(
			/* translators: %s: link */
			esc_html__( 'Board and committee members: when you are done, return to the %s.', 'bdaci-portal-access' ),
			'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'portal sign-in page', 'bdaci-portal-access' ) . '</a>'
		) . '</p>';
	}

	/**
	 * Logo link on reset screens goes to the portal sign-in.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function core_login_header_url( $url ) {
		return self::is_reset_screen() ? self::url() : $url;
	}

	/**
	 * Render the sign-in page.
	 *
	 * @param array $view View state.
	 */
	public static function render( array $view ) {
		status_header( 200 );
		$settings  = BDACI_PA_Settings::all();
		$providers = BDACI_PA_OIDC::enabled_providers();
		include BDACI_PA_DIR . 'templates/login.php';
	}
}
