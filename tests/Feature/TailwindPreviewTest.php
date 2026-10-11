<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\VisualEditor\Http\Middleware\EditorRender;
use Sunrice\VisualEditor\Rendering\TailwindPreview;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\get;

/*
 * Tailwind's browser build, added to pages drawn for the editor on a site
 * whose CSS is built: for the editor only, only when asked for, and not
 * while a dev server serves the real thing.
 */

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    File::deleteDirectory(EditorRender::directory());
    $this->entry = base_path('resources/css/sve-test-entry.css');
    File::ensureDirectoryExists(dirname($this->entry));
    File::put($this->entry, "@import 'tailwindcss';\n@source '../views';\n@theme {\n    --color-brand: #c2410c;\n}\n");
    config(['sunrice-visual-editor.tailwind' => ['preview' => true, 'entry' => 'resources/css/sve-test-entry.css', 'script' => 'https://cdn.example.com/tailwind-browser.js']]);
    actingAsSuperAdmin();
});

afterEach(function () {
    File::delete([$this->entry, public_path('hot')]);
    File::deleteDirectory(EditorRender::directory());
});

/** What the page's manifest says of a preview. */
function previewSaid(string $page): mixed
{
    preg_match('#<script type="application/json" id="sve-manifest">(.*?)</script>#s', $page, $match);

    return json_decode($match[1] ?? '{}', true)['preview'] ?? null;
}

it('adds the browser build and the site\'s theme to a page drawn for the editor, in its head', function () {
    $page = (string) get('/?sve=1')->assertOk()->getContent();
    $head = substr($page, 0, (int) stripos($page, '</head>'));

    expect($head)->toContain('<script src="https://cdn.example.com/tailwind-browser.js" data-sve-preview></script>')
        ->toContain('<style type="text/tailwindcss" data-sve-preview>')
        ->toContain("@import 'tailwindcss';")
        ->toContain('--color-brand: #c2410c;')
        // Where to look for classes means nothing to a browser.
        ->not->toContain('@source')
        ->and(previewSaid($page))->toBeTrue();
});

it('adds nothing to a page a visitor gets, or one asked for by someone who may not use the editor', function () {
    expect((string) get('/')->assertOk()->getContent())->not->toContain('data-sve-preview')->not->toContain('tailwind-browser.js');

    auth()->logout();
    expect((string) get('/?sve=1')->assertOk()->getContent())->not->toContain('data-sve-preview')->not->toContain('tailwind-browser.js');
});

it('adds nothing unless it is asked for', function () {
    config(['sunrice-visual-editor.tailwind.preview' => false]);
    $page = (string) get('/?sve=1')->assertOk()->getContent();

    expect($page)->not->toContain('data-sve-preview')->and(previewSaid($page))->toBeFalse();
    // And it is not asked for unless someone says so.
    expect((require __DIR__.'/../../config/sunrice-visual-editor.php')['tailwind']['preview'])->toBeFalse();
});

it('stays out of the way of a dev server, which serves the real CSS', function () {
    File::put(public_path('hot'), 'http://localhost:5173');
    $page = (string) get('/?sve=1')->assertOk()->getContent();

    expect(TailwindPreview::devServer())->toBeTrue()
        ->and($page)->not->toContain('data-sve-preview')
        ->and(previewSaid($page))->toBeFalse();
});

it('adds the browser build alone when the site has no such entry file', function () {
    config(['sunrice-visual-editor.tailwind.entry' => 'resources/css/not-there.css']);
    $page = (string) get('/?sve=1')->assertOk()->getContent();

    expect($page)->toContain('tailwind-browser.js')->not->toContain('text/tailwindcss')->and(previewSaid($page))->toBeTrue();
});

it('makes an entry file fit for the browser build', function (string $css, string $fit) {
    expect(TailwindPreview::fit($css))->toBe($fit);
})->with([
    'Tailwind\'s own import stays' => ["@import 'tailwindcss';\n@theme { --x: 1 }", "@import 'tailwindcss';\n@theme { --x: 1 }"],
    'and a part of it' => ['@import "tailwindcss/theme" layer(theme);', '@import "tailwindcss/theme" layer(theme);'],
    'another file brought in goes' => ["@import './buttons.css';\n.a{b:c}", "\n.a{b:c}"],
    'and one by url()' => ['@import url("https://fonts.example/x.css");.a{b:c}', '.a{b:c}'],
    'where to look for classes goes' => ["@source '../views';\n@source \"../../vendor/x/*.php\";\n.a{b:c}", "\n\n.a{b:c}"],
    'a plugin goes' => ["@plugin '@tailwindcss/typography';\n.a{b:c}", "\n.a{b:c}"],
    'a plugin with settings goes whole' => ["@plugin \"x\" {\n  strategy: base;\n}\n.a{b:c}", "\n.a{b:c}"],
    'a config file goes' => ['@config "../../tailwind.config.js";.a{b:c}', '.a{b:c}'],
    'the rest is as written' => ["@theme {\n  --font-sans: 'Inter', sans-serif;\n}\n@layer base { h1 { @apply text-2xl; } }", "@theme {\n  --font-sans: 'Inter', sans-serif;\n}\n@layer base { h1 { @apply text-2xl; } }"],
    'nothing in it ends the element it is put in' => ['.a::after{content:"</style><script>alert(1)</script>"}', '.a::after{content:"<\\/style><script>alert(1)</script>"}'],
    'however it is written' => ['.a{b:c}</STYLE >', '.a{b:c}<\\/style >'],
]);
