<?php
/**
 * WP Auto Poster — site profile.
 *
 * This is the only file a non-developer needs to touch. It is the entire
 * briefing the writing model receives about the business: what it sells, how
 * it talks, which pages may be linked, and which external domains are allowed.
 *
 * The values below are generic placeholders. Replace them with the client's
 * own copy. Anything site-identifying (name, URL paths, domain allow-list)
 * belongs here or in environment variables — never in the plugin's logic.
 *
 * @package WPAutoPoster
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'WPAP_CLI' ) ) {
	exit;
}

// Business name used in prompts, image captions and alt text.
wpap_define( 'WPAP_SITE_NAME', 'Example Auto Parts' );

/**
 * @return array<string,mixed>
 */
function wpap_site_material() {

	return array(

		'name'     => WPAP_SITE_NAME,
		'tagline'  => wpap_env( 'WPAP_SITE_TAGLINE', 'Specialist parts and accessories' ),
		'location' => wpap_env( 'WPAP_SITE_LOCATION', 'City, Country' ),

		/*
		 * What the business is. Concrete beats flattering: naming the actual
		 * product categories and brands measurably reduces vague filler in the
		 * generated copy, because the model has something specific to hold on to.
		 */
		'about' => "Example Auto Parts supplies genuine and OEM parts, body panels and "
			. "accessories for high-end vehicles. The catalogue covers exterior bodywork "
			. "(bumpers, rocker panels, quarter-panel trims, hoods, diffusers), exhaust "
			. "systems, wheels and rims, brakes, suspension, steering, mirrors, interior "
			. "trim, and badges. Parts are stocked for a range of premium marques, with "
			. "manufacturer part numbers quoted so buyers can confirm exact fitment before "
			. "ordering. The business serves owners, workshops and restyling shops, and "
			. "ships internationally.",

		/*
		 * Pages the model may link in paragraph two. Anything not listed here
		 * (plus the anchor product and its category archive) is stripped out of
		 * the finished article by the link guard, so a hallucinated internal
		 * URL can never reach a published page.
		 */
		'main_pages' => array(
			array( 'label' => 'the home page',                      'url' => '/' ),
			array( 'label' => 'our full parts and accessories shop', 'url' => '/shop/' ),
			array( 'label' => 'about us',                            'url' => '/about/' ),
			array( 'label' => 'contact the team',                    'url' => '/contact/' ),
		),

		/*
		 * The ONLY external domains an article may link to. Bare domains: the
		 * guard forces https:// and adds rel="nofollow noopener" target="_blank".
		 * Keep this to official manufacturer sites — it is what stops the model
		 * linking a competitor, a marketplace or a forum.
		 */
		'manufacturers' => array_values( array_filter( array_map( 'trim', explode(
			',',
			wpap_env( 'WPAP_ALLOWED_EXTERNAL_DOMAINS', 'example-manufacturer.com,another-manufacturer.com' )
		) ) ) ),

		/*
		 * Domains that must always be rewritten, left => right. Useful when a
		 * partner's canonical site is a country TLD and the model reliably
		 * guesses the .com. Leave empty if you have no such case.
		 */
		'domain_fixes' => wpap_parse_domain_fixes( wpap_env( 'WPAP_DOMAIN_FIXES', '' ) ),

		/*
		 * Stock provenance, stated as a hard fact in every article. Edit this if
		 * the catalogue carries anything other than genuine OEM.
		 */
		'oem' => "Every part and accessory " . WPAP_SITE_NAME . " sells is GENUINE OEM, supplied "
			. "with the manufacturer part number so fitment can be verified before ordering. "
			. "Never describe one of our parts as aftermarket, replica, reproduction, copy, "
			. "pattern, universal, 'OEM-style', 'OEM-equivalent' or 'OEM-quality'. Where an "
			. "article contrasts genuine OEM parts with aftermarket ones, our stock is always "
			. "on the genuine OEM side of that comparison.",

		/*
		 * House style. The negative instructions matter more than the positive
		 * ones — these are the specific failure modes seen in production.
		 */
		'style' => "Write for a buyer who already knows the subject. Be concrete: name models, "
			. "part categories, materials and fitment concerns. No stacked hype adjectives, no "
			. "'in today's fast-paced world' openings, no invented statistics, no invented "
			. "prices, no invented customer quotes, no claims about awards, certifications or "
			. "years in business. Never state a specific price. British/international English "
			. "spelling. Local context where it is genuinely relevant (climate, road surfaces, "
			. "regulations).",
	);
}

/**
 * Parses WPAP_DOMAIN_FIXES, formatted "wrong.com>right.co.uk,www.wrong.com>right.co.uk".
 *
 * @return array<string,string>
 */
function wpap_parse_domain_fixes( $raw ) {
	$out = array();
	foreach ( explode( ',', (string) $raw ) as $pair ) {
		if ( false === strpos( $pair, '>' ) ) {
			continue;
		}
		list( $from, $to ) = explode( '>', $pair, 2 );
		$from = strtolower( trim( $from ) );
		$to   = strtolower( trim( $to ) );
		if ( '' !== $from && '' !== $to ) {
			$out[ $from ] = $to;
		}
	}
	return $out;
}
