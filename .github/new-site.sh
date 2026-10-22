#!/usr/bin/env bash
# new-site.sh <directory>: a new Laravel application with the Sunrice panel
# and CMS installed as their installation page says, from a checkout of the
# Sunrice monorepo (SUNRICE_PATH, else ../sunrice beside this package): the
# CMS's starter templates and its demo content (an About page at /about).
# The checks that need a site begin from this.
set -euo pipefail

site=$1
sunrice=${SUNRICE_PATH:-$(cd "$(dirname "$0")/../../sunrice" && pwd)}
[ -d "$sunrice/packages/cms" ] || { echo "No Sunrice checkout at $sunrice: set SUNRICE_PATH."; exit 1; }

rm -rf "$site"
composer create-project laravel/laravel "$site" --no-interaction --prefer-dist --no-ansi
cd "$site"
touch database/database.sqlite

# Sunrice is not on Packagist yet: its packages come from the checkout.
composer config minimum-stability dev
composer config prefer-stable true
composer config repositories.sunrice path "$sunrice/packages/*"
composer require "sunrice/cms:@dev" --no-interaction --no-ansi

# 1. The user model needs Spatie's roles.
sed -i 's/use HasFactory, Notifiable;/use HasFactory, Notifiable, \\Spatie\\Permission\\Traits\\HasRoles;/' app/Models/User.php
grep -q HasRoles app/Models/User.php

# 2. The panel provider, with the CMS.
cat > app/Providers/AdminPanelProvider.php <<'PHP'
<?php

declare(strict_types=1);

namespace App\Providers;

use Sunrice\Admin\Panel;
use Sunrice\Admin\PanelProvider;
use Sunrice\Cms\CmsPlugin;

final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->path('admin')
            ->brand('Acme')
            ->plugin(CmsPlugin::make());
    }
}
PHP
# Laravel lists its providers as `AppServiceProvider::class` (imported) or by their full name.
sed -i -E 's/^(    (App\\Providers\\)?AppServiceProvider::class,)$/\1\n    App\\Providers\\AdminPanelProvider::class,/' bootstrap/providers.php
grep -q AdminPanelProvider bootstrap/providers.php

# 3. The install command, the starter templates and the demo content.
php artisan sunrice:install --roles --no-user --no-interaction --no-ansi
php artisan vendor:publish --tag=sunrice-cms-templates --no-ansi
# The demo has texts in English and Indonesian, and refuses a site without both.
SUNRICE_LOCALES=en,id php artisan sunrice:demo-content --no-ansi
