<?php

declare(strict_types=1);

/*
 * Round-trip test for the Blade-only visual editor.
 *
 *   php spike/run.php            run, print a summary, write spike/RESULTS.md and results.json
 *   php spike/run.php --check    also exit 1 when anything fails
 *
 * For every template it checks that:
 *   1. the file opens as a tree, and the tree prints back byte for byte;
 *   2. each kind of edit a visual editor makes (add a class, change text,
 *      delete, reorder, wrap, add a field) changes exactly the bytes it
 *      should, and the result still opens as a tree and compiles to valid PHP.
 * The edge fixtures also check that tags which don't nest are kept as
 * written (file name "stray-…"), and that templates the tree can't hold
 * at all are refused (file name "locked-…") instead of being damaged.
 */

use Sunrice\VisualEditor\Blade\Classifier;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Tests\Support\RoundTrip;

require __DIR__.'/bootstrap.php';

// The checks themselves live in tests/Support/RoundTrip.php, shared with the Pest tests.
$corpora = [
    'cms-starter' => ['The CMS\'s starter templates (sunrice/cms stubs/templates)', RoundTrip::fixtures('cms-starter')],
    'cms-package' => ['The CMS\'s own views (fallback pages, components, comments)', RoundTrip::fixtures('cms-package')],
    'starter' => ['Starter templates of Sunrice before the split into panel and CMS', RoundTrip::fixtures('sunrice-starter')],
    'package' => ['Package views of Sunrice before the split (defaults, components, error pages)', RoundTrip::fixtures('sunrice-package')],
    'edge' => ['Edge cases written for this test', RoundTrip::fixtures('edge')],
];
// Blade written by other people: Laravel's own views, when a framework checkout is around.
$laravel = is_dir($vendored = dirname(__DIR__).'/vendor/laravel/framework') ? $vendored : (getenv('SPIKE_DEPS') ?: dirname(__DIR__, 2).'/deps').'/framework';
if (is_dir($laravel)) {
    $corpora['laravel'] = ['Laravel framework\'s own Blade views (not written for this editor)', $laravel];
}

$classifier = new Classifier;
$report = [];
$failed = false;

foreach ($corpora as $key => [$title, $directory]) {
    foreach (RoundTrip::files($directory) as $name => $path) {
        $source = (string) file_get_contents($path);
        $row = ['file' => $name, 'bytes' => strlen($source), 'opens' => false, 'reason' => null, 'identical' => false, 'compiles' => RoundTrip::phpError($source) === null];
        $expectLocked = $key === 'edge' && str_starts_with(basename($name), 'locked-');
        $expectStray = $key === 'edge' && str_starts_with(basename($name), 'stray-');

        $started = hrtime(true);
        try {
            $template = Template::open($source);
            $row['opens'] = true;
            $row['identical'] = $template->print() === $source;
            $row['strays'] = array_map(fn ($node) => $node->stray, $template->strays());
        } catch (Unsupported $e) {
            $row['reason'] = $e->getMessage();
        }
        $row['ms'] = round((hrtime(true) - $started) / 1e6, 2);

        if ($row['opens']) {
            $kinds = ['visual' => 0, 'field' => 0, 'label' => 0, 'expression' => 0, 'mixed' => 0, 'code' => 0];
            $codeBytes = 0;
            foreach ($template->root->walk() as $node) {
                $kind = $classifier->classify($node);
                if ($kind === null) {
                    continue;
                }
                // What sits inside a code block is part of that block.
                for ($up = $node->parent; $up !== null; $up = $up->parent) {
                    if ($up->type === 'block' && $classifier->classify($up) === 'code') {
                        continue 2;
                    }
                }
                $kinds[$kind]++;
                if ($kind === 'code') {
                    $codeBytes += $node->end - $node->start;
                }
                if ($kind === 'mixed') {
                    foreach ($node->attrs as $attr) {
                        $codeBytes += $attr['kind'] === 'code' ? $attr['end'] - $attr['start'] : 0;
                    }
                }
            }
            $row['nodes'] = $kinds;
            $row['codeBytes'] = $codeBytes;
            $row['edits'] = RoundTrip::edits($source);
            foreach ($row['edits'] as $edit) {
                $failed = $failed || $edit['failures'] !== [];
            }
        }

        // A stray- fixture has stray tags and the others have none.
        $row['asExpected'] = $key === 'edge' ? ($expectLocked ? ! $row['opens'] : $row['opens'] && $row['identical'] && $expectStray === (($row['strays'] ?? []) !== [])) : null;
        $failed = $failed || match ($key) {
            'edge' => ! $row['asExpected'],
            // Other people's templates may be refused; they must never open and print back differently.
            'laravel' => $row['opens'] && ! $row['identical'],
            default => ! ($row['opens'] && $row['identical'] && $row['compiles']),
        };
        $report[$key][] = $row;
    }
}

// ---------------------------------------------------------------------------

$sum = function (array $rows): array {
    $total = ['files' => count($rows), 'open' => 0, 'identical' => 0, 'bytes' => 0, 'codeBytes' => 0, 'nodes' => [], 'edits' => [], 'ms' => []];
    foreach ($rows as $row) {
        $total['open'] += (int) $row['opens'];
        $total['identical'] += (int) $row['identical'];
        $total['ms'][] = $row['ms'];
        if (! $row['opens']) {
            continue;
        }
        $total['bytes'] += $row['bytes'];
        $total['codeBytes'] += $row['codeBytes'];
        foreach ($row['nodes'] as $kind => $n) {
            $total['nodes'][$kind] = ($total['nodes'][$kind] ?? 0) + $n;
        }
        foreach ($row['edits'] as $edit => $result) {
            foreach (['tried', 'passed', 'skipped'] as $field) {
                $total['edits'][$edit][$field] = ($total['edits'][$edit][$field] ?? 0) + $result[$field];
            }
            $total['edits'][$edit]['failures'] = array_merge($total['edits'][$edit]['failures'] ?? [], array_map(fn ($f) => $row['file'].' — '.$f, $result['failures']));
        }
    }

    return $total;
};

$percent = fn (int|float $part, int|float $whole): string => $whole > 0 ? round(100 * $part / $whole, 1).'%' : '–';
$md = ["# Round-trip test results\n"];
$md[] = 'Generated by `php spike/run.php` with stillat/blade-parser '.trim((string) @shell_exec('git -C '.escapeshellarg((getenv('SPIKE_DEPS') ?: dirname(__DIR__, 2).'/deps').'/blade-parser').' describe --tags 2>/dev/null') ?: 'installed version').' on PHP '.PHP_VERSION.".\n";

foreach ($corpora as $key => [$title]) {
    $rows = $report[$key] ?? [];
    $total = $sum($rows);
    $md[] = "## {$title}\n";

    if ($key === 'edge') {
        $md[] = "| File | Expected | Result | Kept as written, or the reason given |\n| --- | --- | --- | --- |";
        foreach ($rows as $row) {
            $expected = match (true) {
                str_starts_with(basename($row['file']), 'locked-') => 'refused',
                str_starts_with(basename($row['file']), 'stray-') => 'opens, identical, stray tags kept',
                default => 'opens, identical',
            };
            $strays = $row['strays'] ?? [];
            $result = $row['opens'] ? ($row['identical'] ? 'opens, identical' : 'opens, NOT identical').($strays === [] ? '' : ', stray tags kept') : 'refused';
            $note = $row['reason'] ?? implode('; ', array_unique($strays));
            // As code: the notes name tags, which Markdown would otherwise take as HTML.
            $md[] = "| `{$row['file']}` | {$expected} | ".($row['asExpected'] ? '' : '**WRONG:** ').$result.' | '.($note === '' ? '' : '`'.str_replace('|', '\\|', $note).'`').' |';
        }
        $md[] = '';
    } elseif ($key === 'laravel') {
        $md[] = "{$total['files']} files: {$total['identical']} open and print back identical, ".($total['files'] - $total['open'])." refused.\n";
        foreach ($rows as $row) {
            if (! $row['opens']) {
                $md[] = "- refused `{$row['file']}`: {$row['reason']}";
            }
        }
        $md[] = '';
    } else {
        $md[] = "| File | Bytes | Opens | Identical | Visual | Field | Label | Expression | Mixed | Code | Code bytes |\n| --- | ---: | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: |";
        foreach ($rows as $row) {
            if (! $row['opens']) {
                $md[] = "| `{$row['file']}` | {$row['bytes']} | **no** | – | | | | | | | {$row['reason']} |";

                continue;
            }
            $n = $row['nodes'];
            $md[] = "| `{$row['file']}` | {$row['bytes']} | yes | ".($row['identical'] ? 'yes' : '**no**')." | {$n['visual']} | {$n['field']} | {$n['label']} | {$n['expression']} | {$n['mixed']} | {$n['code']} | {$row['codeBytes']} |";
        }
        $n = $total['nodes'] + ['visual' => 0, 'field' => 0, 'label' => 0, 'expression' => 0, 'mixed' => 0, 'code' => 0];
        $md[] = "| **Total** | {$total['bytes']} | {$total['open']}/{$total['files']} | {$total['identical']}/{$total['files']} | {$n['visual']} | {$n['field']} | {$n['label']} | {$n['expression']} | {$n['mixed']} | {$n['code']} | {$total['codeBytes']} (".$percent($total['codeBytes'], $total['bytes']).") |\n";
        $all = array_sum($n);
        $md[] = 'Of '.$all.' nodes: '.$percent($n['visual'] + $n['field'] + $n['label'], $all).' fully visual, '.$percent($n['expression'], $all).' expressions, '.$percent($n['mixed'], $all).' elements with a directive in the tag, '.$percent($n['code'], $all)." code.\n";
    }

    $md[] = "| Edit | Tried | Passed | Not applicable |\n| --- | ---: | ---: | ---: |";
    $tried = $passed = 0;
    foreach ($total['edits'] as $edit => $result) {
        $md[] = "| {$edit} | {$result['tried']} | {$result['passed']} | {$result['skipped']} |";
        $tried += $result['tried'];
        $passed += $result['passed'];
    }
    $md[] = "| **Total** | {$tried} | {$passed} | |\n";
    foreach ($total['edits'] as $edit => $result) {
        foreach (array_slice($result['failures'], 0, 10) as $failure) {
            $md[] = "- FAILED {$edit}: {$failure}";
        }
    }
    sort($total['ms']);
    $md[] = 'Opening a file: median '.($total['ms'][intdiv(count($total['ms']), 2)] ?? 0).' ms, slowest '.(end($total['ms']) ?: 0)." ms.\n";

    $asExpected = count(array_filter($rows, fn (array $row) => $row['asExpected'] ?? ($row['opens'] && $row['identical'])));
    printf("%-8s %d files: %d open and print back identical, %d refused, %d as expected; edits %d/%d passed\n", $key, $total['files'], $total['identical'], $total['files'] - $total['open'], $asExpected, $passed, $tried);
}

file_put_contents(__DIR__.'/RESULTS.md', implode("\n", $md));
file_put_contents(__DIR__.'/results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
echo $failed ? "FAILED\n" : "OK\n";

exit($failed && in_array('--check', $argv, true) ? 1 : 0);
