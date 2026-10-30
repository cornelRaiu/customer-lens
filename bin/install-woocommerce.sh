#!/usr/bin/env bash
# Downloads WooCommerce into tests/php/plugins/woocommerce/ for the integration tests.
# Usage: bin/install-woocommerce.sh [version]   (default: latest stable)
set -euo pipefail

version="${1:-latest}"
root="$(cd "$(dirname "$0")/.." && pwd)"
target="$root/tests/php/plugins"

if [ "$version" = "latest" ]; then
	# woocommerce.zip is trunk, which can be a beta. The API names the stable release.
	url="$(curl -fsSL 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=woocommerce' \
		| php -r 'echo json_decode( stream_get_contents( STDIN ) )->download_link;')"
else
	url="https://downloads.wordpress.org/plugin/woocommerce.$version.zip"
fi

rm -rf "$target/woocommerce"
mkdir -p "$target"
curl -fsSL "$url" -o "$target/woocommerce.zip"
unzip -q "$target/woocommerce.zip" -d "$target"
rm "$target/woocommerce.zip"

echo "Installed WooCommerce $(grep -m1 -E '^ \* Version:' "$target/woocommerce/woocommerce.php" | awk '{print $3}') in $target/woocommerce"
