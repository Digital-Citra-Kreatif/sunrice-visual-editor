<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use Sunrice\VisualEditor\Blade\EditRejected;
use Sunrice\VisualEditor\Blade\Operations;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Blade\Unsupported;
use Sunrice\VisualEditor\Css\StylesheetNotFound;
use Sunrice\VisualEditor\Css\ThemeFile;
use Sunrice\VisualEditor\Css\ThemeRepository;
use Sunrice\VisualEditor\Templates\Conflict;
use Sunrice\VisualEditor\Templates\DoesNotCompile;
use Sunrice\VisualEditor\Templates\TemplateNotFound;
use Sunrice\VisualEditor\Templates\TemplateRepository;

/**
 * Links one of the theme's stylesheets from a layout: a `<link>` in its
 * `<head>`, after the stylesheets it links already, so that the rules of
 * the new one count where both say something.
 */
final class LinkStylesheet
{
    public function __construct(
        private readonly TemplateRepository $templates,
        private readonly ThemeRepository $theme,
        private readonly SaveTemplate $save,
    ) {}

    /**
     * @param  string  $template  the layout's path inside the site's template directory
     * @param  string  $expectedVersion  the version of the layout the request was made for
     * @param  string  $stylesheet  the stylesheet's path inside the theme directory
     * @return string the layout as it was before
     *
     * @throws TemplateNotFound
     * @throws StylesheetNotFound
     * @throws Conflict
     * @throws EditRejected
     * @throws DoesNotCompile
     */
    public function handle(string $template, string $expectedVersion, string $stylesheet): string
    {
        $sheet = $this->theme->find($stylesheet);
        $source = (string) file_get_contents($this->templates->find($template)->file);
        if (TemplateRepository::version($source) !== $expectedVersion) {
            throw new Conflict($template, TemplateRepository::version($source));
        }
        try {
            $tree = Template::open($source);
        } catch (Unsupported $e) {
            throw new EditRejected('This template can only be edited as code: '.lcfirst($e->getMessage()).'.');
        }

        $head = null;
        foreach ($tree->root->walk() as $node) {
            if ($node->type === 'element' && strtolower($node->name) === 'head') {
                $head = $node;
                break;
            }
        }
        if ($head === null) {
            throw new EditRejected("\"{$template}\" has no <head> to link a stylesheet from. Link it from the layout the page uses.");
        }

        $last = null;
        foreach ($head->walk() as $node) {
            if ($node->type !== 'element' || strtolower($node->name) !== 'link' || ! str_contains(strtolower((string) $node->attributeValue('rel')), 'stylesheet')) {
                continue;
            }
            if (str_contains((string) $node->attributeValue('href'), 'sunrice-theme/'.$sheet->path)) {
                throw new EditRejected("\"{$template}\" links {$sheet->path} already.");
            }
            $last = $node;
        }
        // The whole of what stands in the head around the last one: an @if about its file, say.
        while ($last !== null && $last->parent !== null && $last->parent !== $head) {
            $last = $last->parent;
        }

        $applied = Operations::run($source, [
            $last === null
                ? ['type' => 'append', 'node' => $head->id(), 'blade' => self::link($sheet)]
                : ['type' => 'insertAfter', 'node' => $last->id(), 'blade' => self::link($sheet)],
        ]);
        $this->save->handle($template, $expectedVersion, $applied->source);

        return $source;
    }

    /**
     * The link as the starter layout writes its own: only when the file
     * is there, and with the file's time in the address, so that a
     * visitor's browser asks for it again once it has changed.
     */
    public static function link(ThemeFile $sheet): string
    {
        $path = "'sunrice-theme/{$sheet->path}'";
        $variable = '$sunrice'.str_replace(' ', '', ucwords((string) preg_replace('/[^A-Za-z0-9]+/', ' ', $sheet->path)));

        return "@if (is_file({$variable} = public_path({$path})))\n    <link rel=\"stylesheet\" href=\"{{ asset({$path}) }}?v={{ filemtime({$variable}) }}\">\n@endif";
    }
}
