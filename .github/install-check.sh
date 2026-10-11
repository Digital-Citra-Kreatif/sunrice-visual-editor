#!/usr/bin/env bash
# Does what docs/installation.md says on a new Laravel application, then
# checks the result with .github/install/InstalledTest.php, run inside it.
#
#   .github/install-check.sh [directory for the site]
#
# The package is required from this checkout, at the commit checked out, in
# place of `dev-main` from GitHub: that is the one step that differs from
# the page. INSTALL_SKIP_CREATE=1 takes a site that has Laravel and Sunrice
# already, to try the rest again.
set -euo pipefail

package=$(pwd)
site=${1:-${RUNNER_TEMP:-/tmp}/sunrice-site}
step() { printf '\n=== %s\n' "$1"; }

step "What Composer will be given: the built script, and none of the tests"
shipped=$(git archive --format=tar HEAD | tar -t)
for needed in composer.json src/VisualEditorServiceProvider.php config/sunrice-visual-editor.php routes/admin.php dist/visual-editor.js dist/visual-editor.css dist/bridge.js docs/installation.md; do
    echo "$shipped" | grep -qx "$needed" || { echo "Not in the archive: $needed"; exit 1; }
done
for kept_out in tests/ workbench/ spike/ node_modules/ resources/js/; do
    ! echo "$shipped" | grep -q "^$kept_out" || { echo "In the archive, and should not be: $kept_out"; exit 1; }
done
echo "$(echo "$shipped" | grep -vc '/$') files"

if [ -z "${INSTALL_SKIP_CREATE:-}" ]; then
    step "A new Laravel application, and Sunrice as its own installation page says"
    "$package/.github/new-site.sh" "$site"
fi
cd "$site"

step "docs/installation.md: Install"
git -C "$package" branch -f install-check HEAD
composer config repositories.sunrice-visual-editor vcs "$package"
composer require sunrice/visual-editor:dev-install-check --no-interaction --no-ansi
php artisan vendor:publish --tag=sunrice-visual-editor-assets --force --no-ansi
php artisan sunrice:sync-permissions --no-ansi
# A second time: Sunrice's sync used to fail on a package's permissions that exist.
php artisan sunrice:sync-permissions --no-ansi

step "docs/installation.md: Where it is on"
routes() { php artisan route:list --json | { grep -o '"uri":"[^"]*visual-editor' || true; } | wc -l; }
on=$(APP_ENV=local routes)
elsewhere=$(APP_ENV=production routes)
asked=$(APP_ENV=production SUNRICE_VISUAL_EDITOR=true routes)
refused=$(APP_ENV=local SUNRICE_VISUAL_EDITOR=false routes)
echo "routes: local $on, production $elsewhere, production and asked for $asked, local and turned off $refused"
[ "$on" -gt 0 ] && [ "$elsewhere" -eq 0 ] && [ "$asked" -eq "$on" ] && [ "$refused" -eq 0 ]

step "The site as installed"
mkdir -p tests/Feature
cp "$package/.github/install/InstalledTest.php" tests/Feature/InstalledTest.php
# As a test run (Laravel checks no CSRF token then), with the editor asked for, on the site's own database.
SUNRICE_VISUAL_EDITOR=true DB_CONNECTION=sqlite DB_DATABASE="$site/database/database.sqlite" vendor/bin/phpunit --no-coverage --colors=never tests/Feature/InstalledTest.php | tee "$site/storage/logs/installed-test.log"
tests=$(grep -E '^OK \(' "$site/storage/logs/installed-test.log")

step "docs/installation.md: Removing"
composer remove sunrice/visual-editor --no-interaction --no-ansi
rm -rf public/vendor/sunrice-visual-editor tests/Feature/InstalledTest.php
[ "$(routes)" -eq 0 ]
php artisan sunrice:sync-permissions --no-ansi
echo "Removed, and the site still answers its commands."

# Said where it can be read without the job's log: on the run's page.
summary="Laravel $(php artisan --version --no-ansi | grep -o '[0-9][0-9.]*' | head -n 1), PHP $(php -r 'echo PHP_VERSION;'). Routes: local $on, production $elsewhere, production and asked for $asked, local and turned off $refused. The site as installed: $tests. Removed cleanly."
echo "$summary"
[ -z "${GITHUB_ACTIONS:-}" ] || echo "::notice title=Installed on a new site::$summary"
