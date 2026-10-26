<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Sunrice\VisualEditor\Support\Access;
use Tests\TestCase;

/**
 * Run inside a new Laravel application whose layout takes its CSS from
 * Vite, with Tailwind (.github/vite-check.sh makes it and copies this file
 * in). The tests are run a few at a time, by name, between builds.
 */
class ViteTest extends TestCase
{
    /** The CMS's starter layout, which has the site's header. */
    private const HEADER = 'layout.blade.php';

    public function test_the_layout_opens_as_a_tree_with_vite_as_one_piece_of_code(): void
    {
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $layout = $this->getJson('/admin/visual-editor/api/templates/layout.blade.php')->assertOk()->json('template');

        $this->assertTrue($layout['opens'], (string) ($layout['reason'] ?? ''));
        $vite = $this->find($layout['tree'], fn (array $node) => ($node['name'] ?? null) === 'vite');
        $this->assertNotNull($vite, 'The layout has no @vite.');
        $this->assertSame('code', $vite['kind']);
    }

    public function test_built_a_page_for_the_editor_is_marked_and_takes_its_css_from_the_build(): void
    {
        $this->assertFileDoesNotExist(public_path('hot'));
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/about?sve=1')->assertOk()->getContent();

        $this->assertStringContainsString('data-sve="', $page);
        $this->assertMatchesRegularExpression('#<link[^>]+href="[^"]*/build/assets/[^"]+\.css"#', $page);
    }

    public function test_dev_a_page_for_the_editor_is_marked_and_takes_its_css_from_the_dev_server(): void
    {
        $this->assertFileExists(public_path('hot'));
        $server = trim((string) file_get_contents(public_path('hot')));
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/about?sve=1')->assertOk()->getContent();

        $this->assertStringContainsString('data-sve="', $page);
        $this->assertStringContainsString($server.'/@vite/client', $page);
        $this->assertStringContainsString($server.'/resources/css/app.css', $page);
        // And for a visitor: the same CSS, no marks.
        $this->assertStringNotContainsString('data-sve', $this->get('/about')->assertOk()->getContent());
    }

    public function test_preview_a_page_for_the_editor_has_tailwind_s_browser_build_and_a_visitor_s_has_not(): void
    {
        $this->assertFileDoesNotExist(public_path('hot'));
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/about?sve=1')->assertOk()->getContent();
        $this->assertStringContainsString('@tailwindcss/browser', $page);
        // The site's own entry file, for its theme: Laravel's has the font in it.
        $this->assertStringContainsString('<style type="text/tailwindcss" data-sve-preview>', $page);
        $this->assertStringContainsString('--font-sans', $page);
        $this->assertStringNotContainsString('@source', substr($page, (int) strpos($page, 'data-sve-preview>'), 2000));

        $visitor = $this->get('/about')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-sve-preview', $visitor);
        $this->assertStringNotContainsString('@tailwindcss/browser', $visitor);
    }

    public function test_build_the_site_s_command_is_run_from_the_editor_by_whoever_may(): void
    {
        $this->actingAs($this->someone('designer@example.com', permissions: ['sunrice.access', 'visual-editor.edit']));
        $this->postJson('/admin/visual-editor/api/build')->assertForbidden();

        $this->actingAs($this->someone('builder@example.com', permissions: ['sunrice.access', 'visual-editor.edit', 'visual-editor.build']));
        $built = $this->postJson('/admin/visual-editor/api/build')->assertOk()->json();

        $this->assertTrue($built['ok'], $built['message']."\n".$built['output']);
        $this->assertSame(0, $built['code']);
        // What Vite printed is there to read.
        $this->assertStringContainsString('built in', $built['output']);
    }

    public function test_no_build_a_command_that_is_not_on_the_server_says_so(): void
    {
        $this->actingAs($this->someone('builder@example.com', permissions: ['sunrice.access', 'visual-editor.edit', 'visual-editor.build']));
        $built = $this->postJson('/admin/visual-editor/api/build')->assertOk()->json();

        $this->assertFalse($built['ok']);
        $this->assertSame(127, $built['code']);
        $this->assertStringContainsString('a program it needs is not there', $built['message']);
        $this->assertStringContainsString('no-such-build-command', $built['output']);
    }

    /** Adds the class named in SVE_CLASS to the site's header (the layout's <header>), through the editor, as a designer. It stays. */
    public function test_a_class_is_added_in_the_editor(): void
    {
        $class = (string) getenv('SVE_CLASS');
        $this->assertNotSame('', $class, 'Say which class in SVE_CLASS.');
        $this->actingAs($this->someone('designer@example.com', permissions: ['sunrice.access', 'visual-editor.edit']));

        $template = $this->getJson('/admin/visual-editor/api/templates/'.self::HEADER)->assertOk()->json('template');
        $element = $this->find($template['tree'], fn (array $node) => ($node['type'] ?? null) === 'element' && ($node['name'] ?? null) === 'header' && ($node['can']['attributes'] ?? false));
        $this->assertNotNull($element);

        $this->postJson('/admin/visual-editor/api/templates/'.self::HEADER.'/operations', [
            'version' => $template['version'],
            'operations' => [['type' => 'addClass', 'node' => $element['id'], 'class' => $class]],
        ])->assertOk();

        $this->assertStringContainsString($class, (string) file_get_contents(resource_path('views/cms/'.self::HEADER)));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function someone(string $email, bool $superAdmin = false, array $permissions = []): User
    {
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $email, 'password' => bcrypt('Sunrice-Demo-2026!')]);
        $guard = Access::guard();
        $role = $superAdmin
            ? Role::findOrCreate(config('sunrice-admin.super_admin_role'), $guard)
            : Role::findOrCreate('Check '.implode(' ', $permissions), $guard)->syncPermissions($permissions);

        return $user->syncRoles([$role]);
    }

    /**
     * The first node of a tree that is what is asked for.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): bool  $wanted
     * @return array<string, mixed>|null
     */
    private function find(array $node, callable $wanted): ?array
    {
        if ($wanted($node)) {
            return $node;
        }
        foreach ($node['children'] ?? [] as $child) {
            if (($found = $this->find($child, $wanted)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
