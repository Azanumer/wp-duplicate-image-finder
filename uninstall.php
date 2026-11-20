<?php
/**
 * Uninstall WP Duplicate Image Finder.
 *
 * Removes the stored scan results. Uploaded media is never touched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wdif_scan_results' );
delete_option( 'wdif_scan_count' );
