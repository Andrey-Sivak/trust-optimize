# Changelog

All notable changes to the TrustOptimize plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-05

### Added
- First public release. Converts the JPEG and PNG sizes that WordPress generates into WebP and AVIF on your own server; the originals are never modified. The formats the server can write are detected through `wp_image_editor_supports()`, and a format is switched off when a real conversion proves it does not work.
- `<picture>` delivery built over the core `srcset` and `sizes`: a format is offered only when it covers every `srcset` candidate, otherwise the original `<img>` is left untouched. Markup is processed with `WP_HTML_Tag_Processor`; `loading` and `fetchpriority` stay as core sets them.
- Variants are stored next to their source and named after it (`photo.jpg.webp`); their state lives in the plugin's own database tables. A variant that is not smaller than its source is not kept, and a variant is regenerated when its source file is replaced in place (detected by a changed file size on the next sync or metadata regeneration).
- Safe file handling: variants are written through a temporary file and `rename()`, never overwrite or delete a file that belongs to another attachment, and a file shared by several attachments is deleted only by its last owner.
- Background conversion with Action Scheduler: one task per attachment, an attempt counter for failing images, a pixel limit and a pause when free disk space runs low.
- Bulk conversion and removal of the whole library with progress, pause, resume and cancel, from the settings page, the REST API or WP-CLI.
- Admin pages: an overview with statistics and a bulk optimization panel, and a settings page grouped into delivery, formats and quality, limits and uninstall, with the largest image entered in megapixels (stored as `max_pixels`) and a reset to defaults that respects what the server supports.
- Media Library status column with batched status requests and polling that stops.
- WP-CLI: `wp trust-optimize` with `inventory`, `sync`, `status`, `pause`, `resume`, `cancel`, `remove`, `sync-attachment` and `remove-attachment`.
- REST API under `/trust-optimize/v1/` for statuses, bulk jobs and single attachments.
- Admin notices and Site Health tests for background tasks, supported image formats and free disk space.
- Filters `trust_optimize_should_render`, `trust_optimize_img_attributes`, `trust_optimize_cdn_hosts`, `trust_optimize_max_pixels`, `trust_optimize_min_free_disk_bytes`, `trust_optimize_worker_time_budget`, `trust_optimize_bulk_*`, `trust_optimize_uninstall_cleanup_*` and `trust_optimize_{format}_quality`.
- Multisite support: per-site settings, tables and queue, tables created on the first request of a site, documented system cron for every site of a network.
- Uninstall that removes generated files, tables and options only when asked to, keeps its tables and options when interrupted so that deleting the plugin again continues the cleanup, and supports networks.
- Requires PHP 8.0, WordPress 6.5 and MySQL 5.7 / MariaDB 10.3.
