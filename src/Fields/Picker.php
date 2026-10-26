<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

use Sunrice\VisualEditor\Blade\Node;
use Sunrice\VisualEditor\Blade\Template;
use Sunrice\VisualEditor\Templates\TemplateFile;

/**
 * Everything that can be picked at one place in a template, ready for the
 * Fields panel: each variable there with what can be read from it, the
 * Blade a click writes, and how it is written where a text goes.
 *
 * It is the scope (what is there), the catalog (what each has) and the
 * emitter (what to write) put together and laid out as a tree.
 */
final class Picker
{
    /** How far in from a variable the tree goes: cms_page() → entry → cover → alt text. */
    private const DEPTH = 4;

    /** Read further into, as far as the tree goes. An entry or a term only once: an entry's author, not the entries of its related entries. */
    private const OPENS = [Shape::ASSET, Shape::LINK, Shape::DATE, Shape::GROUP, Shape::ROW, Shape::USER, Shape::GLOBALS, Shape::COLLECTION, Shape::TAXONOMY, Shape::PAGE, Shape::BLOCK, Shape::VALUES, Shape::MENU_ITEM];

    public function __construct(private readonly Catalog $catalog, private readonly Scope $scope, private readonly Emit $emit) {}

    /**
     * @param  Node|null  $node  the place; null for the template as a whole
     * @param  bool  $inside  for something going inside the node, not in its place
     * @return list<array<string, mixed>> the variables, each with `groups` of `fields`, which may have `groups` of their own
     */
    public function at(TemplateFile $file, Template $template, ?Node $node, bool $inside = false): array
    {
        return $this->rows($this->scope->at($file, $template, $node, $inside));
    }

    /**
     * The same for a text that does not open as a tree: what the file is
     * given and what every template has, and nothing of the place.
     *
     * @return list<array<string, mixed>>
     */
    public function anywhere(TemplateFile $file, string $source): array
    {
        return $this->rows($this->scope->anywhere($file, $source));
    }

    /**
     * @param  list<Variable>  $variables
     * @return list<array<string, mixed>>
     */
    private function rows(array $variables): array
    {
        $taken = array_values(array_filter(array_map(fn (Variable $variable) => $variable->name(), $variables)));

        return array_map(function (Variable $variable) use ($taken): array {
            $reading = Reading::of($variable);

            return [
                'code' => $variable->code,
                'title' => $variable->title,
                'from' => $variable->from,
                'sure' => $variable->sure,
                ...$this->about($reading, $this->label($variable), $taken),
                'groups' => $this->groups($reading, $taken, 1, opens: true),
            ];
        }, $variables);
    }

    /**
     * What every row says about its value: what it is, of what, how it is put in.
     *
     * @param  list<string>  $taken
     * @return array<string, mixed>
     */
    private function about(Reading $reading, string $label, array $taken): array
    {
        $shape = $reading->shape;
        $one = $shape->isList() ? ($shape->item ?? $shape) : $shape;
        $subject = match ($one->kind) {
            Shape::ENTRY, Shape::COLLECTION => 'collection',
            Shape::TERM, Shape::TERM_TRANSLATION => 'taxonomy',
            Shape::VALUES => $one->subject,
            Shape::BLOCK => 'fieldset',
            Shape::GLOBALS => 'global',
            default => null,
        };
        $of = $subject === null ? [] : array_values(array_intersect_key($this->catalog->titles($subject), array_flip($one->of)));

        return [
            'what' => $shape->name(),
            'kind' => $shape->kind,
            'of' => $of,
            'inserts' => array_map(fn (Insert $insert) => $insert->toArray(), $this->emit->inserts($reading, $label, $taken)),
            'print' => $this->emit->printed($reading)?->blade,
            'expression' => $this->emit->printed($reading) === null ? null : $reading->printed(),
            // For a condition: whether there is anything, and the value to compare with another.
            'asked' => $reading->asked(),
            'compared' => $reading->loose ? '('.$reading->code.' ?? null)' : $reading->code,
            // For a loop: what to go over; null for what is no list.
            'looped' => $shape->isList() ? $reading->looped() : null,
        ];
    }

    /**
     * What can be read from a value, under its headings.
     *
     * @param  list<string>  $taken
     * @param  bool  $opens  an entry or a term is read into here; further in it is not
     * @return list<array{title: string, fields: list<array<string, mixed>>}>
     */
    private function groups(Reading $owner, array $taken, int $depth, bool $opens): array
    {
        if ($owner->shape->isList() || $depth > self::DEPTH || ! ($opens || in_array($owner->shape->kind, self::OPENS, true))) {
            return [];
        }
        $all = $this->catalog->members($owner->shape);
        // The blueprints it may be of: a field only some of them have says so.
        $blueprints = array_values(array_filter($all, fn (Members $members) => $members->subject !== null && ! in_array($members->subject, ['listing', 'index'], true)));

        $groups = [];
        foreach ($all as $members) {
            $fields = [];
            foreach ($members->fields as $field) {
                $reading = $owner->field($field);
                $lacking = [];
                foreach ($members->subject === null || count($blueprints) < 2 ? [] : $blueprints as $other) {
                    if ($other !== $members && $other->field($field->handle) === null) {
                        $lacking[] = $other->title;
                    }
                }
                $fields[] = [
                    'handle' => $field->handle,
                    'label' => $field->label,
                    'code' => $reading->code,
                    ...$this->about($reading, $field->label, $taken),
                    'note' => $lacking === [] ? null : 'Not in '.implode(', ', $lacking),
                    'groups' => $this->groups($reading, $taken, $depth + 1, opens: $depth === 1 && $owner->shape->kind === Shape::PAGE),
                ];
            }
            if ($fields !== []) {
                $groups[] = ['title' => $members->title, 'fields' => $fields];
            }
        }

        return $groups;
    }

    /** What a new variable made from this one would be named after. */
    private function label(Variable $variable): string
    {
        return $variable->name() ?? (preg_match("/\\('([^']+)'\\)/", $variable->code, $match) === 1 ? $match[1] : $variable->title);
    }
}
