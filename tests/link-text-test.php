<?php
/**
 * Link-text tests — shortening long catalogue titles into usable anchor text.
 *
 *   php tests/link-text-test.php
 *
 * Product titles in a real parts catalogue routinely run past a dozen words
 * with part numbers, colour codes and "OEM" filler. Pasted into a hyperlink
 * that reads terribly and dilutes the anchor. These cover the shortener and
 * the post-generation guard that enforces the limit whatever the model did.
 */

require __DIR__ . '/stubs.php';

define( 'WPAP_ALLOWED_EXTERNAL_DOMAINS', 'example-manufacturer.com' );

require __DIR__ . '/../wp-auto-poster/config.php';
require __DIR__ . '/../wp-auto-poster/includes/generator.php';

/* ------------------------------------------------------------------ */
echo "\n== 1. Shortening real-world catalogue titles ==\n\n";

$titles = array(
	'Example Coupe Front Bumper Cover Dark Sapphire OEM 3SD807437',
	'Example Motors Coupe Right Side Rocker Panel | OEM 3SA853852 | Dark Sapphire',
	'Example Motors Coupe OEM Left Side Rocker Panel | Part-3SA853851 | Dark Sapphire',
	'Example-Coupe-Rear-Left-Quarter-Panel-Chrome-Trim-Molding-Silver-OEM',
	'Example Coupe 21 OEM Factory Wheels Set Silver OEM 3SA 601 025',
	'Example Motors Coupe OEM Exhaust Muffler Silencers LH + RH | Parts 3SD253609',
	'Example Motors Coupe 21" OEM Factory Wheels Set | Part 3SA601025 – Silver',
	'Premium SUV Carbon Fibre Bonnet Hood OEM A4638800057',
	'Example Saloon Front Bumper',
	'Example GT 900 Superfast Sports Exhaust System',
	'Example EV Front Bumper Cover',
	'Example Estate Side Rocker Panels Black',
);

$over = 0;
printf( "%-4s  %-76s  %s\n", 'W', 'ORIGINAL', 'LINK TEXT' );
echo str_repeat( '-', 140 ), "\n";
foreach ( $titles as $title ) {
	$short = wpap_short_product_name( $title );
	$w     = count( preg_split( '/\s+/', trim( $short ) ) );
	if ( $w > WPAP_LINK_WORDS ) {
		$over++;
	}
	printf( "%-4s  %-76s  %s\n", $w, mb_substr( $title, 0, 76 ), $short );
}
echo "\n";
t( 'every title fits the ' . WPAP_LINK_WORDS . '-word limit', 0 === $over, $over . ' over the limit' );
t( 'part numbers dropped',
	false === strpos( wpap_short_product_name( 'Example Saloon Front Bumper Cover OEM 5A1807437' ), '5A1807437' ) );
t( 'OEM filler dropped',
	false === stripos( wpap_short_product_name( 'Example Saloon OEM Front Bumper' ), 'oem' ) );
t( 'real model designations survive the part-number filter',
	false !== strpos( wpap_short_product_name( 'Example GT 900 Exhaust System' ), '900' )
	&& false !== strpos( wpap_short_product_name( 'Example 458 Brake Kit' ), '458' ) );
t( 'brand is always kept',
	0 === strpos( wpap_short_product_name( 'Example Motors Coupe Right Side Rocker Panel | OEM 3SA853852' ), 'Example' ) );
t( 'hyphenated slug-style titles are split into words',
	false !== strpos( wpap_short_product_name( 'Example-Coupe-Rear-Left-Quarter-Panel-Chrome-OEM' ), ' ' ) );
t( 'never ends on a dangling separator',
	! preg_match( '/[\s\-+&]$/', wpap_short_product_name( 'Example Coupe OEM Exhaust Silencers LH + RH | Parts 3SD253609' ) ) );

/* ------------------------------------------------------------------ */
echo "\n== 2. Post-generation anchor-text guard ==\n";

$product = 'https://example.com/product/coupe-rocker/';
$archive = 'https://example.com/product-category/exterior-bodywork/';

$html = '<p>See the <a href="' . $product . '">Example Motors Coupe Right Side Rocker Panel OEM 3SA853852 Dark Sapphire</a> today.</p>'
	. '<p>Also <a href="' . $archive . '">example motors coupe rocker panel replacement parts</a> here.</p>'
	. '<p><a href="https://example-manufacturer.com/" rel="nofollow noopener" target="_blank">the official manufacturer configurator website for owners</a></p>'
	. '<figure><a href="' . $product . '"><img src="x.webp" alt="Example Coupe Side Rocker Panel" /></a></figure>';

list( $out, $replaced ) = wpap_enforce_anchor_text(
	$html,
	array( $product => 'Example Coupe Side Rocker Panel' ),
	array( $archive )
);

t( 'over-long product link relabelled with the short name',
	false !== strpos( $out, '>Example Coupe Side Rocker Panel</a>' ) );
t( 'the keyword link is exempt and keeps its exact phrase',
	false !== strpos( $out, 'example motors coupe rocker panel replacement parts</a>' ) );
t( 'an over-long external anchor is truncated to the limit',
	false !== strpos( $out, '>the official manufacturer configurator website for</a>' ) );
t( 'image links untouched, since alt is the anchor text there',
	false !== strpos( $out, 'alt="Example Coupe Side Rocker Panel"' ) );
t( 'two links were changed', 2 === $replaced, 'replaced=' . $replaced );

wpap_test_summary();
