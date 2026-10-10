<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Rendering;

use Sunrice\VisualEditor\Blade\Rules;
use Sunrice\VisualEditor\Blade\Template;

/**
 * Marks a template for an editor render: every element gets an attribute
 * saying which file it is from and where in that file it starts, so the
 * page in the editor's frame can be traced back to its templates.
 *
 * The marked text is compiled and run for that one render only. It is
 * never written to a template.
 */
final class Marker
{
    public const ATTRIBUTE = 'data-sve';

    /** What may follow a tag's name when the name is all there is to it. */
    private const AFTER_NAME = [' ', "\t", "\n", "\r", "\f", '>', '/'];

    /**
     * The template's source with `data-sve="<key>:<id>"` after the name of
     * every opening tag. The id is the node's id: its offset in the
     * unmarked source.
     *
     * @param  string  $key  what the file is called in the manifest
     */
    public static function mark(Template $template, string $key): string
    {
        $source = $template->source;
        $marks = [];
        foreach ($template->root->walk() as $node) {
            if (! Rules::hasAttributes($node)) {
                continue;
            }
            $at = $node->start + 1 + strlen($node->name);
            // <h{{ $level }}>: the name goes on in Blade, and a mark would cut it in two.
            if (in_array($source[$at] ?? '', self::AFTER_NAME, true)) {
                $marks[$at] = ' '.self::ATTRIBUTE.'="'.$key.':'.$node->id().'"';
            }
        }

        krsort($marks);
        foreach ($marks as $at => $mark) {
            $source = substr($source, 0, $at).$mark.substr($source, $at);
        }

        return $source;
    }
}
