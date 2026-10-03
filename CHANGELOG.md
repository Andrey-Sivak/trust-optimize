# Changelog

All notable changes to the TrustOptimize plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.0.0] - 2026-10-03

### Breaking
- Requires PHP 8.0, WordPress 6.5 and MySQL 5.7 / MariaDB 10.3.
- New storage: the tables `{prefix}trust_optimize_attachments`, `_variants` and `_jobs` are the only source of truth (schema 2.0.x). The JSON manifest and the plugin's entries in `_wp_attachment_metadata` are gone.
- New file names: a variant is `<source file name>.<format>` next to its source (`photo.jpg.webp`), no longer `photo.webp` (H-1).
- `<picture>` is the only delivery mode; markup is built over the core `srcset` / `sizes` (H-5, H-7).
- REST: `GET /trust-optimize/v1/image/{id}/status` is replaced by `GET /trust-optimize/v1/images/status?ids=…`.
- The profile hash is gone; a variant is outdated when its format or quality differs from the current settings (H-4).

### Added
- AVIF output, detected through `wp_image_editor_supports()` and downgraded when a real conversion fails (H-3).
- One Action Scheduler task per attachment; bulk jobs are producers with a cursor and a limit of pending tasks (H-8, M-9, M-10).
- Protection against poisonous files and a full disk: attempt counter, pixel limit, free-space pause (H-9).
- Settings: `force_lazy`, `max_pixels`, `min_free_disk`, `remove_data_on_uninstall`, working reset to defaults (M-12, L-3).
- Real statistics on the dashboard, batched Media Library statuses and polling that stops (L-2, L-4).
- Admin notices, Site Health tests, `wp trust-optimize migration conflicts`.
- Filters `trust_optimize_should_render`, `trust_optimize_img_attributes`, `trust_optimize_cdn_hosts`, `trust_optimize_max_pixels`, `trust_optimize_min_free_disk_bytes`, `trust_optimize_worker_time_budget`, `trust_optimize_bulk_*`, `trust_optimize_{format}_quality`.
- Multisite test coverage; documented system cron for every site of a network.

### Migration
- Upgrading from 1.x imports the old data in the background and resumably, regenerates images in the new layout and only then removes the old files (D-12). Make a backup of `uploads/` and the database first.
- 1.x files that collide with files of other attachments are never deleted; they are listed in the admin and by WP-CLI, because the original of such an attachment may have been overwritten by 1.x (H-1).

### Fixed
- Variants never overwrite files they do not own; writes go through a temporary file and `rename()` (H-1, M-4).
- The attachment status is derived from its variants and no longer stuck after a partial failure (H-2, M-1).
- No trial encoding on the frontend and no writes while rendering (H-3, M-5).
- `loading` and `fetchpriority` are left as core sets them (H-6).
- HTML is processed with `WP_HTML_Tag_Processor`; no document wrapper leaks into `the_content` (M-7).
- WebP / AVIF originals do not get PNG copies; variants that are not smaller than the source are not kept (M-8).
- Uninstall keeps the registry until cleanup completes, supports multisite and does not remove files that belong to other attachments (M-3).
- Deactivation keeps track of the queued work and the next activation resumes it (L-6).
- Repositories resolve their tables on every call, so work done after `switch_to_blog()` lands in the tables of that site (found by the multisite tests).
- The documentation describes what the plugin really does (M-11, L-1).

## [1.1.0] - 2026-05-05

### Added
- Asynchronous image conversion via Action Scheduler — uploads return instantly.
- `ConversionQueue` class for managing background conversion tasks.
- Queue status tracking columns (`status`, `total_tasks`, `completed_tasks`) in `trust_optimize_images` table.
- REST endpoint `GET /trust-optimize/v1/image/{id}/status` for polling optimization progress.
- "Optimization" status column in the Media Library list view.
- JavaScript polling script for real-time status updates in Media Library.
- Graceful degradation in `ImageProcessor` — serves original `<img>` while conversions are in progress.
- Transient caching for format lookups on completed images.
- Per-request object cache in `ImageModel` to avoid repeated DB queries.

### Changed
- `ImageConverter::handle_image_upload()` now schedules async tasks instead of converting synchronously.
- Minimum PHP version raised from 7.4 to 8.0.
- Database schema version bumped (it reached 1.3.0 before 2.0).

### Dependencies
- Added `woocommerce/action-scheduler` ^3.8 as a runtime dependency.
