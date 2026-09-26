<?php
/**
 * WP Auto Poster — cron entry point.
 *
 * cPanel cron (no key needed, runs as CLI):
 *   /usr/local/bin/php -q /home/USER/public_html/wp-content/plugins/wp-auto-poster/cron-post.php
 *
 * Browser (needs the secret from config.php):
 *   https://example.com/wp-content/plugins/wp-auto-poster/cron-post.php?key=YOUR_SECRET
 *
 * Consumes exactly one pending keyword and publishes exactly one article.
 */

$is_cli = ( 'cli' === PHP_SAPI );

if ( $is_cli ) {
	define( 'WPAP_CLI', true );
} else {
	header( 'Content-Type: text/plain; charset=utf-8' );
}

/* -- Boot WordPress -------------------------------------------------------- */

$wp_load = dirname( __FILE__ ) . '/../../../wp-load.php';   // plugins/<this>/ -> public_html/
if ( ! file_exists( $wp_load ) ) {
	// Walk up in case the site lives in a subdirectory.
	$dir = dirname( __FILE__ );
	for ( $i = 0; $i < 6; $i++ ) {
		$dir = dirname( $dir );
		if ( file_exists( $dir . '/wp-load.php' ) ) {
			$wp_load = $dir . '/wp-load.php';
			break;
		}
	}
}
if ( ! file_exists( $wp_load ) ) {
	http_response_code( 500 );
	exit( "FATAL: could not locate wp-load.php\n" );
}

define( 'WP_USE_THEMES', false );
require_once $wp_load;

/* -- Authorise ------------------------------------------------------------- */

if ( ! $is_cli ) {
	$key    = isset( $_GET['key'] ) ? (string) $_GET['key'] : '';
	$secret = defined( 'WPAP_CRON_SECRET' ) ? (string) WPAP_CRON_SECRET : '';

	// An unset secret disables HTTP triggering rather than allowing it through.
	if ( '' === $secret || ! hash_equals( $secret, $key ) ) {
		http_response_code( 403 );
		exit( "403 Forbidden\n" );
	}
}

if ( ! function_exists( 'wpap_run_once' ) ) {
	http_response_code( 500 );
	exit( "FATAL: the WP Auto Poster plugin is not active. Activate it in Plugins.\n" );
}

/* -- Run ------------------------------------------------------------------- */

@set_time_limit( 0 );
@ini_set( 'memory_limit', '512M' );
ignore_user_abort( true );

// Simple lock so an overlapping cron cannot double-post.
$lock = 'wpap_lock';
if ( get_transient( $lock ) ) {
	echo "SKIPPED: another run is still in progress.\n";
	exit( 0 );
}
set_transient( $lock, time(), 20 * MINUTE_IN_SECONDS );

$started = microtime( true );
$runs    = max( 1, (int) WPAP_PER_RUN );

for ( $i = 0; $i < $runs; $i++ ) {
	$res = wpap_run_once();
	echo ( $res['ok'] ? 'OK: ' : 'FAIL: ' ) . $res['message'] . "\n";
	if ( ! empty( $res['url'] ) ) {
		echo '     ' . $res['url'] . "\n";
	}
	if ( empty( $res['keyword'] ) ) {
		break; // queue empty
	}
}

delete_transient( $lock );

printf( "Done in %.1fs\n", microtime( true ) - $started );
exit( 0 );
