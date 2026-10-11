<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Models\Form;
use Sunrice\Models\Taxonomy;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Insert\Library;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(WithStarterSite::class);

/*
 * The list of what can be inserted, for this site, and each entry of it
 * put into a template through the API and drawn on a page.
 */

const SVE_INSERTS = '/cms/visual-editor/api/inserts';

beforeEach(function () {
    $this->setUpStarterSite();
    File::deleteDirectory(storage_path('app/sunrice/template-backups'));
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice/template-backups')));

/** A taxonomy and a form, which the demo site doesn't have. */
function sveTaxonomyAndForm(): void
{
    Taxonomy::create(['handle' => 'topics', 'title' => 'Topics', 'settings' => []]);
    Form::create(['handle' => 'contact', 'title' => 'Contact', 'settings' => [], 'fields' => [
        ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
        ['handle' => 'message', 'type' => 'textarea', 'label' => 'Message'],
        ['handle' => 'topic', 'type' => 'select', 'label' => 'Topic', 'config' => ['options' => [['value' => 'sales', 'label' => 'Sales'], 'support']]],
        ['handle' => 'news', 'type' => 'toggle', 'label' => 'Send me news'],
        ['handle' => 'cv', 'type' => 'file', 'label' => 'CV'],
        ['handle' => 'day', 'type' => 'date', 'label' => 'Day'],
    ]]);
}

it('lists what can be inserted into the templates of this site', function () {
    actingAsSuperAdmin();

    $inserts = collect(getJson(SVE_INSERTS)->assertOk()->json('inserts'))->keyBy('id');

    expect(array_values($inserts->pluck('group')->unique()->all()))->toBe([Library::ELEMENTS, Library::BLADE, Library::SUNRICE, Library::PARTIALS])
        ->and($inserts['element.heading'])->toBe(['id' => 'element.heading', 'group' => 'Elements', 'title' => 'Heading', 'hint' => '<h2>', 'blade' => '<h2>Heading</h2>', 'keywords' => 'title h1 h2 h3 h4'])
        // A component for each collection the site has.
        ->and($inserts)->toHaveKeys(['sunrice.entries.articles', 'sunrice.entries.pages', 'sunrice.search', 'sunrice.seo'])
        ->and($inserts['sunrice.entries.articles']['title'])->toBe('Entries: Articles')
        // The site's partials, each with what it needs.
        ->and($inserts['partial.sunrice.partials.card'])->toMatchArray(['title' => 'partials/card', 'hint' => 'Needs $entry', 'blade' => "@include('sunrice.partials.card')"])
        ->and($inserts)->toHaveKey('partial.sunrice.partials.header')
        // Neither layouts nor blocks nor Sunrice's own views.
        ->and($inserts->keys()->filter(fn (string $id) => str_contains($id, 'layouts') || str_contains($id, 'blocks') || str_contains($id, 'sunrice::'))->all())->toBe([])
        // The demo site has no taxonomy and no form.
        ->and($inserts->keys()->filter(fn (string $id) => str_starts_with($id, 'sunrice.terms.') || str_starts_with($id, 'sunrice.form.'))->all())->toBe([]);

    sveTaxonomyAndForm();
    $inserts = collect(getJson(SVE_INSERTS)->assertOk()->json('inserts'))->keyBy('id');

    expect($inserts['sunrice.terms.topics']['title'])->toBe('Terms: Topics')
        ->and($inserts['sunrice.form.contact']['title'])->toBe('Form: Contact');
});

it('draws a page with each thing inserted into its template', function () {
    actingAsSuperAdmin();
    sveTaxonomyAndForm();

    $path = 'show.blade.php';
    $file = $this->templates.'/'.$path;
    $original = File::get($file);
    $article = Template::open($original)->elements()[0];
    expect($article->name)->toBe('article');

    $drawn = [];
    foreach (app(Library::class)->all() as $insert) {
        if (str_starts_with($insert['hint'], 'Needs ') && ! in_array($insert['hint'], ['Needs $entry'], true)) {
            // A partial that needs what this page doesn't have: it says so, and is for another place.
            continue;
        }
        File::put($file, $original);

        $response = postJson('/cms/visual-editor/api/templates/'.$path.'/operations', [
            'version' => sha1($original),
            'operations' => [['type' => 'append', 'node' => $article->id(), 'blade' => $insert['blade']]],
        ]);
        if ($insert['id'] === 'blade.php') {
            // This template has a @php(…) further up. Blade would take that for the start of the new block and
            // show the code between the two as text: the block is refused here, with the reason.
            $response->assertStatus(422)->assertJsonPath('reason', 'rejected');
            expect($response->json('message'))->toContain('@php(…) above');

            continue;
        }
        $response->assertOk();

        $response = get('/about');
        expect($response->getStatusCode())->toBe(200, $insert['id'].': '.substr(strip_tags((string) $response->getContent()), 0, 400));
        $drawn[$insert['id']] = (string) $response->getContent();
    }
    // Where no @php(…) stands before it, a block of PHP goes in like the rest.
    File::put($file, "<article>\n    <h1>{{ \$entry->title }}</h1>\n</article>\n");
    postJson('/cms/visual-editor/api/templates/'.$path.'/operations', [
        'version' => sha1(File::get($file)),
        'operations' => [['type' => 'append', 'node' => Template::open(File::get($file))->elements()[0]->id(), 'blade' => "@php\n@endphp"]],
    ])->assertOk();
    get('/about')->assertOk();
    File::put($file, $original);

    expect(count($drawn))->toBeGreaterThan(45)
        ->and($drawn['element.heading'])->toContain('<h2>Heading</h2>')
        ->and($drawn['blade.echo'])->toContain('Value')
        // The component found the collection and drew its entries.
        ->and($drawn['sunrice.entries.articles'])->toContain('First post</a></h3>', 'Fifth post</a></h3>')
        ->and($drawn['sunrice.terms.topics'])->toContain('<ul>')
        ->and($drawn['sunrice.search'])->toContain('role="search"')
        // The form, with an input for each kind of field it has.
        ->and($drawn['sunrice.form.contact'])->toContain('name="data[name]"', '<textarea id="contact-message"', '<option value="sales"', '<option value="support"', 'type="checkbox"', 'type="file"', 'type="date"', '>Send me news</label>')
        ->and($drawn['partial.sunrice.partials.card'])->toContain('<time class="meta"');
});
