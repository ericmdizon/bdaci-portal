<?php
/**
 * Portal page template: the portal's own top bar and side menu, without the theme's header and footer.
 *
 * @package BDACI_Portal_Access
 */

defined( 'ABSPATH' ) || exit;
$bdaci_pa_html = BDACI_PA_Portal::render( get_queried_object_id() );
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'bdaci-portal-body' ); ?> style="margin:0">
<?php
wp_body_open();
echo $bdaci_pa_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts in BDACI_PA_Portal::render().
wp_footer();
?>
</body>
</html>
