<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Tests\Support;

use ReflectionClass;
use Sunrice\Cms\CmsServiceProvider;

/** Where the installed CMS keeps its starter templates (what sunrice-cms-templates publishes). */
final class StarterTemplates
{
    public static function directory(): string
    {
        return dirname((string) (new ReflectionClass(CmsServiceProvider::class))->getFileName(), 2).'/stubs/templates';
    }
}
