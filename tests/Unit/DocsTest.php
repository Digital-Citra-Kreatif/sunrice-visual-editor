<?php

declare(strict_types=1);

use Sunrice\VisualEditor\Blade\Operation;
use Sunrice\VisualEditor\Support\Access;

/** The pages people read: the README, the changelog and everything in docs/. */
function pages(): array
{
    $root = dirname(__DIR__, 2);

    return [$root.'/README.md', $root.'/CHANGELOG.md', ...glob($root.'/docs/*.md')];
}

/** A heading as GitHub makes an anchor of it. */
function anchorOf(string $heading): string
{
    $anchor = strtolower(trim(preg_replace('/[`*_]/', '', $heading)));

    return (string) preg_replace(['/[^\p{L}\p{N} _-]/u', '/ /'], ['', '-'], $anchor);
}

/** The anchors a page has. */
function anchorsIn(string $file): array
{
    preg_match_all('/^#{1,6} +(.+)$/m', (string) file_get_contents($file), $headings);

    return array_map(anchorOf(...), $headings[1]);
}

it('has every page the README names', function () {
    $docs = array_map(fn (string $file) => basename($file), glob(dirname(__DIR__, 2).'/docs/*.md'));

    expect($docs)->toEqualCanonicalizing(['installation.md', 'how-it-works.md', 'fields.md', 'styles.md', 'permissions.md', 'agents.md', 'limits.md']);
    foreach ($docs as $doc) {
        expect(file_get_contents(dirname(__DIR__, 2).'/README.md'))->toContain("(docs/{$doc})");
    }
});

it('links only to files and headings that are there', function () {
    $broken = [];
    foreach (pages() as $page) {
        // Not what is inside a code block: Blade and JSON have brackets of their own.
        $text = (string) preg_replace('/^```.*?^```/ms', '', (string) file_get_contents($page));
        preg_match_all('/\]\(([^)\s]+)\)/', $text, $links);
        foreach ($links[1] as $link) {
            if (preg_match('#^(https?:|mailto:)#', $link) === 1) {
                continue;
            }
            [$path, $anchor] = array_pad(explode('#', $link, 2), 2, null);
            $file = $path === '' ? $page : dirname($page).'/'.$path;
            if (! file_exists($file)) {
                $broken[] = basename($page).": {$link} (no such file)";
            } elseif ($anchor !== null && str_ends_with($file, '.md') && ! in_array($anchor, anchorsIn($file), true)) {
                $broken[] = basename($page).": {$link} (no such heading)";
            }
        }
    }

    expect($broken)->toBe([]);
});

it('says what every setting does', function () {
    $installation = (string) file_get_contents(dirname(__DIR__, 2).'/docs/installation.md');
    $names = function (array $config, string $prefix = '') use (&$names): array {
        $found = [];
        foreach ($config as $key => $value) {
            // A list (the widths) is one setting; a map (the paths) is one for each key.
            $found = [...$found, ...(is_array($value) && ! array_is_list($value) ? $names($value, $prefix.$key.'.') : [$prefix.$key])];
        }

        return $found;
    };

    foreach ($names(require dirname(__DIR__, 2).'/config/sunrice-visual-editor.php') as $setting) {
        expect($installation)->toContain("`{$setting}`");
    }
});

it('tells agents of every operation there is', function () {
    $agents = (string) file_get_contents(dirname(__DIR__, 2).'/docs/agents.md');

    foreach (Operation::types() as $type) {
        expect($agents)->toContain("`{$type}`");
        foreach (array_keys(Operation::takes($type)) as $parameter) {
            expect($agents)->toContain("`{$parameter}");
        }
    }
});

it('names the permissions as the package does', function () {
    $permissions = (string) file_get_contents(dirname(__DIR__, 2).'/docs/permissions.md');

    foreach (Access::LABELS as $permission => $label) {
        expect($permissions)->toContain("`{$permission}`")->toContain($label);
    }
});
