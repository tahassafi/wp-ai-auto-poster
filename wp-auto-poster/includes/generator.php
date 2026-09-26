<?php
/**
 * WP Auto Poster — Anthropic call, delimiter parsing, and the code-level guards.
 *
 * The model is never trusted. Everything it returns is repaired or rejected here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =========================================================================
 * 1. Anthropic transport
 * ====================================================================== */

/**
 * One Messages API call. Returns the concatenated text blocks, or WP_Error.
 *
 * @param string $model
 * @param string|array $user      String, or an array of role/content turns.
 * @param string $system
 * @param int    $max_tokens
 */
function wpap_anthropic_call( $model, $user, $system = '', $max_tokens = null ) {

	if ( ! WPAP_API_KEY ) {
		return new WP_Error(
			'no_key',
			'WPAP_API_KEY is not set. Define it in wp-config.php, as an environment variable, or in .env.'
		);
	}

	$messages = is_array( $user )
		? $user
		: array( array( 'role' => 'user', 'content' => (string) $user ) );

	$body = array(
		'model'      => $model,
		'max_tokens' => (int) ( $max_tokens ? $max_tokens : WPAP_MAX_TOKENS ),
		'messages'   => $messages,
	);
	if ( '' !== $system ) {
		$body['system'] = $system;
	}

	$ch = curl_init( 'https://api.anthropic.com/v1/messages' );
	curl_setopt_array( $ch, array(
		CURLOPT_POST           => true,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => WPAP_TIMEOUT,
		CURLOPT_CONNECTTIMEOUT => 20,
		CURLOPT_HTTPHEADER     => array(
			'Content-Type: application/json',
			'x-api-key: ' . WPAP_API_KEY,
			'anthropic-version: 2023-06-01',
		),
		CURLOPT_POSTFIELDS     => wp_json_encode( $body ),
	) );

	$raw  = curl_exec( $ch );
	$err  = curl_error( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );

	if ( false === $raw ) {
		return new WP_Error( 'curl', 'cURL error: ' . $err );
	}

	$json = json_decode( $raw, true );

	if ( 200 !== $code ) {
		$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : substr( (string) $raw, 0, 500 );
		return new WP_Error( 'api_' . $code, 'Anthropic API HTTP ' . $code . ': ' . $msg );
	}
	if ( ! isset( $json['content'] ) || ! is_array( $json['content'] ) ) {
		return new WP_Error( 'api_shape', 'Unexpected API response: ' . substr( (string) $raw, 0, 500 ) );
	}

	$text = '';
	foreach ( $json['content'] as $block ) {
		if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
			$text .= $block['text'];
		}
	}

	if ( isset( $json['stop_reason'] ) && 'max_tokens' === $json['stop_reason'] ) {
		return new WP_Error( 'truncated', 'The model hit max_tokens and the article was cut off. Raise WPAP_MAX_TOKENS or shorten the target length.' );
	}

	return $text;
}

/* =========================================================================
 * 2. Prompt
 * ====================================================================== */

function wpap_system_prompt() {
	$m = wpap_site_material();
	return "You are the senior SEO content writer for {$m['name']} ({$m['tagline']}), based in {$m['location']}.\n\n"
		. "ABOUT THE BUSINESS\n{$m['about']}\n\n"
		. "PARTS PROVENANCE\n{$m['oem']}\n\n"
		. "HOUSE STYLE\n{$m['style']}\n\n"
		. "You output articles in a strict delimiter-section format. You never output JSON. "
		. "You never wrap your output in markdown code fences. You never add commentary before "
		. "or after the sections.";
}

/**
 * Builds the user prompt for one article.
 *
 * @param string $keyword
 * @param array  $ctx  anchor, keyword_target, main_pages, related, recent_titles
 */
function wpap_build_prompt( $keyword, $ctx ) {
	$m       = wpap_site_material();
	$anchor  = $ctx['anchor'];
	$target  = $ctx['keyword_target'];

	$anchor_bits = array();
	foreach ( array( 'brands' => 'Brand', 'models' => 'Model', 'cats' => 'Category' ) as $k => $label ) {
		$names = wp_list_pluck( $anchor[ $k ], 'name' );
		if ( $names ) {
			$anchor_bits[] = $label . ': ' . implode( ', ', $names );
		}
	}

	$anchor_bits_joined = implode( "\n", $anchor_bits );
	$anchor_short       = wpap_short_product_name( $anchor['title'] );
	$oem                = $m['oem'];
	$link_words         = (int) WPAP_LINK_WORDS;

	$related = '';
	foreach ( $ctx['related'] as $p ) {
		$related .= "- {$p['title']} — {$p['url']}\n";
	}

	$mains = '';
	foreach ( $ctx['main_pages'] as $p ) {
		$mains .= "- {$p['label']} — {$p['url']}\n";
	}

	$manus = '';
	foreach ( $m['manufacturers'] as $d ) {
		$manus .= "- https://{$d}/\n";
	}

	$recent = '';
	foreach ( $ctx['recent_titles'] as $t ) {
		$recent .= "- {$t}\n";
	}

	$min    = WPAP_MIN_WORDS;
	$target_words = WPAP_TARGET_WORDS;
	$minh2  = WPAP_MIN_H2;
	$maxh2  = WPAP_MAX_H2;
	$slugw  = WPAP_SLUG_MAX_WORDS;
	$slugc  = WPAP_SLUG_MAX_CHARS;

	return <<<PROMPT
Write ONE blog article for {$m['name']}.

TARGET KEYWORD (use this exact phrase): {$keyword}

THE PRODUCT THIS ARTICLE IS BUILT AROUND (chosen for you — write around it, do not substitute it):
Title: {$anchor['title']}
{$anchor_bits_joined}
URL: {$anchor['url']}
Use this SHORT NAME whenever you link or refer to it: {$anchor_short}

RELATED PRODUCTS YOU MAY MENTION (optional):
{$related}
MAIN PAGES:
{$mains}
CATEGORY / ARCHIVE PAGE FOR THE KEYWORD LINK:
{$target['url']}

OFFICIAL MANUFACTURER SITES — the ONLY external domains you are permitted to link:
{$manus}
HEADLINES ALREADY PUBLISHED ON THIS BLOG — your headline and angle must be clearly different from every one of these:
{$recent}

==================== HARD RULES ====================

LENGTH
- Minimum {$min} words of body copy. Aim for {$min}–{$target_words}. Shorter output is rejected.

HEADLINE
- The headline MUST contain the exact phrase "{$keyword}".
- It must not duplicate or lightly reword any headline in the list above.

PARAGRAPH 1
- Must contain the exact phrase "{$keyword}".
- That phrase must be a hyperlink, and the link must be ON the keyword phrase itself:
  <a href="{$target['url']}">{$keyword}</a>
- Do not link anything else in paragraph 1.

PARAGRAPH 2
- Must contain a link to ONE of the main pages listed above, using natural anchor text.

PARAGRAPH 3
- Must contain a link to the product page above:
  <a href="{$anchor['url']}">{$anchor_short}</a>
- ANCHOR TEXT LENGTH: never more than {$link_words} words inside any <a>…</a>.
  Never paste a full catalogue title, part number, colour code or "OEM" into link text.
  Write "the {$anchor_short}", not the long title. This applies to EVERY link in
  the article, internal and external.

STRUCTURE
- Between {$minh2} and {$maxh2} <h2> sections. No <h1>. <h3> is allowed inside a section.
- Plain HTML only: <p>, <h2>, <h3>, <ul>, <ol>, <li>, <strong>, <em>, <a>. No classes, no ids,
  no inline styles, no <div>, no <img>, no markdown.

IMAGE
- Write the literal marker {{IMG1}} on its own line, immediately after the FIRST <h2> section's
  first paragraph. Do not write any <img> tag — the marker is replaced with the real image by the site.
  Use the marker exactly once.

EXTERNAL LINKS
- Include 1 or 2 external links, and ONLY to the official manufacturer domains listed above.
- Every external link must be a full absolute URL beginning with https://
- Every external link must carry rel="nofollow noopener" target="_blank"
- Never link to a competitor, marketplace, forum, blog, news site, Wikipedia, or any other domain.

PARTS PROVENANCE — NON-NEGOTIABLE
{$oem}

FACTUAL DISCIPLINE
- No prices. No invented statistics, dates, awards, certifications or customer quotes.
- Do not claim stock levels or delivery times.

==================== OUTPUT FORMAT ====================

Output EXACTLY these six sections, in this order, each delimiter on its own line.
Nothing before ===TITLE=== and nothing after the content.

===TITLE===
The headline, plain text, no HTML, no surrounding quotes.

===SLUG===
A URL slug. Lowercase, hyphen-separated, maximum {$slugw} words and {$slugc} characters.
No stop words padding, no year, no site name.

===EXCERPT===
One or two sentences, plain text, 140–220 characters.

===SEO_TITLE===
Maximum 60 characters, plain text. Must contain the keyword.

===SEO_DESCRIPTION===
140–160 characters, plain text. Must contain the keyword. No quotes.

===CONTENT===
The full article body as HTML, starting with the first <p>.
PROMPT;
}

/* =========================================================================
 * 3. Delimiter parsing
 * ====================================================================== */

function wpap_parse_sections( $raw ) {
	$raw = (string) $raw;

	// Strip any stray markdown fences.
	$raw = preg_replace( '/^\s*```[a-z]*\s*$/mi', '', $raw );

	$keys  = array( 'TITLE', 'SLUG', 'EXCERPT', 'SEO_TITLE', 'SEO_DESCRIPTION', 'CONTENT' );
	$out   = array();
	$found = array();

	foreach ( $keys as $k ) {
		if ( preg_match( '/^[ \t]*={2,}[ \t]*' . $k . '[ \t]*={2,}[ \t]*$/mi', $raw, $m, PREG_OFFSET_CAPTURE ) ) {
			$found[ $k ] = array(
				'start' => $m[0][1] + strlen( $m[0][0] ),
				'head'  => $m[0][1],
			);
		}
	}

	$missing = array_diff( $keys, array_keys( $found ) );
	if ( $missing ) {
		return new WP_Error( 'sections', 'Missing section(s): ' . implode( ', ', $missing ) );
	}

	// Order the headings by position so we can slice between them.
	$positions = $found;
	uasort( $positions, function ( $a, $b ) { return $a['head'] - $b['head']; } );
	$ordered = array_keys( $positions );

	foreach ( $ordered as $i => $k ) {
		$start = $found[ $k ]['start'];
		$end   = isset( $ordered[ $i + 1 ] ) ? $found[ $ordered[ $i + 1 ] ]['head'] : strlen( $raw );
		$out[ strtolower( $k ) ] = trim( substr( $raw, $start, $end - $start ) );
	}

	foreach ( array( 'title', 'slug', 'excerpt', 'seo_title', 'seo_description' ) as $k ) {
		$out[ $k ] = trim( wp_strip_all_tags( $out[ $k ] ), " \t\n\r\"'" );
	}

	if ( '' === $out['title'] || '' === $out['content'] ) {
		return new WP_Error( 'sections', 'TITLE or CONTENT came back empty.' );
	}

	return $out;
}

/* =========================================================================
 * 4. Guards — link repair, image, slug
 * ====================================================================== */

function wpap_host_of( $url ) {
	$h = wp_parse_url( $url, PHP_URL_HOST );
	return $h ? strtolower( preg_replace( '/^www\./', '', $h ) ) : '';
}

/**
 * Repairs every <a> in the article.
 *
 * - bare domain.com          -> https://domain.com
 * - a domain the client insists on (see 'domain_fixes' in site-profile.php)
 * - external, manufacturer   -> rel="nofollow noopener" target="_blank"
 * - external, anything else  -> link removed, text kept
 * - internal, not on the approved list -> link removed, text kept
 * - more than 2 external     -> extras unwrapped
 *
 * @param string $html
 * @param array  $allowed_internal  Absolute internal URLs the model was given.
 * @return array array( html, stats )
 */
function wpap_repair_links( $html, $allowed_internal ) {
	$m         = wpap_site_material();
	$site_host = wpap_host_of( home_url() );
	$whitelist = array();
	foreach ( $m['manufacturers'] as $d ) {
		$whitelist[ strtolower( preg_replace( '/^www\./', '', $d ) ) ] = true;
	}

	// Normalise the approved internal set for comparison (ignore trailing slash).
	$internal_ok = array();
	foreach ( (array) $allowed_internal as $u ) {
		$internal_ok[ untrailingslashit( strtolower( $u ) ) ] = true;
	}

	$stats = array( 'external' => 0, 'internal' => 0, 'stripped' => 0, 'fixed_scheme' => 0 );

	$html = preg_replace_callback(
		'#<a\b([^>]*)>(.*?)</a>#is',
		function ( $mm ) use ( $m, $site_host, $whitelist, $internal_ok, &$stats ) {
			$attrs = $mm[1];
			$text  = $mm[2];

			if ( ! preg_match( '/\bhref\s*=\s*("|\')(.*?)\1/i', $attrs, $h ) ) {
				$stats['stripped']++;
				return $text;
			}
			$href = trim( html_entity_decode( $h[2], ENT_QUOTES, 'UTF-8' ) );

			// Kill anything dangerous or empty outright.
			if ( '' === $href || preg_match( '#^(javascript|data|vbscript):#i', $href ) || '#' === $href ) {
				$stats['stripped']++;
				return $text;
			}

			// Forced domain corrections (Onyx Concept .com -> .ae, etc).
			foreach ( $m['domain_fixes'] as $bad => $good ) {
				$href = preg_replace( '#(^|//)' . preg_quote( $bad, '#' ) . '#i', '$1' . $good, $href );
			}

			// Protocol-relative.
			if ( 0 === strpos( $href, '//' ) ) {
				$href = 'https:' . $href;
				$stats['fixed_scheme']++;
			}

			// Root-relative -> absolute on our own host.
			if ( 0 === strpos( $href, '/' ) ) {
				$href = home_url( $href );
			} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
				// Schemeless bare domain, e.g. example.com/path or example.com
				if ( preg_match( '#^[a-z0-9][a-z0-9\-\.]*\.[a-z]{2,}(/|$|\?|\#)#i', $href ) ) {
					$href = 'https://' . ltrim( $href, '/' );
					$stats['fixed_scheme']++;
				} else {
					$stats['stripped']++;
					return $text;
				}
			}

			// http -> https for our own domain and for the whitelist.
			$host = wpap_host_of( $href );
			if ( 0 === strpos( strtolower( $href ), 'http://' ) ) {
				$href = 'https://' . substr( $href, 7 );
				$stats['fixed_scheme']++;
			}

			if ( $host === $site_host ) {
				if ( ! isset( $internal_ok[ untrailingslashit( strtolower( $href ) ) ] ) ) {
					$stats['stripped']++;   // internal page we never approved — probably a 404
					return $text;
				}
				$stats['internal']++;
				return '<a href="' . esc_url( $href ) . '">' . $text . '</a>';
			}

			if ( ! isset( $whitelist[ $host ] ) ) {
				$stats['stripped']++;
				return $text;
			}

			if ( $stats['external'] >= 2 ) {
				$stats['stripped']++;
				return $text;
			}

			$stats['external']++;
			return '<a href="' . esc_url( $href ) . '" rel="nofollow noopener" target="_blank">' . $text . '</a>';
		},
		$html
	);

	return array( $html, $stats );
}

/**
 * Makes sure paragraph one carries the keyword as a hyperlink to the archive.
 *
 * @return array array( html, ok_bool )
 */
function wpap_enforce_keyword_link( $html, $keyword, $target_url ) {
	if ( ! preg_match( '#<p\b[^>]*>(.*?)</p>#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return array( $html, false );
	}
	$whole  = $m[0][0];
	$offset = $m[0][1];
	$inner  = $m[1][0];

	$plain = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' );
	if ( false === mb_stripos( $plain, $keyword ) ) {
		return array( $html, false );  // keyword genuinely absent — caller retries/fails
	}

	// Already linked on the phrase? Just correct the href.
	$fixed = preg_replace_callback(
		'#<a\b([^>]*)>(.*?)</a>#is',
		function ( $a ) use ( $keyword, $target_url ) {
			$txt = html_entity_decode( wp_strip_all_tags( $a[2] ), ENT_QUOTES, 'UTF-8' );
			if ( false !== mb_stripos( $txt, $keyword ) || false !== mb_stripos( $keyword, $txt ) ) {
				return '<a href="' . esc_url( $target_url ) . '">' . $a[2] . '</a>';
			}
			return $a[0];
		},
		$inner
	);

	if ( false === stripos( $fixed, '<a ' ) ) {
		// Not linked at all — wrap the first literal occurrence.
		$done = false;
		$fixed   = preg_replace_callback(
			'/' . preg_quote( $keyword, '/' ) . '/iu',
			function ( $k ) use ( $target_url, &$done ) {
				if ( $done ) {
					return $k[0];
				}
				$done = true;
				return '<a href="' . esc_url( $target_url ) . '">' . $k[0] . '</a>';
			},
			$fixed,
			1
		);
		if ( ! $done ) {
			return array( $html, false );
		}
	}

	$new_p = str_replace( $inner, $fixed, $whole );
	$html  = substr_replace( $html, $new_p, $offset, strlen( $whole ) );
	return array( $html, true );
}

/**
 * Swaps {{IMG1}} for the anchor product's real media-library image.
 * If the model forgot the marker, the image is inserted after the first <h2> block.
 */
function wpap_insert_image( $html, $anchor, $keyword ) {
	$att_id = (int) $anchor['thumb_id'];
	$src    = wp_get_attachment_image_src( $att_id, 'large' );
	if ( ! $src ) {
		$src = wp_get_attachment_image_src( $att_id, 'full' );
	}
	if ( ! $src ) {
		return str_replace( '{{IMG1}}', '', $html );
	}

	list( $url, $w, $h ) = $src;

	// The <img> sits inside a link to the product, so its alt IS the anchor text.
	// Keep it to the same word limit as every other product link.
	$short   = wpap_short_product_name( $anchor['title'] );
	$alt     = $short;
	$material = wpap_site_material();
	$caption = $short . ' — ' . $material['name'] . '.';

	$srcset = wp_get_attachment_image_srcset( $att_id, 'large' );
	$sizes  = wp_get_attachment_image_sizes( $att_id, 'large' );

	$img = '<img src="' . esc_url( $url ) . '"'
		. ' width="' . (int) $w . '" height="' . (int) $h . '"'
		. ' alt="' . esc_attr( $alt ) . '"'
		. ' loading="lazy" decoding="async"'
		. ( $srcset ? ' srcset="' . esc_attr( $srcset ) . '"' : '' )
		. ( $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' )
		. ' class="wp-image-' . $att_id . '" />';

	$figure = "\n<figure class=\"wp-block-image size-large\">"
		. '<a href="' . esc_url( $anchor['url'] ) . '">' . $img . '</a>'
		. '<figcaption>' . esc_html( $caption ) . '</figcaption>'
		. "</figure>\n";

	if ( false !== strpos( $html, '{{IMG1}}' ) ) {
		// Replace the first marker, delete any extras.
		$html = preg_replace( '/\{\{\s*IMG1\s*\}\}/', $figure, $html, 1 );
		$html = preg_replace( '/\{\{\s*IMG\d*\s*\}\}/', '', $html );
		// Marker often lands inside its own <p> — unwrap that.
		$html = preg_replace( '#<p>\s*(' . preg_quote( $figure, '#' ) . ')\s*</p>#is', '$1', $html );
		return $html;
	}

	// Fallback: after the first paragraph that follows the first <h2>.
	if ( preg_match( '#</h2>\s*<p\b[^>]*>.*?</p>#is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		$at = $m[0][1] + strlen( $m[0][0] );
		return substr_replace( $html, $figure, $at, 0 );
	}

	return $html . $figure;
}

/**
 * Forces a short slug: max N words, max N chars, unique on the site.
 */
function wpap_force_slug( $slug, $title ) {
	$slug = sanitize_title( $slug ? $slug : $title );
	if ( '' === $slug ) {
		$slug = sanitize_title( $title );
	}

	$stop  = array_flip( array( 'a','an','the','and','or','of','for','to','in','on','at','with','your','you','our','is','are','how','why','what','best','top' ) );
	$words = array_values( array_filter( explode( '-', $slug ) ) );

	$kept = array();
	foreach ( $words as $w ) {
		if ( count( $kept ) >= WPAP_SLUG_MAX_WORDS ) {
			break;
		}
		if ( isset( $stop[ $w ] ) && count( $kept ) > 0 ) {
			continue;
		}
		if ( isset( $stop[ $w ] ) && count( $kept ) === 0 ) {
			continue;
		}
		$kept[] = $w;
	}
	if ( ! $kept ) {
		$kept = array_slice( $words, 0, WPAP_SLUG_MAX_WORDS );
	}

	$slug = implode( '-', $kept );
	while ( strlen( $slug ) > WPAP_SLUG_MAX_CHARS && count( $kept ) > 2 ) {
		array_pop( $kept );
		$slug = implode( '-', $kept );
	}
	$slug = substr( $slug, 0, WPAP_SLUG_MAX_CHARS );
	return trim( $slug, '-' );
}

/**
 * Turns a long catalogue title into short, readable link text.
 *
 *  "Bentley Continental GTC Right Side Rocker Panel | OEM 3SA853852 | Dark Sapphire"
 *    -> "Bentley Continental Right Side Rocker Panel"
 *  "Continental GTC 21 OEM Factory Wheels Set Silver OEM 3SA 601 025"
 *    -> "Continental GTC 21 Factory Wheels"
 *
 * Order of operations: un-hyphenate slug-style titles, cut at the first
 * separator, drop OEM/Part filler and long part numbers, drop trailing
 * part-number fragments, drop the trailing colour/finish run, and only then
 * trim to the word limit keeping brand + model at the front and the part
 * itself at the back.
 */
function wpap_short_product_name( $title, $max_words = null ) {
	$max = (int) ( $max_words ? $max_words : WPAP_LINK_WORDS );

	$t = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
	$t = str_replace( array( "\u{201C}", "\u{201D}", "\u{2033}", "\u{2019}", "\u{00A0}" ), array( '', '', '"', "'", ' ' ), $t );

	// Slug-style titles ("Continental-GTC-Rear-Left-Quarter-Panel-Chrome-OEM").
	if ( substr_count( $t, ' ' ) <= 2 && substr_count( $t, '-' ) >= 3 ) {
		$t = str_replace( '-', ' ', $t );
	}

	// Everything after the first separator is catalogue metadata, not a name.
	$parts = preg_split( '/\s*[|(\x{2013}\x{2014}\x{2012}]\s*/u', $t, 2 );
	if ( ! empty( $parts[0] ) && count( wpap_words( $parts[0] ) ) >= 3 ) {
		$t = $parts[0];
	}

	$filler = array_flip( array(
		'oem', 'part', 'parts', 'genuine', 'original', 'new', 'set', 'sets', 'pcs', 'pc',
		'lh', 'rh', 'for', 'with', 'the', '+', '&', 'and', 'x',
	) );

	// Trailing finish/colour words, dropped as a whole run only when over length.
	$finish = array_flip( array(
		'silver', 'black', 'white', 'blue', 'red', 'green', 'grey', 'gray', 'chrome',
		'sapphire', 'jetstream', 'dark', 'light', 'gloss', 'glossy', 'matte', 'matt',
		'beluga', 'onyx', 'magma', 'bronze', 'gold', 'anthracite', 'titanium', 'satin',
	) );

	$kept = array();
	foreach ( wpap_words( $t ) as $w ) {
		$bare = trim( $w, ".,;:/\\\"'" );
		if ( '' === $bare ) {
			continue;
		}
		if ( isset( $filler[ strtolower( $bare ) ] ) ) {
			continue;
		}
		// Long part numbers: 4+ chars mixing letters and digits, or 4+ bare digits.
		// Real model names survive: G63, 812, 458, 21.
		if ( preg_match( '/^[A-Za-z0-9\-\/]{4,}$/', $bare )
			&& preg_match( '/[0-9]/', $bare )
			&& ( preg_match( '/[A-Za-z]/', $bare ) || strlen( preg_replace( '/[^0-9]/', '', $bare ) ) >= 4 ) ) {
			continue;
		}
		$kept[] = $bare;
	}

	if ( ! $kept ) {
		$kept = wpap_words( $t );
	}

	// Part numbers split across tokens ("3SA 601 025"). Pop from the end only,
	// never from the first two positions, so "Ferrari 812" keeps its 812.
	while ( count( $kept ) > 3 ) {
		$last = $kept[ count( $kept ) - 1 ];
		$is_fragment = preg_match( '/^[0-9]{2,}$/', $last )
			|| ( preg_match( '/^[0-9]/', $last ) && preg_match( '/[A-Za-z]/', $last ) );
		if ( ! $is_fragment ) {
			break;
		}
		array_pop( $kept );
	}

	// Drop the trailing colour/finish run in one go, so we never end on "Dark".
	if ( count( $kept ) > $max ) {
		$run = 0;
		for ( $i = count( $kept ) - 1; $i >= 0; $i-- ) {
			if ( isset( $finish[ strtolower( $kept[ $i ] ) ] ) ) {
				$run++;
			} else {
				break;
			}
		}
		if ( $run > 0 && ( count( $kept ) - $run ) >= 3 ) {
			$kept = array_slice( $kept, 0, count( $kept ) - $run );
		}
	}

	// Still long: keep brand + model at the front and the part noun at the back.
	if ( count( $kept ) > $max ) {
		$merged = array_merge( array_slice( $kept, 0, 2 ), array_slice( $kept, -1 * max( 1, $max - 2 ) ) );
		$seen   = array();
		$kept   = array();
		foreach ( $merged as $w ) {
			$k = strtolower( $w );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$kept[]     = $w;
		}
	}

	$name = trim( implode( ' ', array_slice( $kept, 0, $max ) ), " -+&,\x{2013}\x{2014}" );
	return '' !== $name ? $name : wp_strip_all_tags( $title );
}

function wpap_words( $text ) {
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	return '' === $text ? array() : explode( ' ', $text );
}

/**
 * Last-line guard: no link on the page may carry more than the word limit as
 * anchor text. Product links are relabelled with their short name; any other
 * over-long anchor is simply truncated.
 *
 * @param string $html
 * @param array  $short_by_url  absolute product URL => short name
 * @return array array( html, replaced_count )
 */
function wpap_enforce_anchor_text( $html, $short_by_url, $exempt_urls = array() ) {
	$max     = (int) WPAP_LINK_WORDS;
	$lookup  = array();
	foreach ( (array) $short_by_url as $url => $name ) {
		$lookup[ untrailingslashit( strtolower( $url ) ) ] = $name;
	}
	$exempt = array();
	foreach ( (array) $exempt_urls as $u ) {
		$exempt[ untrailingslashit( strtolower( $u ) ) ] = true;
	}
	$replaced = 0;

	$html = preg_replace_callback(
		'#<a\b([^>]*)>(.*?)</a>#is',
		function ( $m ) use ( $lookup, $exempt, $max, &$replaced ) {
			$attrs = $m[1];
			$inner = $m[2];

			// Never touch an image link — its text is the alt attribute.
			if ( false !== stripos( $inner, '<img' ) ) {
				return $m[0];
			}

			$plain = html_entity_decode( wp_strip_all_tags( $inner ), ENT_QUOTES, 'UTF-8' );
			$words = wpap_words( $plain );
			if ( count( $words ) <= $max ) {
				return $m[0];
			}

			if ( preg_match( '/\bhref\s*=\s*("|\')(.*?)\1/i', $attrs, $h ) ) {
				$key = untrailingslashit( strtolower( html_entity_decode( $h[2], ENT_QUOTES, 'UTF-8' ) ) );
				if ( isset( $exempt[ $key ] ) ) {
					return $m[0];   // the keyword link — its text must stay the exact phrase
				}
				if ( isset( $lookup[ $key ] ) ) {
					$replaced++;
					return '<a' . $attrs . '>' . esc_html( $lookup[ $key ] ) . '</a>';
				}
			}

			$replaced++;
			return '<a' . $attrs . '>' . esc_html( implode( ' ', array_slice( $words, 0, $max ) ) ) . '</a>';
		},
		$html
	);

	return array( $html, $replaced );
}

function wpap_word_count( $html ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', $text );
	return str_word_count( $text, 0, '0123456789-' );
}

/**
 * Every published post title on the site, lowercased and normalised.
 */
function wpap_existing_titles() {
	global $wpdb;
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	$rows  = $wpdb->get_col(
		"SELECT post_title FROM {$wpdb->posts}
		 WHERE post_type = 'post' AND post_status IN ('publish','future','draft','pending','private')"
	);
	$cache = array();
	foreach ( (array) $rows as $t ) {
		$cache[ wpap_title_key( $t ) ] = true;
	}
	return $cache;
}

function wpap_title_key( $title ) {
	$t = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
	$t = strtolower( $t );
	$t = preg_replace( '/[^a-z0-9]+/', ' ', $t );
	return trim( preg_replace( '/\s+/', ' ', $t ) );
}

function wpap_recent_titles( $limit ) {
	global $wpdb;
	return (array) $wpdb->get_col(
		$wpdb->prepare(
			"SELECT post_title FROM {$wpdb->posts}
			 WHERE post_type = 'post'
			   AND post_status IN ('publish','future','draft','pending','private')
			 ORDER BY post_modified DESC LIMIT %d",
			(int) $limit
		)
	);
}

/**
 * Strips attributes and tags the article is not allowed to carry.
 */
function wpap_sanitise_html( $html ) {
	$allowed = array(
		'p'      => array(),
		'h2'     => array(),
		'h3'     => array(),
		'ul'     => array(),
		'ol'     => array(),
		'li'     => array(),
		'strong' => array(),
		'b'      => array(),
		'em'     => array(),
		'i'      => array(),
		'br'     => array(),
		'blockquote' => array(),
		'a'      => array( 'href' => array(), 'rel' => array(), 'target' => array(), 'title' => array() ),
	);
	// Demote any h1 before wp_kses strips the tag and loses the heading.
	$html = preg_replace( '#<h1\b[^>]*>(.*?)</h1>#is', '<h2>$1</h2>', (string) $html );
	// h4+ are not in the article spec; fold them into h3.
	$html = preg_replace( '#<(/?)h[4-6]\b[^>]*>#i', '<$1h3>', $html );

	$html = wp_kses( $html, $allowed );
	return trim( $html );
}
