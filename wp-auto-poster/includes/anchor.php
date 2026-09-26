<?php
/**
 * WP Auto Poster — anchor selection.
 *
 * Every article is anchored to one real, published WooCommerce product.
 * The product is chosen HERE, in code — never by the writing model.
 * The model only receives the product it must write around.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Words that carry no discriminating power on this catalogue.
 */
function wpap_stopwords() {
	return array_flip( array(
		'the','and','for','with','from','your','you','our','are','was','not','all','any','can','get',
		'best','top','buy','shop','store','sale','sales','price','prices','cheap','near','nearby','me',
		'dubai','uae','emirates','abu','dhabi','sharjah','online','new','used','genuine','original',
		'luxury','premium','exotic','high','end','quality','parts','part','accessory','accessories',
		'auto','autos','automotive','car','cars','vehicle','vehicles','spare','spares','supplier',
		'suppliers','seller','sellers','dealer','dealers','showroom','showrooms','shops','stores',
		'centre','center','service','services','guide','how','what','where','which','why','when',
		'brand','brands','multi','sourcing','source','inside','find','finding','choosing','choose',
		'tips','need','know','buying','purchase','upgrade','upgrades','replacement','replacements',
		'2024','2025','2026','uaes','dubais','in','of','to','a','an','on','at','by','or','vs',
	) );
}

function wpap_tokenise( $text ) {
	$text = strtolower( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
	$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
	$out  = array();
	foreach ( preg_split( '/\s+/', trim( $text ) ) as $w ) {
		if ( '' !== $w ) {
			$out[] = $w;
		}
	}
	return $out;
}

/**
 * Loads every published product once, with its taxonomy terms and image.
 * Cached for the duration of the request.
 */
function wpap_load_catalogue() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}
	global $wpdb;

	$rows = $wpdb->get_results(
		"SELECT p.ID, p.post_title, p.post_name, p.post_excerpt
		 FROM {$wpdb->posts} p
		 WHERE p.post_type = 'product' AND p.post_status = 'publish'",
		ARRAY_A
	);
	if ( ! $rows ) {
		return $cache = array();
	}

	$ids = wp_list_pluck( $rows, 'ID' );
	$in  = implode( ',', array_map( 'intval', $ids ) );

	// Featured images in one query.
	$thumbs = array();
	foreach ( (array) $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_thumbnail_id' AND post_id IN ($in)", ARRAY_A ) as $m ) {
		$thumbs[ (int) $m['post_id'] ] = (int) $m['meta_value'];
	}

	// Stock status in one query. WooCommerce keeps it in _stock_status on the
	// product, and mirrors it into wc_product_meta_lookup; the postmeta is the
	// authority, so that is what we read.
	$stock = array();
	foreach ( (array) $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_stock_status' AND post_id IN ($in)", ARRAY_A ) as $m ) {
		$stock[ (int) $m['post_id'] ] = strtolower( trim( $m['meta_value'] ) );
	}

	// Products hidden from the catalogue should never be linked either.
	$hidden = array();
	foreach ( (array) $wpdb->get_results(
		"SELECT tr.object_id
		 FROM {$wpdb->term_relationships} tr
		 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		 WHERE tr.object_id IN ($in)
		   AND tt.taxonomy = 'product_visibility'
		   AND t.slug IN ('exclude-from-catalog','outofstock')", ARRAY_A ) as $h ) {
		$hidden[ (int) $h['object_id'] ] = true;
	}

	// Taxonomy terms in one query.
	$terms = array();
	foreach ( (array) $wpdb->get_results(
		"SELECT tr.object_id, tt.taxonomy, t.name, t.slug
		 FROM {$wpdb->term_relationships} tr
		 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		 WHERE tr.object_id IN ($in)
		   AND tt.taxonomy IN ('product_cat','product_brand','pa_model','product_tag')", ARRAY_A ) as $t ) {
		$terms[ (int) $t['object_id'] ][ $t['taxonomy'] ][] = array(
			'name' => html_entity_decode( $t['name'], ENT_QUOTES, 'UTF-8' ),
			'slug' => $t['slug'],
		);
	}

	$catalogue = array();
	foreach ( $rows as $r ) {
		$id = (int) $r['ID'];

		if ( empty( $thumbs[ $id ] ) ) {
			continue; // no image, cannot anchor
		}

		if ( WPAP_REQUIRE_IN_STOCK ) {
			// Missing _stock_status means the product was never given one; WooCommerce
			// treats that as in stock, and so do we.
			$status = isset( $stock[ $id ] ) ? $stock[ $id ] : 'instock';

			$in_stock = ( 'instock' === $status )
				|| ( 'onbackorder' === $status && WPAP_ALLOW_BACKORDER );

			if ( ! $in_stock || isset( $hidden[ $id ] ) ) {
				continue;
			}
		}

		$tx = isset( $terms[ $id ] ) ? $terms[ $id ] : array();
		$catalogue[ $id ] = array(
			'id'        => $id,
			'title'     => html_entity_decode( $r['post_title'], ENT_QUOTES, 'UTF-8' ),
			'slug'      => $r['post_name'],
			'excerpt'   => wp_strip_all_tags( (string) $r['post_excerpt'] ),
			'thumb_id'  => (int) $thumbs[ $id ],
			'cats'      => isset( $tx['product_cat'] ) ? $tx['product_cat'] : array(),
			'brands'    => isset( $tx['product_brand'] ) ? $tx['product_brand'] : array(),
			'models'    => isset( $tx['pa_model'] ) ? $tx['pa_model'] : array(),
			'url'       => '',   // filled in on demand — 442 get_permalink() calls would be wasteful
		);
	}
	return $cache = $catalogue;
}

/**
 * How many products are actually eligible to anchor an article, and how many
 * were filtered out. Used by the Status screen.
 *
 * @return array array( pool, published, excluded )
 */
function wpap_anchor_pool_stats() {
	global $wpdb;
	$published = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
	);
	$pool = count( wpap_load_catalogue() );
	return array(
		'pool'      => $pool,
		'published' => $published,
		'excluded'  => max( 0, $published - $pool ),
	);
}

/**
 * Resolves a catalogue entry's permalink, once, only for the handful we use.
 */
function wpap_hydrate_url( $p ) {
	if ( empty( $p['url'] ) ) {
		$link     = get_permalink( $p['id'] );
		$p['url'] = $link ? $link : home_url( '/product/' . $p['slug'] . '/' );
	}
	return $p;
}

/**
 * Brands and models used by the last N published/drafted articles.
 *
 * Rotation by product_id alone is not enough: three different Cybertruck parts
 * are still three Cybertruck articles. This gives the scorer a set of brands and
 * models to steer away from.
 *
 * @return array array( 'brands' => array( slug => true ), 'models' => array( slug => true ) )
 */
function wpap_recent_anchor_terms( $window = null ) {
	static $cache = array();

	$window = (int) ( $window ? $window : WPAP_VARIETY_WINDOW );
	if ( isset( $cache[ $window ] ) ) {
		return $cache[ $window ];
	}

	$out = array( 'brands' => array(), 'models' => array(), 'cats' => array() );
	if ( $window < 1 ) {
		return $cache[ $window ] = $out;
	}

	global $wpdb;
	$table = wpap_table( 'keywords' );

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT product_id FROM $table
			 WHERE status = 'used' AND product_id IS NOT NULL AND product_id > 0
			 ORDER BY used_at DESC, id DESC LIMIT %d",
			$window
		)
	);
	if ( ! $ids ) {
		return $cache[ $window ] = $out;
	}

	$in = implode( ',', array_map( 'intval', $ids ) );

	// Read the terms straight from the DB rather than the catalogue, so a product
	// that has since gone out of stock still counts against variety.
	foreach ( (array) $wpdb->get_results(
		"SELECT tt.taxonomy, t.slug
		 FROM {$wpdb->term_relationships} tr
		 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
		 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
		 WHERE tr.object_id IN ($in)
		   AND tt.taxonomy IN ('product_brand','pa_model','product_cat')", ARRAY_A ) as $r ) {
		$map = array( 'product_brand' => 'brands', 'pa_model' => 'models', 'product_cat' => 'cats' );
		if ( isset( $map[ $r['taxonomy'] ] ) ) {
			$out[ $map[ $r['taxonomy'] ] ][ $r['slug'] ] = true;
		}
	}

	return $cache[ $window ] = $out;
}

/**
 * Scores every product against the keyword and returns them sorted best-first.
 *
 * Weighting: brand > model > category > title word > title bigram.
 * Recently-featured products are demoted so the same part is not reused until
 * the alternatives have cycled through.
 *
 * @return array list of array('product' => ..., 'score' => float)
 */
function wpap_score_products( $keyword ) {
	$catalogue = wpap_load_catalogue();
	if ( ! $catalogue ) {
		return array();
	}

	$stop   = wpap_stopwords();
	$tokens = wpap_tokenise( $keyword );

	$strong = array(); // discriminating words
	foreach ( $tokens as $t ) {
		if ( strlen( $t ) >= 3 && ! isset( $stop[ $t ] ) ) {
			$strong[ $t ] = true;
		}
	}

	// Bigrams from the raw keyword, used for phrase hits like "front bumper".
	$bigrams = array();
	for ( $i = 0, $n = count( $tokens ) - 1; $i < $n; $i++ ) {
		$bigrams[ $tokens[ $i ] . ' ' . $tokens[ $i + 1 ] ] = true;
	}

	$usage  = wpap_anchor_usage_map();
	$now    = current_time( 'timestamp' );
	$scored = array();
	unset( $now );

	$recent_terms = wpap_recent_anchor_terms();

	foreach ( $catalogue as $id => $p ) {
		$score    = 0.0;
		$explicit = false;   // the keyword itself names this brand or model

		foreach ( $p['brands'] as $term ) {
			foreach ( wpap_tokenise( $term['name'] ) as $w ) {
				if ( isset( $strong[ $w ] ) ) { $score += 6; $explicit = true; }
			}
		}
		foreach ( $p['models'] as $term ) {
			foreach ( wpap_tokenise( $term['name'] ) as $w ) {
				if ( isset( $strong[ $w ] ) ) { $score += 5; $explicit = true; }
			}
		}
		foreach ( $p['cats'] as $term ) {
			foreach ( wpap_tokenise( $term['name'] ) as $w ) {
				if ( isset( $strong[ $w ] ) ) { $score += 4; }
			}
		}

		$title_tokens = wpap_tokenise( $p['title'] );
		$seen         = array();
		foreach ( $title_tokens as $w ) {
			if ( isset( $strong[ $w ] ) && ! isset( $seen[ $w ] ) ) {
				$score += 2;
				$seen[ $w ] = true;
			}
		}
		for ( $i = 0, $n = count( $title_tokens ) - 1; $i < $n; $i++ ) {
			if ( isset( $bigrams[ $title_tokens[ $i ] . ' ' . $title_tokens[ $i + 1 ] ] ) ) {
				$score += 3;
			}
		}

		// Same brand or model as a recent article? Demote — unless the keyword
		// explicitly asked for that brand or model.
		$repeat = false;
		if ( ! $explicit ) {
			foreach ( $p['brands'] as $term ) {
				if ( isset( $recent_terms['brands'][ $term['slug'] ] ) ) { $repeat = true; break; }
			}
			if ( ! $repeat ) {
				foreach ( $p['models'] as $term ) {
					if ( isset( $recent_terms['models'][ $term['slug'] ] ) ) { $repeat = true; break; }
				}
			}
		}

		$uses = isset( $usage[ $id ]['uses'] ) ? (int) $usage[ $id ]['uses'] : 0;

		// A part that has anchored an article before goes to the back and stays
		// there until every other eligible part has had a turn. A time window is
		// not enough: it would let a part return while hundreds are still unused.
		$used_before = ( $uses > 0 );

		// Soft variety on the part TYPE, so two Rolls-Royce keywords do not both
		// land on a front bumper. Applied after relevance, never instead of it.
		$cat_repeat = false;
		foreach ( $p['cats'] as $term ) {
			if ( isset( $recent_terms['cats'][ $term['slug'] ] ) ) { $cat_repeat = true; break; }
		}

		$scored[] = array(
			'product'    => $p,
			'score'      => $score,
			'last_used'  => isset( $usage[ $id ]['last_used'] ) ? $usage[ $id ]['last_used'] : 0,
			'uses'       => $uses,
			'cooling'    => $used_before,
			'repeat'     => $repeat,
			'cat_repeat' => $cat_repeat,
			'demote'     => ( $used_before || $repeat ),
		);
	}

	// Rank: real word match first; within that, never-used before recently-used.
	usort( $scored, function ( $a, $b ) {
		if ( $a['demote'] !== $b['demote'] ) {
			return $a['demote'] ? 1 : -1;   // recently used product, brand or model goes last
		}
		if ( $a['score'] !== $b['score'] ) {
			return ( $a['score'] < $b['score'] ) ? 1 : -1;
		}
		if ( $a['cat_repeat'] !== $b['cat_repeat'] ) {
			return $a['cat_repeat'] ? 1 : -1;   // vary the part type too
		}
		if ( $a['uses'] !== $b['uses'] ) {
			return ( $a['uses'] > $b['uses'] ) ? 1 : -1;
		}
		if ( $a['last_used'] !== $b['last_used'] ) {
			return ( $a['last_used'] > $b['last_used'] ) ? 1 : -1;
		}
		return 0;
	} );

	return $scored;
}

/**
 * Picks the anchor product for a keyword.
 *
 * 1. Keyword scoring against titles, brands, models and categories.
 * 2. If nothing scores, a cheap claude-haiku-4-5 call picks the semantically
 *    closest product from a numbered list of least-recently-featured items.
 * 3. If that also fails, the least-recently-featured product wins.
 *
 * @return array|WP_Error array('product'=>..., 'how'=>'score|ai|rotation')
 */
function wpap_pick_anchor( $keyword ) {
	$scored = wpap_score_products( $keyword );
	if ( ! $scored ) {
		return new WP_Error(
			'no_products',
			WPAP_REQUIRE_IN_STOCK
				? 'No in-stock published products with a featured image were found. Either everything is out of stock, or set WPAP_REQUIRE_IN_STOCK to false in config.php.'
				: 'No published products with a featured image were found.'
		);
	}

	if ( $scored[0]['score'] > 0 ) {
		return array( 'product' => wpap_hydrate_url( $scored[0]['product'] ), 'how' => 'score' );
	}

	// No word overlap at all — generic keyword. Ask the cheap model.
	$pool = array();
	foreach ( $scored as $row ) {
		$pool[] = $row['product'];
		if ( count( $pool ) >= 60 ) {
			break;
		}
	}

	// Shuffle the presentation order. The pool itself is already the
	// least-recently-featured slice; without this the model sees an identical
	// list every run and keeps landing on the same item near the top.
	shuffle( $pool );

	$list = '';
	foreach ( $pool as $i => $p ) {
		$bits = array();
		foreach ( array( 'brands', 'models', 'cats' ) as $k ) {
			foreach ( $p[ $k ] as $t ) {
				$bits[] = $t['name'];
			}
		}
		$list .= ( $i + 1 ) . '. ' . $p['title']
			. ( $bits ? '  [' . implode( ', ', array_unique( $bits ) ) . ']' : '' ) . "\n";
	}

	// Name the recently covered brands and models so the cheap model steers away too.
	$recent_terms = wpap_recent_anchor_terms();
	$avoid        = array_merge( array_keys( $recent_terms['brands'] ), array_keys( $recent_terms['models'] ) );
	$avoid_line   = $avoid
		? "The blog has just covered these recently, so AVOID them unless the topic clearly demands one: "
			. implode( ', ', array_map( function ( $x ) { return str_replace( '-', ' ', $x ); }, $avoid ) ) . ".\n\n"
		: '';

	$material = wpap_site_material();

	$prompt = "A blog article is being written for {$material['name']}"
		. " ({$material['tagline']}) on this topic:\n\n"
		. "TOPIC: {$keyword}\n\n"
		. $avoid_line
		. "Below is a numbered list of products in the shop. Choose the ONE product that a reader "
		. "of that article would most plausibly want to look at. Prefer variety across vehicle "
		. "brands and part categories.\n\n"
		. $list . "\n"
		. "Reply with the number only. No words, no punctuation, no explanation.";

	$reply = wpap_anthropic_call(
		WPAP_MODEL_CHEAP,
		$prompt,
		'You select the single most relevant catalogue item. You reply with one integer and nothing else.',
		16
	);

	if ( ! is_wp_error( $reply ) && preg_match( '/\d+/', $reply, $m ) ) {
		$idx = (int) $m[0] - 1;
		if ( isset( $pool[ $idx ] ) ) {
			return array( 'product' => wpap_hydrate_url( $pool[ $idx ] ), 'how' => 'ai' );
		}
	}

	return array( 'product' => wpap_hydrate_url( $scored[0]['product'] ), 'how' => 'rotation' );
}

/**
 * Related products from the same category/brand, for context in the prompt.
 */
function wpap_related_products( $anchor, $limit = 6 ) {
	$catalogue  = wpap_load_catalogue();
	$want_cat   = wp_list_pluck( $anchor['cats'], 'slug' );
	$want_brand = wp_list_pluck( $anchor['brands'], 'slug' );
	$picked     = array();

	// Pass 1: same category AND same brand. Pass 2: same category only.
	foreach ( array( 'both', 'cat' ) as $pass ) {
		foreach ( $catalogue as $p ) {
			if ( $p['id'] === $anchor['id'] || isset( $picked[ $p['id'] ] ) ) {
				continue;
			}
			$share_cat = (bool) array_intersect( $want_cat, wp_list_pluck( $p['cats'], 'slug' ) );
			if ( ! $share_cat ) {
				continue;
			}
			if ( 'both' === $pass
				&& ! array_intersect( $want_brand, wp_list_pluck( $p['brands'], 'slug' ) ) ) {
				continue;
			}
			$picked[ $p['id'] ] = wpap_hydrate_url( $p );
			if ( count( $picked ) >= $limit ) {
				break 2;
			}
		}
	}
	return array_values( $picked );
}

/**
 * The archive URL the keyword phrase itself should link to in paragraph one:
 * the anchor product's category archive, falling back to the brand archive,
 * falling back to the shop page.
 */
function wpap_keyword_link_target( $anchor ) {
	if ( ! empty( $anchor['cats'][0]['slug'] ) ) {
		$link = get_term_link( $anchor['cats'][0]['slug'], 'product_cat' );
		if ( ! is_wp_error( $link ) ) {
			return array( 'url' => $link, 'label' => $anchor['cats'][0]['name'] . ' parts' );
		}
	}
	if ( ! empty( $anchor['brands'][0]['slug'] ) ) {
		$link = get_term_link( $anchor['brands'][0]['slug'], 'product_brand' );
		if ( ! is_wp_error( $link ) ) {
			return array( 'url' => $link, 'label' => $anchor['brands'][0]['name'] . ' parts' );
		}
	}
	return array( 'url' => home_url( '/shop/' ), 'label' => 'our shop' );
}
