#!/usr/bin/env bash
# setup.sh <path-to-a-clone-of-main>
#
# Installs vendor/ and node_modules/ for a clone of this repository's main
# branch where Packagist and the npm registry can't be reached but GitHub can.
# See README.md here.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "${1:?the path of a clone of main}" && pwd)
packages=${PACKAGES_DIR:-$(dirname "$repo")/packages}

# --- Composer: every locked package from GitHub, shallow; then an ordinary install from those copies.
python3 "$here/vendor_from_lock.py" "$here/composer.lock" "$packages" "$repo/composer.lock"
(cd "$repo" && COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_MIRROR_PATH_REPOS=1 COMPOSER_DISABLE_NETWORK=1 composer install --no-interaction --no-progress --no-ansi | tail -n 3)

# --- npm: the build tools, from wherever this machine has them (NPM_TOOLS), and React's types from GitHub.
tools=${NPM_TOOLS:-/opt/npm-tools/node_modules}
types=$(dirname "$repo")/types
if [ ! -d "$types/DefinitelyTyped" ]; then
    git clone -q --depth 1 --filter=blob:none --sparse https://github.com/DefinitelyTyped/DefinitelyTyped "$types/DefinitelyTyped"
    git -C "$types/DefinitelyTyped" sparse-checkout set types/react types/react-dom
    git clone -q --depth 1 https://github.com/frenic/csstype "$types/csstype"
fi
rm -rf "$repo/node_modules"
mkdir -p "$repo/node_modules/@types" "$repo/node_modules/.bin"
for package in esbuild @esbuild typescript playwright-core; do
    [ -e "$tools/$package" ] || { echo "No $package in $tools: set NPM_TOOLS to a node_modules that has it."; exit 1; }
    ln -s "$tools/$package" "$repo/node_modules/$package"
done
ln -s "$types/DefinitelyTyped/types/react" "$repo/node_modules/@types/react"
ln -s "$types/DefinitelyTyped/types/react-dom" "$repo/node_modules/@types/react-dom"
ln -s "$types/csstype" "$repo/node_modules/csstype"
ln -s ../typescript/bin/tsc "$repo/node_modules/.bin/tsc"
ln -s ../esbuild/bin/esbuild "$repo/node_modules/.bin/esbuild"
printf 'node_modules\nworkbench/storage\n' >> "$repo/.git/info/exclude"

echo "esbuild $("$repo/node_modules/.bin/esbuild" --version) (package.json pins $(grep -o '"esbuild": "[^"]*"' "$repo/package.json" | cut -d'"' -f4): dist/ only comes out the same as CI's with the same version)"
echo "Done. In $repo: composer test, composer lint, composer analyse, composer spike, npm run typecheck, npm run test:frontend, npm run build."
