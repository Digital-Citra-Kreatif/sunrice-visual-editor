<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use FilesystemIterator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Sunrice\VisualEditor\Blade\Edit;
use Sunrice\VisualEditor\Blade\HtmlLexer;
use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Throwable;

/**
 * The round-trip checks, shared by the Pest tests and the report
 * (spike/run.php).
 *
 * For a template they answer two questions: does it open as a tree that
 * prints back byte for byte, and does each kind of edit a visual editor
 * makes change exactly the bytes it should, leaving a file that still opens
 * and still compiles.
 */
final class RoundTrip
{
    private static ?BladeCompiler $compiler = null;

    public static function fixtures(string $directory = ''): string
    {
        return dirname(__DIR__).'/fixtures'.($directory === '' ? '' : '/'.$directory);
    }

    /**
     * The Blade files under a directory.
     *
     * @return array<string, string> relative name => absolute path, sorted
     */
    public static function files(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[ltrim(str_replace($directory, '', $file->getPathname()), '/')] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    /** Null when the template compiles to valid PHP, else the error. */
    public static function phpError(string $blade): ?string
    {
        try {
            token_get_all(self::compiler()->compileString($blade), TOKEN_PARSE);
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** An edited template is good when it is exactly what was expected, opens as a tree again and compiles. */
    public static function verify(string $out, string $expected): ?string
    {
        if ($out !== $expected) {
            return 'output differs from the expected bytes';
        }
        try {
            Template::open($out);
        } catch (Unsupported $e) {
            return 'edited file no longer opens: '.$e->getMessage();
        }

        return ($error = self::phpError($out)) === null ? null : 'edited file does not compile: '.$error;
    }

    /**
     * Nodes that can be moved, wrapped or deleted on their own.
     *
     * @return list<Node>
     */
    public static function editable(Template $template): array
    {
        return array_values(array_filter($template->selectable(), Rules::movable(...)));
    }

    /**
     * Runs every edit on every node it applies to, each on a fresh tree.
     *
     * @return array<string, array{tried: int, passed: int, skipped: int, failures: list<string>}>
     */
    public static function edits(string $source): array
    {
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
        $slice = fn (int $from, int $to): string => substr($source, $from, $to - $from);
        $end = strlen($source);

        $count = count(Template::open($source)->elements());
        for ($i = 0; $i < $count; $i++) {
            // Add a class: one insertion, inside this element's opening tag.
            $element = Template::open($source)->elements()[$i];
            $openEnd = $element->start + strlen($element->open);
            $hadClass = $element->attr('class') !== null;
            if (Edit::addClass($element, 'sr-added')) {
                $out = self::root($element)->print();
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
                $error = $inserted === null ? 'not a single insertion inside the opening tag' : self::verify($out, $expected);
                if ($error === null) {
                    $after = Template::open($out)->elements()[$i];
                    $value = $after->attr('class');
                    $classes = $value === null ? '' : substr($after->open, (int) $value['valueStart'], (int) $value['valueEnd'] - (int) $value['valueStart']);
                    $error = str_contains($classes, 'sr-added') ? null : 'class not found after reopening';
                    $error ??= $hadClass === ($inserted === ' class="sr-added"') ? 'wrong insertion form' : null;
                }
                $record('add class', $error, self::where($element));
            } else {
                $skip('add class');
            }

            // Add a field as the last child: one insertion, just before the closing tag.
            $element = Template::open($source)->elements()[$i];
            if ($element->close !== '' && ! in_array($element->name, HtmlLexer::RAW_TEXT, true)) {
                $closeStart = $element->end - strlen($element->close);
                Edit::appendField($element, 'p', "\$entry->get('sr_field')");
                $record('add field', self::verify(self::root($element)->print(), $slice(0, $closeStart)."<p>{{ \$entry->get('sr_field') }}</p>".$slice($closeStart, $end)), self::where($element));
            } else {
                $skip('add field');
            }
        }

        $count = count(Template::open($source)->texts());
        for ($i = 0; $i < $count; $i++) {
            $text = Template::open($source)->texts()[$i];
            $leading = strlen($text->open) - strlen(ltrim($text->open));
            $trailing = strlen($text->open) - strlen(rtrim($text->open));
            $root = self::root($text);
            Edit::setText($text, 'Edited — teks baru');
            $record('change text', self::verify($root->print(), self::join($slice(0, $text->start + $leading), 'Edited — teks baru', $slice($text->end - $trailing, $end))), self::where($text));
        }

        $count = count(self::editable(Template::open($source)));
        for ($i = 0; $i < $count; $i++) {
            $node = self::editable(Template::open($source))[$i];
            $root = self::root($node);
            Edit::remove($node);
            $record('delete', self::verify($root->print(), self::join($slice(0, $node->start), $slice($node->end, $end))), self::where($node));

            $node = self::editable(Template::open($source))[$i];
            Edit::wrap($node, 'div', 'sr-wrap');
            $record('wrap', self::verify(self::root($node)->print(), $slice(0, $node->start).'<div class="sr-wrap">'.$slice($node->start, $node->end).'</div>'.$slice($node->end, $end)), self::where($node));

            // Reorder: swap with the next editable sibling.
            $nodes = self::editable(Template::open($source));
            $node = $nodes[$i];
            $next = null;
            foreach (array_slice($node->parent->children ?? [], $node->index() + 1) as $sibling) {
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
            $expected = self::join($slice(0, $node->start), $slice($next->start, $next->end), $slice($node->end, $next->start), $slice($node->start, $node->end), $slice($next->end, $end));
            $record('reorder', self::verify(self::root($node)->print(), $expected), self::where($node));
        }

        return $results;
    }

    /**
     * Every failure message from edits(), flat.
     *
     * @param  array<string, array{tried: int, passed: int, skipped: int, failures: list<string>}>  $results
     * @return list<string>
     */
    public static function failures(array $results): array
    {
        $failures = [];
        foreach ($results as $edit => $result) {
            foreach ($result['failures'] as $failure) {
                $failures[] = "{$edit} — {$failure}";
            }
        }

        return $failures;
    }

    /**
     * Pieces brought together by an edit get one space where Blade would
     * misread them joined (see Edit::unsafeSeam).
     */
    private static function join(string ...$pieces): string
    {
        $out = (string) array_shift($pieces);
        foreach ($pieces as $piece) {
            $out .= (Edit::unsafeSeam($out, $piece) ? ' ' : '').$piece;
        }

        return $out;
    }

    private static function root(Node $node): Node
    {
        while ($node->parent !== null) {
            $node = $node->parent;
        }

        return $node;
    }

    private static function where(Node $node): string
    {
        return match (true) {
            $node->type === 'element' => "<{$node->name}>",
            $node->type === 'text' => 'text',
            in_array($node->kind, ['echo', 'raw_echo'], true) => 'echo',
            $node->kind === 'component' => "<x-{$node->name}>",
            default => '@'.$node->name,
        }.' at byte '.$node->start;
    }

    /**
     * A Blade compiler of its own, so the check is the same with or without
     * an application: component tags are left as text, and Sunrice's
     *
     * @bodyClass is known.
     */
    private static function compiler(): BladeCompiler
    {
        if (self::$compiler === null) {
            self::$compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
            self::$compiler->withoutComponentTags();
            self::$compiler->directive('bodyClass', fn ($expression) => '<?php echo sunrice_body_class('.$expression.'); ?>');
        }

        return self::$compiler;
    }
}
