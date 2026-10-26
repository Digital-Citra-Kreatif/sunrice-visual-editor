<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Sunrice\Cms\Structure\StructureImporter;
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

const SVE_INSERTS = '/admin/visual-editor/api/inserts';

beforeEach(function () {
    $this->setUpStarterSite();
    File::deleteDirectory(storage_path('app/sunrice-cms/template-backups'));
});

afterEach(fn () => File::deleteDirectory(storage_path('app/sunrice-cms/template-backups')));

/** A taxonomy and a form, which the demo site doesn't have. */
function sveTaxonomyAndForm(): void
{
    $field = fn (string $handle, string $type, string $label, array $config = [], array $more = []): array => ['handle' => $handle, 'type' => $type, 'label' => $label, 'config' => $config, ...$more];
    app(StructureImporter::class)->import([
        'blueprints' => [['handle' => 'contact_form', 'title' => 'Contact form', 'fields' => [
            $field('name', 'text', 'Name', [], ['required' => true]),
            $field('message', 'textarea', 'Message'),
            $field('topic', 'select', 'Topic', ['options' => [['value' => 'sales', 'label' => 'Sales'], ['value' => 'support', 'label' => 'Support']]]),
            $field('news', 'toggle', 'Send me news'),
            $field('cv', 'file', 'CV'),
            $field('day', 'date', 'Day'),
        ]]],
        'taxonomies' => [['handle' => 'topics', 'title' => 'Topics', 'route' => '/topics/{slug}', 'collections' => ['articles']]],
        'forms' => [['handle' => 'contact', 'title' => 'Contact', 'blueprint' => 'contact_form']],
    ]);
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
        ->and($inserts['partial.cms.partials.card'])->toMatchArray(['title' => 'partials/card', 'hint' => 'Needs $item', 'blade' => "@include('cms.partials.card')"])
        ->and($inserts['partial.cms.partials.fields']['hint'])->toBe('Needs $values, $richText')
        // Neither the layout nor blocks nor the CMS's own views.
        ->and($inserts->keys()->filter(fn (string $id) => str_contains($id, 'layout') || str_contains($id, '.blocks.') || str_contains($id, 'sunrice-cms::'))->all())->toBe([])
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

    $path = 'collections/show.blade.php';
    $file = $this->templates.'/'.$path;
    $original = File::get($file);
    $article = Template::open($original)->elements()[0];
    expect($article->name)->toBe('article');

    $drawn = [];
    foreach (app(Library::class)->all() as $insert) {
        $needs = str_starts_with($insert['hint'], 'Needs ') ? explode(', ', substr($insert['hint'], strlen('Needs '))) : [];
        if (array_diff($needs, ['$entry', '$values', '$richText', '$code', '$title', '$collection']) !== []) {
            // A partial that needs what an entry's page doesn't have: it says so, and is for another place.
            continue;
        }
        File::put($file, $original);

        $response = postJson('/admin/visual-editor/api/templates/'.$path.'/operations', [
            'version' => sha1($original),
            'operations' => [['type' => 'append', 'node' => $article->id(), 'blade' => $insert['blade']]],
        ]);
        $response->assertOk();

        $response = get('/about');
        expect($response->getStatusCode())->toBe(200, $insert['id'].': '.substr(strip_tags((string) $response->getContent()), 0, 400));
        $drawn[$insert['id']] = (string) $response->getContent();
    }
    File::put($file, $original);
    // The card has a @php(…) further up. Blade would take that for the start of a new block of PHP and
    // show the code between the two as text: the block is refused there, with the reason.
    $card = File::get($this->templates.'/partials/card.blade.php');
    $response = postJson('/admin/visual-editor/api/templates/partials/card.blade.php/operations', [
        'version' => sha1($card),
        'operations' => [['type' => 'append', 'node' => Template::open($card)->elements()[0]->id(), 'blade' => "@php\n@endphp"]],
    ])->assertStatus(422)->assertJsonPath('reason', 'rejected');
    expect($response->json('message'))->toContain('@php(…) above');

    expect(count($drawn))->toBeGreaterThan(45)
        ->and($drawn['element.heading'])->toContain('<h2>Heading</h2>')
        ->and($drawn['blade.echo'])->toContain('Value')
        // The component found the collection and drew its entries.
        ->and($drawn['sunrice.entries.articles'])->toContain('First post</a></h3>', 'Fifth post</a></h3>')
        ->and($drawn['sunrice.terms.topics'])->toContain('<ul>')
        ->and($drawn['sunrice.search'])->toContain('role="search"')
        ->and($drawn['sunrice.language-switcher'])->toBeString()
        // The form, with an input for each kind of field it has, as the CMS prints it.
        ->and($drawn['sunrice.form.contact'])->toContain('name="data[name]"', '<textarea', '<option value="sales"', '<option value="support"', 'type="checkbox"', 'type="file"', 'type="date"', 'Send me news')
        // A partial given what it needs by the page.
        ->and($drawn['partial.cms.partials.fields'])->toContain('A page with a rich text body and no blocks.');
});
