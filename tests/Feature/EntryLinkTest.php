<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Sunrice\Admin\Panel;
use Sunrice\Cms\Actions\Entries\TrashEntry;
use Sunrice\Cms\Actions\Entries\UnpublishEntry;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\VisualEditor\Support\Access;
use Sunrice\VisualEditor\Tests\Support\WithStarterSite;
use Workbench\App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(WithStarterSite::class);

beforeEach(function () {
    $this->setUpStarterSite();
    $this->about = EntryTranslation::query()->where('title', 'About')->with('entry.collection')->firstOrFail();
});

/** The entry's page in the admin, in the CMS's editor. */
function entryPage(EntryTranslation $translation): string
{
    return route('sunrice.admin.cms.collections.entries.edit', ['collection' => $translation->entry->collection->handle, 'entry' => $translation->entry_id]);
}

/** Someone in the admin who may see and edit entries, with these of the editor's permissions. */
function entryEditor(string ...$permissions): User
{
    $user = createUser(email: 'editor'.uniqid().'@example.com');
    foreach ([Panel::ACCESS, 'cms.entries.view', 'cms.entries.update', ...$permissions] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, Access::guard()));
    }

    return $user;
}

it('links the entry\'s page to the editor on it, in the entry page\'s menu', function () {
    actingAsSuperAdmin();

    get(entryPage($this->about))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Cms/Entries/Edit', false)
        ->where('links', [['label' => 'Edit with Visual Editor', 'href' => '/admin/visual-editor?url=%2Fabout']]));

    // And the link opens the editor on that page.
    get('/admin/visual-editor?url=%2Fabout')->assertInertia(fn (AssertableInertia $page) => $page->where('startPath', '/about'));
});

it('links it for whoever may open the editor, and for nobody else', function () {
    actingAs(entryEditor(Access::OPEN), Access::guard());
    get(entryPage($this->about))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('links', [['label' => 'Edit with Visual Editor', 'href' => '/admin/visual-editor?url=%2Fabout']]));

    actingAs(entryEditor(Access::BUILD), Access::guard());
    get(entryPage($this->about))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));

    actingAs(entryEditor(), Access::guard());
    get(entryPage($this->about))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));
});

it('does not link an entry with no page on the site to open', function () {
    actingAsSuperAdmin();

    // A draft: never published, or taken off the site.
    app(UnpublishEntry::class)->handle($this->about);
    get(entryPage($this->about))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));

    // Published with a date to come.
    $this->about->forceFill(['status' => EntryTranslation::PUBLISHED, 'published_at' => now()->addWeek()])->save();
    get(entryPage($this->about))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));

    // Live, but in the trash.
    $this->about->forceFill(['published_at' => now()->subDay()])->save();
    get(entryPage($this->about))->assertInertia(fn (AssertableInertia $page) => $page->where('links', [['label' => 'Edit with Visual Editor', 'href' => '/admin/visual-editor?url=%2Fabout']]));
    app(TrashEntry::class)->handle($this->about->entry);
    get(entryPage($this->about))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('links', []));
});
