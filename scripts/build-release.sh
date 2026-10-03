#!/usr/bin/env sh
# Builds dist/trust-optimize-<version>.zip (and its .sha256) from a clean git checkout.
#
# Usage: scripts/build-release.sh [<tag or commit>]   (default: HEAD)
#
# - checks out the commit into build/ (uncommitted changes never get in);
# - runs phpcs and the unit tests there, with the dev dependencies;
# - archives the tracked files that are not export-ignore'd (.gitattributes),
#   installs the production dependencies from composer.lock and packs a zip
#   whose bytes depend only on the commit and composer.lock.
#
# COMPOSER: the composer command. By default `composer`, or tools/bin/composer (PHP 8.0 container,
# which only sees the plugin directory) when composer is not installed.
set -eu

PLUGIN_SLUG="trust-optimize"
SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PLUGIN_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
REF="${1:-HEAD}"
BUILD_REL="build/release"
BUILD_DIR="$PLUGIN_DIR/$BUILD_REL"
SRC_DIR="$BUILD_DIR/source"
PKG_DIR="$BUILD_DIR/package"
STAGE_DIR="$PKG_DIR/$PLUGIN_SLUG"
DIST_DIR="$PLUGIN_DIR/dist"

if [ -z "${COMPOSER:-}" ]; then
	if command -v composer >/dev/null 2>&1; then
		COMPOSER="composer"
	else
		COMPOSER="$PLUGIN_DIR/tools/bin/composer"
	fi
fi

fail() {
	echo "Error: $*" >&2
	exit 1
}

cleanup() {
	[ -n "${KEEP_BUILD:-}" ] || rm -rf "$BUILD_DIR"
}
trap cleanup EXIT INT TERM

cd "$PLUGIN_DIR"

COMMIT=$(git rev-parse --verify "$REF^{commit}") || fail "unknown revision: $REF"
EPOCH=$(git show -s --format=%ct "$COMMIT")

rm -rf "$BUILD_DIR"
mkdir -p "$BUILD_DIR"
git clone -q --no-checkout "$PLUGIN_DIR" "$SRC_DIR"
git -C "$SRC_DIR" checkout -q --detach "$COMMIT"

VERSION=$(sed -n 's/^ \* Version: *//p' "$SRC_DIR/trust-optimize.php" | tr -d '[:space:]')
[ -n "$VERSION" ] || fail "cannot read the version from trust-optimize.php"
grep -q "define( 'TRUST_OPTIMIZE_VERSION', '$VERSION' );" "$SRC_DIR/trust-optimize.php" || fail "TRUST_OPTIMIZE_VERSION differs from the plugin header ($VERSION)"
grep -q "^Stable tag: $VERSION\$" "$SRC_DIR/readme.txt" || fail "Stable tag in readme.txt differs from $VERSION"
case "$REF" in
	v[0-9]*) [ "$REF" = "v$VERSION" ] || fail "tag $REF does not match the plugin version $VERSION" ;;
esac

echo "Building $PLUGIN_SLUG $VERSION from $COMMIT"

# 1. Checks, on the sources with the dev dependencies.
"$COMPOSER" --working-dir="$BUILD_REL/source" install --no-interaction --prefer-dist
"$COMPOSER" --working-dir="$BUILD_REL/source" run-script phpcs
"$COMPOSER" --working-dir="$BUILD_REL/source" run-script test:unit

# 2. The package: tracked files without the export-ignore'd ones, production dependencies only.
mkdir -p "$STAGE_DIR"
git -C "$SRC_DIR" archive "$COMMIT" | tar -x -C "$STAGE_DIR"
"$COMPOSER" --working-dir="$BUILD_REL/package/$PLUGIN_SLUG" install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# 3. Composition.
for required in trust-optimize.php uninstall.php readme.txt LICENSE includes templates assets vendor/autoload.php vendor/woocommerce/action-scheduler/action-scheduler.php; do
	[ -e "$STAGE_DIR/$required" ] || fail "missing in the archive: $required"
done
for forbidden in tests docs tools scripts .github .husky .git node_modules phpcs.xml phpunit.xml.dist package.json vendor/phpunit vendor/squizlabs vendor/wp-coding-standards vendor/phpcompatibility vendor/phpcsstandards vendor/dealerdirect vendor/yoast vendor/wp-phpunit; do
	[ ! -e "$STAGE_DIR/$forbidden" ] || fail "must not be in the archive: $forbidden"
done

# 4. Reproducible zip: fixed permissions, times and order.
find "$STAGE_DIR" -type d -exec chmod 755 {} +
find "$STAGE_DIR" -type f -exec chmod 644 {} +
find "$STAGE_DIR" -exec touch -h -d "@$EPOCH" {} +

mkdir -p "$DIST_DIR"
ARCHIVE="$DIST_DIR/$PLUGIN_SLUG-$VERSION.zip"
rm -f "$ARCHIVE" "$ARCHIVE.sha256"
(
	cd "$PKG_DIR"
	find "$PLUGIN_SLUG" | LC_ALL=C sort | zip -X -q "$ARCHIVE" -@
)
(
	cd "$DIST_DIR"
	sha256sum "$PLUGIN_SLUG-$VERSION.zip" > "$PLUGIN_SLUG-$VERSION.zip.sha256"
)

echo "$ARCHIVE"
cat "$ARCHIVE.sha256"
