<?php
// TEST ONLY: fake GitHub releases API for the plugin updater, and keep WordPress.org offline.
// Option test_fake_gh = array( 'code' => 200|401|404, 'version' => '2.99.0' ). No option: GitHub unreachable.
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$resp = function ( $code, $body, $headers = array() ) {
		return array( 'headers' => $headers, 'body' => $body, 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	};
	if ( false !== strpos( $url, 'api.wordpress.org' ) ) {
		return new WP_Error( 'http_request_failed', 'WordPress.org is offline in tests' );
	}
	if ( 0 === strpos( $url, 'https://objects.githubusercontent.com/fake/' ) ) {
		file_put_contents( WP_CONTENT_DIR . '/gh-req.log', json_encode( array( $url, $args['headers']['Authorization'] ?? '' ) ) . "\n", FILE_APPEND );
		if ( ! empty( $args['stream'] ) && ! empty( $args['filename'] ) ) {
			copy( WP_CONTENT_DIR . '/fake-update.zip', $args['filename'] );
		}
		return $resp( 200, '', array( 'content-type' => 'application/zip' ) );
	}
	if ( 0 !== strpos( $url, 'https://api.github.com/repos/' ) ) {
		return $pre;
	}
	file_put_contents( WP_CONTENT_DIR . '/gh-req.log', json_encode( array( $url, $args['headers']['Authorization'] ?? '' ) ) . "\n", FILE_APPEND );
	$fake = get_option( 'test_fake_gh' );
	if ( ! is_array( $fake ) ) {
		return new WP_Error( 'http_request_failed', 'GitHub is offline in tests' );
	}
	if ( 200 !== (int) $fake['code'] ) {
		return $resp( (int) $fake['code'], '{"message":"nope"}' );
	}
	$repo = preg_replace( '#^https://api.github.com/repos/([^/]+/[^/]+)/.*$#', '$1', $url );
	if ( false !== strpos( $url, '/releases/assets/' ) ) {
		return $resp( 302, '', array( 'location' => 'https://objects.githubusercontent.com/fake/bdaci-portal-access.zip?sig=abc' ) );
	}
	$asset = array( array( 'name' => 'bdaci-portal-access.zip', 'url' => 'https://api.github.com/repos/' . $repo . '/releases/assets/101' ) );
	$list  = array(
		array( 'tag_name' => 'v' . $fake['version'], 'draft' => false, 'prerelease' => false, 'body' => "## Changes\n- Test release <script>x</script>", 'html_url' => 'https://github.com/' . $repo . '/releases/tag/v' . $fake['version'], 'published_at' => '2026-11-25T15:00:00Z', 'assets' => $asset ),
		array( 'tag_name' => 'v9.9.9', 'draft' => true, 'prerelease' => false, 'body' => '', 'assets' => $asset ),
		array( 'tag_name' => 'v8.0.0', 'draft' => false, 'prerelease' => true, 'body' => '', 'assets' => $asset ),
		array( 'tag_name' => 'v7.0.0', 'draft' => false, 'prerelease' => false, 'body' => '', 'assets' => array() ),
		array( 'tag_name' => 'nightly', 'draft' => false, 'prerelease' => false, 'body' => '', 'assets' => $asset ),
		array( 'tag_name' => 'v1.0.0', 'draft' => false, 'prerelease' => false, 'body' => '', 'assets' => $asset ),
	);
	return $resp( 200, wp_json_encode( $list ) );
}, 5, 3 );
