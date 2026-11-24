<?php
// Tiny CLI: php wpcli.php <wp dir> <php code>
$_SERVER['HTTP_HOST'] = 'localhost:8089'; $_SERVER['REQUEST_URI']='/';
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
eval( $argv[2] );
