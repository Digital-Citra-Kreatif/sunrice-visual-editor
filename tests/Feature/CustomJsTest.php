<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Admin\Activity\Activity;
use Sunrice\VisualEditor\Rendering\CustomJs;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

uses(WithStarterSite::class);

const SVE_CUSTOM_JS = '/admin/visual-editor/api/scripts/custom';

beforeEach(function () {
    $this->setUpStarterSite();
    $this->custom = $this->theme.'/custom.js';
    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice-cms/template-backups')));

/** The scripts tags for the custom script in a page of the site. */
function customScripts(string $path = '/'): array
{
    preg_match_all('/<script src="([^"]*custom\.js[^"]*)" defer data-sunrice-custom-js><\/script>/', (string) get($path)->assertOk()->getContent(), $tags);

    return $tags[1];
}

it('says what the custom script is before there is any', function () {
    actingAsSuperAdmin();

    expect(getJson(SVE_CUSTOM_JS)->assertOk()->json('custom'))->toMatchArray([
        'path' => 'custom.js',
        'version' => sha1(''),
        'exists' => false,
        'source' => '',
        'injected' => true,
        'stack' => 'head',
    ])->and(getJson(SVE_CUSTOM_JS)->json('custom.url'))->toEndWith('/cms/custom.js');
});

it('writes the custom script, and every page of the site loads it at the end of the body without a template being changed', function () {
    actingAsSuperAdmin();
    $layout = File::get($this->templates.'/layout.blade.php');
    $script = "document.documentElement.dataset.custom = 'yes';\n";
    expect(customScripts())->toBe([]);

    putJson(SVE_CUSTOM_JS, ['version' => sha1(''), 'source' => $script])
        ->assertOk()
        ->assertJsonPath('custom.version', sha1($script))
        ->assertJsonPath('custom.exists', true);

    expect(File::get($this->custom))->toBe($script)
        ->and(customScripts())->toHaveCount(1)
        ->and(customScripts()[0])->toEndWith('/cms/custom.js?v='.substr(sha1($script), 0, 10))
        ->and(customScripts('/about'))->toHaveCount(1)
        ->and(File::get($this->templates.'/layout.blade.php'))->toBe($layout)
        ->and(Activity::query()->where('subject_label', 'cms/custom.js')->count())->toBe(1);

    // In the head, deferred: it runs once the page is read, as a script at the end of the body would.
    $html = (string) get('/')->getContent();
    expect(strpos($html, 'data-sunrice-custom-js'))->toBeLessThan(strpos($html, '</head>'))
        ->and($html)->toMatch('#<script src="[^"]+/cms/custom\.js\?v=[0-9a-f]{10}" defer data-sunrice-custom-js></script>#');

    // A second save keeps the first as a backup.
    putJson(SVE_CUSTOM_JS, ['version' => sha1($script), 'source' => "// nothing\n"])->assertOk();
    expect(File::allFiles(storage_path('app/sunrice-cms/template-backups')))->toHaveCount(1);
});

it('loads nothing while the custom script is empty, or when the site loads it itself', function () {
    File::put($this->custom, "  \n");
    expect(customScripts())->toBe([]);

    File::put($this->custom, 'console.log(1);');
    config(['sunrice-visual-editor.custom_js.inject' => false]);
    expect(CustomJs::injected())->toBeFalse()
        ->and(CustomJs::tag())->not->toBeNull()
        ->and(customScripts())->toBe([]);
});

it('refuses a save made against another version of the custom script, and one that is not text', function () {
    actingAsSuperAdmin();
    File::put($this->custom, 'console.log(1);');

    putJson(SVE_CUSTOM_JS, ['version' => sha1(''), 'source' => 'console.log(2);'])->assertStatus(409);
    putJson(SVE_CUSTOM_JS, ['version' => sha1('console.log(1);'), 'source' => "\xff\xfe"])->assertStatus(422);
    putJson(SVE_CUSTOM_JS, ['version' => sha1('console.log(1);')])->assertStatus(422);

    expect(File::get($this->custom))->toBe('console.log(1);');
});

it('names nothing it would write outside the theme directory', function () {
    config(['sunrice-visual-editor.custom_js.file' => '../custom.js']);
    expect(CustomJs::file())->toBeNull();

    actingAsSuperAdmin();
    getJson(SVE_CUSTOM_JS)->assertStatus(422);
    putJson(SVE_CUSTOM_JS, ['version' => sha1(''), 'source' => 'x'])->assertStatus(422);
    expect(File::exists(dirname($this->theme).'/custom.js'))->toBeFalse();
});
