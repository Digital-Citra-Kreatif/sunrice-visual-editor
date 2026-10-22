<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * What the site's content is made of, as a template reads it: the fields
 * of each blueprint, and what the CMS gives every entry, term, file and
 * menu link besides.
 *
 * The CMS hands a page's fields to its template as `$values`, by handle
 * (`$values['excerpt']`); an entry in a list has them as
 * `$item->values()['excerpt']`, a block as `$block->heading` and a global
 * set as `cms_global('footer')['text']`.
 */
final class Catalog
{
    /** How a blueprint's fields are read from the value that has them. */
    private const READS = [
        'collection' => 'key',      // $values['excerpt']
        'listing' => 'key',         // $values['intro'] on a listing page
        'taxonomy' => 'key',        // $values['description'] on a term page
        'fieldset' => 'property',   // $block->heading
        'global' => 'key',          // cms_global('footer')['text']
    ];

    /** @var array<string, list<Field>> */
    private array $read = [];

    /** @var array<string, array<string, string>> titles by handle, for each kind of subject */
    private array $titles = [];

    /**
     * The fields of a blueprint, in its order.
     *
     * @param  string  $subject  collection (its entries' fields), listing (its listing page's), fieldset, taxonomy or global
     * @param  string|null  $how  how they are read when not as the subject's own are: values (an entry's, `->values()['excerpt']`)
     * @return list<Field>
     */
    public function fields(string $subject, string $handle, ?string $how = null): array
    {
        $how ??= self::READS[$subject] ?? 'key';

        return $this->read[$subject.':'.$handle.':'.$how] ??= $this->convert(SunriceBridge::fields($subject, $handle), $how);
    }

    /**
     * What can be read from a value of this shape, under a heading each:
     * what every such value has, then the fields of each blueprint it may
     * be of.
     *
     * @return list<Members>
     */
    public function members(Shape $shape): array
    {
        return match ($shape->kind) {
            Shape::ENTRY => [new Members('Every entry', $this->ofEveryEntry($shape->of)), ...$this->blueprints('collection', $shape->of, 'values')],
            Shape::VALUES => array_values(array_filter(
                $this->blueprints($shape->subject ?? 'collection', $shape->of),
                fn (Members $members) => $shape->subject !== 'listing' || $members->fields !== [],
            )),
            Shape::TERM => [new Members('Every term', $this->ofEveryTerm($shape->of))],
            Shape::RECORD => [new Members('In every language', [
                $this->builtIn('collection', 'Collection', Shape::collection(count($shape->of) === 1 ? $shape->of[0] : null), '%s->collection'),
                $this->builtIn('terms', 'Terms', Shape::listOf(Shape::term()), '%s->terms'),
                $this->builtIn('parent', 'Entry above it', Shape::record($shape->of), '%s->parent'),
                $this->builtIn('id', 'ID', Shape::of(Shape::NUMBER), '%s->id'),
            ])],
            Shape::TERM_PAGE => [new Members('The term', [
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title'),
                $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), '%s->url()'),
                $this->builtIn('slug', 'Slug', Shape::of(Shape::TEXT), '%s->slug'),
                $this->builtIn('term', 'Term', Shape::term($shape->of), '%s->term'),
            ]), ...$this->blueprints('taxonomy', $shape->of, 'values')],
            // A block's own fields first: its type, key and id are seldom what is wanted.
            Shape::BLOCK => [...$this->blueprints('fieldset', $shape->of), new Members('Every block', [
                $this->builtIn('type', 'Block type', Shape::of(Shape::TEXT), '%s->type'),
                $this->builtIn('key', 'Key', Shape::of(Shape::TEXT), '%s->key'),
                $this->builtIn('id', 'ID', Shape::of(Shape::TEXT), '%s->id'),
            ])],
            Shape::GLOBALS => $this->blueprints('global', $shape->of),
            Shape::COLLECTION => [new Members('Collection', [
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->titleIn()'),
                $this->builtIn('listingUrl', 'Listing page URL', Shape::of(Shape::TEXT), '%s->listingUrl()'),
                $this->builtIn('handle', 'Handle', Shape::of(Shape::TEXT), '%s->handle'),
            ])],
            Shape::TAXONOMY => [new Members('Taxonomy', [
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->titleIn()'),
                $this->builtIn('handle', 'Handle', Shape::of(Shape::TEXT), '%s->handle'),
            ])],
            Shape::ASSET => [new Members('File', [
                ...$this->urls(),
                $this->builtIn('alt', 'Alt text', Shape::of(Shape::TEXT), '%s->altIn()'),
                $this->builtIn('caption', 'Caption', Shape::of(Shape::TEXT), '%s->captionIn()'),
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title'),
                $this->builtIn('width', 'Width', Shape::of(Shape::NUMBER), '%s->width'),
                $this->builtIn('height', 'Height', Shape::of(Shape::NUMBER), '%s->height'),
                $this->builtIn('isImage', 'Is an image', Shape::of(Shape::BOOL), '%s->isImage()'),
            ])],
            Shape::LINK => [new Members('Link', [
                $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), "%s['url']"),
                $this->builtIn('label', 'Label', Shape::of(Shape::TEXT), "%s['label']"),
                $this->builtIn('title', 'Title of the entry linked to', Shape::of(Shape::TEXT), "%s['title']"),
                $this->builtIn('new_tab', 'Opens in a new tab', Shape::of(Shape::BOOL), "%s['new_tab']"),
            ])],
            Shape::DATE => [new Members('Date', [
                $this->builtIn('long', 'As a date (11 October 2026)', Shape::of(Shape::TEXT), "%s->translatedFormat('j F Y')"),
                $this->builtIn('short', 'As a short date (11/10/2026)', Shape::of(Shape::TEXT), "%s->format('d/m/Y')"),
                $this->builtIn('time', 'Time (14:30)', Shape::of(Shape::TEXT), "%s->format('H:i')"),
                $this->builtIn('year', 'Year', Shape::of(Shape::NUMBER), '%s->year'),
                $this->builtIn('machine', 'For a datetime attribute', Shape::of(Shape::TEXT), '%s->toAtomString()'),
            ])],
            Shape::USER => [new Members('User', [$this->builtIn('name', 'Name', Shape::of(Shape::TEXT), '%s->name')])],
            Shape::MENU_ITEM => [new Members('Menu link', [
                $this->builtIn('label', 'Label', Shape::of(Shape::TEXT), "%s['label']"),
                $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), "%s['url']"),
                $this->builtIn('new_tab', 'Opens in a new tab', Shape::of(Shape::BOOL), "%s['new_tab']"),
                $this->builtIn('active', 'Is the page being shown', Shape::of(Shape::BOOL), "%s['active']"),
                $this->builtIn('active_trail', 'Is the page being shown, or above it', Shape::of(Shape::BOOL), "%s['active_trail']"),
                $this->builtIn('children', 'Links below it', Shape::listOf(Shape::of(Shape::MENU_ITEM)), "%s['children']"),
            ])],
            Shape::PAGE => [new Members('The page being shown', [
                $this->builtIn('entry', 'Entry', Shape::entry(), '%s->entry'),
                $this->builtIn('term', 'Term', Shape::termPage(), '%s->term'),
                $this->builtIn('collection', 'Collection', Shape::collection(), '%s->collection'),
                $this->builtIn('home', 'Is the homepage', Shape::of(Shape::BOOL), '%s->home'),
                $this->builtIn('locale', 'Language', Shape::of(Shape::TEXT), '%s->locale'),
                $this->builtIn('pageNumber', 'Page number', Shape::of(Shape::NUMBER), '%s->pageNumber'),
            ])],
            Shape::GROUP, Shape::ROW => $shape->fields === [] ? [] : [new Members('Fields', $shape->fields)],
            default => [],
        };
    }

    /** Finds a field by its handle among what a value of this shape has. */
    public function member(Shape $shape, string $handle): ?Field
    {
        foreach ($this->members($shape) as $members) {
            if (($field = $members->field($handle)) !== null) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Title by handle of every collection, taxonomy, fieldset or global set.
     *
     * @param  string  $subject  collection, listing, taxonomy, fieldset or global
     * @return array<string, string>
     */
    public function titles(string $subject): array
    {
        return $this->titles[$subject] ??= array_column(match ($subject) {
            'collection', 'listing' => SunriceBridge::collections(),
            'taxonomy' => SunriceBridge::taxonomies(),
            'fieldset' => SunriceBridge::fieldsets(),
            'global' => SunriceBridge::globals(),
            default => [],
        }, 'title', 'handle');
    }

    /**
     * The fields of each blueprint named, or of every one there is.
     *
     * @param  list<string>  $handles
     * @param  string|null  $how  as fields()
     * @return list<Members>
     */
    private function blueprints(string $subject, array $handles, ?string $how = null): array
    {
        $titles = $this->titles($subject);
        $members = [];
        foreach ($handles === [] ? array_keys($titles) : $handles as $handle) {
            $handle = (string) $handle;
            if (isset($titles[$handle])) {
                $members[] = new Members($subject === 'listing' ? 'Listing page of '.$titles[$handle] : $titles[$handle], $this->fields($subject, $handle, $how), $subject, $handle);
            }
        }

        return $members;
    }

    /**
     * @param  list<string>  $collections  the entry's: its collection is one of those
     * @return list<Field>
     */
    private function ofEveryEntry(array $collections): array
    {
        return [
            $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title'),
            $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), '%s->url()'),
            $this->builtIn('published_at', 'Published', Shape::of(Shape::DATE), '%s->published_at'),
            $this->builtIn('slug', 'Slug', Shape::of(Shape::TEXT), '%s->slug'),
            $this->builtIn('entry', 'In every language', Shape::record($collections), '%s->entry'),
        ];
    }

    /**
     * A term as fields and relations give it (a Term, in the page's language).
     *
     * @param  list<string>  $taxonomies  the term's own: the terms above and below it are of the same
     * @return list<Field>
     */
    private function ofEveryTerm(array $taxonomies): array
    {
        return [
            $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title()'),
            $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), '%s->url()'),
            $this->builtIn('parent', 'Term above it', Shape::term($taxonomies), '%s->parent'),
            $this->builtIn('children', 'Terms below it', Shape::listOf(Shape::term($taxonomies)), '%s->children'),
        ];
    }

    /**
     * An image's address at each size the site makes, the largest first, then as uploaded.
     *
     * @return list<Field>
     */
    private function urls(): array
    {
        $sizes = SunriceBridge::imageSizes();
        uasort($sizes, fn ($a, $b) => (int) (is_array($b) ? ($b[0] ?? 0) : 0) <=> (int) (is_array($a) ? ($a[0] ?? 0) : 0));
        $urls = [];
        foreach (array_keys($sizes) as $size) {
            $urls[] = $this->builtIn('url:'.$size, 'URL, '.$size, Shape::of(Shape::TEXT), "%s->url('".$this->quoted((string) $size)."')");
        }
        $urls[] = $this->builtIn('url', 'URL, as uploaded', Shape::of(Shape::TEXT), '%s->url()');

        return $urls;
    }

    private function builtIn(string $handle, string $label, Shape $shape, string $read): Field
    {
        return new Field($handle, $label, $shape->isList() ? 'list' : $shape->kind, $shape, $read, builtIn: true);
    }

    /**
     * @param  list<array<string, mixed>>  $definitions  as SunriceBridge::fields() gives them
     * @param  string  $how  key, property or values
     * @return list<Field>
     */
    private function convert(array $definitions, string $how): array
    {
        $fields = [];
        foreach ($definitions as $definition) {
            $handle = (string) ($definition['handle'] ?? '');
            if ($handle === '') {
                continue;
            }
            $type = (string) ($definition['type'] ?? '');
            $config = (array) ($definition['config'] ?? []);
            $several = (bool) ($config['multiple'] ?? false);
            // A group's fields and a row's are read from an array.
            $inside = fn (): array => $this->convert(array_values(array_filter((array) ($config['fields'] ?? []), is_array(...))), 'key');
            $handles = fn (mixed $value): array => array_values(array_filter(array_map(strval(...), (array) $value), fn (string $one) => $one !== ''));

            $shape = match (SunriceBridge::baseType($type)) {
                'text', 'textarea' => Shape::of(Shape::TEXT),
                'rich_text' => Shape::of(Shape::HTML),
                'number' => Shape::of(Shape::NUMBER),
                'toggle' => Shape::of(Shape::BOOL),
                'select' => $several ? Shape::listOf(Shape::of(Shape::TEXT)) : Shape::of(Shape::TEXT),
                'date' => Shape::of(Shape::DATE),
                'link' => Shape::of(Shape::LINK),
                'media' => $several ? Shape::listOf(Shape::of(Shape::ASSET)) : Shape::of(Shape::ASSET),
                'entries' => $several ? Shape::listOf(Shape::entry($handles($config['collections'] ?? []))) : Shape::entry($handles($config['collections'] ?? [])),
                'terms' => $several ? Shape::listOf(Shape::term($handles($config['taxonomy'] ?? []))) : Shape::term($handles($config['taxonomy'] ?? [])),
                'group' => Shape::group($inside()),
                'repeater' => Shape::listOf(Shape::row($inside())),
                'flexible' => Shape::listOf(Shape::block($handles($config['blocks'] ?? []))),
                default => Shape::of(Shape::VALUE),
            };

            $fields[] = new Field($handle, (string) ($definition['label'] ?? $handle), $type, $shape, $this->reading($how, $handle));
        }

        return $fields;
    }

    private function reading(string $how, string $handle): string
    {
        $named = preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $handle) === 1;
        $quoted = "'".$this->quoted($handle)."'";

        return match ($how) {
            // An entry's or a term's fields, from the entry itself: $item->values()['excerpt'].
            'values' => '%s->values()['.$quoted.']',
            // A property when the handle can be one: $block->heading. `get` reads any handle.
            'property' => $named ? '%s->'.$handle : '%s->get('.$quoted.')',
            default => '%s['.$quoted.']',
        };
    }

    /** For between single quotes in PHP, and safe in sprintf(). */
    private function quoted(string $text): string
    {
        return str_replace(['\\', "'", '%'], ['\\\\', "\\'", '%%'], $text);
    }
}
