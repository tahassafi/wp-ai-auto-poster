<?php
/**
 * WP Auto Poster — tables and queue helpers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function wpap_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'wpap_' . $name;
}

/**
 * Creates / upgrades the three custom tables. Safe to call repeatedly.
 */
function wpap_install_tables() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$kw      = wpap_table( 'keywords' );
	$log     = wpap_table( 'log' );
	$anchor  = wpap_table( 'anchors' );

	dbDelta( "CREATE TABLE $kw (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		keyword varchar(255) NOT NULL,
		keyword_hash char(32) NOT NULL,
		status varchar(16) NOT NULL DEFAULT 'pending',
		attempts smallint(5) unsigned NOT NULL DEFAULT 0,
		error text NULL,
		post_id bigint(20) unsigned NULL,
		product_id bigint(20) unsigned NULL,
		added_by bigint(20) unsigned NULL,
		created_at datetime NOT NULL,
		used_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY keyword_hash (keyword_hash),
		KEY status_created (status,created_at)
	) $charset;" );

	dbDelta( "CREATE TABLE $log (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		level varchar(12) NOT NULL DEFAULT 'info',
		keyword_id bigint(20) unsigned NULL,
		post_id bigint(20) unsigned NULL,
		message text NOT NULL,
		PRIMARY KEY  (id),
		KEY created_at (created_at)
	) $charset;" );

	dbDelta( "CREATE TABLE $anchor (
		product_id bigint(20) unsigned NOT NULL,
		last_used_at datetime NULL,
		uses int(10) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (product_id),
		KEY last_used_at (last_used_at)
	) $charset;" );
}

/* -------------------------------------------------------------------------
 * Logging
 * ---------------------------------------------------------------------- */

function wpap_log( $message, $level = 'info', $keyword_id = null, $post_id = null ) {
	global $wpdb;
	$wpdb->insert(
		wpap_table( 'log' ),
		array(
			'created_at' => current_time( 'mysql' ),
			'level'      => $level,
			'keyword_id' => $keyword_id,
			'post_id'    => $post_id,
			'message'    => is_scalar( $message ) ? (string) $message : wp_json_encode( $message ),
		),
		array( '%s', '%s', '%d', '%d', '%s' )
	);
	if ( defined( 'WPAP_CLI' ) && WPAP_CLI ) {
		echo '[' . strtoupper( $level ) . '] ' . $message . "\n";
	}
}

/**
 * Trims the log so it can never grow without bound.
 */
function wpap_prune_log( $keep = 2000 ) {
	global $wpdb;
	$log = wpap_table( 'log' );
	$cut = $wpdb->get_var( "SELECT id FROM $log ORDER BY id DESC LIMIT 1 OFFSET " . (int) $keep );
	if ( $cut ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $log WHERE id < %d", $cut ) );
	}
}

/* -------------------------------------------------------------------------
 * Keyword queue
 * ---------------------------------------------------------------------- */

function wpap_normalise_keyword( $keyword ) {
	$keyword = wp_strip_all_tags( (string) $keyword );
	$keyword = str_replace( array( "\xC2\xA0", '’', '“', '”' ), array( ' ', "'", '"', '"' ), $keyword );
	$keyword = preg_replace( '/\s+/u', ' ', $keyword );
	return trim( $keyword, " \t\n\r\0\x0B-–—•*" );
}

function wpap_keyword_hash( $keyword ) {
	return md5( strtolower( wpap_normalise_keyword( $keyword ) ) );
}

/**
 * Adds a block of pasted keywords, one per line.
 *
 * @return array{added:int,duplicates:int,skipped:int}
 */
function wpap_add_keywords( $raw, $user_id = 0 ) {
	global $wpdb;
	$table  = wpap_table( 'keywords' );
	$lines  = preg_split( '/\r\n|\r|\n/', (string) $raw );
	$result = array( 'added' => 0, 'duplicates' => 0, 'skipped' => 0 );

	foreach ( $lines as $line ) {
		$keyword = wpap_normalise_keyword( $line );
		if ( '' === $keyword || mb_strlen( $keyword ) < 4 ) {
			if ( '' !== trim( $line ) ) {
				$result['skipped']++;
			}
			continue;
		}
		if ( mb_strlen( $keyword ) > 240 ) {
			$keyword = mb_substr( $keyword, 0, 240 );
		}

		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table (keyword, keyword_hash, status, created_at, added_by)
				 VALUES (%s, %s, 'pending', %s, %d)",
				$keyword,
				wpap_keyword_hash( $keyword ),
				current_time( 'mysql' ),
				(int) $user_id
			)
		);
		if ( $ok ) {
			$result['added']++;
		} else {
			$result['duplicates']++;
		}
	}
	return $result;
}

/**
 * Claims the oldest pending keyword. Uses a status flip as a lock so two
 * overlapping runs can never grab the same row.
 */
function wpap_claim_keyword() {
	global $wpdb;
	$table = wpap_table( 'keywords' );

	$row = $wpdb->get_row( "SELECT * FROM $table WHERE status = 'pending' ORDER BY id ASC LIMIT 1" );
	if ( ! $row ) {
		return null;
	}

	$claimed = $wpdb->query(
		$wpdb->prepare(
			"UPDATE $table SET status = 'running', attempts = attempts + 1 WHERE id = %d AND status = 'pending'",
			$row->id
		)
	);
	if ( ! $claimed ) {
		return null; // another run took it
	}

	$row->status   = 'running';
	$row->attempts = (int) $row->attempts + 1;
	return $row;
}

function wpap_mark_keyword_used( $keyword_id, $post_id, $product_id ) {
	global $wpdb;
	$wpdb->update(
		wpap_table( 'keywords' ),
		array(
			'status'     => 'used',
			'post_id'    => (int) $post_id,
			'product_id' => (int) $product_id,
			'used_at'    => current_time( 'mysql' ),
			'error'      => null,
		),
		array( 'id' => (int) $keyword_id ),
		array( '%s', '%d', '%d', '%s', '%s' ),
		array( '%d' )
	);
}

function wpap_mark_keyword_failed( $keyword_id, $error ) {
	global $wpdb;
	$wpdb->update(
		wpap_table( 'keywords' ),
		array(
			'status' => 'failed',
			'error'  => mb_substr( (string) $error, 0, 2000 ),
		),
		array( 'id' => (int) $keyword_id ),
		array( '%s', '%s' ),
		array( '%d' )
	);
}

function wpap_reset_keyword( $keyword_id ) {
	global $wpdb;
	$wpdb->update(
		wpap_table( 'keywords' ),
		array( 'status' => 'pending', 'error' => null, 'attempts' => 0 ),
		array( 'id' => (int) $keyword_id ),
		array( '%s', '%s', '%d' ),
		array( '%d' )
	);
}

function wpap_delete_keyword( $keyword_id ) {
	global $wpdb;
	$wpdb->delete( wpap_table( 'keywords' ), array( 'id' => (int) $keyword_id ), array( '%d' ) );
}

function wpap_queue_counts() {
	global $wpdb;
	$table = wpap_table( 'keywords' );
	$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status" );
	$out   = array( 'pending' => 0, 'running' => 0, 'used' => 0, 'failed' => 0 );
	foreach ( (array) $rows as $r ) {
		$out[ $r->status ] = (int) $r->n;
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Anchor rotation
 * ---------------------------------------------------------------------- */

function wpap_record_anchor_use( $product_id ) {
	global $wpdb;
	$table = wpap_table( 'anchors' );
	$now   = current_time( 'mysql' );
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO $table (product_id, last_used_at, uses) VALUES (%d, %s, 1)
			 ON DUPLICATE KEY UPDATE last_used_at = VALUES(last_used_at), uses = uses + 1",
			(int) $product_id,
			$now
		)
	);
}

/**
 * product_id => array( last_used_at, uses )
 */
function wpap_anchor_usage_map() {
	global $wpdb;
	$table = wpap_table( 'anchors' );
	$rows  = $wpdb->get_results( "SELECT product_id, last_used_at, uses FROM $table", ARRAY_A );
	$map   = array();
	foreach ( (array) $rows as $r ) {
		$map[ (int) $r['product_id'] ] = array(
			'last_used' => $r['last_used_at'] ? strtotime( $r['last_used_at'] ) : 0,
			'uses'      => (int) $r['uses'],
		);
	}
	return $map;
}
