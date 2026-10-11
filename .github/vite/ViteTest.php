<?php

namespace Tests\Feature;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Run inside a new Laravel application whose layout takes its CSS from
 * Vite, with Tailwind (.github/vite-check.sh makes it and copies this file
 * in). The tests are run a few at a time, by name, between builds.
 */
class ViteTest extends TestCase
{
    private const HEADER = 'partials/header.blade.php';

    public function test_the_layout_opens_as_a_tree_with_vite_as_one_piece_of_code(): void
    {
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $layout = $this->getJson('/cms/visual-editor/api/templates/layouts/app.blade.php')->assertOk()->json('template');

        $this->assertTrue($layout['opens'], (string) ($layout['reason'] ?? ''));
        $vite = $this->find($layout['tree'], fn (array $node) => ($node['name'] ?? null) === 'vite');
        $this->assertNotNull($vite, 'The layout has no @vite.');
        $this->assertSame('code', $vite['kind']);
    }

    public function test_built_a_page_for_the_editor_is_marked_and_takes_its_css_from_the_build(): void
    {
        $this->assertFileDoesNotExist(public_path('hot'));
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/demo?sve=1')->assertOk()->getContent();

        $this->assertStringContainsString('data-sve="', $page);
        $this->assertMatchesRegularExpression('#<link[^>]+href="[^"]*/build/assets/[^"]+\.css"#', $page);
    }

    public function test_dev_a_page_for_the_editor_is_marked_and_takes_its_css_from_the_dev_server(): void
    {
        $this->assertFileExists(public_path('hot'));
        $server = trim((string) file_get_contents(public_path('hot')));
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/demo?sve=1')->assertOk()->getContent();

        $this->assertStringContainsString('data-sve="', $page);
        $this->assertStringContainsString($server.'/@vite/client', $page);
        $this->assertStringContainsString($server.'/resources/css/app.css', $page);
        // And for a visitor: the same CSS, no marks.
        $this->assertStringNotContainsString('data-sve', $this->get('/demo')->assertOk()->getContent());
    }

    public function test_preview_a_page_for_the_editor_has_tailwind_s_browser_build_and_a_visitor_s_has_not(): void
    {
        $this->assertFileDoesNotExist(public_path('hot'));
        $this->actingAs($this->someone('admin@example.com', superAdmin: true));

        $page = $this->get('/demo?sve=1')->assertOk()->getContent();
        $this->assertStringContainsString('@tailwindcss/browser', $page);
        // The site's own entry file, for its theme: Laravel's has the font in it.
        $this->assertStringContainsString('<style type="text/tailwindcss" data-sve-preview>', $page);
        $this->assertStringContainsString('--font-sans', $page);
        $this->assertStringNotContainsString('@source', substr($page, (int) strpos($page, 'data-sve-preview>'), 2000));

        $visitor = $this->get('/demo')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-sve-preview', $visitor);
        $this->assertStringNotContainsString('@tailwindcss/browser', $visitor);
    }

    /** Adds the class named in SVE_CLASS to the header's first element, through the editor, as a designer. It stays. */
    public function test_a_class_is_added_in_the_editor(): void
    {
        $class = (string) getenv('SVE_CLASS');
        $this->assertNotSame('', $class, 'Say which class in SVE_CLASS.');
        $this->actingAs($this->someone('designer@example.com', permissions: ['sunrice.access-admin', 'visual-editor.edit']));

        $template = $this->getJson('/cms/visual-editor/api/templates/'.self::HEADER)->assertOk()->json('template');
        $element = $this->find($template['tree'], fn (array $node) => ($node['type'] ?? null) === 'element' && ($node['can']['attributes'] ?? false));
        $this->assertNotNull($element);

        $this->postJson('/cms/visual-editor/api/templates/'.self::HEADER.'/operations', [
            'version' => $template['version'],
            'operations' => [['type' => 'addClass', 'node' => $element['id'], 'class' => $class]],
        ])->assertOk();

        $this->assertStringContainsString($class, (string) file_get_contents(resource_path('views/sunrice/'.self::HEADER)));
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
