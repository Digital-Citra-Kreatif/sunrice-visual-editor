<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Tests\Support\WithStarterSite;
use Workbench\App\Seeders\DemoSeeder;

use function Pest\Laravel\get;

uses(WithStarterSite::class);

beforeEach(fn () => $this->setUpStarterSite());

it('renders the homepage from the layout, header, footer and block partials', function () {
    get('/')
        ->assertOk()
        ->assertSee(DemoSeeder::SITE_NAME)                  // partials/header: the `site` global
        ->assertSee('Articles')                             // partials/menu: the `main` menu
        ->assertSee(DemoSeeder::HERO_HEADING)               // blocks/hero
        ->assertSee('href="'.DemoSeeder::HERO_LINK.'"', false) // blocks/hero: link field
        ->assertSee('<code>blocks/</code>', false)          // blocks/text: rich text
        ->assertSee('Built with Sunrice CMS.');             // partials/footer: the `footer` global
});

it('renders a page, the article listing and an article', function () {
    get('/about')->assertOk()->assertSee('A page with a rich text body and no blocks.');

    get('/articles')->assertOk()
        ->assertSee('First post')
        ->assertSee('Fifth post')
        ->assertSee('A short summary of the Third post.');

    get('/articles/second-post')->assertOk()->assertSee('The body of the Second post.');
});
