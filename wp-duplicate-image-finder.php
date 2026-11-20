<?php
/**
 * Plugin Name:       WP Duplicate Image Finder
 * Plugin URI:        https://github.com/Azanumer/wp-duplicate-image-finder
 * Description:       Finds duplicate images in the Media Library by file content hash (md5), groups them, and lets you delete the extras while keeping one copy.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://azanumer.cv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-duplicate-image-finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WDIF_VERSION', '1.0.0' );
define( 'WDIF_OPTION', 'wdif_scan_results' );
define( 'WDIF_COUNT_OPTION', 'wdif_scan_count' );

/**
 * Image MIME types the scanner considers.
 *
 * @return string[]
 */
function wdif_image_mime_types() {
	return apply_filters( 'wdif_image_mime_types', array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ) );
}

/**
 * How many attachments to hash per scan batch.
 *
 * @return int
 */
function wdif_scan_batch_size() {
	return (int) apply_filters( 'wdif_scan_batch_size', 100 );
}

/**
 * Register the Tools page.
 */
function wdif_register_page() {
	add_management_page(
		__( 'Duplicate Images', 'wp-duplicate-image-finder' ),
		__( 'Duplicate Images', 'wp-duplicate-image-finder' ),
		'manage_options',
		'wdif-duplicate-images',
		'wdif_render_page'
	);
}
add_action( 'admin_menu', 'wdif_register_page' );

/**
 * Total number of image attachments on the site.
 *
 * @return int
 */
function wdif_total_images() {
	$counts = wp_count_attachments( wdif_image_mime_types() );
	$total  = 0;
	foreach ( (array) $counts as $mime => $count ) {
		if ( 'trash' === $mime ) {
			continue;
		}
		$total += (int) $count;
	}
	return $total;
}

/**
 * Hash one batch of attachments and merge the results into the stored map.
 *
 * The stored map is hash => array of attachment IDs. Only hashes seen more
 * than once can be duplicates, but every hash is kept so later batches can
 * complete a group that started in an earlier batch.
 *
 * @param int $offset First attachment to process in this batch.
 * @return int Number of attachments hashed in this batch.
 */
function wdif_scan_batch( $offset ) {
	$batch = wdif_scan_batch_size();

	$query = new WP_Query( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'post_mime_type' => wdif_image_mime_types(),
		'posts_per_page' => $batch,
		'offset'         => $offset,
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	$map = get_option( WDIF_OPTION, array() );
	if ( ! is_array( $map ) ) {
		$map = array();
	}

	foreach ( $query->posts as $attachment_id ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_file( $file ) || ! is_readable( $file ) ) {
			continue;
		}
		// The original file only: thumbnails share the same content as the
		// original in scaled form, so hashing sizes would be misleading.
		$hash = md5_file( $file );
		if ( false === $hash ) {
			continue;
		}
		if ( ! isset( $map[ $hash ] ) ) {
			$map[ $hash ] = array();
		}
		$map[ $hash ][] = (int) $attachment_id;
	}

	update_option( WDIF_OPTION, $map, false );
	update_option( WDIF_COUNT_OPTION, $offset + count( $query->posts ), false );

	return count( $query->posts );
}

/**
 * Duplicate groups from the stored scan map.
 *
 * A group is a hash with two or more live attachments. Groups whose
 * attachments were deleted since the scan are dropped.
 *
 * @return array[] Each group: array( 'hash' => string, 'ids' => int[] ).
 */
function wdif_duplicate_groups() {
	$map    = get_option( WDIF_OPTION, array() );
	$groups = array();
	if ( ! is_array( $map ) ) {
		return $groups;
	}
	foreach ( $map as $hash => $ids ) {
		$live = array();
		foreach ( (array) $ids as $id ) {
			if ( 'attachment' === get_post_type( (int) $id ) ) {
				$live[] = (int) $id;
			}
		}
		if ( count( $live ) > 1 ) {
			$groups[] = array(
				'hash' => (string) $hash,
				'ids'  => $live,
			);
		}
	}
	return $groups;
}

/**
 * Delete the non-kept attachments of the selected groups.
 *
 * @param array $keep_by_hash Hash => attachment ID to keep.
 * @return array{deleted: int, skipped: int} Counts of what happened.
 */
function wdif_delete_duplicates( $keep_by_hash ) {
	$deleted = 0;
	$skipped = 0;
	foreach ( wdif_duplicate_groups() as $group ) {
		if ( ! isset( $keep_by_hash[ $group['hash'] ] ) ) {
			// Group not selected for deletion: leave it alone.
			continue;
		}
		$keep = (int) $keep_by_hash[ $group['hash'] ];
		foreach ( $group['ids'] as $id ) {
			if ( $id === $keep ) {
				continue;
			}
			if ( wp_delete_attachment( $id, true ) ) {
				$deleted++;
			} else {
				$skipped++;
			}
		}
	}
	// The scan map is stale after deletions: clear it so the next scan
	// starts fresh instead of showing ghosts.
	delete_option( WDIF_OPTION );
	delete_option( WDIF_COUNT_OPTION );
	return array( 'deleted' => $deleted, 'skipped' => $skipped );
}

/**
 * Render the Tools page: scan progress, results, and delete form.
 */
function wdif_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-duplicate-image-finder' ) );
	}

	// --- Handle actions -------------------------------------------------
	if ( isset( $_GET['wdif_scan'] ) && '1' === $_GET['wdif_scan'] ) {
		check_admin_referer( 'wdif_scan' );
		delete_option( WDIF_OPTION );
		delete_option( WDIF_COUNT_OPTION );
		$scanned = wdif_scan_batch( 0 );
		$total   = wdif_total_images();
		if ( $scanned < $total ) {
			wp_safe_redirect( add_query_arg( array(
				'page'         => 'wdif-duplicate-images',
				'wdif_continue' => 1,
				'wdif_offset'  => $scanned,
			), admin_url( 'tools.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array(
			'page'        => 'wdif-duplicate-images',
			'wdif_done'   => 1,
		), admin_url( 'tools.php' ) ) );
		exit;
	}

	if ( isset( $_GET['wdif_continue'] ) && '1' === $_GET['wdif_continue'] ) {
		check_admin_referer( 'wdif_scan' );
		$offset  = isset( $_GET['wdif_offset'] ) ? max( 0, (int) $_GET['wdif_offset'] ) : 0;
		$scanned = wdif_scan_batch( $offset );
		$total   = wdif_total_images();
		if ( $offset + $scanned < $total ) {
			wp_safe_redirect( add_query_arg( array(
				'page'          => 'wdif-duplicate-images',
				'wdif_continue' => 1,
				'wdif_offset'   => $offset + $scanned,
				'_wpnonce'      => wp_create_nonce( 'wdif_scan' ),
			), admin_url( 'tools.php' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array(
			'page'      => 'wdif-duplicate-images',
			'wdif_done' => 1,
		), admin_url( 'tools.php' ) ) );
		exit;
	}

	$notice = '';
	if ( isset( $_POST['wdif_delete'] ) ) {
		check_admin_referer( 'wdif_delete' );
		$keep = array();
		if ( isset( $_POST['wdif_keep'] ) && is_array( $_POST['wdif_keep'] ) ) {
			foreach ( $_POST['wdif_keep'] as $hash => $id ) {
				$keep[ sanitize_text_field( wp_unslash( $hash ) ) ] = (int) $id;
			}
		}
		$result = wdif_delete_duplicates( $keep );
		$notice = sprintf(
			/* translators: %1$d deleted, %2$d failed. */
			__( 'Deleted %1$d duplicate image(s). %2$d could not be deleted.', 'wp-duplicate-image-finder' ),
			$result['deleted'],
			$result['skipped']
		);
	}

	if ( isset( $_GET['wdif_done'] ) ) {
		$notice = __( 'Scan complete. Duplicate groups are listed below.', 'wp-duplicate-image-finder' );
	}

	$groups = wdif_duplicate_groups();
	$total  = wdif_total_images();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Duplicate Images', 'wp-duplicate-image-finder' ); ?></h1>
		<p><?php esc_html_e( 'Scans the Media Library and groups images with identical file contents (md5 hash). Same picture uploaded twice — even under a different filename — is caught; different crops or sizes are not.', 'wp-duplicate-image-finder' ); ?></p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<p>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => 'wdif-duplicate-images', 'wdif_scan' => 1 ), admin_url( 'tools.php' ) ), 'wdif_scan' ) ); ?>">
				<?php esc_html_e( 'Scan Media Library', 'wp-duplicate-image-finder' ); ?>
			</a>
			<span style="margin-left: 10px;"><?php printf( esc_html__( '%d image(s) in the library.', 'wp-duplicate-image-finder' ), (int) $total ); ?></span>
		</p>

		<?php if ( empty( $groups ) ) : ?>
			<p><?php esc_html_e( 'No duplicates found yet. Run a scan to check.', 'wp-duplicate-image-finder' ); ?></p>
		<?php else : ?>
			<form method="post">
				<?php wp_nonce_field( 'wdif_delete' ); ?>
				<p><?php esc_html_e( 'Choose which copy to keep in each group, then delete the rest. Deletions are permanent and remove the files from disk.', 'wp-duplicate-image-finder' ); ?></p>
				<?php foreach ( $groups as $index => $group ) : ?>
					<h2><?php printf( esc_html__( 'Group %1$d — %2$d copies', 'wp-duplicate-image-finder' ), (int) $index + 1, count( $group['ids'] ) ); ?></h2>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Keep', 'wp-duplicate-image-finder' ); ?></th>
								<th><?php esc_html_e( 'Preview', 'wp-duplicate-image-finder' ); ?></th>
								<th><?php esc_html_e( 'File', 'wp-duplicate-image-finder' ); ?></th>
								<th><?php esc_html_e( 'Size', 'wp-duplicate-image-finder' ); ?></th>
								<th><?php esc_html_e( 'Uploaded', 'wp-duplicate-image-finder' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $group['ids'] as $pos => $id ) : ?>
								<?php
								$file     = get_attached_file( $id );
								$filesize = ( $file && is_file( $file ) ) ? size_format( filesize( $file ) ) : '—';
								?>
								<tr>
									<td>
										<input type="radio" name="wdif_keep[<?php echo esc_attr( $group['hash'] ); ?>]" value="<?php echo (int) $id; ?>" <?php checked( 0, $pos ); ?> />
									</td>
									<td><?php echo wp_get_attachment_image( $id, array( 80, 80 ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( basename( (string) $file ) ); ?></a>
										<br /><span class="description">ID <?php echo (int) $id; ?></span>
									</td>
									<td><?php echo esc_html( $filesize ); ?></td>
									<td><?php echo esc_html( get_the_date( '', $id ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
				<p>
					<input type="submit" name="wdif_delete" class="button button-primary" value="<?php esc_attr_e( 'Delete Selected Duplicates', 'wp-duplicate-image-finder' ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Permanently delete the non-kept copies in every group?', 'wp-duplicate-image-finder' ) ); ?>');" />
				</p>
			</form>
		<?php endif; ?>
	</div>
	<?php
}
