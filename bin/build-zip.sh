#!/usr/bin/env bash
# Builds the release folder dist/customer-lens/ and dist/customer-lens.zip.
# The folder is what Plugin Check inspects and what gets deployed to WordPress.org.
set -euo pipefail

slug="customer-lens"
root="$(cd "$(dirname "$0")/.." && pwd)"
dist="$root/dist"
target="$dist/$slug"

rm -rf "$dist"
mkdir -p "$target"

rsync -a --exclude-from="$root/.distignore" "$root/" "$target/"

# The only runtime dependencies are the Strauss-prefixed libraries, which composer install already
# put in vendor-prefixed/ from the same lock file. The release copies them and needs an autoloader
# for its own classes only. WordPress.org expects composer.json next to vendor/.
if [ ! -f "$root/vendor-prefixed/autoload.php" ]; then
	echo "vendor-prefixed/ is missing. Run composer install first." >&2
	exit 1
fi
cp -R "$root/vendor-prefixed" "$target/vendor-prefixed"
cp "$root/composer.json" "$target/"
composer dump-autoload --working-dir="$target" --no-dev --classmap-authoritative --no-scripts --no-interaction --quiet

(cd "$dist" && zip -qr "$slug.zip" "$slug")

echo "Built $dist/$slug.zip"
