# Dev tools

Wrappers around the Docker test stand (`docker-compose.yml` four levels above the plugin root, profile `tools`). Override the compose file with `TRUST_OPTIMIZE_COMPOSE_FILE`.

Prerequisites: the stand is running (`docker compose up -d`) and tool images are built (`docker compose --profile tools build`).

| Command | What it does |
|---|---|
| `tools/bin/compose <args>` | `docker compose` against the stand |
| `tools/bin/composer <args>` | composer in the PHP 8.0 container (minimum supported platform) |
| `tools/bin/wp <args>` | WP-CLI against the stand site (`/var/www/html`) |
| `tools/bin/test <unit\|integration> <8.0\|8.4> [wp-version\|latest]` | PHPUnit in the PHP 8.0 / 8.4 container |

## Container facts (checked 2026-10-02)

| | `tools-php80` | `tools-php84` |
|---|---|---|
| PHP | 8.0.30 | 8.4.26 |
| `gd` `imagewebp()` | yes | yes |
| `gd` `imageavif()` | **no** | yes |
| Imagick WEBP / AVIF | yes / yes | yes / yes |

Extensions (both): ctype curl dom exif fileinfo gd iconv imagick intl json mbstring mysqli mysqlnd openssl pdo_sqlite sodium xml zip zlib.

Check: `tools/bin/compose run --rm -T tools-php80 php -m`.

## Git pre-commit hook

`.husky/pre-commit` runs lint-staged, which runs phpcbf and phpcs through `tools/bin/composer` (relative paths, no TTY). Run `npm install` once so husky sets `core.hooksPath`. On filesystems that drop the executable bit (e.g. some ACL mounts) the hooks in `.husky/_/` and `node_modules/lint-staged/bin/lint-staged.js` need `chmod u+x` locally.
