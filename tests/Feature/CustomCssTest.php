<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Sunrice\VisualEditor\Rendering\CustomCss;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(WithStarterSite::class);

const SVE_CUSTOM = '/admin/visual-editor/api/styles/custom';

beforeEach(function () {
    $this->setUpStarterSite();
    $this->custom = $this->theme.'/custom.css';
    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice-cms/template-backups')));

/** The links to the custom CSS in a page of the site. */
function customLinks(string $path = '/'): array
{
    preg_match_all('/<link rel="stylesheet" href="([^"]*custom\.css[^"]*)" data-sunrice-custom-css>/', (string) get($path)->assertOk()->getContent(), $links);

    return $links[1];
}

it('says what the custom CSS is before there is any', function () {
    actingAsSuperAdmin();

    expect(getJson(SVE_CUSTOM)->assertOk()->json('custom'))->toMatchArray([
        'path' => 'custom.css',
        'displayPath' => $this->custom,
        'version' => sha1(''),
        'exists' => false,
        'source' => '',
        'injected' => true,
        'stack' => 'head',
    ])->and(getJson(SVE_CUSTOM)->json('custom.url'))->toEndWith('/cms/custom.css');
});

it('describes the configured custom CSS path relative to the application', function () {
    actingAsSuperAdmin();
    config(['sunrice-visual-editor.paths.theme' => public_path('brand'), 'sunrice-visual-editor.custom_css.file' => 'css/overrides.css']);
    getJson(SVE_CUSTOM)->assertOk()->assertJsonPath('custom.displayPath', 'public/brand/css/overrides.css');
});

it('writes the custom CSS, and every page of the site links it from the layout without a template being changed', function () {
    actingAsSuperAdmin();
    $layout = File::get($this->templates.'/layout.blade.php');
    expect(customLinks())->toBe([]);

    putJson('/admin/visual-editor/api/styles/files/custom.css/source', ['version' => sha1(''), 'source' => ".hero { color: tomato; }\n"])
        ->assertOk()
        ->assertJsonPath('file.version', sha1(".hero { color: tomato; }\n"));

    expect(File::get($this->custom))->toBe(".hero { color: tomato; }\n")
        ->and(getJson(SVE_CUSTOM)->json('custom'))->toMatchArray(['exists' => true, 'source' => ".hero { color: tomato; }\n"])
        // Once on a page, in its head, with the file's version: a save is fetched anew.
        ->and(customLinks())->toHaveCount(1)
        ->and(customLinks()[0])->toEndWith('/cms/custom.css?v='.substr(sha1(".hero { color: tomato; }\n"), 0, 10))
        ->and(customLinks('/about'))->toHaveCount(1)
        ->and(File::get($this->templates.'/layout.blade.php'))->toBe($layout);

    $html = (string) get('/')->getContent();
    expect(strpos($html, 'data-sunrice-custom-css'))->toBeLessThan(strpos($html, '</head>'));
});

it('links nothing while the custom CSS is empty', function () {
    File::put($this->custom, "  \n");

    expect(customLinks())->toBe([]);
});

it('links nothing when the site links the file itself', function () {
    File::put($this->custom, '.hero { color: tomato; }');
    config(['sunrice-visual-editor.custom_css.inject' => false]);

    expect(CustomCss::injected())->toBeFalse()
        ->and(CustomCss::link())->not->toBeNull()
        // Not linked: the composer was asked for at boot, and asks the setting again as each page is drawn.
        ->and(customLinks())->toBe([]);
});

it('refuses a save made against another version of the custom CSS', function () {
    actingAsSuperAdmin();
    File::put($this->custom, '.a { color: red; }');

    putJson('/admin/visual-editor/api/styles/files/custom.css/source', ['version' => sha1(''), 'source' => '.b {}'])->assertStatus(409);

    expect(File::get($this->custom))->toBe('.a { color: red; }');
});

it('lets someone who may only look read the custom CSS, not write it', function () {
    $user = createUser();
    $guard = Access::guard();
    $user->givePermissionTo(Permission::findOrCreate('sunrice.access', $guard), Permission::findOrCreate('visual-editor.open', $guard));
    actingAs($user, $guard);

    getJson(SVE_CUSTOM)->assertOk();
    putJson('/admin/visual-editor/api/styles/files/custom.css/source', ['version' => sha1(''), 'source' => '.b {}'])->assertForbidden();

    expect(File::exists($this->custom))->toBeFalse();
});
