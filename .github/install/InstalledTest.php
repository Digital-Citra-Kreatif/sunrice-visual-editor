<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Run inside a new Laravel application on which Sunrice and the visual
 * editor were installed as docs/installation.md says (.github/install-check.sh
 * does that and copies this file in). It works on the site as installed:
 * its database, its templates, its theme.
 */
class InstalledTest extends TestCase
{
    private const HEADER = 'partials/header.blade.php';

    private string $header;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->header = resource_path('views/sunrice/'.self::HEADER);
        $this->original = (string) file_get_contents($this->header);
    }

    protected function tearDown(): void
    {
        file_put_contents($this->header, $this->original);
        parent::tearDown();
    }

    public function test_the_admin_script_was_published_and_the_permissions_made(): void
    {
        foreach (['visual-editor.js', 'visual-editor.css', 'bridge.js', 'code-editor.js'] as $file) {
            $this->assertFileExists(public_path('vendor/sunrice-visual-editor/'.$file));
        }

        $this->assertSame(
            ['visual-editor.code', 'visual-editor.edit', 'visual-editor.open'],
            Permission::query()->where('name', 'like', 'visual-editor.%')->orderBy('name')->pluck('name')->all(),
        );
    }

    public function test_a_super_admin_opens_the_editor_from_the_sidebar(): void
    {
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/cms/visual-editor?url=/demo')->assertOk()->getContent();
        $this->assertStringContainsString('VisualEditor\/Editor', $page);
        $this->assertStringContainsString('vendor/sunrice-visual-editor/visual-editor.js', $page);
        // Where the code editor's script will be fetched from, when code is first shown.
        $this->assertStringContainsString('vendor\/sunrice-visual-editor\/code-editor.js', $page);
        $this->assertStringContainsString('Visual editor', $page);
    }

    public function test_a_page_is_marked_for_the_editor_and_for_nobody_else(): void
    {
        $this->assertStringNotContainsString('data-sve', $this->get('/demo?sve=1')->assertOk()->getContent());

        $this->actingAs($this->someone('admin@example.com', superAdmin: true));
        $this->assertStringNotContainsString('data-sve', $this->get('/demo')->assertOk()->getContent());

        $marked = $this->get('/demo?sve=1')->assertOk()->getContent();
        $this->assertStringContainsString('data-sve="', $marked);
        $this->assertStringContainsString('vendor/sunrice-visual-editor/bridge.js', $marked);
    }

    public function test_someone_without_a_permission_is_kept_out(): void
    {
        $this->actingAs($this->someone('author@example.com', permissions: ['sunrice.access-admin']));

        $this->get('/cms/visual-editor')->assertForbidden();
        $this->getJson('/cms/visual-editor/api/templates')->assertForbidden();
    }

    public function test_a_designer_changes_a_template_and_only_what_was_meant(): void
    {
        $this->actingAs($this->someone('designer@example.com', permissions: ['sunrice.access-admin', 'visual-editor.edit']));

        $template = $this->getJson('/cms/visual-editor/api/templates/'.self::HEADER)->assertOk()->json('template');
        $element = $this->firstElement($template['tree']);

        $this->postJson('/cms/visual-editor/api/templates/'.self::HEADER.'/operations', [
            'version' => $template['version'],
            'operations' => [['type' => 'addClass', 'node' => $element['id'], 'class' => 'installed-check']],
        ])->assertOk();

        $now = (string) file_get_contents($this->header);
        $this->assertStringContainsString('installed-check', $now);
        // One class went in, and nothing else is different.
        $this->assertSame($this->original, preg_replace('/ ?installed-check/', '', $now, 1));
        $this->assertNotEmpty(File::allFiles(storage_path('app/sunrice/template-backups')));

        // The page is still drawn.
        $this->get('/demo')->assertOk();
    }

    public function test_a_designer_cannot_write_code(): void
    {
        $this->actingAs($this->someone('designer@example.com', permissions: ['sunrice.access-admin', 'visual-editor.edit']));

        $template = $this->getJson('/cms/visual-editor/api/templates/'.self::HEADER)->assertOk()->json('template');
        $element = $this->firstElement($template['tree']);

        $this->postJson('/cms/visual-editor/api/templates/'.self::HEADER.'/operations', [
            'version' => $template['version'],
            'operations' => [['type' => 'insertBefore', 'node' => $element['id'], 'blade' => "@php(file_put_contents(storage_path('owned'), '1'))"]],
        ])->assertStatus(422);

        $this->putJson('/cms/visual-editor/api/templates/'.self::HEADER.'/source', [
            'version' => $template['version'],
            'source' => "{{ config('app.key') }}\n".$this->original,
        ])->assertStatus(422);

        $this->assertSame($this->original, file_get_contents($this->header));
        $this->get('/demo');
        $this->assertFileDoesNotExist(storage_path('owned'));
    }

    public function test_a_style_is_changed_in_the_theme_s_own_file(): void
    {
        $this->actingAs($this->someone('designer@example.com', permissions: ['sunrice.access-admin', 'visual-editor.edit']));
        $css = public_path('sunrice-theme/app.css');
        $before = (string) file_get_contents($css);

        try {
            $styles = $this->getJson('/cms/visual-editor/api/styles?class=site-header')->assertOk()->json('styles');
            $rule = $styles['rules'][0];
            $file = collect($styles['files'])->firstWhere('path', $rule['file']);
            $declaration = $rule['declarations'][0];

            $this->postJson('/cms/visual-editor/api/styles/files/'.$rule['file'].'/changes', [
                'version' => $file['version'],
                'changes' => [['type' => 'set', 'declaration' => $declaration['id'], 'value' => 'var(--installed-check)']],
            ])->assertOk();

            $after = (string) file_get_contents($css);
            $this->assertStringContainsString('var(--installed-check)', $after);
            $this->assertSame(strlen($before) - strlen($declaration['value']) + strlen('var(--installed-check)'), strlen($after));
        } finally {
            file_put_contents($css, $before);
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    private function someone(string $email, bool $superAdmin = false, array $permissions = []): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $email, 'password' => bcrypt('password')]);
        $guard = config('sunrice.auth.guard', 'web');
        $role = $superAdmin
            ? Role::findOrCreate(config('sunrice.super_admin_role'), $guard)
            : Role::findOrCreate('Check '.implode(' ', $permissions), $guard)->syncPermissions($permissions);

        return $user->syncRoles([$role]);
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function firstElement(array $node): array
    {
        if (($node['type'] ?? null) === 'element' && in_array('attributes', array_keys(array_filter($node['can'] ?? [])), true)) {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            try {
                return $this->firstElement($child);
            } catch (\RuntimeException) {
                continue;
            }
        }

        throw new \RuntimeException('The template has no element.');
    }
}
