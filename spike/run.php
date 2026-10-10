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
 * The edge fixtures also check that templates the tree can't hold are
 * refused (file name "locked-…") instead of being damaged.
 */

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Sunrice\VisualEditor\Blade\Classifier;
use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\HtmlLexer;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;

require __DIR__.'/bootstrap.php';

$fixtures = dirname(__DIR__).'/tests/fixtures';
$corpora = [
    'starter' => ['Starter templates (stubs/templates)', $fixtures.'/sunrice-starter'],
    'package' => ['Package views (defaults, components, error pages)', $fixtures.'/sunrice-package'],
    'edge' => ['Edge cases written for this test', $fixtures.'/edge'],
];
// Blade written by other people: Laravel's own views, when a framework checkout is around.
$laravel = is_dir($vendored = dirname(__DIR__).'/vendor/laravel/framework') ? $vendored : (getenv('SPIKE_DEPS') ?: dirname(__DIR__, 2).'/deps').'/framework';
if (is_dir($laravel)) {
    $corpora['laravel'] = ['Laravel framework\'s own Blade views (not written for this editor)', $laravel];
}

$compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
$compiler->withoutComponentTags();
$compiler->directive('bodyClass', fn ($expression) => '<?php echo sunrice_body_class('.$expression.'); ?>');

/** Null when the template compiles to valid PHP, else the error. */
$phpError = function (string $blade) use ($compiler): ?string {
    try {
        token_get_all($compiler->compileString($blade), TOKEN_PARSE);
    } catch (Throwable $e) {
        return $e->getMessage();
    }

    return null;
};

/** An edited template is good when it is exactly what was expected, opens as a tree again and compiles. */
$verify = function (string $out, string $expected) use ($phpError): ?string {
    if ($out !== $expected) {
        return 'output differs from the expected bytes';
    }
    try {
        Template::open($out);
    } catch (Unsupported $e) {
        return 'edited file no longer opens: '.$e->getMessage();
    }

    return ($error = $phpError($out)) === null ? null : 'edited file does not compile: '.$error;
};

/** Directives that only mean something inside their block; never edited alone. */
$isSeparator = fn (Node $node): bool => $node->type === 'atom' && $node->kind === 'directive'
    && (in_array($node->name, ['case', 'default', 'break'], true) || ($node->name === 'empty' && $node->args === null));

/** Selectable nodes outside code blocks (@switch bodies are edited as text). */
$editable = function (Template $template) use ($isSeparator): array {
    $classifier = new Classifier;

    return array_values(array_filter($template->selectable(), function (Node $node) use ($classifier, $isSeparator) {
        if ($isSeparator($node)) {
            return false;
        }
        for ($up = $node->parent; $up !== null; $up = $up->parent) {
            if ($up->type === 'block' && $classifier->classify($up) === 'code') {
                return false;
            }
        }

        return true;
    }));
};

/**
 * Runs every edit on every node it applies to, each on a fresh tree.
 *
 * @return array<string, array{tried: int, passed: int, skipped: int, failures: list<string>}>
 */
$runEdits = function (string $source) use ($verify, $editable): array {
    $results = [];
    $record = function (string $edit, ?string $error, string $where) use (&$results): void {
        $results[$edit] ??= ['tried' => 0, 'passed' => 0, 'skipped' => 0, 'failures' => []];
        $results[$edit]['tried']++;
        $error === null ? $results[$edit]['passed']++ : $results[$edit]['failures'][] = "{$where}: {$error}";
    };
    $skip = function (string $edit) use (&$results): void {
        $results[$edit] ??= ['tried' => 0, 'passed' => 0, 'skipped' => 0, 'failures' => []];
        $results[$edit]['skipped']++;
    };
    $at = fn (Node $node): string => match (true) {
        $node->type === 'element' => "<{$node->name}>",
        $node->type === 'text' => 'text',
        in_array($node->kind, ['echo', 'raw_echo'], true) => 'echo',
        $node->kind === 'component' => "<x-{$node->name}>",
        default => '@'.$node->name,
    }.' at byte '.$node->start;
    $slice = fn (int $from, int $to): string => substr($source, $from, $to - $from);
    $end = strlen($source);
    // Pieces brought together by an edit get one space when Blade would misread them joined (see Edit::unsafeSeam).
    $join = function (string ...$pieces): string {
        $out = array_shift($pieces);
        foreach ($pieces as $piece) {
            $out .= (Edit::unsafeSeam($out, $piece) ? ' ' : '').$piece;
        }

        return $out;
    };

    $count = count(Template::open($source)->elements());
    for ($i = 0; $i < $count; $i++) {
        // Add a class: one insertion, inside this element's opening tag.
        $element = Template::open($source)->elements()[$i];
        $openEnd = $element->start + strlen($element->open);
        $hadClass = $element->attr('class') !== null;
        if (Edit::addClass($element, 'sr-added')) {
            $out = rootOf($element)->print();
            // Exactly one insertion, somewhere in this element's opening tag.
            $inserted = null;
            $expected = '';
            foreach ([' class="sr-added"', ' sr-added', 'sr-added'] as $candidate) {
                for ($p = $element->start; $p <= $openEnd && $inserted === null; $p++) {
                    if ($out === $slice(0, $p).$candidate.$slice($p, $end)) {
                        $inserted = $candidate;
                        $expected = $slice(0, $p).$candidate.$slice($p, $end);
                    }
                }
            }
            $error = $inserted === null ? 'not a single insertion inside the opening tag' : $verify($out, $expected);
            if ($error === null) {
                $after = Template::open($out)->elements()[$i];
                $value = $after->attr('class');
                $classes = $value === null ? '' : substr($after->open, (int) $value['valueStart'], (int) $value['valueEnd'] - (int) $value['valueStart']);
                $error = str_contains($classes, 'sr-added') ? null : 'class not found after reopening';
                $error ??= $hadClass === ($inserted === ' class="sr-added"') ? 'wrong insertion form' : null;
            }
            $record('add class', $error, $at($element));
        } else {
            $skip('add class');
        }

        // Add a field as the last child: one insertion, just before the closing tag.
        $element = Template::open($source)->elements()[$i];
        if ($element->close !== '' && ! in_array($element->name, HtmlLexer::RAW_TEXT, true)) {
            $closeStart = $element->end - strlen($element->close);
            Edit::appendField($element, 'p', "\$entry->get('sr_field')");
            $record('add field', $verify(rootOf($element)->print(), $slice(0, $closeStart)."<p>{{ \$entry->get('sr_field') }}</p>".$slice($closeStart, $end)), $at($element));
        } else {
            $skip('add field');
        }
    }

    $count = count(Template::open($source)->texts());
    for ($i = 0; $i < $count; $i++) {
        $text = Template::open($source)->texts()[$i];
        $leading = strlen($text->open) - strlen(ltrim($text->open));
        $trailing = strlen($text->open) - strlen(rtrim($text->open));
        $root = rootOf($text);
        Edit::setText($text, 'Edited — teks baru');
        $record('change text', $verify($root->print(), $join($slice(0, $text->start + $leading), 'Edited — teks baru', $slice($text->end - $trailing, $end))), $at($text));
    }

    $count = count($editable(Template::open($source)));
    for ($i = 0; $i < $count; $i++) {
        $node = $editable(Template::open($source))[$i];
        $root = rootOf($node);
        Edit::remove($node);
        $record('delete', $verify($root->print(), $join($slice(0, $node->start), $slice($node->end, $end))), $at($node));

        $node = $editable(Template::open($source))[$i];
        Edit::wrap($node, 'div', 'sr-wrap');
        $record('wrap', $verify(rootOf($node)->print(), $slice(0, $node->start).'<div class="sr-wrap">'.$slice($node->start, $node->end).'</div>'.$slice($node->end, $end)), $at($node));

        // Reorder: swap with the next selectable sibling.
        $nodes = $editable(Template::open($source));
        $node = $nodes[$i];
        $next = null;
        foreach (array_slice($node->parent->children, $node->index() + 1) as $sibling) {
            if (in_array($sibling, $nodes, true)) {
                $next = $sibling;
                break;
            }
        }
        if ($next === null) {
            $skip('reorder');

            continue;
        }
        Edit::swap($node, $next);
        $expected = $join($slice(0, $node->start), $slice($next->start, $next->end), $slice($node->end, $next->start), $slice($node->start, $node->end), $slice($next->end, $end));
        $record('reorder', $verify(rootOf($node)->print(), $expected), $at($node));
    }

    return $results;
};

function rootOf(Node $node): Node
{
    while ($node->parent !== null) {
        $node = $node->parent;
    }

    return $node;
}

// ---------------------------------------------------------------------------

$classifier = new Classifier;
$report = [];
$failed = false;

foreach ($corpora as $key => [$title, $directory]) {
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    foreach ($files as $path) {
        $name = ltrim(str_replace($directory, '', $path), '/');
        $source = (string) file_get_contents($path);
        $row = ['file' => $name, 'bytes' => strlen($source), 'opens' => false, 'reason' => null, 'identical' => false, 'compiles' => $phpError($source) === null];
        $expectLocked = $key === 'edge' && str_starts_with(basename($name), 'locked-');

        $started = hrtime(true);
        try {
            $template = Template::open($source);
            $row['opens'] = true;
            $row['identical'] = $template->print() === $source;
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
            $row['edits'] = $runEdits($source);
            foreach ($row['edits'] as $edit) {
                $failed = $failed || $edit['failures'] !== [];
            }
        }

        $row['asExpected'] = $key === 'edge' ? ($expectLocked ? ! $row['opens'] : $row['opens'] && $row['identical']) : null;
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
        $md[] = "| File | Expected | Result | Reason given |\n| --- | --- | --- | --- |";
        foreach ($rows as $row) {
            $expected = str_starts_with(basename($row['file']), 'locked-') ? 'refused' : 'opens, identical';
            $result = $row['opens'] ? ($row['identical'] ? 'opens, identical' : 'opens, NOT identical') : 'refused';
            $md[] = "| `{$row['file']}` | {$expected} | ".($row['asExpected'] ? '' : '**WRONG:** ').$result.' | '.($row['reason'] ?? '').' |';
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
