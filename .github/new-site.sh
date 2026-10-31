#!/usr/bin/env bash
# new-site.sh <directory>: a new Laravel application with the Sunrice panel
# and CMS installed as their own installation page says, the CMS's starter
# templates and its demo content (an About page at /about). The checks that
# need a site begin from this.
set -euo pipefail

site=$1
rm -rf "$site"
composer create-project laravel/laravel "$site" --no-interaction --prefer-dist --no-ansi
cd "$site"
touch database/database.sqlite

# The panel and the CMS, each from its read-only split of Digital-Citra-Kreatif/sunrice.
composer config repositories.sunrice-admin '{"type":"vcs","url":"https://github.com/Digital-Citra-Kreatif/sunrice-admin","no-api":true}'
composer config repositories.sunrice-cms '{"type":"vcs","url":"https://github.com/Digital-Citra-Kreatif/sunrice-cms","no-api":true}'
# SUNRICE_REF tries a branch of Sunrice in place of main: "dev-some-branch as dev-main".
composer require "sunrice/admin:${SUNRICE_REF:-dev-main}" "sunrice/cms:${SUNRICE_REF:-dev-main}" --no-interaction --no-ansi
# The user model needs Spatie's roles; the install command asks to add them, and without interaction stops.
sed -i 's/use HasFactory, Notifiable;/use HasFactory, Notifiable, \\Spatie\\Permission\\Traits\\HasRoles;/' app/Models/User.php
grep -q HasRoles app/Models/User.php
# It writes the panel provider with the CMS's plugin; without interaction it only prints the lines that
# take Laravel's welcome route out of routes/web.php (which would hide the CMS's homepage), so do that here.
php artisan sunrice:install --no-user --no-interaction --no-ansi
sed -i "/Route::get('\/', function () {/,/});/d" routes/web.php
! grep -q "view('welcome')" routes/web.php
php artisan vendor:publish --tag=sunrice-cms-templates --no-ansi
# The CMS's demo has texts in English and Indonesian, and a new site has English only: the demo needs both.
echo 'SUNRICE_LOCALES=en,id' >> .env
php artisan config:clear --no-ansi
php artisan sunrice:demo-content --no-ansi
