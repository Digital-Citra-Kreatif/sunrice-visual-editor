<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * Turns a tree into the plain data the editor's panels work from: what each
 * node is, how to label it, what it holds and what may be done to it.
 */
final class TreeData
{
    private const LABEL_LENGTH = 60;

    private Classifier $classifier;

    public function __construct()
    {
        $this->classifier = new Classifier;
    }

    /** @return array<string, mixed> */
    public function node(Node $node): array
    {
        $data = [
            'id' => $node->id(),
            'type' => $node->type,
            'kind' => $this->classifier->classify($node),
            'name' => $node->name,
            'label' => $this->label($node),
            'start' => $node->start,
            'end' => $node->end,
            'can' => [
                'move' => Rules::movable($node),
                'delete' => Rules::movable($node),
                'insert' => Rules::holdsChildren($node),
                'attributes' => $node->type === 'element',
            ],
        ];

        switch ($node->type) {
            case 'element':
                $data['attributes'] = $this->attributes($node);
                break;
            case 'text':
                $data['text'] = trim($node->open);
                break;
            case 'atom':
                $data['atom'] = $node->kind;
                $data['source'] = $node->open;
                if (in_array($node->kind, ['echo', 'raw_echo'], true)) {
                    $data['expression'] = (string) $node->args;
                } elseif ($node->kind === 'directive') {
                    $data['arguments'] = $node->args;
                }
                break;
            case 'branch':
                $data['source'] = $node->open;
                $data['arguments'] = $node->args;
                break;
            case 'block':
                $data['atom'] = $node->kind;
                if ($data['kind'] === 'code') {
                    $data['source'] = $node->print();
                }
                break;
            case 'comment':
            case 'doctype':
                $data['source'] = $node->open;
                break;
        }

        // A code block is one piece of text: what is inside it isn't listed.
        if ($node->type === 'block' && $data['kind'] === 'code') {
            return $data;
        }

        if (! $node->isLeaf()) {
            $data['children'] = [];
            foreach ($node->children as $child) {
                if ($child->type === 'text' && trim($child->open) === '') {
                    continue;
                }
                $data['children'][] = $this->node($child);
            }
        }

        return $data;
    }

    /**
     * @return list<array{name: string, value: ?string, dynamic: bool, code: bool, source: string}>
     */
    private function attributes(Node $element): array
    {
        $attributes = [];
        foreach ($element->attrs as $attr) {
            $attributes[] = [
                'name' => $attr['name'],
                'value' => $attr['valueStart'] === null || $attr['valueEnd'] === null ? null : substr($element->open, $attr['valueStart'], $attr['valueEnd'] - $attr['valueStart']),
                // The value, or the name, is built by Blade.
                'dynamic' => $attr['dynamic'],
                // A directive or echo standing in the tag on its own: edited as text.
                'code' => $attr['kind'] === 'code',
                'source' => substr($element->open, $attr['start'], $attr['end'] - $attr['start']),
            ];
        }

        return $attributes;
    }

    private function label(Node $node): string
    {
        return match ($node->type) {
            'root' => 'Template',
            'element' => $this->elementLabel($node),
            'text' => $this->shorten(trim($node->open)),
            'comment' => 'Comment',
            'doctype' => 'Doctype',
            'block' => $node->kind === 'component' ? "<x-{$node->name}>" : $this->shorten(rtrim($node->children[0]->open ?? '@'.$node->name)),
            'branch' => $this->shorten($node->open),
            default => match ($node->kind) {
                'echo', 'raw_echo' => $this->shorten((string) $node->args),
                'component' => "<x-{$node->name}>",
                'comment' => 'Blade comment',
                'php' => 'PHP',
                'verbatim' => '@verbatim',
                default => $this->shorten($node->open),
            },
        };
    }

    /** The tag, with its id or first class when they are plain text: "section.hero", "div#app". */
    private function elementLabel(Node $element): string
    {
        $label = $element->name;
        $id = $element->attributeValue('id');
        if ($id !== null && $id !== '' && ! $element->attr('id')['dynamic']) {
            return $label.'#'.$id;
        }
        $class = $element->attributeValue('class');
        if ($class !== null && ! $element->attr('class')['dynamic']) {
            $first = strtok(trim($class), " \t\r\n");
            if ($first !== false) {
                $label .= '.'.$first;
            }
        }

        return $label;
    }

    private function shorten(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > self::LABEL_LENGTH ? mb_substr($text, 0, self::LABEL_LENGTH - 1).'…' : $text;
    }
}
