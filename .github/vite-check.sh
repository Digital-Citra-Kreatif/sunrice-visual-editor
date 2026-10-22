#!/usr/bin/env bash
# What the editor does on a site whose CSS is Tailwind built by Vite, the
# way a new Laravel application is set up: found out on such a site, with a
# build and with a dev server.
#
#   .github/vite-check.sh [directory for the site]
#
# Needs Composer, Node and npm, and for the last part Chrome.
set -euo pipefail

package=$(pwd)
site=${1:-${RUNNER_TEMP:-/tmp}/sunrice-vite-site}
step() { printf '\n=== %s\n' "$1"; }
fail() { echo "$1"; exit 1; }
phpunit() { SUNRICE_VISUAL_EDITOR=true DB_CONNECTION=sqlite DB_DATABASE="$site/database/database.sqlite" vendor/bin/phpunit --no-coverage --colors=never tests/Feature/ViteTest.php "$@"; }
built() { cat public/build/assets/*.css; }

step "A new Laravel application with Sunrice and the editor"
"$package/.github/new-site.sh" "$site"
cd "$site"
git -C "$package" branch -f vite-check HEAD
composer config repositories.sunrice-visual-editor vcs "$package"
composer require sunrice/visual-editor:dev-vite-check --no-interaction --no-ansi
sed -i 's/->plugin(CmsPlugin::make());/->plugin(CmsPlugin::make())\n            ->plugin(\\Sunrice\\VisualEditor\\VisualEditorPlugin::make());/' app/Providers/AdminPanelProvider.php
grep -q VisualEditorPlugin app/Providers/AdminPanelProvider.php
php artisan vendor:publish --tag=sunrice-visual-editor-assets --force --no-ansi
php artisan sunrice:sync-permissions --no-ansi
mkdir -p tests/Feature
cp "$package/.github/vite/ViteTest.php" tests/Feature/ViteTest.php

step "The layout takes its CSS from Vite, as Laravel's own welcome page does"
layout=resources/views/cms/layout.blade.php
sed -i "s|^    @stack('head')|    @vite(['resources/css/app.css', 'resources/js/app.js'])\n    @stack('head')|" "$layout"
grep -q "@vite(\['resources/css/app.css'" "$layout" || fail "The layout did not take the @vite line."

step "npm install, and a first build"
npm install --no-audit --no-fund
npm run build
echo "tailwindcss $(node -p "require('tailwindcss/package.json').version"), vite $(node -p "require('vite/package.json').version")"
! built | grep -q 'bg-fuchsia-500' || fail "The built CSS has a rule for bg-fuchsia-500 before any template uses it."

step "Built: the layout opens, a page is marked, and a class is added in the editor"
phpunit --filter 'test_the_layout_opens|test_built_'
SVE_CLASS=bg-fuchsia-500 phpunit --filter test_a_class_is_added
! built | grep -q 'bg-fuchsia-500' || fail "The built CSS changed without a build."

step "Built again: Tailwind found the class in the template the editor wrote"
npm run build
built | grep -q '\.bg-fuchsia-500' || fail "After a build the CSS still has no rule for bg-fuchsia-500."

step "Built from the editor: whoever may build runs the site's own command, and the class is in the CSS"
echo 'SUNRICE_VISUAL_EDITOR_BUILD="npm run build"' >> .env
SVE_CLASS=bg-teal-600 phpunit --filter test_a_class_is_added
! built | grep -q 'bg-teal-600' || fail "The built CSS changed without a build."
SUNRICE_VISUAL_EDITOR_BUILD="npm run build" phpunit --filter test_build_
built | grep -q '\.bg-teal-600' || fail "After a build from the editor the CSS still has no rule for bg-teal-600."
SUNRICE_VISUAL_EDITOR_BUILD="no-such-build-command --production" phpunit --filter test_no_build_

step "Neither built nor served: the preview shows a class in the editor, and not to a visitor, until the Build button is used"
echo 'SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW=true' >> .env
SVE_CLASS=pt-9 phpunit --filter test_a_class_is_added
! built | grep -q '\.pt-9' || fail "The built CSS has a rule for pt-9 without a build."
SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW=true phpunit --filter test_preview_
php artisan serve --port=8091 --no-ansi > storage/logs/serve.log 2>&1 &
served=$!
trap 'kill $served 2>/dev/null || true' EXIT
(cd "$package" && npm ci --no-audit --no-fund)
for attempt in $(seq 1 30); do curl -fsS -o /dev/null http://127.0.0.1:8091/admin/login && break; sleep 1; done
BASE_URL=http://127.0.0.1:8091 node "$package/.github/vite/preview.mjs" | tee storage/logs/preview.log
previewed="The preview, neither built nor served, and then a build from the editor's bar: $(grep -c '^ok' storage/logs/preview.log) steps in a browser."

step "With the dev server running, no build is needed"
# Laravel's plugin won't start a dev server where CI is set, unless told that it is meant.
LARAVEL_BYPASS_ENV_CHECK=1 node node_modules/vite/bin/vite.js > storage/logs/vite.log 2>&1 &
vite=$!
trap 'kill $vite $served 2>/dev/null || true' EXIT
for attempt in $(seq 1 60); do [ -f public/hot ] && break; sleep 0.5; done
[ -f public/hot ] || { tail -n 30 storage/logs/vite.log; fail "The dev server did not start."; }
dev=$(cat public/hot)
echo "dev server at $dev"
phpunit --filter test_dev_
SVE_CLASS=bg-lime-300 phpunit --filter test_a_class_is_added
found=""
for attempt in $(seq 1 40); do
    if curl -gsS -H 'Accept: text/css' "$dev/resources/css/app.css" | grep -q 'bg-lime-300'; then found=yes; break; fi
    sleep 0.5
done
[ -n "$found" ] || { tail -n 30 storage/logs/vite.log; fail "The dev server's CSS has no rule for bg-lime-300."; }
! built | grep -q 'bg-lime-300' || fail "The built CSS changed without a build."

step "The editor itself, in a browser, with the dev server running"
BASE_URL=http://127.0.0.1:8091 node "$package/.github/vite/browser.mjs" | tee storage/logs/browser.log
browser="In a browser with the dev server running: $(grep -c '^ok' storage/logs/browser.log) steps."

kill $vite $served 2>/dev/null || true
summary="tailwindcss $(node -p "require('tailwindcss/package.json').version"), vite $(node -p "require('vite/package.json').version"). A class added in the editor: in the built CSS after a build, run by hand or from the editor; in the dev server's CSS without one. $previewed $browser"
echo "$summary"
[ -z "${GITHUB_ACTIONS:-}" ] || echo "::notice title=Vite and Tailwind::$summary"
