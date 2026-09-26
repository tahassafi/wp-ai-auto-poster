<?php
/**
 * WP Auto Poster — admin screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'wpap_admin_menu' );

function wpap_admin_menu() {
	add_menu_page(
		'AI Poster',
		'AI Poster',
		'manage_options',
		'wp-auto-poster',
		'wpap_page_queue',
		'dashicons-edit-page',
		26
	);
	add_submenu_page( 'wp-auto-poster', 'Keyword Queue', 'Keyword Queue', 'manage_options', 'wp-auto-poster', 'wpap_page_queue' );
	add_submenu_page( 'wp-auto-poster', 'Activity Log', 'Activity Log', 'manage_options', 'wp-auto-poster-log', 'wpap_page_log' );
	add_submenu_page( 'wp-auto-poster', 'Status', 'Status', 'manage_options', 'wp-auto-poster-status', 'wpap_page_status' );
}

function wpap_notice( $msg, $type = 'success' ) {
	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . wp_kses_post( $msg ) . '</p></div>';
}

/* =========================================================================
 * Keyword queue
 * ====================================================================== */

function wpap_page_queue() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nope.' );
	}
	global $wpdb;
	$table = wpap_table( 'keywords' );

	/* ---- actions ---- */

	if ( isset( $_POST['wpap_add'], $_POST['keywords'] ) && check_admin_referer( 'wpap_add' ) ) {
		$res = wpap_add_keywords( wp_unslash( $_POST['keywords'] ), get_current_user_id() );
		wpap_notice( sprintf(
			'<strong>%d</strong> keyword(s) added. %d already in the queue. %d line(s) skipped as too short.',
			$res['added'], $res['duplicates'], $res['skipped']
		) );
	}

	if ( isset( $_GET['wpap_action'], $_GET['kid'] ) && check_admin_referer( 'wpap_row_' . (int) $_GET['kid'] ) ) {
		$kid = (int) $_GET['kid'];
		if ( 'retry' === $_GET['wpap_action'] ) {
			wpap_reset_keyword( $kid );
			wpap_notice( 'Keyword put back in the queue.' );
		} elseif ( 'delete' === $_GET['wpap_action'] ) {
			wpap_delete_keyword( $kid );
			wpap_notice( 'Keyword deleted.' );
		}
	}

	if ( isset( $_POST['wpap_clear_used'] ) && check_admin_referer( 'wpap_clear_used' ) ) {
		$n = $wpdb->query( "DELETE FROM $table WHERE status = 'used'" );
		wpap_notice( sprintf( '%d finished keyword(s) removed from the list.', (int) $n ) );
	}

	/* ---- view ---- */

	$counts = wpap_queue_counts();
	$filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : 'all';
	$where  = in_array( $filter, array( 'pending', 'running', 'used', 'failed' ), true )
		? $wpdb->prepare( 'WHERE status = %s', $filter ) : '';

	$paged   = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
	$per     = 50;
	$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
	$rows    = $wpdb->get_results( "SELECT * FROM $table $where ORDER BY
		FIELD(status,'running','pending','failed','used'), id ASC
		LIMIT $per OFFSET " . ( ( $paged - 1 ) * $per ) );

	$days_left = $counts['pending']; // one article per day
	?>
	<div class="wrap">
		<h1>AI Poster — Keyword Queue</h1>

		<p style="font-size:14px;">
			<strong><?php echo (int) $counts['pending']; ?></strong> pending &nbsp;·&nbsp;
			<strong><?php echo (int) $counts['used']; ?></strong> published &nbsp;·&nbsp;
			<strong style="color:#b32d2e;"><?php echo (int) $counts['failed']; ?></strong> failed
			<?php if ( $counts['running'] ) : ?>
				&nbsp;·&nbsp; <strong style="color:#996800;"><?php echo (int) $counts['running']; ?></strong> running
			<?php endif; ?>
			&nbsp;—&nbsp; at one article per day that is roughly
			<strong><?php echo (int) $days_left; ?></strong> more day<?php echo 1 === $days_left ? '' : 's'; ?> of content.
		</p>

		<?php $cov = wpap_cover_stats(); ?>
		<?php if ( $cov['pool'] && $cov['unused'] <= WPAP_COVER_LOW_WARN ) : ?>
			<div class="notice notice-warning inline"><p>
				Only <strong><?php echo (int) $cov['unused']; ?></strong> unused cover photo<?php echo 1 === (int) $cov['unused'] ? '' : 's'; ?>
				left of <?php echo (int) $cov['pool']; ?>. Add more to
				<code>wp-content/uploads/<?php echo esc_html( WPAP_COVER_DIR ); ?>/</code>
				or covers will start repeating.
			</p></div>
		<?php endif; ?>

		<?php if ( $counts['pending'] < 7 ) : ?>
			<div class="notice notice-warning inline"><p>
				Fewer than a week of keywords left. Paste more below.
			</p></div>
		<?php endif; ?>

		<h2>Add keywords</h2>
		<form method="post">
			<?php wp_nonce_field( 'wpap_add' ); ?>
			<p class="description">
				One keyword or topic per line. Duplicates are ignored automatically.
				Keep every line a genuinely different subject — do not paste permutations of the
				same phrase, they compete with each other and read as spam.
			</p>
			<textarea name="keywords" rows="10" style="width:100%;max-width:820px;font-family:monospace;"
				placeholder="rolls royce cullinan body kit&#10;g63 carbon fibre hood&#10;ferrari 812 exhaust upgrade"></textarea>
			<p><button class="button button-primary" name="wpap_add" value="1">Add to queue</button></p>
		</form>

		<h2 style="margin-top:2em;">Queue</h2>
		<ul class="subsubsub">
			<?php
			$base = admin_url( 'admin.php?page=wp-auto-poster' );
			$tabs = array(
				'all'     => 'All (' . array_sum( $counts ) . ')',
				'pending' => 'Pending (' . $counts['pending'] . ')',
				'used'    => 'Published (' . $counts['used'] . ')',
				'failed'  => 'Failed (' . $counts['failed'] . ')',
			);
			$i = 0;
			foreach ( $tabs as $k => $label ) {
				$url = 'all' === $k ? $base : add_query_arg( 'status', $k, $base );
				echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( $url ) . '"'
					. ( $filter === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
			}
			?>
		</ul>

		<table class="wp-list-table widefat striped" style="margin-top:1em;">
			<thead><tr>
				<th style="width:40px;">#</th>
				<th>Keyword</th>
				<th style="width:90px;">Status</th>
				<th style="width:130px;">Added</th>
				<th>Result</th>
				<th style="width:130px;">Actions</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="6">Queue is empty.</td></tr>
			<?php endif; ?>
			<?php foreach ( (array) $rows as $r ) : ?>
				<tr>
					<td><?php echo (int) $r->id; ?></td>
					<td><strong><?php echo esc_html( $r->keyword ); ?></strong></td>
					<td>
						<?php
						$colors = array( 'pending' => '#2271b1', 'running' => '#996800', 'used' => '#007017', 'failed' => '#b32d2e' );
						$c      = isset( $colors[ $r->status ] ) ? $colors[ $r->status ] : '#666';
						echo '<span style="color:' . esc_attr( $c ) . ';font-weight:600;">' . esc_html( $r->status ) . '</span>';
						if ( $r->attempts > 1 ) {
							echo '<br><small>' . (int) $r->attempts . ' attempts</small>';
						}
						?>
					</td>
					<td><small><?php echo esc_html( mysql2date( 'j M Y H:i', $r->created_at ) ); ?></small></td>
					<td>
						<?php if ( $r->post_id && get_post( $r->post_id ) ) : ?>
							<a href="<?php echo esc_url( get_permalink( $r->post_id ) ); ?>" target="_blank">
								<?php echo esc_html( get_the_title( $r->post_id ) ); ?></a>
							&nbsp;<a href="<?php echo esc_url( get_edit_post_link( $r->post_id ) ); ?>">(edit)</a>
							<?php if ( $r->product_id ) : ?>
								<br><small>anchor: <a href="<?php echo esc_url( get_permalink( $r->product_id ) ); ?>" target="_blank"><?php echo esc_html( get_the_title( $r->product_id ) ); ?></a></small>
							<?php endif; ?>
						<?php elseif ( $r->error ) : ?>
							<span style="color:#b32d2e;"><?php echo esc_html( $r->error ); ?></span>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
					<td>
						<?php
						$retry = wp_nonce_url( add_query_arg( array( 'wpap_action' => 'retry', 'kid' => $r->id ), $base ), 'wpap_row_' . $r->id );
						$del   = wp_nonce_url( add_query_arg( array( 'wpap_action' => 'delete', 'kid' => $r->id ), $base ), 'wpap_row_' . $r->id );
						?>
						<?php if ( 'used' !== $r->status ) : ?>
							<a href="<?php echo esc_url( $retry ); ?>">Requeue</a> |
						<?php endif; ?>
						<a href="<?php echo esc_url( $del ); ?>" style="color:#b32d2e;">Delete</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array(
				'base'    => add_query_arg( 'paged', '%#%' ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) . '</div></div>';
		}
		?>

		<form method="post" style="margin-top:1.5em;"
			onsubmit="return confirm('Remove all finished keywords from this list? The published posts are not touched.');">
			<?php wp_nonce_field( 'wpap_clear_used' ); ?>
			<button class="button" name="wpap_clear_used" value="1">Clear published rows</button>
		</form>
	</div>
	<?php
}

/* =========================================================================
 * Log
 * ====================================================================== */

function wpap_page_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nope.' );
	}
	global $wpdb;
	$log = wpap_table( 'log' );

	if ( isset( $_POST['wpap_clear_log'] ) && check_admin_referer( 'wpap_clear_log' ) ) {
		$wpdb->query( "TRUNCATE TABLE $log" );
		wpap_notice( 'Log cleared.' );
	}

	$rows = $wpdb->get_results( "SELECT * FROM $log ORDER BY id DESC LIMIT 300" );
	?>
	<div class="wrap">
		<h1>AI Poster — Activity Log</h1>
		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th style="width:150px;">When</th>
				<th style="width:80px;">Level</th>
				<th>Message</th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="3">Nothing logged yet.</td></tr>
			<?php endif; ?>
			<?php foreach ( (array) $rows as $r ) :
				$colors = array( 'success' => '#007017', 'error' => '#b32d2e', 'warn' => '#996800' );
				$c      = isset( $colors[ $r->level ] ) ? $colors[ $r->level ] : '#50575e';
				?>
				<tr>
					<td><small><?php echo esc_html( mysql2date( 'j M Y H:i:s', $r->created_at ) ); ?></small></td>
					<td><span style="color:<?php echo esc_attr( $c ); ?>;font-weight:600;"><?php echo esc_html( $r->level ); ?></span></td>
					<td><?php echo esc_html( $r->message ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" style="margin-top:1.5em;">
			<?php wp_nonce_field( 'wpap_clear_log' ); ?>
			<button class="button" name="wpap_clear_log" value="1">Clear log</button>
		</form>
	</div>
	<?php
}

/* =========================================================================
 * Status / run now
 * ====================================================================== */

function wpap_page_status() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Nope.' );
	}

	if ( isset( $_POST['wpap_run'] ) && check_admin_referer( 'wpap_run' ) ) {
		@set_time_limit( 0 );
		$res = wpap_run_once();
		if ( $res['ok'] ) {
			wpap_notice( esc_html( $res['message'] )
				. ( ! empty( $res['url'] ) ? ' — <a href="' . esc_url( $res['url'] ) . '" target="_blank">view</a>' : '' ) );
		} else {
			wpap_notice( 'Run failed: ' . esc_html( $res['message'] ), 'error' );
		}
	}

	$counts   = wpap_queue_counts();
	$key_ok   = (bool) WPAP_API_KEY;
	$masked   = $key_ok ? substr( WPAP_API_KEY, 0, 12 ) . str_repeat( '•', 12 ) . substr( WPAP_API_KEY, -4 ) : '— not set —';
	$cron_url = home_url( '/wp-content/plugins/wp-auto-poster/cron-post.php?key=' . rawurlencode( WPAP_CRON_SECRET ) );
	$abs      = WP_PLUGIN_DIR . '/wp-auto-poster/cron-post.php';

	global $wpdb;
	$pool     = wpap_anchor_pool_stats();
	$anchored = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wpap_table( 'anchors' ) );
	$cat      = get_term( (int) WPAP_POST_CATEGORY, 'category' );
	$author   = get_userdata( (int) WPAP_POST_AUTHOR );
	?>
	<div class="wrap">
		<h1>AI Poster — Status</h1>

		<table class="widefat striped" style="max-width:900px;">
			<tbody>
			<tr><td style="width:230px;"><strong>API key</strong></td>
				<td><code><?php echo esc_html( $masked ); ?></code>
					<?php if ( ! $key_ok ) : ?><span style="color:#b32d2e;"> — set WPAP_API_KEY in wp-config.php or .env</span><?php endif; ?></td></tr>
			<tr><td><strong>Writing model</strong></td><td><code><?php echo esc_html( WPAP_MODEL ); ?></code> · max_tokens <?php echo (int) WPAP_MAX_TOKENS; ?></td></tr>
			<tr><td><strong>Anchor fallback model</strong></td><td><code><?php echo esc_html( WPAP_MODEL_CHEAP ); ?></code></td></tr>
			<tr><td><strong>Publishes as</strong></td><td><?php echo $author ? esc_html( $author->display_name . ' (#' . $author->ID . ')' ) : 'MISSING USER'; ?></td></tr>
			<tr><td><strong>Category</strong></td><td><?php echo ( $cat && ! is_wp_error( $cat ) ) ? esc_html( $cat->name . ' (#' . $cat->term_id . ')' ) : 'MISSING CATEGORY'; ?></td></tr>
			<tr><td><strong>Post status</strong></td><td><code><?php echo esc_html( WPAP_POST_STATUS ); ?></code></td></tr>
			<tr><td><strong>Article length</strong></td><td>min <?php echo (int) WPAP_MIN_WORDS; ?> words, <?php echo (int) WPAP_MIN_H2; ?>–<?php echo (int) WPAP_MAX_H2; ?> h2 sections</td></tr>
			<tr><td><strong>Anchor pool</strong></td><td>
				<strong><?php echo (int) $pool['pool']; ?></strong> eligible
				of <?php echo (int) $pool['published']; ?> published products
				<?php if ( $pool['excluded'] ) : ?>
					<span style="color:#996800;">(<?php echo (int) $pool['excluded']; ?> excluded<?php echo WPAP_REQUIRE_IN_STOCK ? ' &mdash; out of stock, hidden, or no image' : ' &mdash; no featured image'; ?>)</span>
				<?php endif; ?>
				<br><small>
					In-stock only: <strong><?php echo WPAP_REQUIRE_IN_STOCK ? 'yes' : 'no'; ?></strong><?php echo WPAP_ALLOW_BACKORDER ? ' (backorders count as in stock)' : ''; ?>
					&nbsp;·&nbsp; <?php echo $anchored; ?> used so far
					&nbsp;·&nbsp; <?php echo (int) WPAP_ROTATION_DAYS; ?>-day rotation cooldown
					<?php if ( $pool['pool'] > 0 ) : ?>
						&nbsp;·&nbsp; ~<?php echo (int) $pool['pool']; ?> days before any product repeats
					<?php endif; ?>
				</small>
			</td></tr>
			<tr><td><strong>Cover images</strong></td><td>
				<?php $cov = wpap_cover_stats(); $cdir = wpap_cover_dir(); ?>
				<?php if ( ! $cov['pool'] ) : ?>
					<span style="color:#b32d2e;">none found</span> — the featured image falls back to the product photo.
					<br><small>Put images in <code>wp-content/uploads/<?php echo esc_html( WPAP_COVER_DIR ); ?>/</code>
					<?php echo is_dir( $cdir['path'] ) ? '(folder exists)' : '<strong>(folder does not exist yet)</strong>'; ?></small>
				<?php else : ?>
					<strong><?php echo (int) $cov['unused']; ?></strong> unused of <?php echo (int) $cov['pool']; ?>
					<?php if ( $cov['unused'] <= WPAP_COVER_LOW_WARN ) : ?>
						<span style="color:#996800;">— running low</span>
					<?php endif; ?>
					<br><small>
						<?php echo (int) $cov['unused']; ?> more day<?php echo 1 === (int) $cov['unused'] ? '' : 's'; ?> before photos repeat
						&nbsp;·&nbsp; folder <code>wp-content/uploads/<?php echo esc_html( WPAP_COVER_DIR ); ?>/</code>
						&nbsp;·&nbsp; each cover is copied and renamed to the article slug
					</small>
				<?php endif; ?>
			</td></tr>
			<tr><td><strong>Queue</strong></td><td><?php echo (int) $counts['pending']; ?> pending, <?php echo (int) $counts['used']; ?> published, <?php echo (int) $counts['failed']; ?> failed</td></tr>
			</tbody>
		</table>

		<h2 style="margin-top:2em;">cPanel cron command</h2>
		<p>Cron Jobs → add one job. <strong>Once per day</strong> is the configured volume.</p>
		<p><code style="display:block;padding:12px;background:#fff;border:1px solid #c3c4c7;">
			/usr/local/bin/php -q <?php echo esc_html( $abs ); ?>
		</code></p>
		<p class="description">
			Suggested schedule: minute <code>17</code>, hour <code>7</code> — one article a day. Note that cron uses <strong>server</strong> time, which is often UTC.
			Avoid on-the-hour times; shared servers are busiest then.
		</p>

		<h3>Browser trigger (for testing)</h3>
		<p><code style="display:block;padding:12px;background:#fff;border:1px solid #c3c4c7;word-break:break-all;">
			<?php echo esc_html( $cron_url ); ?>
		</code></p>
		<p class="description">Keep this URL private. Rotate <code>WPAP_CRON_SECRET</code> if it leaks. Leave the secret unset to disable HTTP triggering entirely.</p>

		<h2 style="margin-top:2em;">Run one now</h2>
		<p class="description">Takes one keyword off the queue and writes one article. This can take 1–3 minutes — do not reload the page.</p>
		<form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Writing…';">
			<?php wp_nonce_field( 'wpap_run' ); ?>
			<button class="button button-primary" name="wpap_run" value="1"
				<?php disabled( ! $key_ok || ! $counts['pending'] ); ?>>Write and publish one article</button>
		</form>
	</div>
	<?php
}
