<?php
/**
 * Portal sign-in page.
 *
 * Variables: $view (step, error, notice, email, redirect), $settings, $providers.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;

$bdaci_color = $settings['brand_color'] && '#1d3f72' !== strtolower( $settings['brand_color'] ) ? $settings['brand_color'] : '#0077a8';
$bdaci_logo  = $settings['logo_url'] ? $settings['logo_url'] : BDACI_PA_URL . 'assets/logo-full.png';
$bdaci_photo = $settings['signin_image'] ?? '';
$bdaci_quote = $settings['signin_quote'] ?? '';
$bdaci_blurb = $settings['signin_blurb'] ?? '';
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( sprintf( /* translators: %s: portal name */ __( 'Sign in · %s', 'bdaci-portal-access' ), $settings['portal_name'] ) ); ?></title>
	<?php if ( ! empty( $settings['portal_fonts'] ) ) : ?>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@500;700&family=Open+Sans:wght@400;600;700&display=swap">
	<?php endif; ?>
	<link rel="stylesheet" href="<?php echo esc_url( BDACI_PA_URL . 'assets/portal-login.css?ver=' . BDACI_PA_VERSION ); ?>">
	<style>:root{--bdaci-brand:<?php echo esc_html( $bdaci_color ); ?>}</style>
</head>
<body class="bdaci-login<?php echo $bdaci_photo ? ' has-photo' : ''; ?>">
<?php if ( ! empty( $view['preview'] ) ) : ?>
	<div class="bdaci-previewbar" role="status"><?php esc_html_e( 'Preview: you are signed in, so this is how the sign-in page looks to everyone else. The buttons are switched off here.', 'bdaci-portal-access' ); ?> <a href="<?php echo esc_url( $view['redirect'] ); ?>"><?php esc_html_e( 'Back to the portal', 'bdaci-portal-access' ); ?></a></div>
<?php endif; ?>
<div class="bdaci-split<?php echo ! empty( $view['preview'] ) ? ' is-preview' : ''; ?>">
<aside class="bdaci-art"<?php echo $bdaci_photo ? ' style="--bdaci-photo:url(\'' . esc_url( $bdaci_photo ) . '\')"' : ''; ?>>
	<div class="bdaci-badge"><img src="<?php echo esc_url( BDACI_PA_URL . 'assets/logo-badge.png' ); ?>" alt="<?php esc_attr_e( 'Celebrating 70 years of community involvement', 'bdaci-portal-access' ); ?>"></div>
	<?php if ( ! $bdaci_photo ) : ?>
		<img class="bdaci-illustration" src="<?php echo esc_url( BDACI_PA_URL . 'assets/signin-art.svg?ver=' . BDACI_PA_VERSION ); ?>" alt="">
	<?php endif; ?>
	<div class="bdaci-art-text">
		<?php if ( $bdaci_quote ) : ?>
			<blockquote><?php echo nl2br( esc_html( $bdaci_quote ) ); ?></blockquote>
		<?php endif; ?>
		<?php if ( $bdaci_blurb ) : ?>
			<p><?php echo esc_html( $bdaci_blurb ); ?></p>
		<?php endif; ?>
	</div>
</aside>
<main class="bdaci-card" role="main">
	<header class="bdaci-head">
		<img class="bdaci-logo" src="<?php echo esc_url( $bdaci_logo ); ?>" alt="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
		<p class="bdaci-eyebrow"><?php echo esc_html( $settings['portal_name'] ); ?></p>
		<h1><?php echo 'code' === $view['step'] ? esc_html__( 'Check your email', 'bdaci-portal-access' ) : esc_html__( 'Welcome. Please sign in.', 'bdaci-portal-access' ); ?></h1>
		<?php if ( 'password' === $view['step'] && $settings['welcome_text'] && 0 !== strpos( $settings['welcome_text'], 'Welcome. Please sign in' ) ) : ?>
			<p class="bdaci-welcome"><?php echo esc_html( $settings['welcome_text'] ); ?></p>
		<?php endif; ?>
	</header>

	<?php if ( $view['error'] ) : ?>
		<div class="bdaci-alert bdaci-alert-error" role="alert"><?php echo esc_html( $view['error'] ); ?></div>
	<?php endif; ?>
	<?php if ( $view['notice'] ) : ?>
		<div class="bdaci-alert" role="status"><?php echo esc_html( $view['notice'] ); ?></div>
	<?php endif; ?>

	<?php if ( 'code' === $view['step'] ) : ?>
		<form method="post" action="<?php echo esc_url( BDACI_PA_Login::url() ); ?>" class="bdaci-form" autocomplete="off">
			<p><?php esc_html_e( 'We emailed you a 6-digit verification code. Enter it below to finish signing in.', 'bdaci-portal-access' ); ?></p>
			<label for="bdaci-code"><?php esc_html_e( 'Verification code', 'bdaci-portal-access' ); ?></label>
			<input id="bdaci-code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" required autofocus>
			<?php wp_nonce_field( 'bdaci_pa_code', '_bdaci_nonce' ); ?>
			<input type="hidden" name="bdaci_step" value="code">
			<button type="submit" class="bdaci-btn bdaci-btn-primary"><?php esc_html_e( 'Verify and sign in', 'bdaci-portal-access' ); ?></button>
		</form>
		<form method="post" action="<?php echo esc_url( BDACI_PA_Login::url() ); ?>" class="bdaci-inline">
			<?php wp_nonce_field( 'bdaci_pa_code', '_bdaci_nonce' ); ?>
			<input type="hidden" name="bdaci_step" value="resend">
			<button type="submit" class="bdaci-link"><?php esc_html_e( 'Send a new code', 'bdaci-portal-access' ); ?></button>
			· <a href="<?php echo esc_url( BDACI_PA_Login::url( array( 'restart' => '1' ) ) ); ?>"><?php esc_html_e( 'Start over', 'bdaci-portal-access' ); ?></a>
		</form>
	<?php else : ?>
		<?php if ( $providers ) : ?>
			<div class="bdaci-sso">
				<?php foreach ( $providers as $bdaci_key => $bdaci_label ) : ?>
					<a class="bdaci-btn bdaci-btn-sso bdaci-<?php echo esc_attr( $bdaci_key ); ?>" href="<?php echo esc_url( BDACI_PA_Login::url( array( 'sso' => $bdaci_key, 'redirect_to' => $view['redirect'] ) ) ); ?>">
						<?php if ( 'microsoft' === $bdaci_key ) : ?>
							<svg aria-hidden="true" width="18" height="18" viewBox="0 0 21 21"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
						<?php else : ?>
							<svg aria-hidden="true" width="18" height="18" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
						<?php endif; ?>
						<span><?php echo esc_html( $bdaci_label ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<div class="bdaci-divider"><span><?php esc_html_e( 'or use your email and password', 'bdaci-portal-access' ); ?></span></div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( BDACI_PA_Login::url() ); ?>" class="bdaci-form">
			<label for="bdaci-email"><?php esc_html_e( 'Email', 'bdaci-portal-access' ); ?></label>
			<input id="bdaci-email" name="log" type="text" autocomplete="username" value="<?php echo esc_attr( $view['email'] ); ?>" required <?php echo $providers ? '' : 'autofocus'; ?>>
			<label for="bdaci-password"><?php esc_html_e( 'Password', 'bdaci-portal-access' ); ?></label>
			<input id="bdaci-password" name="pwd" type="password" autocomplete="current-password" required>
			<label class="bdaci-check"><input type="checkbox" name="rememberme" value="1"> <?php esc_html_e( 'Keep me signed in on this device', 'bdaci-portal-access' ); ?></label>
			<?php wp_nonce_field( 'bdaci_pa_password', '_bdaci_nonce' ); ?>
			<input type="hidden" name="bdaci_step" value="password">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $view['redirect'] ); ?>">
			<button type="submit" class="bdaci-btn bdaci-btn-primary"><?php esc_html_e( 'Sign in', 'bdaci-portal-access' ); ?></button>
		</form>
		<p class="bdaci-links"><a href="<?php echo esc_url( wp_lostpassword_url() ); ?>"><?php esc_html_e( 'Forgot your password?', 'bdaci-portal-access' ); ?></a></p>
	<?php endif; ?>

	<footer class="bdaci-foot">
		<?php if ( $settings['support_email'] ) : ?>
			<p><?php esc_html_e( 'Only authorized board and committee members can sign in. Need access? Contact', 'bdaci-portal-access' ); ?> <a href="<?php echo esc_url( 'mailto:' . $settings['support_email'] ); ?>"><?php echo esc_html( $settings['support_email'] ); ?></a></p>
		<?php endif; ?>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">&larr; <?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Back to %s', 'bdaci-portal-access' ), get_bloginfo( 'name' ) ) ); ?></a></p>
	</footer>
</main>
</div>
</body>
</html>
