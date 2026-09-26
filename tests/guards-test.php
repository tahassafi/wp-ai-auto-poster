<?php
/**
 * Guard tests — the code-level repairs and validations applied to model output.
 *
 *   php tests/guards-test.php
 *
 * The fixture below is a deliberately hostile draft: schemeless hrefs, a
 * javascript: URL, a link to an unapproved internal page, a link to a domain
 * that is not on the allow-list, more external links than the cap permits, an
 * event-handler attribute, an over-long slug and a stray preamble.
 */

require __DIR__ . '/stubs.php';

// Constants win over .env, so the suite pins its own allow-list and rewrites.
define( 'WPAP_ALLOWED_EXTERNAL_DOMAINS', 'example-manufacturer.com,another-manufacturer.com,partner.co.uk' );
define( 'WPAP_DOMAIN_FIXES', 'partner.com>partner.co.uk,www.partner.com>partner.co.uk' );

require __DIR__ . '/../wp-auto-poster/config.php';
require __DIR__ . '/../wp-auto-poster/includes/generator.php';

/* ------------------------------------------------------------------ */
echo "\n== 1. Delimiter section parsing ==\n";

$raw = <<<'EOT'
Sure! Here is your article.

===TITLE===
"Example Front Bumper Options That Actually Fit"

===SLUG===
the-best-example-front-bumper-options-for-your-car-this-year-and-beyond

===EXCERPT===
A practical look at front bumper choices.

===SEO_TITLE===
Example Front Bumper: A Fitment Guide For Owners

===SEO_DESCRIPTION===
Everything an owner needs to know about choosing an example front bumper, including OEM fitment, panel gaps, finish matching and what to check before buying anything at all.

===CONTENT===
<p>Fitting an example front bumper is not a cosmetic decision alone.</p>
<p>Our team at <a href="/about/">Example Auto Parts</a> sees this weekly.</p>
<p>The part most owners start with is the <a href="https://example.com/product/front-bumper/">front bumper</a>.</p>
<h2>Panel gaps are the tell</h2>
<p>Intro to section one.</p>
{{IMG1}}
<p>More detail, see <a href="example-manufacturer.com/en/showroom.html">the official showroom</a>.</p>
<h2>Finish matching</h2>
<p>Paint codes matter. <a href="https://en.wikipedia.org/wiki/Paint">Wikipedia</a> explains.</p>
<h2>Hardware and fixings</h2>
<p>Clips and brackets. <a href="https://partner.com/parts">Partner</a> publishes fitment notes.</p>
<h2>What to ask before you buy</h2>
<p>Ask for the part number. Also see <a href="/secret-admin-page/">this page</a>.</p>
<h3>A checklist</h3>
<ul><li onclick="x()">Part number</li><li>Finish code</li></ul>
<p><a href="javascript:alert(1)">click</a> and <a href="https://another-manufacturer.com/">Another</a>.</p>
EOT;

$s = wpap_parse_sections( $raw );
t( 'all six sections parsed', ! is_wp_error( $s ), is_wp_error( $s ) ? $s->get_error_message() : '' );
t( 'title unquoted', isset( $s['title'] ) && 'Example Front Bumper Options That Actually Fit' === $s['title'], $s['title'] ?? '' );
t( 'chat preamble before ===TITLE=== discarded', isset( $s['title'] ) && false === strpos( $s['title'], 'Sure!' ) );

/* ------------------------------------------------------------------ */
echo "\n== 2. Slug forcing ==\n";
$slug = wpap_force_slug( $s['slug'], $s['title'] );
t( 'slug within word limit', count( explode( '-', $slug ) ) <= WPAP_SLUG_MAX_WORDS, $slug );
t( 'slug within char limit', strlen( $slug ) <= WPAP_SLUG_MAX_CHARS, $slug . ' (' . strlen( $slug ) . ')' );
echo "        slug = $slug\n";

/* ------------------------------------------------------------------ */
echo "\n== 3. Sanitising and link repair ==\n";

$allowed_internal = array(
	home_url( '/' ), home_url( '/shop/' ), home_url( '/about/' ), home_url( '/contact/' ),
	'https://example.com/product/front-bumper/',
	'https://example.com/product-category/exterior-bodywork/',
);

$html = wpap_sanitise_html( $s['content'] );
t( 'event-handler attribute stripped', false === strpos( $html, 'onclick' ) );

list( $html, $stats ) = wpap_repair_links( $html, $allowed_internal );

t( 'schemeless allowed href repaired to https',
	false !== strpos( $html, 'https://example-manufacturer.com/en/showroom.html' ) );
t( 'forced domain rewrite applied',
	false !== strpos( $html, 'partner.co.uk' ) && false === strpos( $html, 'partner.com/' ) );
t( 'off-allow-list external link unwrapped', false === strpos( $html, 'wikipedia.org' ) );
t( 'unwrapped link keeps its anchor TEXT', false !== strpos( $html, 'Wikipedia' ) );
t( 'javascript: link unwrapped', false === strpos( $html, 'javascript:' ) );
t( 'unapproved internal page unwrapped', false === strpos( $html, 'secret-admin-page' ) );
t( 'approved internal page kept', false !== strpos( $html, 'example.com/about/' ) );
t( 'external links capped at 2', 2 === $stats['external'], 'external=' . $stats['external'] );
t( 'every surviving external link has rel and target',
	preg_match_all( '#<a href="https?://(?!example\.com)#i', $html )
	=== preg_match_all( '#rel="nofollow noopener" target="_blank"#', $html ) );
t( 'internal links carry no nofollow or target',
	! preg_match( '#<a href="https://example\.com[^"]*"[^>]*(rel|target)=#i', $html ) );
echo '        stats: ' . json_encode( $stats ) . "\n";

/* ------------------------------------------------------------------ */
echo "\n== 4. Keyword hyperlink in paragraph one ==\n";
$kw     = 'example front bumper';
$target = 'https://example.com/product-category/exterior-bodywork/';
list( $html, $ok ) = wpap_enforce_keyword_link( $html, $kw, $target );
t( 'paragraph one accepted', $ok );
preg_match( '#<p\b[^>]*>.*?</p>#is', $html, $p1 );
t( 'the keyword phrase itself is the anchor text',
	(bool) preg_match( '#<a href="' . preg_quote( $target, '#' ) . '">' . preg_quote( $kw, '#' ) . '</a>#i', $p1[0] ),
	$p1[0] );
echo '        p1 = ' . trim( $p1[0] ) . "\n";

list( , $ok2 ) = wpap_enforce_keyword_link( '<p>No mention here at all.</p><h2>x</h2>', $kw, $target );
t( 'rejected, not silently patched, when the keyword is absent', false === $ok2 );

/* ------------------------------------------------------------------ */
echo "\n== 5. Structure ==\n";
$h2 = preg_match_all( '#<h2\b[^>]*>#i', $html );
t( 'h2 count within bounds', $h2 >= WPAP_MIN_H2 && $h2 <= WPAP_MAX_H2, 'h2=' . $h2 );
echo '        word count of this toy article = ' . wpap_word_count( $html )
	. ' (production runs enforce >= ' . WPAP_MIN_WORDS . ")\n";

/* ------------------------------------------------------------------ */
echo "\n== 6. Image marker ==\n";
$anchor = array(
	'id' => 471, 'thumb_id' => 2867,
	'title' => 'Example Front Bumper Cover OEM 5A1807437',
	'url' => 'https://example.com/product/front-bumper/',
);
$out = wpap_insert_image( $html, $anchor, $kw );
t( 'marker replaced', false === strpos( $out, '{{IMG1}}' ) );
t( 'media-library URL used, not a hotlink', false !== strpos( $out, 'example.com/wp-content/uploads/' ) );
t( 'alt text present', (bool) preg_match( '#alt="Example Front Bumper#', $out ) );
t( 'image links to the anchor product', false !== strpos( $out, 'href="https://example.com/product/front-bumper/"' ) );

$nomarker = '<p>Intro.</p><h2>One</h2><p>Body.</p><h2>Two</h2><p>More.</p>';
$fb = wpap_insert_image( $nomarker, $anchor, $kw );
t( 'missing marker still gets an image', false !== strpos( $fb, '<figure' ) );
t( 'fallback lands after the first h2 section', strpos( $fb, '<figure' ) < strpos( $fb, '<h2>Two</h2>' ) );

/* ------------------------------------------------------------------ */
echo "\n== 7. Headline checks ==\n";
t( 'title key normalises punctuation and case',
	'example front bumper guide' === wpap_title_key( 'Example&nbsp;Front — Bumper Guide!' ),
	wpap_title_key( 'Example&nbsp;Front — Bumper Guide!' ) );
t( 'keyword found inside the headline',
	false !== strpos( wpap_title_key( $s['title'] ), wpap_title_key( $kw ) ) );
t( 'headline without the keyword is caught',
	false === strpos( wpap_title_key( 'A Guide To Upgrades' ), wpap_title_key( $kw ) ) );

/* ------------------------------------------------------------------ */
echo "\n== 8. Missing-section handling ==\n";
$bad = wpap_parse_sections( "===TITLE===\nHi\n===CONTENT===\n<p>x</p>" );
t( 'missing sections reported rather than published', is_wp_error( $bad ) );
echo '        -> ' . ( is_wp_error( $bad ) ? $bad->get_error_message() : '' ) . "\n";

wpap_test_summary();
