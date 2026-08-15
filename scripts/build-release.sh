#!/usr/bin/env sh
set -eu

PLUGIN_SLUG="trust-optimize"
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
DIST_DIR="$PLUGIN_DIR/dist"
ARCHIVE="$DIST_DIR/$PLUGIN_SLUG.zip"
BUILD_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/trust-optimize-build.XXXXXX")
STAGE_DIR="$BUILD_ROOT/$PLUGIN_SLUG"

cleanup() {
	rm -rf "$BUILD_ROOT"
}
trap cleanup EXIT INT TERM

cd "$PLUGIN_DIR"

if [ ! -f "trust-optimize.php" ]; then
	echo "Error: trust-optimize.php not found. Run this script from the plugin repository." >&2
	exit 1
fi

if [ ! -f "vendor/autoload.php" ]; then
	echo "Error: vendor/autoload.php is missing. Run composer install --no-dev --prefer-dist --optimize-autoloader first." >&2
	exit 1
fi

if [ ! -f "vendor/woocommerce/action-scheduler/action-scheduler.php" ]; then
	echo "Error: Action Scheduler runtime dependency is missing from vendor/." >&2
	exit 1
fi

for dev_package in \
	"vendor/phpunit" \
	"vendor/squizlabs" \
	"vendor/wp-coding-standards" \
	"vendor/phpcompatibility" \
	"vendor/dealerdirect"
do
	if [ -e "$dev_package" ]; then
		echo "Error: dev dependency found in vendor: $dev_package" >&2
		echo "Run composer install --no-dev --prefer-dist --optimize-autoloader before building." >&2
		exit 1
	fi
done

mkdir -p "$DIST_DIR" "$STAGE_DIR"

rsync -rlptD --no-owner --no-group \
	--exclude ".git/" \
	--exclude ".gitignore" \
	--exclude ".editorconfig" \
	--exclude ".husky/" \
	--exclude ".info/" \
	--exclude ".phpunit.result.cache" \
	--exclude "dist/" \
	--exclude "build/" \
	--exclude "node_modules/" \
	--exclude "package.json" \
	--exclude "package-lock.json" \
	--exclude "phpcs.xml" \
	--exclude "phpunit.xml" \
	--exclude "tests/" \
	--exclude "docs/" \
	--exclude "scripts/" \
	--exclude "*.log" \
	"$PLUGIN_DIR/" "$STAGE_DIR/"

find "$STAGE_DIR" -type f -name ".DS_Store" -delete

rm -f "$ARCHIVE"

(
	cd "$BUILD_ROOT"
	zip -qr "$ARCHIVE" "$PLUGIN_SLUG"
)

echo "$ARCHIVE"
