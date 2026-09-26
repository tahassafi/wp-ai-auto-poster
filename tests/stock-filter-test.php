<?php
/**
 * Stock-filter tests — which products are eligible to anchor an article.
 *
 *   php tests/stock-filter-test.php
 *
 * Exercises the real wpap_load_catalogue() against a stubbed $wpdb, so the
 * actual SQL-shaped code path is covered rather than a reimplementation of it.
 */

require __DIR__ . '/stubs.php';

/**
 * Serves the four queries wpap_load_catalogue() makes, told apart by the
 * marker columns each one selects.
 */
class WPAP_Fake_Wpdb {
	public $posts = 'wp_posts', $postmeta = 'wp_postmeta', $term_relationships = 'wp_tr',
		$term_taxonomy = 'wp_tt', $terms = 'wp_terms', $prefix = 'wp_';

	public $products = array(), $thumbs = array(), $stock = array(), $hidden = array(), $terms_rows = array();

	public function prepare( $q, ...$a ) {
		foreach ( $a as $v ) {
			$q = preg_replace( '/%[ds]/', is_int( $v ) ? (string) $v : "'" . $v . "'", $q, 1 );
		}
		return $q;
	}
	public function get_col( $sql ) { return array(); }
	public function get_var( $sql ) { return count( $this->products ); }

	public function get_results( $sql, $out = null ) {
		if ( false !== strpos( $sql, '_thumbnail_id' ) ) {
			$r = array();
			foreach ( $this->thumbs as $id => $t ) { $r[] = array( 'post_id' => $id, 'meta_value' => $t ); }
			return $r;
		}
		if ( false !== strpos( $sql, '_stock_status' ) ) {
			$r = array();
			foreach ( $this->stock as $id => $v ) { $r[] = array( 'post_id' => $id, 'meta_value' => $v ); }
			return $r;
		}
		if ( false !== strpos( $sql, 'product_visibility' ) ) {
			$r = array();
			foreach ( $this->hidden as $id ) { $r[] = array( 'object_id' => $id ); }
			return $r;
		}
		if ( false !== strpos( $sql, "'product_tag'" ) ) {   // only the catalogue load selects product_tag
			return $this->terms_rows;
		}
		return $this->products;
	}
}

$wpdb = new WPAP_Fake_Wpdb();

require __DIR__ . '/../wp-auto-poster/config.php';
require __DIR__ . '/../wp-auto-poster/includes/anchor.php';

/* ------------------------------------------------------------------ */
// id => array( stock_status, has_image, hidden_from_catalogue, description, expected )
$cases = array(
	1 => array( 'instock',     true,  false, 'in stock, has image',            true  ),
	2 => array( 'outofstock',  true,  false, 'OUT OF STOCK',                   false ),
	3 => array( 'onbackorder', true,  false, 'on backorder',                   false ),
	4 => array( null,          true,  false, 'no _stock_status set at all',    true  ),
	5 => array( 'instock',     false, false, 'in stock but NO IMAGE',          false ),
	6 => array( 'instock',     true,  true,  'in stock but HIDDEN from shop',  false ),
	7 => array( 'INSTOCK',     true,  false, 'in stock, unexpected casing',    true  ),
	8 => array( 'instock ',    true,  false, 'in stock, trailing whitespace',  true  ),
);

foreach ( $cases as $id => $c ) {
	$wpdb->products[] = array( 'ID' => $id, 'post_title' => "Product $id", 'post_name' => "p$id", 'post_excerpt' => '' );
	if ( $c[1] ) { $wpdb->thumbs[ $id ] = 1000 + $id; }
	if ( null !== $c[0] ) { $wpdb->stock[ $id ] = $c[0]; }
	if ( $c[2] ) { $wpdb->hidden[] = $id; }
	$wpdb->terms_rows[] = array( 'object_id' => $id, 'taxonomy' => 'product_cat', 'name' => 'Hood', 'slug' => 'hood' );
}

echo "\n== Eligibility for the anchor pool ==\n\n";
printf( "%-4s %-14s %-7s %-7s %-9s %s\n", 'ID', 'stock', 'image', 'hidden', 'eligible', 'case' );
echo str_repeat( '-', 82 ), "\n";

$catalogue = wpap_load_catalogue();

foreach ( $cases as $id => $c ) {
	$got = isset( $catalogue[ $id ] );
	printf( "%-4s %-14s %-7s %-7s %-9s %s\n",
		$id, var_export( $c[0], true ), $c[1] ? 'yes' : 'NO', $c[2] ? 'YES' : 'no', $got ? 'YES' : 'no', $c[3] );
	t( 'case ' . $id . ': ' . $c[3], $got === $c[4] );
}

echo "\n";
$stats = wpap_anchor_pool_stats();
t( 'pool counts only eligible products', 4 === $stats['pool'], json_encode( $stats ) );
t( 'published count is the unfiltered total', 8 === $stats['published'], json_encode( $stats ) );
t( 'excluded is the difference', 4 === $stats['excluded'], json_encode( $stats ) );
echo '        stats: ' . json_encode( $stats ) . "\n";

wpap_test_summary();
