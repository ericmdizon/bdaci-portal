<?php
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( $path !== '/' && file_exists( $root . $path ) && ! is_dir( $root . $path ) ) {
	if ( substr( $path, -4 ) === '.php' ) { chdir( dirname( $root . $path ) ); require $root . $path; return true; }
	return false;
}
if ( is_dir( $root . $path ) && file_exists( $root . rtrim( $path, '/' ) . '/index.php' ) ) { chdir( $root . rtrim( $path, '/' ) ); require $root . rtrim( $path, '/' ) . '/index.php'; return true; }
chdir( $root ); require $root . '/index.php';
