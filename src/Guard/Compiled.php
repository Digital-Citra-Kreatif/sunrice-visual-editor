<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Guard;

use Illuminate\View\Compilers\BladeCompiler;

/**
 * The PHP a template runs, as Laravel's own Blade compiler turns it out:
 * what is asked here is the compiler's answer, not this package's reading
 * of the template.
 */
final class Compiled
{
    /**
     * Every piece of PHP in the compiled template: each run of code from
     * a `<?php` or `<?=` to its `?>`, without its spaces and comments.
     * Each piece once, however often it comes.
     *
     * @return array<string, true> the pieces, as keys
     *
     * @throws \Throwable when the template does not compile
     */
    public static function islands(string $blade): array
    {
        return self::read($blade)[0];
    }

    /**
     * How often the text the compiled template shows has the start of a
     * PHP tag in it ("<?") that PHP does not take for one. Blade leaves
     * such behind when it pairs a `@php(…)` with an `@endphp` that was
     * not meant for it: code that was run is then shown as text.
     */
    public static function strayTags(string $blade): int
    {
        return self::read($blade)[1];
    }

    /**
     * @return array{0: array<string, true>, 1: int}
     */
    private static function read(string $blade): array
    {
        /** @var BladeCompiler $compiler */
        $compiler = app('blade.compiler');

        $islands = [];
        $island = null;
        $stray = 0;
        foreach (token_get_all($compiler->compileString($blade)) as $token) {
            [$kind, $text] = is_array($token) ? [$token[0], $token[1]] : [null, $token];
            if ($island === null) {
                // Outside PHP everything is text the page shows, until a tag opens.
                if ($kind === T_OPEN_TAG) {
                    $island = [];
                } elseif ($kind === T_OPEN_TAG_WITH_ECHO) {
                    $island = ['echo'];
                } else {
                    $stray += substr_count($text, '<?');
                }

                continue;
            }
            if ($kind === T_CLOSE_TAG) {
                $islands[self::written($island)] = true;
                $island = null;
            } elseif (! in_array($kind, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $island[] = $text;
            }
        }
        if ($island !== null) {
            // PHP that runs to the end of the file without a closing tag.
            $islands[self::written($island)] = true;
        }
        unset($islands['']);

        return [$islands, $stray];
    }

    /**
     * @param  list<string>  $tokens
     */
    private static function written(array $tokens): string
    {
        // A space between tokens: `a b` and `ab` are not the same code.
        // What Blade makes up anew each time it compiles (the id of an @once) is not a difference.
        return (string) preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', 'uuid', implode(' ', $tokens));
    }
}
