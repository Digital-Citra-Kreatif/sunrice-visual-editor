<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

use Illuminate\Support\Str;
use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * Writes the Blade that shows a value: what a click on a field in the
 * picker puts into the template.
 *
 * Each kind of value has the form that suits it: text is printed, rich
 * text printed as HTML, an image becomes an `<img>`, a link an `<a>`, a
 * list a loop, a yes-or-no a condition. Whatever is written is drawn
 * without an error when the value is empty or the field is not there at
 * all: a template shared by two collections reads fields only one of
 * them has.
 */
final class Emit
{
    /** Names Blade and Sunrice use themselves: a loop over entries must not call each one `$entry`, which the page has. */
    private const THEIRS = ['this', 'loop', 'slot', 'component', 'attributes', 'errors', 'app', 'locale', 'pageType', 'sunricePage', 'entry', 'entries', 'term', 'collection', 'taxonomy'];

    public function __construct(private readonly Catalog $catalog) {}

    /**
     * The ways a value can be put into a template, the usual one first.
     * None for what is only read further into: an entry, a group, a
     * global set.
     *
     * @param  list<string>  $taken  names of variables already in use at the place, without "$": a new one is not given one of these
     * @return list<Insert>
     */
    public function inserts(Reading $reading, string $label, array $taken = []): array
    {
        $shape = $reading->shape;
        $inserts = match (true) {
            $shape->isList() => $this->lists($reading, $label, $taken),
            $shape->kind === Shape::HTML => [new Insert('Rich text', Insert::PRINT, '{!! '.$reading->printed().' !!}')],
            $shape->isPrinted() => [new Insert('Text', Insert::PRINT, '{{ '.$reading->printed().' }}')],
            $shape->kind === Shape::BOOL => [],
            $shape->kind === Shape::DATE => [new Insert('Date', Insert::PRINT, '{{ '.$this->part($reading, 'long')->printed().' }}')],
            $shape->kind === Shape::ASSET => $this->files($reading, $label, $taken),
            $shape->kind === Shape::LINK => [new Insert('Link', Insert::ELEMENT, $this->link($reading, $label, $taken))],
            $shape->kind === Shape::USER => [new Insert('Name', Insert::PRINT, '{{ '.$this->part($reading, 'name')->printed().' }}')],
            default => [],
        };

        if ($shape->kind === Shape::BOOL) {
            $inserts[] = new Insert('Only when it is on', Insert::STRUCTURE, '@if ('.$reading->asked().")\n@endif");
            $inserts[] = new Insert('Only when it is off', Insert::STRUCTURE, '@if (! '.($reading->loose ? '('.$reading->code.' ?? false)' : $reading->code).")\n@endif");
        } elseif ($inserts !== [] || in_array($shape->kind, [Shape::ENTRY, Shape::TERM, Shape::VALUE], true)) {
            $inserts[] = new Insert($shape->isList() ? 'Only when there are any' : 'Only when it is set', Insert::STRUCTURE, '@if ('.$reading->asked().")\n@endif");
        }

        return $inserts;
    }

    /**
     * How a value is written where a text goes: between tags or in an
     * attribute. Null for what is no text: a list, a file, a link.
     */
    public function printed(Reading $reading): ?Insert
    {
        return match (true) {
            $reading->shape->isList() => null,
            $reading->shape->kind === Shape::HTML => new Insert('Rich text', Insert::PRINT, '{!! '.$reading->printed().' !!}'),
            $reading->shape->isPrinted() => new Insert('Text', Insert::PRINT, '{{ '.$reading->printed().' }}'),
            default => null,
        };
    }

    // --- Lists --------------------------------------------------------------------

    /**
     * @param  list<string>  $taken
     * @return list<Insert>
     */
    private function lists(Reading $reading, string $label, array $taken): array
    {
        $item = $reading->shape->item ?? Shape::of(Shape::VALUE);

        if ($item->kind === Shape::BLOCK) {
            // Each block is drawn by the template named after its type.
            $block = $this->name('block', $taken);

            return [new Insert('Each block, drawn by its own template', Insert::STRUCTURE, implode("\n", [
                '@foreach ('.$reading->looped().' as $'.$block.')',
                "    @includeFirst(['sunrice.blocks.'.\$".$block."->type, 'sunrice.blocks.default'], ['block' => \$".$block.'])',
                '@endforeach',
            ]))];
        }
        if ($item->kind === Shape::MENU_ITEM) {
            $each = $this->name('item', $taken);

            return [new Insert('Menu', Insert::STRUCTURE, implode("\n", [
                '<ul>',
                '    @foreach ('.$reading->looped().' as $'.$each.')',
                '        <li><a href="{{ $'.$each.'->url }}">{{ $'.$each.'->label }}</a></li>',
                '    @endforeach',
                '</ul>',
            ]))];
        }

        $each = $this->name($this->singular($label, $item), $taken);
        $one = $reading->item($each);
        $inside = match ($item->kind) {
            Shape::ENTRY => [$this->entry($one, $item)],
            Shape::TERM => $this->term($one, $item, relation: ! $reading->fromField),
            Shape::ASSET => [$this->image($one)],
            Shape::ROW => $this->row($one, [...$taken, $each]),
            Shape::TEXT, Shape::NUMBER, Shape::VALUE => ['{{ $'.$each.' }}'],
            Shape::HTML => ['{!! $'.$each.' !!}'],
            default => [],
        };
        $loop = implode("\n", ['@foreach ('.$reading->looped().' as $'.$each.')', ...array_map(fn (string $line) => '    '.$line, $this->lines($inside)), '@endforeach']);

        $inserts = [new Insert('Each one, in a loop', Insert::STRUCTURE, $loop)];
        if (in_array($item->kind, [Shape::TEXT, Shape::NUMBER], true)) {
            // A few words picked from a list read better in a row: "Small, Large".
            array_unshift($inserts, new Insert('All of them, with commas', Insert::PRINT, "{{ implode(', ', (array) (".$reading->code.' ?? [])) }}'));
        }

        return $inserts;
    }

    /** An entry in a list: its title, linked when entries of its collections have pages. */
    private function entry(Reading $entry, Shape $shape): string
    {
        $collections = SunriceBridge::collections();
        $possible = $shape->of === [] ? $collections : array_filter($collections, fn (array $collection) => in_array($collection['handle'], $shape->of, true));
        $pages = $possible !== [] && array_filter($possible, fn (array $collection) => ! $collection['single']) === [];

        return $pages
            ? '<a href="{{ '.$entry->code.'->url }}">{{ '.$entry->code.'->title }}</a>'
            : '{{ '.$entry->code.'->title }}';
    }

    /**
     * A term in a list: its name, linked when terms of its taxonomies have pages.
     *
     * @param  bool  $relation  the terms are not a field's (an entry's own, a term's children): those may not be in the page's language yet
     * @return list<string>
     */
    private function term(Reading $term, Shape $shape, bool $relation): array
    {
        $taxonomies = SunriceBridge::taxonomies();
        $possible = $shape->of === [] ? $taxonomies : array_filter($taxonomies, fn (array $taxonomy) => in_array($taxonomy['handle'], $shape->of, true));
        $pages = $possible !== [] && array_filter($possible, fn (array $taxonomy) => ! $taxonomy['archive']) === [];

        return [
            ...($relation ? ['@php('.$term->code.'->resolveFor($locale))'] : []),
            $pages ? '<a href="{{ '.$term->code.'->url }}">{{ '.$term->code.'->name }}</a>' : '<span>{{ '.$term->code.'->name }}</span>',
        ];
    }

    /**
     * A row of a repeater: each of its fields that can be shown on its own, one under the other.
     *
     * @param  list<string>  $taken
     * @return list<string>
     */
    private function row(Reading $row, array $taken): array
    {
        $shown = [];
        foreach ($row->shape->fields as $field) {
            $reading = $row->field($field);
            if (! $reading->shape->isList() && ($insert = $this->inserts($reading, $field->label, $taken)[0] ?? null) !== null && $insert->kind !== Insert::STRUCTURE) {
                $shown[] = $insert->blade;
            }
        }

        return ['<div>', ...array_map(fn (string $line) => '    '.$line, $this->lines($shown)), '</div>'];
    }

    // --- Files and links ------------------------------------------------------------

    /**
     * @param  list<string>  $taken
     * @return list<Insert>
     */
    private function files(Reading $reading, string $label, array $taken): array
    {
        [$file, $open, $close] = $this->held($reading, $label, $taken);
        $link = '<a href="{{ '.$file->code.'->url() }}">{{ '.$file->code.'->title ?: '.$file->code.'->filename }}</a>';

        return [
            new Insert('Image', Insert::ELEMENT, implode("\n", [...$open, ($open === [] ? '' : '    ').$this->image($file), ...$close])),
            new Insert('Link to the file', Insert::ELEMENT, implode("\n", [...$open, ($open === [] ? '' : '    ').$link, ...$close])),
        ];
    }

    private function image(Reading $file): string
    {
        $large = $this->catalog->member($file->shape, 'url:large') ?? $this->catalog->member($file->shape, 'url');

        return '<img src="{{ '.($large?->from($file->code) ?? $file->code.'->url()').' }}" alt="{{ '.$file->code.'->alt }}">';
    }

    /** @param  list<string>  $taken */
    private function link(Reading $reading, string $label, array $taken): string
    {
        [$link, $open, $close] = $this->held($reading, $label, $taken, also: "! empty(%s['url'])");
        $tag = '<a href="{{ '.$link->code."['url'] }}\" @if (! empty(".$link->code."['new_tab'])) target=\"_blank\" rel=\"noopener\" @endif>{{ ".$link->code."['label'] ?: ".$link->code."['url'] }}</a>";

        return implode("\n", [...$open, '    '.$tag, ...$close]);
    }

    /**
     * Something read once and held in a variable while it is shown, as
     * Sunrice's own templates do: `@if ($cover = $entry->get('cover'))`.
     * A value that is a variable already, and sure to be there, needs
     * neither the variable nor the condition.
     *
     * @param  list<string>  $taken
     * @param  string|null  $also  a further condition on the held value, with %s for it
     * @return array{0: Reading, 1: list<string>, 2: list<string>} the value as it is read inside, and the lines that open and close around it
     */
    private function held(Reading $reading, string $label, array $taken, ?string $also = null): array
    {
        $isVariable = preg_match('/^\$[A-Za-z_]\w*$/', $reading->code) === 1;
        if ($isVariable && ! $reading->maybe && ! $reading->loose && $also === null) {
            return [$reading, [], []];
        }
        if ($isVariable) {
            $condition = $also === null ? $reading->code : ($reading->maybe ? $reading->code.' && ' : '').sprintf($also, $reading->code);

            return [new Reading($reading->code, $reading->shape), ['@if ('.$condition.')'], ['@endif']];
        }

        $name = $this->name($this->variable($label), $taken);
        $set = '$'.$name.' = '.($reading->loose ? $reading->code.' ?? null' : $reading->code);

        return [new Reading('$'.$name, $reading->shape), ['@if ('.($also === null ? $set : '('.$set.') && '.sprintf($also, '$'.$name)).')'], ['@endif']];
    }

    // --- Helpers ------------------------------------------------------------------

    /** The reading of one of the things every value of its kind has: a date's long form, a user's name. */
    private function part(Reading $reading, string $handle): Reading
    {
        $field = $this->catalog->member($reading->shape, $handle);

        return $field === null ? $reading : $reading->field($field);
    }

    /**
     * What one of a list is called: "Photos" has a `$photo`, "Related articles" an `$article`.
     */
    private function singular(string $label, Shape $item): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $last = Str::singular(strtolower((string) end($words)));

        return $last !== '' && $last !== strtolower((string) end($words)) && ! in_array($last, self::THEIRS, true) ? $last : match ($item->kind) {
            Shape::ASSET => 'file',
            Shape::ROW => 'row',
            default => 'item',
        };
    }

    /** A label as the name of a variable: "Cover image" is `coverImage`. */
    private function variable(string $label): string
    {
        $name = Str::camel((string) preg_replace('/[^A-Za-z0-9]+/', ' ', Str::ascii($label)));

        return preg_match('/^[A-Za-z_]\w*$/', $name) === 1 ? $name : 'value';
    }

    /**
     * A name nothing at the place has yet: `photo`, or `photo2`.
     *
     * @param  list<string>  $taken
     */
    private function name(string $wanted, array $taken): string
    {
        $wanted = $this->variable($wanted);
        // Never one of the names Blade and Sunrice use themselves.
        $taken = [...$taken, ...self::THEIRS];
        if (! in_array($wanted, $taken, true)) {
            return $wanted;
        }
        for ($number = 2; in_array($wanted.$number, $taken, true); $number++);

        return $wanted.$number;
    }

    /**
     * Blade given piece by piece, as lines.
     *
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private function lines(array $pieces): array
    {
        return $pieces === [] ? [] : explode("\n", implode("\n", $pieces));
    }
}
