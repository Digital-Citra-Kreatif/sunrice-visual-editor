<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use ParseError;
use Throwable;

/**
 * Whether a template compiles: Blade to PHP, and the PHP read by PHP's own
 * parser. Nothing is run and nothing is written.
 */
final class Syntax
{
    /**
     * Null when the template compiles, else why not.
     *
     * With an application, its Blade compiler does the work, so its own
     * directives and components count. Without $components, component tags
     * are left as they are: a template that names a component this
     * application doesn't have can then still be checked for the rest.
     */
    public static function error(string $blade, bool $components = true): ?string
    {
        try {
            // Throws a ParseError on invalid PHP.
            $tokens = token_get_all(self::compile($blade, $components), TOKEN_PARSE);
            unset($tokens);
        } catch (ParseError $e) {
            return $e->getMessage().' (line '.$e->getLine().' of the compiled template)';
        } catch (Throwable $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * What is wrong with a new version of a template that was not wrong
     * with the old one; null when the new one may be saved.
     *
     * A template that didn't compile before, because it names a component
     * this application doesn't have, is not held to that: its PHP still
     * has to be sound.
     */
    public static function brokenBy(string $before, string $after): ?string
    {
        $error = self::error($after);
        if ($error === null || self::error($before) === null) {
            return $error;
        }

        return self::error($after, components: false);
    }

    private static function compile(string $blade, bool $components): string
    {
        $container = Container::getInstance();
        $application = $container->bound('blade.compiler') ? $container->make('blade.compiler') : null;
        if ($components && $application instanceof BladeCompiler) {
            return $application->compileString($blade);
        }

        // A compiler of its own, with the application's directives.
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $compiler->withoutComponentTags();
        if ($application instanceof BladeCompiler) {
            foreach ($application->getCustomDirectives() as $name => $handler) {
                $compiler->directive($name, $handler);
            }
        }

        return $compiler->compileString($blade);
    }
}
