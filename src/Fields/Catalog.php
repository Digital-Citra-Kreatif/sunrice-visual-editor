<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

use Sunrice\VisualEditor\Support\SunriceBridge;

/**
 * What the site's content is made of, as a template reads it: the fields
 * of each blueprint, and what the CMS gives every entry, term, image and
 * menu item besides.
 */
final class Catalog
{
    /** How a blueprint's fields are read from what has them, with %s for the handle: an entry's are in its values(). */
    public const ON_ENTRY = "%s->values()['%s']";

    /** A term's own fields: those of its translation in the page's language. */
    public const ON_TERM = "%s->translation()?->values()['%s']";

    /** A block's fields: properties of it, which are null when it has none (Sunrice\Cms\Fields\Block). */
    public const ON_BLOCK = '%s->%s';

    /** A page's `$values`, a block's values, a global set, a row: an array by handle. */
    public const BY_KEY = "%s['%s']";

    /** @var array<string, list<Field>> */
    private array $read = [];

    /** @var array<string, array<string, string>> titles by handle, for each kind of subject */
    private array $titles = [];

    /**
     * The fields of a blueprint, in its order.
     *
     * @param  string  $subject  collection (its entries' fields), listing (its listing page's), fieldset, taxonomy, index (a taxonomy's index page's) or global
     * @param  string  $how  how each is read from what has it: ON_ENTRY, ON_TERM, ON_BLOCK or BY_KEY
     * @return list<Field>
     */
    public function fields(string $subject, string $handle, string $how = self::BY_KEY): array
    {
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
            Shape::ENTRY => [new Members('Every entry', $this->ofEveryEntry()), ...$this->blueprints('collection', $shape->of, self::ON_ENTRY)],
            Shape::TERM => [new Members('Every term', $this->ofEveryTerm($shape->of)), ...$this->blueprints('taxonomy', $shape->of, self::ON_TERM)],
            Shape::TERM_TRANSLATION => [new Members('Term', [
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title'),
                $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), '%s->url()'),
                $this->builtIn('slug', 'Slug', Shape::of(Shape::TEXT), '%s->slug'),
                $this->builtIn('term', 'In every language', Shape::term($shape->of), '%s->term'),
            ]), ...$this->blueprints('taxonomy', $shape->of, self::ON_ENTRY)],
            Shape::VALUES => $shape->subject === Shape::MIXED
                ? array_merge(...array_map(function (string $one): array {
                    [$subject, $handle] = array_pad(explode(':', $one, 2), 2, '');

                    return $this->blueprints($subject, [$handle], self::BY_KEY);
                }, $shape->of))
                : $this->blueprints((string) $shape->subject, $shape->of, self::BY_KEY),
            // A block's own fields first: its type and key are seldom what is wanted.
            Shape::BLOCK => [...$this->blueprints('fieldset', $shape->of, self::ON_BLOCK), new Members('Every block', [
                $this->builtIn('type', 'Block type', Shape::of(Shape::TEXT), '%s->type'),
                $this->builtIn('key', 'Key', Shape::of(Shape::TEXT), '%s->key'),
                $this->builtIn('id', 'ID', Shape::of(Shape::TEXT), '%s->id'),
            ])],
            Shape::GLOBALS => $this->blueprints('global', $shape->of, self::BY_KEY),
            Shape::COLLECTION => [new Members('Collection', [
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->titleIn($locale)'),
                $this->builtIn('listingUrl', 'Listing page URL', Shape::of(Shape::TEXT), '%s->listingUrl($locale)'),
            ])],
            Shape::TAXONOMY => [new Members('Taxonomy', [
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->titleIn($locale)'),
                $this->builtIn('indexUrl', 'Index page URL', Shape::of(Shape::TEXT), '%s->indexUrl($locale)'),
            ])],
            Shape::ASSET => [new Members('File', [
                ...$this->urls(),
                $this->builtIn('alt', 'Alt text', Shape::of(Shape::TEXT), '%s->altIn()'),
                $this->builtIn('caption', 'Caption', Shape::of(Shape::TEXT), '%s->captionIn()'),
                $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title'),
                $this->builtIn('width', 'Width', Shape::of(Shape::NUMBER), '%s->width'),
                $this->builtIn('height', 'Height', Shape::of(Shape::NUMBER), '%s->height'),
            ])],
            Shape::LINK => [new Members('Link', [
                $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), "%s['url']"),
                $this->builtIn('label', 'Label', Shape::of(Shape::TEXT), "%s['label']"),
                $this->builtIn('title', 'Title of the entry it links to', Shape::of(Shape::TEXT), "%s['title']"),
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
            Shape::MENU_ITEM => [new Members('Menu item', [
                $this->builtIn('label', 'Label', Shape::of(Shape::TEXT), "%s['label']"),
                $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), "%s['url']"),
                $this->builtIn('new_tab', 'Opens in a new tab', Shape::of(Shape::BOOL), "%s['new_tab']"),
                $this->builtIn('active', 'Is the page being shown', Shape::of(Shape::BOOL), "%s['active']"),
                $this->builtIn('active_trail', 'Is the page being shown, or above it', Shape::of(Shape::BOOL), "%s['active_trail']"),
                $this->builtIn('children', 'Items below it', Shape::listOf(Shape::of(Shape::MENU_ITEM)), "%s['children']"),
            ])],
            Shape::PAGE => [new Members('The page being shown', [
                $this->builtIn('entry', 'Entry', Shape::entry(), '%s->entry'),
                $this->builtIn('term', 'Term', Shape::termTranslation(), '%s->term'),
                $this->builtIn('collection', 'Collection', Shape::collection(), '%s->collection'),
                $this->builtIn('taxonomy', 'Taxonomy', Shape::of(Shape::TAXONOMY), '%s->taxonomy'),
                $this->builtIn('home', 'Is the homepage', Shape::of(Shape::BOOL), '%s->home'),
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
     * @param  string  $subject  collection, listing, taxonomy, index, fieldset or global
     * @return array<string, string>
     */
    public function titles(string $subject): array
    {
        return $this->titles[$subject] ??= array_column(match ($subject) {
            'collection', 'listing' => SunriceBridge::collections(),
            'taxonomy', 'index' => SunriceBridge::taxonomies(),
            'fieldset' => SunriceBridge::fieldsets(),
            'global' => SunriceBridge::globals(),
            default => [],
        }, 'title', 'handle');
    }

    /**
     * The fields of each blueprint named, or of every one there is.
     *
     * @param  list<string>  $handles
     * @param  string  $how  ON_ENTRY, ON_TERM or BY_KEY
     * @return list<Members>
     */
    private function blueprints(string $subject, array $handles, string $how): array
    {
        $titles = $this->titles($subject);
        $members = [];
        foreach ($handles === [] ? array_keys($titles) : $handles as $handle) {
            $handle = (string) $handle;
            if (isset($titles[$handle])) {
                $title = match ($subject) {
                    'listing' => 'Listing page of '.$titles[$handle],
                    'index' => 'Index page of '.$titles[$handle],
                    default => $titles[$handle],
                };
                $fields = $this->fields($subject, $handle, $how);
                // A listing page or an index page without fields of its own has none to show.
                if ($fields !== [] || ! in_array($subject, ['listing', 'index'], true)) {
                    $members[] = new Members($title, $fields, $subject, $handle);
                }
            }
        }

        return $members;
    }

    /** @return list<Field> */
    private function ofEveryEntry(): array
    {
        return [
            $this->builtIn('title', 'Title', Shape::of(Shape::TEXT), '%s->title'),
            $this->builtIn('url', 'URL', Shape::of(Shape::TEXT), '%s->url()'),
            $this->builtIn('published_at', 'Published', Shape::of(Shape::DATE), '%s->published_at'),
            $this->builtIn('terms', 'Terms', Shape::listOf(Shape::term()), '%s->terms'),
            $this->builtIn('slug', 'Slug', Shape::of(Shape::TEXT), '%s->slug'),
        ];
    }

    /**
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
        $sizes = (array) config('sunrice-admin.media.image_sizes', []);
        uasort($sizes, fn ($a, $b) => (int) ($b[0] ?? 0) <=> (int) ($a[0] ?? 0));
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
     * @param  string  $how  ON_ENTRY, ON_TERM or BY_KEY
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
            $inside = fn (): array => $this->convert(array_values(array_filter((array) ($definition['fields'] ?? []), is_array(...))), self::BY_KEY);
            $handles = fn (mixed $value): array => array_values(array_filter(array_map(strval(...), (array) $value), fn (string $one) => $one !== ''));
            $many = fn (Shape $one): Shape => $several ? Shape::listOf($one) : $one;

            $shape = match (SunriceBridge::baseType($type)) {
                'text', 'textarea', 'code' => Shape::of(Shape::TEXT),
                'rich_text' => Shape::of(Shape::HTML),
                'number' => Shape::of(Shape::NUMBER),
                'toggle' => Shape::of(Shape::BOOL),
                'select' => $many(Shape::of(Shape::TEXT)),
                'date' => Shape::of(Shape::DATE),
                'link' => Shape::of(Shape::LINK),
                'media' => $many(Shape::of(Shape::ASSET)),
                'entries' => $many(Shape::entry($handles($config['collections'] ?? []))),
                'terms' => $many(Shape::term($handles($config['taxonomy'] ?? []))),
                'group' => Shape::group($inside()),
                'repeater' => Shape::listOf(Shape::row($inside())),
                'flexible' => Shape::listOf(Shape::block($handles($config['blocks'] ?? []))),
                default => Shape::of(Shape::VALUE),
            };

            $fields[] = new Field($handle, (string) ($definition['label'] ?? $handle), $type, $shape, sprintf($how, '%s', $this->quoted($handle)));
        }

        return $fields;
    }

    /** For between single quotes in PHP, and safe in sprintf(). */
    private function quoted(string $text): string
    {
        return str_replace(['\\', "'", '%'], ['\\\\', "\\'", '%%'], $text);
    }
}
