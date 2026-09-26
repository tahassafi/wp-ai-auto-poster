<?php
/**
 * WP Auto Poster — configuration resolver.
 *
 * No secret is ever written into a source file. Values are resolved in this
 * order, first hit wins:
 *
 *   1. A PHP constant already defined (wp-config.php is the usual home).
 *   2. A real environment variable (getenv / $_ENV / $_SERVER).
 *   3. A .env file sitting beside the plugin folder or at the WordPress root.
 *   4. The default passed by the caller.
 *
 * Constants-in-wp-config is the most common deployment on shared hosting,
 * where you cannot set process environment variables for a cPanel cron job.
 * The .env path exists so the same codebase runs unchanged in Docker or CI.
 *
 * @package WPAutoPoster
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPAP_CLI' ) ) {
	exit;
}

/**
 * Parses a .env file once and caches it.
 *
 * Deliberately minimal: KEY=value, one per line, # comments, optional
 * surrounding quotes. No interpolation, no multiline, no export syntax —
 * anything more and you should be using a real secrets manager.
 *
 * @return array<string,string>
 */
function wpap_env_file() {
	static $vars = null;
	if ( null !== $vars ) {
		return $vars;
	}
	$vars = array();

	$candidates = array();
	if ( defined( 'WPAP_ENV_FILE' ) ) {
		$candidates[] = WPAP_ENV_FILE;
	}
	$candidates[] = dirname( __DIR__ ) . '/.env';          // inside the plugin folder
	$candidates[] = dirname( __DIR__, 2 ) . '/.env';       // wp-content/plugins/
	if ( defined( 'ABSPATH' ) ) {
		$candidates[] = rtrim( ABSPATH, '/\\' ) . '/.env'; // WordPress root
		$candidates[] = dirname( rtrim( ABSPATH, '/\\' ) ) . '/.env';
	}

	foreach ( $candidates as $file ) {
		if ( ! $file || ! is_readable( $file ) || ! is_file( $file ) ) {
			continue;
		}
		foreach ( (array) file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $k, $v ) = explode( '=', $line, 2 );
			$k = trim( $k );
			$v = trim( $v );
			if ( strlen( $v ) > 1 && ( ( '"' === $v[0] && '"' === substr( $v, -1 ) ) || ( "'" === $v[0] && "'" === substr( $v, -1 ) ) ) ) {
				$v = substr( $v, 1, -1 );
			}
			if ( '' !== $k && ! isset( $vars[ $k ] ) ) {
				$vars[ $k ] = $v;
			}
		}
		break; // first readable file wins
	}

	return $vars;
}

/**
 * Resolves one configuration value.
 *
 * @param string $key     e.g. 'WPAP_API_KEY'
 * @param mixed  $default Returned when nothing else supplies a value.
 * @return mixed
 */
function wpap_env( $key, $default = null ) {
	if ( defined( $key ) ) {
		return constant( $key );
	}

	$raw = getenv( $key );
	if ( false === $raw || '' === $raw ) {
		if ( isset( $_ENV[ $key ] ) ) {
			$raw = $_ENV[ $key ];
		} elseif ( isset( $_SERVER[ $key ] ) ) {
			$raw = $_SERVER[ $key ];
		} else {
			$file = wpap_env_file();
			$raw  = isset( $file[ $key ] ) ? $file[ $key ] : false;
		}
	}

	if ( false === $raw || '' === $raw ) {
		return $default;
	}

	return wpap_env_cast( $raw, $default );
}

/**
 * Environment variables are always strings. Coerce to the default's type so
 * WPAP_MIN_WORDS comes back as an int and WPAP_REQUIRE_IN_STOCK as a bool.
 */
function wpap_env_cast( $raw, $default ) {
	if ( is_bool( $default ) ) {
		return in_array( strtolower( trim( (string) $raw ) ), array( '1', 'true', 'yes', 'on' ), true );
	}
	if ( is_int( $default ) ) {
		return (int) $raw;
	}
	if ( is_float( $default ) ) {
		return (float) $raw;
	}
	return $raw;
}

/**
 * Defines a constant from the resolved value, unless something already did.
 */
function wpap_define( $key, $default = null ) {
	if ( ! defined( $key ) ) {
		define( $key, wpap_env( $key, $default ) );
	}
}
