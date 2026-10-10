<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Says how the editor can present a node:
 *
 *   visual      edited with panels: elements, text, structure directives
 *   field       an echo of a plain variable path: {{ $entry->title }}, {{ $block->get('x') }}
 *   label       an echo of a translation string: {{ __('…') }}
 *   expression  an echo of any other PHP expression: shown as a chip, edited as text
 *   mixed       an element whose tag holds a directive: movable, attributes edited as text
 *   code        kept verbatim and edited as text: @php, @while, unknown directives
 */
final class Classifier
{
    /** Paired directives the editor can show as a condition, a loop or a region. */
    private const STRUCTURE = ['if', 'unless', 'isset', 'empty', 'foreach', 'forelse', 'for', 'switch', 'section', 'push', 'prepend', 'once', 'auth', 'guest', 'env', 'production', 'error'];

    /** Single directives the editor understands. */
    private const KNOWN = ['include', 'includeif', 'includewhen', 'includeunless', 'includefirst', 'each', 'extends', 'yield', 'stack', 'section', 'csrf', 'method', 'empty', 'break', 'continue'];

    private const PATH = '/^\$[A-Za-z_]\w*(?:\??->\w+(?:\((?:\s*(?:\'[^\']*\'|"[^"]*"|\d+|\$\w+)\s*(?:,\s*(?:\'[^\']*\'|"[^"]*"|\d+|\$\w+)\s*)*)?\))?|\[(?:\'[^\']*\'|"[^"]*"|\d+)\])*$/';

    private const LABEL = '/^(?:__|trans)\(\s*(?:\'[^\']*\'|"[^"]*")\s*\)$/';

    public function classify(Node $node): ?string
    {
        return match ($node->type) {
            'element' => $node->hasCodeAttributes() ? 'mixed' : 'visual',
            'text' => trim($node->open) === '' ? null : 'visual',
            'block' => $node->kind === 'component' || in_array(strtolower($node->name), self::STRUCTURE, true) ? 'visual' : 'code',
            'atom' => $this->atom($node),
            default => null,
        };
    }

    private function atom(Node $node): ?string
    {
        return match ($node->kind) {
            'comment' => null,
            'echo', 'raw_echo' => $this->echo((string) $node->args),
            'component' => 'visual',
            'directive' => in_array(strtolower($node->name), self::KNOWN, true) ? 'visual' : 'code',
            default => 'code',
        };
    }

    public function echo(string $expression): string
    {
        return match (true) {
            preg_match(self::PATH, $expression) === 1 => 'field',
            preg_match(self::LABEL, $expression) === 1 => 'label',
            default => 'expression',
        };
    }
}
