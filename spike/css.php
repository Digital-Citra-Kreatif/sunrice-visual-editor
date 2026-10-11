<?php

declare(strict_types=1);

/*
 * Round-trip test for stylesheets: can a rule be found, and one
 * declaration changed, with nothing else in the file touched?
 *
 *   php spike/css.php            run, print a summary, write spike/CSS_RESULTS.md
 *   php spike/css.php --check    also exit 1 when anything fails
 *
 * For every stylesheet it reads the file with Sunrice\VisualEditor\Css\Stylesheet
 * and checks that:
 *   1. every rule and declaration found is where it is said to be;
 *   2. each kind of change (a value set, a declaration taken out, one added)
 *      changes exactly the bytes it should, and the file read again says the
 *      same as before but for that one thing;
 *   3. a value that would break out of its declaration is refused.
 * It also tries a library that parses CSS and writes it out again, to see
 * whether such a library could make the same changes.
 *
 * `node spike/css-browser.mjs` then asks a browser about the same files and
 * the same changes (see there); its answers are put into the results the next
 * time this runs.
 */

use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sunrice\VisualEditor\Css\CssRejected;
use Sunrice\VisualEditor\Css\Declaration;
use Sunrice\VisualEditor\Css\Rule;
use Sunrice\VisualEditor\Css\Stylesheet;

require __DIR__.'/bootstrap.php';

$deps = getenv('SPIKE_DEPS') ?: dirname(__DIR__, 2).'/deps';
$fixtures = dirname(__DIR__).'/tests/fixtures/css';
$corpora = [
    'fixtures' => ['The starter theme and cases written for this test', array_values(array_filter(glob($fixtures.'/*.css') ?: [], is_file(...)))],
    'others' => ['Stylesheets written by other people', array_values(array_filter([
        $deps.'/normalize.css/normalize.css',
        $deps.'/pico/css/pico.css',
        $deps.'/pico/css/pico.min.css',
        $deps.'/bulma/css/bulma.css',
        $deps.'/bootstrap/dist/css/bootstrap.css',
        $deps.'/bootstrap/dist/css/bootstrap.min.css',
    ], is_file(...)))],
];

/** At most this many declarations (and rules) are changed in one file, evenly spread through it: every change is two readings of the whole file. */
const SAMPLE = 200;

/**
 * @template T
 *
 * @param  list<T>  $all
 * @return list<T>
 */
function sampled(array $all): array
{
    if (count($all) <= SAMPLE) {
        return $all;
    }
    $step = count($all) / SAMPLE;
    $picked = [];
    for ($i = 0; $i < SAMPLE; $i++) {
        $picked[] = $all[(int) floor($i * $step)];
    }

    return $picked;
}

/** What two texts have in common at their start and their end: [bytes at the start, bytes at the end]. */
function common(string $a, string $b): array
{
    $max = min(strlen($a), strlen($b));
    $prefix = strspn($a ^ $b, "\0", 0, $max);
    $suffix = 0;
    while ($suffix < $max - $prefix && $a[strlen($a) - 1 - $suffix] === $b[strlen($b) - 1 - $suffix]) {
        $suffix++;
    }

    return [$prefix, $suffix];
}

/** A selector without the spaces and quotes a browser writes differently. */
function stripped(string $selector): string
{
    return strtolower((string) preg_replace(['~/\*.*?\*/~s', '/\s+/', '/["\']/', '/::/'], ['', '', '', ':'], $selector));
}

$report = [];
$plan = [];
$failed = false;

foreach ($corpora as $key => [$title, $files]) {
    foreach ($files as $path) {
        $name = $key === 'fixtures' ? basename($path) : basename(dirname($path, str_contains($path, '/dist/') ? 3 : (str_contains($path, '/css/') ? 2 : 1))).'/'.basename($path);
        $source = (string) file_get_contents($path);
        $started = hrtime(true);
        $sheet = Stylesheet::read($source);
        $ms = round((hrtime(true) - $started) / 1e6, 2);

        $rules = $sheet->all();
        $withBlock = array_values(array_filter($rules, fn (Rule $rule) => $rule->open !== null));
        $declarations = [];
        foreach ($rules as $rule) {
            foreach ($rule->declarations as $declaration) {
                $declarations[] = [$rule, $declaration];
            }
        }
        $row = ['file' => $name, 'bytes' => strlen($source), 'rules' => count($rules), 'declarations' => count($declarations), 'ms' => $ms, 'failures' => [], 'edits' => []];
        $fail = function (string $what) use (&$row): void {
            if (count($row['failures']) < 12) {
                $row['failures'][] = $what;
            }
        };

        // 1. Everything is where it is said to be.
        $placed = 0;
        foreach ($rules as $rule) {
            $ok = substr($source, $rule->start, strlen($rule->prelude)) === $rule->prelude
                && ($rule->open === null ? true : $source[$rule->open] === '{' && ($rule->close === strlen($source) || $source[$rule->close] === '}'))
                && $rule->start < $rule->close && $rule->close <= $rule->end;
            $ok ? $placed++ : $fail('rule "'.substr($rule->prelude, 0, 60).'" is not where it is said to be');
        }
        foreach ($declarations as [$rule, $declaration]) {
            $ok = substr($source, $declaration->start, strlen($declaration->property)) === $declaration->property
                && substr($source, $declaration->valueStart, $declaration->valueEnd - $declaration->valueStart) === $declaration->value
                && $declaration->start > $rule->open && $declaration->end <= $rule->close
                && $declaration->valueEnd <= $declaration->end && $declaration->end - $declaration->valueEnd <= 1 + strspn($source, " \t\r\n", $declaration->valueEnd);
            $ok ? $placed++ : $fail("declaration {$declaration->property} of \"".substr($rule->prelude, 0, 40).'" is not where it is said to be');
        }
        $row['placed'] = [$placed, count($rules) + count($declarations)];

        // 2. Changes.
        $record = function (string $edit, ?string $error, string $where) use (&$row, $fail): void {
            $row['edits'][$edit] ??= ['tried' => 0, 'passed' => 0];
            $row['edits'][$edit]['tried']++;
            $error === null ? $row['edits'][$edit]['passed']++ : $fail("{$edit} at {$where}: {$error}");
        };
        $entry = ['file' => $name, 'path' => $path, 'rules' => [], 'edits' => []];
        // What a browser would list: rules with a selector, not those of @keyframes, not at-rules.
        // Each with where its block starts: the browser is shown the file with a mark there,
        // and says by the marks which of its rules is which of these.
        $listed = new SplObjectStorage;
        foreach ($rules as $rule) {
            if (! $rule->isAt() && ! str_ends_with((string) $rule->parent?->at(), 'keyframes')) {
                $listed[$rule] = count($entry['rules']);
                $entry['rules'][] = ['at' => $rule->open + 1, 'selector' => stripped($rule->prelude)];
            }
        }

        foreach (sampled($declarations) as [$rule, $declaration]) {
            $where = substr($rule->prelude, 0, 40).' { '.$declaration->property.' }';
            $inBrowser = isset($listed[$rule]);

            // A value set: only the value's bytes change.
            try {
                $out = $sheet->withValue($declaration, 'inherit');
                $expected = substr($source, 0, $declaration->valueStart).'inherit'.substr($source, $declaration->valueEnd);
                $record('set a value', $out === $expected ? null : 'other bytes changed', $where);
                if ($inBrowser) {
                    $entry['edits'][] = ['kind' => 'set', 'index' => $listed[$rule], 'rule' => stripped($rule->prelude), 'property' => $declaration->property, 'at' => $declaration->valueStart, 'remove' => $declaration->valueEnd - $declaration->valueStart, 'insert' => 'inherit'];
                }
            } catch (CssRejected $e) {
                $record('set a value', $e->getMessage(), $where);
            }

            // A declaration taken out: it goes, with its semicolon and the space that was its own.
            try {
                $out = $sheet->without($declaration);
                // Either its whole line, when it had the line to itself, or itself and the spaces after it.
                $newline = strrpos(substr($source, 0, $declaration->start), "\n");
                $lineStart = $newline === false ? 0 : $newline + 1;
                $lineEnd = strpos($source, "\n", $declaration->end);
                $itsLine = $lineEnd === false ? null : substr($source, 0, $lineStart).substr($source, $lineEnd + 1);
                $itself = substr($source, 0, $declaration->start).substr($source, $declaration->end + strspn($source, " \t", $declaration->end));
                $alone = $lineEnd !== false && trim(substr($source, $lineStart, $lineEnd - $lineStart)) === trim(substr($source, $declaration->start, $declaration->end - $declaration->start));
                $error = $out === ($alone ? $itsLine : $itself) ? null : 'more than the declaration went, or less';
                [$prefix, $suffix] = common($source, $out);
                $gone = substr($source, $prefix, strlen($source) - $prefix - $suffix);
                $record('take a declaration out', $error, $where);
                if ($inBrowser) {
                    $entry['edits'][] = ['kind' => 'remove', 'index' => $listed[$rule], 'rule' => stripped($rule->prelude), 'property' => $declaration->property, 'at' => $prefix, 'remove' => strlen($gone), 'insert' => ''];
                }
            } catch (CssRejected $e) {
                $record('take a declaration out', $e->getMessage(), $where);
            }

            // A value that would end the declaration and start another rule: refused.
            foreach (['red; } body { display: none', 'url(', '"unclosed', 'red /* unclosed'] as $breaking) {
                try {
                    $sheet->withValue($declaration, $breaking);
                    $record('refuse a value that breaks out', 'it was accepted: '.$breaking, $where);
                } catch (CssRejected) {
                    $record('refuse a value that breaks out', null, $where);
                }
                if ($row['edits']['refuse a value that breaks out']['tried'] > 4 * 40) {
                    break;   // Each is a full reading of the file: a few dozen declarations say as much as all of them.
                }
            }
        }

        foreach (sampled($withBlock) as $rule) {
            $where = substr($rule->prelude, 0, 50);
            // A declaration added: one piece put in, inside the rule's block.
            try {
                $out = $sheet->withDeclaration($rule, '--sve-probe', '1');
                [$prefix, $suffix] = common($source, $out);
                $put = substr($out, $prefix, strlen($out) - $prefix - $suffix);
                $error = match (true) {
                    strlen($out) !== strlen($source) + strlen($put) => 'bytes were taken away',
                    ! str_contains($put, '--sve-probe') || trim(str_replace(['--sve-probe', ':', '1', ';'], '', $put)) !== '' => 'more than the declaration was put in: '.json_encode($put),
                    $prefix <= $rule->open || $prefix > $rule->close => 'it was put outside the block',
                    default => null,
                };
                $record('add a declaration', $error, $where);
                if (isset($listed[$rule])) {
                    $entry['edits'][] = ['kind' => 'add', 'index' => $listed[$rule], 'rule' => stripped($rule->prelude), 'property' => '--sve-probe', 'at' => $prefix, 'remove' => 0, 'insert' => $put];
                }
            } catch (CssRejected $e) {
                $record('add a declaration', $e->getMessage(), $where);
            }
        }

        // A rule added to a block (a @media, say): one piece put in, at the end of that block.
        foreach (sampled(array_values(array_filter($withBlock, fn (Rule $rule) => $rule->isAt() && $rule->close < strlen($source)))) as $block) {
            $where = substr($block->prelude, 0, 50);
            try {
                $out = $sheet->withRuleIn($block, '.sve-probe', [['--sve-probe', '1']]);
                [$prefix, $suffix] = common($source, $out);
                $put = substr($out, $prefix, strlen($out) - $prefix - $suffix);
                $error = match (true) {
                    strlen($out) !== strlen($source) + strlen($put) => 'bytes were taken away',
                    ! str_contains($put, '.sve-probe') || trim(str_replace(['.sve-probe', '--sve-probe', ':', '1', ';', '{', '}'], '', $put)) !== '' => 'more than the rule was put in: '.json_encode($put),
                    $prefix <= $block->open || $prefix > $block->close => 'it was put outside the block',
                    default => null,
                };
                $record('add a rule to a block', $error, $where);
            } catch (CssRejected $e) {
                $record('add a rule to a block', $e->getMessage(), $where);
            }
        }

        // A rule added at the end: the file as it was, then the rule.
        try {
            $out = $sheet->withRule('.sve-probe', ['color' => 'red', 'margin' => '0']);
            $unclosed = array_filter($rules, fn (Rule $rule) => $rule->open !== null && $rule->close === strlen($source)) !== [];
            $record('add a rule', str_starts_with($out, $source) && ! $unclosed ? null : ($unclosed ? 'accepted in a file whose last block is not closed' : 'the file before it changed'), 'the end');
        } catch (CssRejected $e) {
            $unclosed = array_filter($rules, fn (Rule $rule) => $rule->open !== null && $rule->close === strlen($source)) !== [];
            $record('add a rule', $unclosed ? null : $e->getMessage(), 'the end');
        }

        // 3. A library that parses CSS and writes it again: is what it writes the file?
        $row['library'] = null;
        if (is_dir($deps.'/PHP-CSS-Parser/src') && strlen($source) < 400_000) {
            spl_autoload_register($library ??= function (string $class) use ($deps): void {
                if (str_starts_with($class, 'Sabberworm\\CSS\\') && is_file($file = $deps.'/PHP-CSS-Parser/src/'.str_replace('\\', '/', substr($class, 15)).'.php')) {
                    require $file;
                }
            });
            try {
                $parsed = @(new Parser($source))->parse();
                $compact = @$parsed->render(OutputFormat::createCompact());
                $pretty = @$parsed->render(OutputFormat::createPretty());
                $row['library'] = ['same' => $compact === $source || $pretty === $source || @$parsed->render() === $source, 'error' => null];
            } catch (Throwable $e) {
                $row['library'] = ['same' => false, 'error' => substr($e->getMessage(), 0, 80)];
            }
        }

        $failed = $failed || $row['failures'] !== [];
        $report[$key][] = $row;
        $plan[] = $entry;
    }
}

file_put_contents(__DIR__.'/css-plan.json', json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

// ---------------------------------------------------------------------------

$browser = is_file(__DIR__.'/css-browser.json') ? json_decode((string) file_get_contents(__DIR__.'/css-browser.json'), true) : null;
$inBrowser = [];
foreach ($browser['files'] ?? [] as $file) {
    $inBrowser[$file['file']] = $file;
}

$md = ["# Round-trip test results: stylesheets\n"];
$md[] = 'Generated by `php spike/css.php` on PHP '.PHP_VERSION.($browser === null ? '' : ', with what '.$browser['browser'].' said to `node spike/css-browser.mjs`').".\n";
$md[] = "The question: can a rule be found in a stylesheet, and one declaration of it changed, with nothing else in the file touched? `Sunrice\\VisualEditor\\Css\\Stylesheet` reads a file for where its rules and declarations are, and makes each change as a cut into the text at one of those places. \"In place\" counts the rules and declarations found exactly where they are said to be. Each change is checked byte for byte: only the value, only the declaration (with its line, when it had one to itself), only the piece put in.\n";

foreach ($corpora as $key => [$title]) {
    $rows = $report[$key] ?? [];
    if ($rows === []) {
        continue;
    }
    $md[] = "## {$title}\n";
    $md[] = "| File | Bytes | Rules | Declarations | In place | Read in |\n| --- | ---: | ---: | ---: | --- | ---: |";
    $totals = [];
    foreach ($rows as $row) {
        $md[] = "| `{$row['file']}` | ".number_format($row['bytes']).' | '.number_format($row['rules']).' | '.number_format($row['declarations'])." | {$row['placed'][0]}/{$row['placed'][1]} | {$row['ms']} ms |";
        foreach ($row['edits'] as $edit => $result) {
            $totals[$edit]['tried'] = ($totals[$edit]['tried'] ?? 0) + $result['tried'];
            $totals[$edit]['passed'] = ($totals[$edit]['passed'] ?? 0) + $result['passed'];
        }
    }
    $md[] = '';
    $md[] = "| Change | Tried | Passed |\n| --- | ---: | ---: |";
    $tried = $passed = 0;
    foreach ($totals as $edit => $result) {
        $md[] = "| {$edit} | ".number_format($result['tried']).' | '.number_format($result['passed']).' |';
        $tried += $result['tried'];
        $passed += $result['passed'];
    }
    $md[] = '| **Total** | '.number_format($tried).' | '.number_format($passed)." |\n";
    foreach ($rows as $row) {
        foreach ($row['failures'] as $failure) {
            $md[] = "- FAILED `{$row['file']}`: {$failure}";
        }
    }

    if ($browser !== null) {
        $md[] = "\nWhat the browser said of the same files and the same changes:\n";
        $md[] = "| File | Rules found | The browser has | of them found, in order | Changes asked | One rule changed: the one meant, as meant | The browser never had it | Wrong |\n| --- | ---: | ---: | --- | ---: | ---: | ---: | ---: |";
        foreach ($rows as $row) {
            $said = $inBrowser[$row['file']] ?? null;
            if ($said !== null) {
                $md[] = "| `{$row['file']}` | ".number_format($said['mine']).' | '.number_format($said['browser']).' | '.($said['inOrder'] && $said['unfound'] === 0 ? 'all' : '**'.($said['browser'] - $said['unfound']).'**').' | '.number_format($said['edits']).' | '.number_format($said['right']).' | '.number_format($said['unseen']).' | '.($said['wrong'] === 0 ? '0' : '**'.$said['wrong'].'**').' |';
                $failed = $failed || ! $said['inOrder'] || $said['unfound'] > 0 || $said['wrong'] > 0;
                foreach (array_slice($said['examples'] ?? [], 0, 6) as $example) {
                    $md[] = "- `{$row['file']}`: {$example}";
                }
            }
        }
        $md[] = "\nThe browser has fewer rules than were found where it drops a rule whose selector it does not know (`::-moz-focus-inner`). A change it never had is one of a property it does not know (`*zoom`), one in such a rule, or one of a declaration that another of the same rule overrides either way.\n";
    }

    $library = array_filter($rows, fn (array $row) => $row['library'] !== null);
    if ($library !== []) {
        $same = count(array_filter($library, fn (array $row) => $row['library']['same']));
        $errors = count(array_filter($library, fn (array $row) => $row['library']['error'] !== null));
        $md[] = 'A library that parses CSS and writes it out again (sabberworm/php-css-parser 8.9): of '.count($library)." files, {$same} came out as they went in, in any of its three ways of writing".($errors > 0 ? "; {$errors} it could not read" : '').".\n";
    }

    printf("%-9s %d files, %s rules, %s declarations; changes %s/%s passed\n", $key, count($rows), number_format(array_sum(array_column($rows, 'rules'))), number_format(array_sum(array_column($rows, 'declarations'))), number_format($passed), number_format($tried));
}

file_put_contents(__DIR__.'/CSS_RESULTS.md', implode("\n", $md));
echo $failed ? "FAILED\n" : "OK\n";

exit($failed && in_array('--check', $argv, true) ? 1 : 0);
