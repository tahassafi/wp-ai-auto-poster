<?php
/**
 * WP Auto Poster — the run itself.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consumes exactly one pending keyword and publishes exactly one article.
 *
 * @return array array( ok => bool, message => string, post_id => int|null, keyword => string|null )
 */
function wpap_run_once() {

	$kw_row = wpap_claim_keyword();
	if ( ! $kw_row ) {
		wpap_log( 'Nothing to do — the keyword queue is empty.', 'info' );
		return array( 'ok' => true, 'message' => 'Queue empty — nothing published.', 'post_id' => null, 'keyword' => null );
	}

	$keyword = $kw_row->keyword;
	$kid     = (int) $kw_row->id;
	wpap_log( 'Run started for keyword: ' . $keyword, 'info', $kid );

	try {
		$result = wpap_generate_and_publish( $keyword, $kid );
	} catch ( Exception $e ) {
		wpap_mark_keyword_failed( $kid, 'Exception: ' . $e->getMessage() );
		wpap_log( 'Exception: ' . $e->getMessage(), 'error', $kid );
		return array( 'ok' => false, 'message' => $e->getMessage(), 'post_id' => null, 'keyword' => $keyword );
	}

	if ( is_wp_error( $result ) ) {
		wpap_mark_keyword_failed( $kid, $result->get_error_message() );
		wpap_log( 'FAILED: ' . $result->get_error_message(), 'error', $kid );
		return array( 'ok' => false, 'message' => $result->get_error_message(), 'post_id' => null, 'keyword' => $keyword );
	}

	wpap_mark_keyword_used( $kid, $result['post_id'], $result['product_id'] );
	wpap_record_anchor_use( $result['product_id'] );
	wpap_log(
		'Published "' . $result['title'] . '" (' . $result['words'] . ' words), anchored to product #'
		. $result['product_id'] . ' via ' . $result['anchor_how'] . '.',
		'success', $kid, $result['post_id']
	);
	$cov = wpap_cover_stats();
	if ( $cov['pool'] && $cov['unused'] <= WPAP_COVER_LOW_WARN ) {
		wpap_log(
			'Cover images running low: ' . $cov['unused'] . ' of ' . $cov['pool']
			. ' still unused. Add more to wp-content/uploads/' . WPAP_COVER_DIR
			. '/ or photos will start repeating.',
			'warn'
		);
	}
	wpap_prune_log();

	return array(
		'ok'      => true,
		'message' => 'Published: ' . $result['title'],
		'post_id' => $result['post_id'],
		'keyword' => $keyword,
		'url'     => get_permalink( $result['post_id'] ),
	);
}

/**
 * The generate → guard → validate → publish pipeline for one keyword.
 *
 * @return array|WP_Error
 */
function wpap_generate_and_publish( $keyword, $kid = null ) {

	/* ---- 1. Anchor, chosen in code ---------------------------------- */

	$pick = wpap_pick_anchor( $keyword );
	if ( is_wp_error( $pick ) ) {
		return $pick;
	}
	$anchor = $pick['product'];
	wpap_log( 'Anchor product #' . $anchor['id'] . ' "' . $anchor['title'] . '" (' . $pick['how'] . ')', 'info', $kid );

	$material   = wpap_site_material();
	$kw_target  = wpap_keyword_link_target( $anchor );
	$related    = wpap_related_products( $anchor, 6 );

	$main_pages = array();
	foreach ( $material['main_pages'] as $mp ) {
		$main_pages[] = array( 'label' => $mp['label'], 'url' => home_url( $mp['url'] ) );
	}

	// Short link text for every product URL the model is allowed to link.
	$short_by_url = array( $anchor['url'] => wpap_short_product_name( $anchor['title'] ) );
	foreach ( $related as $r ) {
		$short_by_url[ $r['url'] ] = wpap_short_product_name( $r['title'] );
	}

	$allowed_internal = array( $anchor['url'], $kw_target['url'] );
	foreach ( $main_pages as $mp ) {
		$allowed_internal[] = $mp['url'];
	}
	foreach ( $related as $r ) {
		$allowed_internal[] = $r['url'];
	}

	$ctx = array(
		'anchor'         => $anchor,
		'keyword_target' => $kw_target,
		'related'        => $related,
		'main_pages'     => $main_pages,
		'recent_titles'  => wpap_recent_titles( WPAP_RECENT_TITLES ),
	);

	/* ---- 2. Generate, with one corrective retry ---------------------- */

	$messages = array(
		array( 'role' => 'user', 'content' => wpap_build_prompt( $keyword, $ctx ) ),
	);

	$parsed   = null;
	$problems = array();

	for ( $attempt = 1; $attempt <= 2; $attempt++ ) {

		$raw = wpap_anthropic_call( WPAP_MODEL, $messages, wpap_system_prompt() );
		if ( is_wp_error( $raw ) ) {
			return $raw;
		}

		$sections = wpap_parse_sections( $raw );
		if ( is_wp_error( $sections ) ) {
			if ( 2 === $attempt ) {
				return $sections;
			}
			$messages[] = array( 'role' => 'assistant', 'content' => $raw );
			$messages[] = array( 'role' => 'user', 'content' => 'Your output did not parse: '
				. $sections->get_error_message()
				. ' Re-send the COMPLETE article using exactly the six ===SECTION=== delimiters, nothing else.' );
			continue;
		}

		$check = wpap_prepare_article( $sections, $keyword, $anchor, $kw_target, $allowed_internal, $short_by_url );

		if ( ! is_wp_error( $check ) ) {
			$parsed = $check;
			break;
		}

		$problems[] = $check->get_error_message();

		// Hard stops — a retry cannot help.
		if ( in_array( $check->get_error_code(), array( 'duplicate_title' ), true ) ) {
			return $check;
		}

		if ( 2 === $attempt ) {
			return new WP_Error( 'validation', 'Rejected after 2 attempts: ' . implode( ' | ', $problems ) );
		}

		wpap_log( 'Attempt 1 rejected: ' . $check->get_error_message() . ' — retrying.', 'warn', $kid );

		$messages[] = array( 'role' => 'assistant', 'content' => $raw );
		$messages[] = array( 'role' => 'user', 'content' =>
			'That draft was rejected for this reason: ' . $check->get_error_message()
			. "\n\nFix it and re-send the COMPLETE article again in the same six ===SECTION=== format. "
			. 'Do not apologise, do not explain, output only the sections.' );
	}

	if ( ! $parsed ) {
		return new WP_Error( 'validation', 'Rejected: ' . implode( ' | ', $problems ) );
	}

	/* ---- 3. Publish -------------------------------------------------- */

	$post_id = wp_insert_post( array(
		'post_title'    => $parsed['title'],
		'post_name'     => $parsed['slug'],
		'post_content'  => $parsed['content'],
		'post_excerpt'  => $parsed['excerpt'],
		'post_status'   => WPAP_POST_STATUS,
		'post_author'   => (int) WPAP_POST_AUTHOR,
		'post_type'     => 'post',
		'comment_status'=> 'closed',
		'ping_status'   => 'closed',
		'post_category' => array( (int) WPAP_POST_CATEGORY ),
	), true );

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	// Featured image: a photo from the cover pool, named after this article.
	// The product image stays in the body as {{IMG1}} — never both.
	$cover_id = wpap_attach_cover( $post_id, $parsed['slug'], $parsed['title'] );
	if ( $cover_id ) {
		set_post_thumbnail( $post_id, $cover_id );
		update_post_meta( $post_id, '_wpap_cover_id', $cover_id );
	} else {
		// No cover folder, or nothing usable in it — fall back to the product image.
		set_post_thumbnail( $post_id, (int) $anchor['thumb_id'] );
		wpap_log( 'No cover image available; used the product image instead.', 'warn', $kid, $post_id );
	}

	update_post_meta( $post_id, '_wpap_keyword', $keyword );
	update_post_meta( $post_id, '_wpap_keyword_id', (int) $kid );
	update_post_meta( $post_id, '_wpap_product_id', (int) $anchor['id'] );
	update_post_meta( $post_id, '_wpap_anchor_how', $pick['how'] );
	update_post_meta( $post_id, '_wpap_generated_at', current_time( 'mysql' ) );
	update_post_meta( $post_id, '_wpap_words', (int) $parsed['words'] );

	wpap_write_aioseo( $post_id, $parsed['seo_title'], $parsed['seo_description'], $keyword );

	if ( 'publish' === WPAP_POST_STATUS ) {
		wpap_ping_indexnow( get_permalink( $post_id ) );
	}

	return array(
		'post_id'    => $post_id,
		'product_id' => (int) $anchor['id'],
		'anchor_how' => $pick['how'],
		'title'      => $parsed['title'],
		'words'      => $parsed['words'],
		'cover_id'   => $cover_id,
	);
}

/**
 * Runs every guard and every validation rule over the parsed sections.
 *
 * @return array|WP_Error
 */
function wpap_prepare_article( $s, $keyword, $anchor, $kw_target, $allowed_internal, $short_by_url = array() ) {

	$title = trim( $s['title'] );

	// -- headline must carry the keyword ---------------------------------
	$title_key = wpap_title_key( $title );
	$kw_key    = wpap_title_key( $keyword );
	if ( false === strpos( $title_key, $kw_key ) ) {
		return new WP_Error( 'keyword_title', 'The headline does not contain the exact keyword phrase "' . $keyword . '".' );
	}

	// -- exact duplicate headline is fatal --------------------------------
	$existing = wpap_existing_titles();
	if ( isset( $existing[ $title_key ] ) ) {
		return new WP_Error( 'duplicate_title', 'A post with the headline "' . $title . '" already exists. Nothing was published.' );
	}

	// -- clean, then repair links ----------------------------------------
	$content = wpap_sanitise_html( $s['content'] );

	list( $content, $stats ) = wpap_repair_links( $content, $allowed_internal );

	list( $content, $kw_ok ) = wpap_enforce_keyword_link( $content, $keyword, $kw_target['url'] );
	if ( ! $kw_ok ) {
		return new WP_Error( 'keyword_p1', 'The first paragraph does not contain the keyword phrase "' . $keyword . '", so it could not be hyperlinked.' );
	}

	// Anchor text must stay short — catalogue titles here run to 12+ words with
	// part numbers and colour codes. Runs AFTER the keyword link so the keyword
	// phrase itself is never trimmed.
	list( $content, $shortened ) = wpap_enforce_anchor_text( $content, $short_by_url, array( $kw_target['url'] ) );
	$stats['shortened_anchors']  = $shortened;

	// -- structure --------------------------------------------------------
	$h2 = preg_match_all( '#<h2\b[^>]*>#i', $content );
	if ( $h2 < WPAP_MIN_H2 || $h2 > WPAP_MAX_H2 ) {
		return new WP_Error( 'h2_count', 'The article has ' . $h2 . ' h2 sections; it must have between '
			. WPAP_MIN_H2 . ' and ' . WPAP_MAX_H2 . '.' );
	}

	// -- the anchor product link must survive -----------------------------
	if ( false === strpos( $content, esc_url( $anchor['url'] ) ) && false === strpos( $content, $anchor['url'] ) ) {
		// inject it into the third paragraph rather than fail
		$content = wpap_inject_product_link( $content, $anchor );
	}

	// -- at least one external manufacturer link --------------------------
	if ( $stats['external'] < 1 ) {
		return new WP_Error( 'external_links', 'The article has no valid link to an official manufacturer site (all external links were to domains that are not on the approved list).' );
	}

	// -- length ------------------------------------------------------------
	$words = wpap_word_count( $content );
	if ( $words < WPAP_MIN_WORDS ) {
		return new WP_Error( 'too_short', 'The article is ' . $words . ' words; the minimum is ' . WPAP_MIN_WORDS . '.' );
	}

	// -- image (last, so wp_kses never sees it) ---------------------------
	$content = wpap_insert_image( $content, $anchor, $keyword );

	// -- slug ---------------------------------------------------------------
	$slug = wpap_force_slug( $s['slug'], $title );

	// -- SEO fields ---------------------------------------------------------
	$seo_title = trim( $s['seo_title'] );
	if ( '' === $seo_title ) {
		$seo_title = $title;
	}
	if ( mb_strlen( $seo_title ) > 60 ) {
		$seo_title = rtrim( mb_substr( $seo_title, 0, 60 ), " \t-–—|," );
	}

	$seo_desc = trim( preg_replace( '/\s+/u', ' ', $s['seo_description'] ) );
	if ( mb_strlen( $seo_desc ) > 160 ) {
		$seo_desc = rtrim( mb_substr( $seo_desc, 0, 157 ), ' ,;:-' ) . '…';
	}

	$excerpt = trim( preg_replace( '/\s+/u', ' ', $s['excerpt'] ) );
	if ( '' === $excerpt ) {
		$excerpt = $seo_desc;
	}

	return array(
		'title'           => $title,
		'slug'            => $slug,
		'content'         => $content,
		'excerpt'         => $excerpt,
		'seo_title'       => $seo_title,
		'seo_description' => $seo_desc,
		'words'           => $words,
		'link_stats'      => $stats,
	);
}

/**
 * Last-resort insertion of the product deep link into paragraph three.
 */
function wpap_inject_product_link( $content, $anchor ) {
	if ( ! preg_match_all( '#<p\b[^>]*>.*?</p>#is', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return $content;
	}
	$idx = isset( $m[0][2] ) ? 2 : ( isset( $m[0][1] ) ? 1 : 0 );
	$p   = $m[0][ $idx ][0];
	$at  = $m[0][ $idx ][1];

	$sentence = ' You can see the exact part here: <a href="' . esc_url( $anchor['url'] ) . '">'
		. esc_html( wpap_short_product_name( $anchor['title'] ) ) . '</a>.';

	$new = preg_replace( '#</p>$#i', $sentence . '</p>', $p );
	return substr_replace( $content, $new, $at, strlen( $p ) );
}

/* =========================================================================
 * All in One SEO
 * ====================================================================== */

/**
 * Writes the SEO title / description / focus keyword so AIOSEO picks them up.
 *
 * Writes the aioseo_posts row (what AIOSEO 4.x actually renders from) and the
 * legacy postmeta keys, so the values also show in the editor sidebar.
 */
function wpap_write_aioseo( $post_id, $seo_title, $seo_desc, $keyword ) {
	global $wpdb;

	$post_id = (int) $post_id;

	update_post_meta( $post_id, '_aioseo_title', $seo_title );
	update_post_meta( $post_id, '_aioseo_description', $seo_desc );
	update_post_meta( $post_id, '_aioseo_focus_keyword', $keyword );

	$table = $wpdb->prefix . 'aioseo_posts';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return; // AIOSEO not installed
	}

	// Only touch columns this install actually has.
	$cols = array();
	foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM $table", ARRAY_A ) as $c ) {
		$cols[ $c['Field'] ] = true;
	}

	$now  = current_time( 'mysql' );
	$data = array( 'post_id' => $post_id );

	if ( isset( $cols['title'] ) )         { $data['title'] = $seo_title; }
	if ( isset( $cols['description'] ) )   { $data['description'] = $seo_desc; }
	if ( isset( $cols['focus_keyword'] ) ) { $data['focus_keyword'] = mb_substr( $keyword, 0, 255 ); }
	if ( isset( $cols['keyphrases'] ) ) {
		$data['keyphrases'] = wp_json_encode( array(
			'focus'      => array( 'keyphrase' => $keyword, 'score' => 0, 'analysis' => array() ),
			'additional' => array(),
		) );
	}
	if ( isset( $cols['robots_default'] ) ) { $data['robots_default'] = 1; }
	if ( isset( $cols['updated'] ) )        { $data['updated'] = $now; }

	$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE post_id = %d", $post_id ) );

	if ( $existing ) {
		$wpdb->update( $table, $data, array( 'id' => (int) $existing ) );
	} else {
		if ( isset( $cols['created'] ) ) {
			$data['created'] = $now;
		}
		$wpdb->insert( $table, $data );
	}

	// Keep AIOSEO's cached output from serving the old values.
	if ( function_exists( 'aioseo' ) ) {
		$aioseo = aioseo();
		if ( isset( $aioseo->core ) && method_exists( $aioseo->core, 'cache' ) ) {
			$cache = $aioseo->core->cache();
			if ( is_object( $cache ) && method_exists( $cache, 'clear' ) ) {
				$cache->clear();
			}
		}
	}
}

/* =========================================================================
 * IndexNow
 * ====================================================================== */

function wpap_ping_indexnow( $url ) {
	if ( ! WPAP_INDEXNOW_KEY || ! $url ) {
		return;
	}
	$host     = wp_parse_url( home_url(), PHP_URL_HOST );
	$endpoint = add_query_arg( array(
		'url' => rawurlencode( $url ),
		'key' => WPAP_INDEXNOW_KEY,
	), 'https://api.indexnow.org/indexnow' );

	wp_remote_get( $endpoint, array( 'timeout' => 10, 'blocking' => false ) );
	unset( $host );
}

/* =========================================================================
 * Cover images
 *
 * The featured image comes from a pool of photos dropped into
 * wp-content/uploads/<WPAP_COVER_DIR>/ by FTP or cPanel File Manager.
 * Files put there are NOT in the Media Library — WordPress only knows about
 * files that have an attachment record — so each run copies one out, renames
 * it to the article slug, and registers it properly.
 *
 * The product image stays where it is, as the in-content {{IMG1}}.
 * ====================================================================== */

function wpap_cover_dir() {
	$up  = wp_upload_dir();
	$sub = trim( (string) WPAP_COVER_DIR, '/\\' );
	return array(
		'path' => trailingslashit( $up['basedir'] ) . $sub,
		'sub'  => $sub,
	);
}

/**
 * Every usable image sitting in the cover folder, sorted by name.
 */
function wpap_cover_files() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$dir = wpap_cover_dir();
	if ( ! is_dir( $dir['path'] ) ) {
		return $cache = array();
	}

	$allowed = array( 'webp', 'jpg', 'jpeg', 'png' );
	$out     = array();

	foreach ( (array) scandir( $dir['path'] ) as $f ) {
		if ( '' === $f || '.' === $f[0] ) {
			continue;
		}
		$ext = strtolower( pathinfo( $f, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, $allowed, true ) && is_file( $dir['path'] . '/' . $f ) ) {
			$out[] = $f;
		}
	}
	sort( $out, SORT_NATURAL | SORT_FLAG_CASE );
	return $cache = $out;
}

function wpap_cover_usage() {
	return (array) get_option( 'wpap_cover_usage', array() );
}

/**
 * pool, unused, used — for the Status and Queue screens.
 */
function wpap_cover_stats() {
	$files = wpap_cover_files();
	$usage = wpap_cover_usage();
	$unused = 0;
	foreach ( $files as $f ) {
		if ( empty( $usage[ $f ]['uses'] ) ) {
			$unused++;
		}
	}
	return array(
		'pool'   => count( $files ),
		'unused' => $unused,
		'used'   => count( $files ) - $unused,
	);
}

/**
 * Chooses the next cover: never-used first, then least-used, then
 * longest-ago. Returns the filename, or '' when the folder is empty.
 */
function wpap_next_cover_file() {
	$files = wpap_cover_files();
	if ( ! $files ) {
		return '';
	}

	$usage = wpap_cover_usage();
	$rows  = array();
	foreach ( $files as $i => $f ) {
		$rows[] = array(
			'file'  => $f,
			'uses'  => isset( $usage[ $f ]['uses'] ) ? (int) $usage[ $f ]['uses'] : 0,
			'last'  => isset( $usage[ $f ]['last'] ) ? (int) $usage[ $f ]['last'] : 0,
			'order' => $i,
		);
	}

	usort( $rows, function ( $a, $b ) {
		if ( $a['uses'] !== $b['uses'] ) {
			return ( $a['uses'] > $b['uses'] ) ? 1 : -1;
		}
		if ( $a['last'] !== $b['last'] ) {
			return ( $a['last'] > $b['last'] ) ? 1 : -1;
		}
		return ( $a['order'] > $b['order'] ) ? 1 : -1;
	} );

	return $rows[0]['file'];
}

function wpap_record_cover_use( $file ) {
	$usage = wpap_cover_usage();
	$usage[ $file ] = array(
		'uses' => isset( $usage[ $file ]['uses'] ) ? (int) $usage[ $file ]['uses'] + 1 : 1,
		'last' => time(),
	);
	update_option( 'wpap_cover_usage', $usage, false );
}

/**
 * Copies the next cover into the Media Library under the article's own slug
 * and returns its attachment ID, or 0 if no cover could be used.
 *
 * The source file is copied, never moved, so the pool stays intact and a
 * reused photo still gets its own filename, URL and alt text.
 */
function wpap_attach_cover( $post_id, $slug, $alt ) {
	$file = wpap_next_cover_file();
	if ( ! $file ) {
		return 0;
	}

	$dir = wpap_cover_dir();
	$src = $dir['path'] . '/' . $file;
	if ( ! is_readable( $src ) ) {
		wpap_log( 'Cover file is not readable: ' . $file, 'warn', null, $post_id );
		return 0;
	}

	$ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
	$base = sanitize_title( $slug );
	if ( '' === $base ) {
		$base = 'cover-' . $post_id;
	}

	// Land it in the normal year/month folder so it behaves like any upload.
	$up = wp_upload_dir();
	if ( ! empty( $up['error'] ) ) {
		wpap_log( 'Uploads directory is not writable: ' . $up['error'], 'error', null, $post_id );
		return 0;
	}

	$name = wp_unique_filename( $up['path'], $base . '.' . $ext );
	$dest = trailingslashit( $up['path'] ) . $name;

	if ( ! @copy( $src, $dest ) ) {
		wpap_log( 'Could not copy cover into the uploads folder: ' . $file, 'error', null, $post_id );
		return 0;
	}
	@chmod( $dest, 0644 );

	$type = wp_check_filetype( $dest );
	if ( empty( $type['type'] ) ) {
		@unlink( $dest );
		wpap_log( 'Cover has a file type WordPress will not accept: ' . $file, 'error', null, $post_id );
		return 0;
	}

	$att_id = wp_insert_attachment( array(
		'post_mime_type' => $type['type'],
		'post_title'     => $alt,
		'post_content'   => '',
		'post_excerpt'   => $alt,
		'post_status'    => 'inherit',
	), $dest, $post_id, true );

	if ( is_wp_error( $att_id ) || ! $att_id ) {
		@unlink( $dest );
		wpap_log( 'wp_insert_attachment failed for cover ' . $file, 'error', null, $post_id );
		return 0;
	}

	// Thumbnails / srcset sizes.
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $att_id, wp_generate_attachment_metadata( $att_id, $dest ) );

	update_post_meta( $att_id, '_wp_attachment_image_alt', $alt );
	update_post_meta( $att_id, '_wpap_cover_source', $file );

	wpap_record_cover_use( $file );

	return (int) $att_id;
}
