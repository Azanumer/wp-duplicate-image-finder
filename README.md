# WP Duplicate Image Finder

Finds duplicate images in the WordPress Media Library by **file content hash** (md5) — not by filename. The same picture uploaded twice under a different name is caught; different crops or resized copies are not.

## How it works

1. Go to **Tools → Duplicate Images** and click **Scan Media Library**.
2. The plugin hashes every image file (JPEG, PNG, GIF, WebP) in batches of 100, so large libraries don't time out.
3. Images sharing a hash are shown as a group, with a thumbnail, filename, file size and upload date for each copy.
4. Pick which copy to keep in each group and click **Delete Selected Duplicates**. Deletions use `wp_delete_attachment()` with force-delete, so the files leave the disk too.

## Notes

- Only the **original** file is hashed — WordPress-generated thumbnails are never compared, so a thumbnail can never be flagged as a duplicate of its parent.
- Attachments whose file is missing from disk are skipped, not deleted.
- After a delete run the scan map is cleared automatically, so a fresh scan never shows ghosts.
- The scan results live in two options (`wdif_scan_results`, `wdif_scan_count`), removed on uninstall.

## Filters

- `wdif_image_mime_types` — change which MIME types are scanned.
- `wdif_scan_batch_size` — attachments hashed per batch (default `100`).

```php
// Scan in bigger batches on a fast server.
add_filter( 'wdif_scan_batch_size', function() { return 250; } );
```

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
