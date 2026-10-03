# TrustOptimize

Converts the image sizes WordPress generates into WebP and AVIF and serves them through `<picture>`. Everything runs on your own server; originals are never modified.

## Features

- WebP and AVIF variants of every generated size of JPEG and PNG uploads, named after the source: `photo.jpg` → `photo.jpg.webp`, `photo.jpg.avif`.
- `<picture>` built on top of the `srcset` and `sizes` that WordPress calculates; only the URLs and `type` change. Without a finished variant the original `<img>` stays untouched.
- Background conversion with Action Scheduler, one task per attachment.
- Bulk conversion and removal for the existing library: settings page and WP-CLI (`wp trust-optimize`).
- Protection against bad input: pixel limit, free disk check, retry counter, variants that are not smaller than the source are dropped.
- Site Health tests, REST API for the Media Library status column.
- Migration of data created by 1.x (background, resumable, never deletes files that collide with other attachments).
- Multisite: every site has its own settings, tables, queue and uploads folder.

## Requirements

- PHP 8.0 or higher
- WordPress 6.5 or higher
- MySQL 5.7 or higher, or MariaDB 10.3 or higher
- GD or Imagick with WebP (and optionally AVIF) support

The full user documentation (FAQ, settings, hooks, WP-CLI, REST) is in [readme.txt](readme.txt).

## Installation

- From a release archive: upload and activate it; dependencies are included.
- From a git checkout: run `composer install --no-dev` in the plugin directory, then activate.

Run the Action Scheduler queue from the system cron on production sites. On a multisite network the cron has to visit every site:

```
* * * * * cd /path/to/site && wp site list --field=url | xargs -I{} wp action-scheduler run --url={} --quiet
```

Make a backup of `uploads/` and the database before updating from 1.x.

## Structure

- `includes/` – plugin classes, one folder per concern (`core` is the composition root)
- `templates/`, `assets/` – admin screens
- `tests/` – unit and integration tests, `tests/smoke` – smoke script for a live site
- `tools/bin/` – wrappers for the Docker toolchain (`composer`, `test`, `wp`)

## Development

```
tools/bin/composer install
tools/bin/composer phpcs
tools/bin/test unit 8.0
tools/bin/test integration 8.0 6.5
tools/bin/test integration 8.4 latest
WP_MULTISITE=1 tools/bin/test integration 8.0 6.5 "--group ms-required"
```
