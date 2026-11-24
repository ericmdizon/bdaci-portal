<?php
// TEST ONLY: capture mail, fake OIDC token endpoint.
function wp_mail( $to, $subject, $message, $headers = '', $attachments = array() ) {
	file_put_contents( WP_CONTENT_DIR . '/mail.log', json_encode( compact( 'to', 'subject', 'message' ) ) . "\n", FILE_APPEND );
	return true;
}
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'oauth2' ) && false === strpos( $url, 'token' ) ) { return $pre; }
	$claims = json_decode( (string) get_option( 'test_fake_claims' ), true );
	file_put_contents( WP_CONTENT_DIR . '/token-req.log', json_encode( array( $url, $args['body'] ) ) . "\n", FILE_APPEND );
	$b64 = function ( $s ) { return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); };
	$jwt = $b64( '{"alg":"RS256","typ":"JWT"}' ) . '.' . $b64( json_encode( $claims ) ) . '.sig';
	return array( 'headers' => array(), 'body' => json_encode( array( 'id_token' => $jwt, 'access_token' => 'x' ) ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
}, 10, 3 );
