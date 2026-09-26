<?php
/**
 * WP Auto Poster — configuration.
 *
 * Nothing secret lives in this file. Every value below is resolved by
 * wpap_env(): a constant in wp-config.php wins first, then a real environment
 * variable, then a .env file, then the default written here.
 *
 * See .env.example in the repository root for the full variable list.
 *
 * @package WPAutoPoster
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPAP_CLI' ) ) {
	exit;
}

require_once __DIR__ . '/includes/env.php';

/* ---------------------------------------------------------------------------
 * 1. ANTHROPIC API
 * ------------------------------------------------------------------------- */

// REQUIRED. Use a key scoped to this site so spend is attributable per-site.
wpap_define( 'WPAP_API_KEY', '' );

// Writing model.
wpap_define( 'WPAP_MODEL', 'claude-sonnet-5' );

// Cheap model, used only to pick an anchor product when keyword scoring finds
// no word match at all. Kept separate so the fallback path costs almost nothing.
wpap_define( 'WPAP_MODEL_CHEAP', 'claude-haiku-4-5' );

wpap_define( 'WPAP_MAX_TOKENS', 8192 );

// Seconds to wait on the API. A 1,200-word article with a retry can be slow.
wpap_define( 'WPAP_TIMEOUT', 300 );

/* ---------------------------------------------------------------------------
 * 2. CRON SECURITY
 * ------------------------------------------------------------------------- */

// REQUIRED if you ever trigger cron-post.php over HTTP. Passed as ?key=...
// CLI runs (a cPanel cron job) skip the check, since argv is not web-reachable.
wpap_define( 'WPAP_CRON_SECRET', '' );

/* ---------------------------------------------------------------------------
 * 3. PUBLISHING
 * ------------------------------------------------------------------------- */

// WP user ID the posts are authored by.
wpap_define( 'WPAP_POST_AUTHOR', 1 );

// Category term_id the posts are filed under.
wpap_define( 'WPAP_POST_CATEGORY', 1 );

// 'publish' or 'draft'. Use 'draft' when a human reviews before going live.
wpap_define( 'WPAP_POST_STATUS', 'draft' );

// Keywords consumed per run. Keep at 1; the scheduler controls volume.
wpap_define( 'WPAP_PER_RUN', 1 );

/* ---------------------------------------------------------------------------
 * 4. ARTICLE RULES
 * ------------------------------------------------------------------------- */

wpap_define( 'WPAP_MIN_WORDS', 1000 );
wpap_define( 'WPAP_TARGET_WORDS', 1200 );
wpap_define( 'WPAP_MIN_H2', 4 );
wpap_define( 'WPAP_MAX_H2', 6 );

// Only anchor to products that are in stock. Out-of-stock products and
// products hidden from the catalogue are excluded from the pool entirely.
wpap_define( 'WPAP_REQUIRE_IN_STOCK', true );

// Treat "on backorder" as in stock. Off by default — a backorder is not stock.
wpap_define( 'WPAP_ALLOW_BACKORDER', false );

// Maximum words allowed inside any <a>…</a>. Catalogue titles routinely run to
// 12+ words with part numbers; anything longer is replaced with a short name
// generated in code.
wpap_define( 'WPAP_LINK_WORDS', 6 );

wpap_define( 'WPAP_SLUG_MAX_WORDS', 5 );
wpap_define( 'WPAP_SLUG_MAX_CHARS', 60 );

// The brand and model used by the last N articles are pushed to the back of the
// anchor pool, so three articles about the same vehicle cannot run in a row.
// A keyword that names the brand or model explicitly overrides this.
wpap_define( 'WPAP_VARIETY_WINDOW', 6 );

// Deprecated. Product rotation is no longer time-based: a product that has
// anchored an article stays at the back until every other eligible product has
// had a turn. Retained so older configs do not fatal.
wpap_define( 'WPAP_ROTATION_DAYS', 120 );

// Recent headlines shown to the model so it writes something different.
wpap_define( 'WPAP_RECENT_TITLES', 30 );

/* ---------------------------------------------------------------------------
 * 5. COVER IMAGES
 * ------------------------------------------------------------------------- */

// Folder inside wp-content/uploads/ holding cover photos. Files dropped there
// by FTP do not need to be in the Media Library: each run copies one out,
// renames it to the article slug, registers it, and sets it as the featured
// image. The product image stays in the body as {{IMG1}}, so the two are never
// the same picture. Accepted: .webp .jpg .jpeg .png
wpap_define( 'WPAP_COVER_DIR', 'blog-covers' );

// Warn in the log and in wp-admin once this many unused covers remain.
wpap_define( 'WPAP_COVER_LOW_WARN', 14 );

/* ---------------------------------------------------------------------------
 * 6. INDEXNOW (optional)
 * ------------------------------------------------------------------------- */

// Pings Bing/Yandex with each new URL. Leave empty to disable. The matching
// <key>.txt file must exist at the site root for the ping to be accepted.
wpap_define( 'WPAP_INDEXNOW_KEY', '' );

/* ---------------------------------------------------------------------------
 * 7. SITE PROFILE
 * ------------------------------------------------------------------------- */

// Everything the model is told about the business lives in site-profile.php.
// It is prose, not configuration — a non-developer can safely edit it.
require_once __DIR__ . '/site-profile.php';
