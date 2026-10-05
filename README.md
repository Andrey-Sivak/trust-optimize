# TrustOptimize

Converts the image sizes WordPress generates into WebP and AVIF and serves them through `<picture>`. Everything runs on your own server; originals are never modified.

## Features

- WebP and AVIF variants of every generated size of JPEG and PNG uploads, named after the source: `photo.jpg` → `photo.jpg.webp`, `photo.jpg.avif`.
- `<picture>` built on top of the `srcset` and `sizes` that WordPress calculates; only the URLs and `type` change. Without a finished variant the original `<img>` stays untouched.
- Background conversion with Action Scheduler, one task per attachment.
- Bulk conversion and removal for the existing library: the admin page and WP-CLI (`wp trust-optimize`).
- Protection against bad input: pixel limit, free disk check, retry counter, variants that are not smaller than the source are dropped.
- Site Health tests, REST API for the Media Library status column.
- Never deletes or overwrites a file that another attachment uses, or the original of another attachment.
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

Uninstall on a large multisite network: the web request cleans every site with a limited amount of work per site. When it is interrupted, the sites that were not reached keep all their data (nothing is deleted half-way), so delete the plugin again. For large networks run `wp plugin uninstall trust-optimize --deactivate` from the command line and repeat it if interrupted. The option `trust_optimize_pending_cleanup` that may remain is explained in [readme.txt](readme.txt).

## Structure

- `includes/` – plugin classes, one folder per concern (`core` is the composition root)
- `templates/`, `assets/` – admin screens
- `tests/` – unit and integration tests, `tests/smoke` – smoke script for a live site
- `tools/bin/` – wrappers for the Docker toolchain (`compose`, `composer`, `test`, `wp`), see [tools/README.md](tools/README.md)
- `scripts/build-release.sh` – builds the release archive
- `.github/workflows/` – `ci.yml` (checks) and `release.yml` (archive and GitHub Release on a `v*` tag)

## Development

```
tools/bin/composer install
tools/bin/composer phpcs
tools/bin/test unit 8.0
tools/bin/test integration 8.0 6.5
tools/bin/test integration 8.4 latest
WP_MULTISITE=1 tools/bin/test integration 8.0 6.5 "--group ms-required"
```

## Release

```
scripts/build-release.sh v1.0.0
```

The script builds from a clean checkout of the given tag or commit (uncommitted changes never get in). It requires the version in the plugin header, `TRUST_OPTIMIZE_VERSION` and `Stable tag` in `readme.txt` to match each other and the tag, runs PHPCS and the unit tests, installs the production dependencies from `composer.lock`, checks that the archive contains what it must and nothing from the development setup, and writes a reproducible `dist/trust-optimize-<version>.zip` with its `.sha256`.

Pushing a `v*` tag runs `.github/workflows/release.yml`: it runs the CI workflow, builds the archive with the same script and publishes it, with its checksum, as a GitHub Release.
