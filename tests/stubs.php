<?php
/**
 * Minimal WordPress stand-ins, so the pure guard functions can be exercised
 * without a WordPress install. Only the handful of core functions the guards
 * actually call are implemented.
 */
define( 'ABSPATH', '/stub/' );
define( 'WPAP_CLI', true );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

class WP_Error {
	public $code, $message;
	public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

$GLOBALS['WPAP_TEST_SITE'] = 'https://example.com';

function home_url( $p = '/' ) { return rtrim( $GLOBALS['WPAP_TEST_SITE'], '/' ) . '/' . ltrim( $p, '/' ); }
function wp_parse_url( $u, $c = -1 ) {
	$x = parse_url( $u );
	if ( -1 === $c ) { return $x; }
	$map = array( PHP_URL_HOST => 'host', PHP_URL_SCHEME => 'scheme' );
	return isset( $x[ $map[ $c ] ] ) ? $x[ $map[ $c ] ] : null;
}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function esc_url( $u )  { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function wp_json_encode( $d ) { return json_encode( $d ); }
function wp_list_pluck( $a, $f ) { $o = array(); foreach ( $a as $r ) { $o[] = is_array( $r ) ? $r[ $f ] : $r->$f; } return $o; }
function current_time( $t ) { return 'timestamp' === $t ? time() : date( 'Y-m-d H:i:s' ); }
function get_permalink( $id ) { return 'https://example.com/product/p' . $id . '/'; }
function get_term_link( $s, $t ) { return 'https://example.com/product-category/' . $s . '/'; }
function sanitize_title( $t ) {
	$t = strtolower( html_entity_decode( strip_tags( $t ), ENT_QUOTES, 'UTF-8' ) );
	return trim( preg_replace( '/[^a-z0-9]+/', '-', $t ), '-' );
}
function wp_kses( $html, $allowed ) {
	return preg_replace_callback( '#<(/?)([a-z0-9]+)([^>]*)>#i', function ( $m ) use ( $allowed ) {
		$tag = strtolower( $m[2] );
		if ( ! isset( $allowed[ $tag ] ) ) { return ''; }
		if ( $m[1] || ! $allowed[ $tag ] ) { return '<' . $m[1] . $tag . '>'; }
		$keep = '';
		preg_match_all( '/([a-z\-]+)\s*=\s*("|\')(.*?)\2/i', $m[3], $a, PREG_SET_ORDER );
		foreach ( $a as $at ) {
			if ( isset( $allowed[ $tag ][ strtolower( $at[1] ) ] ) ) { $keep .= ' ' . $at[1] . '="' . $at[3] . '"'; }
		}
		return '<' . $tag . $keep . '>';
	}, $html );
}
function wp_get_attachment_image_src( $id, $s )    { return array( 'https://example.com/wp-content/uploads/2026/01/part.webp', 1200, 800 ); }
function wp_get_attachment_image_srcset( $id, $s ) { return 'https://example.com/wp-content/uploads/2026/01/part.webp 1200w'; }
function wp_get_attachment_image_sizes( $id, $s )  { return '(max-width: 1200px) 100vw, 1200px'; }
function wpap_table( $n ) { return 'wp_wpap_' . $n; }

$PASS = 0; $FAIL = 0;
function t( $name, $cond, $extra = '' ) {
	global $PASS, $FAIL;
	if ( $cond ) { $PASS++; echo "  PASS  $name\n"; }
	else { $FAIL++; echo "  FAIL  $name" . ( $extra ? "  -> $extra" : '' ) . "\n"; }
}
function wpap_test_summary() {
	global $PASS, $FAIL;
	echo "\n" . str_repeat( '-', 44 ) . "\nPASSED: $PASS   FAILED: $FAIL\n";
	exit( $FAIL ? 1 : 0 );
}
