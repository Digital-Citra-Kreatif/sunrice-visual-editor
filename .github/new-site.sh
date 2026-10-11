#!/usr/bin/env bash
# new-site.sh <directory>: a new Laravel application with Sunrice installed
# as Sunrice's own installation page says, its starter templates and three
# demo articles (at /demo). The checks that need a site begin from this.
set -euo pipefail

site=$1
rm -rf "$site"
composer create-project laravel/laravel "$site" --no-interaction --prefer-dist --no-ansi
cd "$site"
touch database/database.sqlite

composer config repositories.sunrice-cms vcs https://github.com/littleboy130491/sunrice-cms
composer require sunrice/cms:dev-main --no-interaction --no-ansi
# The user model needs Spatie's roles; Sunrice's installer says so.
sed -i 's/use HasFactory, Notifiable;/use HasFactory, Notifiable, \\Spatie\\Permission\\Traits\\HasRoles;/' app/Models/User.php
grep -q HasRoles app/Models/User.php
php artisan sunrice:install --no-user --no-ansi
php artisan vendor:publish --tag=sunrice-templates --no-ansi
php artisan sunrice:demo-content 3 --no-ansi
