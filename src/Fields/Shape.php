<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * What a value in a template is: an entry, a piece of text, a list of
 * images. It decides how the value is printed, what can be read from it,
 * and whether it is looped over.
 *
 * A shape names what it is made of (an entry of the `articles`
 * collection) and does not hold it: an article can list related articles,
 * and the catalog is asked for the fields only as far as someone looks.
 */
final class Shape
{
    public const TEXT = 'text';

    public const HTML = 'html';

    public const NUMBER = 'number';

    public const BOOL = 'bool';

    public const DATE = 'date';

    public const LINK = 'link';

    public const ASSET = 'asset';

    public const ENTRY = 'entry';

    public const TERM = 'term';

    public const USER = 'user';

    public const GROUP = 'group';

    public const ROW = 'row';

    public const BLOCK = 'block';

    public const GLOBALS = 'global';

    public const MENU_ITEM = 'menu_item';

    public const COLLECTION = 'collection';

    public const TAXONOMY = 'taxonomy';

    public const PAGE = 'page';

    public const LIST = 'list';

    /** Something the editor knows nothing about: it can be printed, and that is all. */
    public const VALUE = 'value';

    /**
     * @param  list<string>  $of  entry: its collections; term: its taxonomies; block: its fieldsets; global: its set. None means any.
     * @param  list<Field>  $fields  group, row: the fields it holds, which belong to nothing else
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?Shape $item = null,
        public readonly array $of = [],
        public readonly array $fields = [],
    ) {}

    public static function of(string $kind): self
    {
        return new self($kind);
    }

    public static function listOf(Shape $item): self
    {
        return new self(self::LIST, $item);
    }

    /** @param  list<string>  $collections */
    public static function entry(array $collections = []): self
    {
        return new self(self::ENTRY, of: $collections);
    }

    /** @param  list<string>  $taxonomies */
    public static function term(array $taxonomies = []): self
    {
        return new self(self::TERM, of: $taxonomies);
    }

    /** @param  list<string>  $fieldsets */
    public static function block(array $fieldsets = []): self
    {
        return new self(self::BLOCK, of: $fieldsets);
    }

    public static function globals(string $set): self
    {
        return new self(self::GLOBALS, of: [$set]);
    }

    /** One collection ("articles"), or whichever the page is of. */
    public static function collection(?string $handle = null): self
    {
        return new self(self::COLLECTION, of: $handle === null ? [] : [$handle]);
    }

    /** @param  list<Field>  $fields */
    public static function group(array $fields): self
    {
        return new self(self::GROUP, fields: $fields);
    }

    /** @param  list<Field>  $fields */
    public static function row(array $fields): self
    {
        return new self(self::ROW, fields: $fields);
    }

    public function isList(): bool
    {
        return $this->kind === self::LIST;
    }

    /** Whether it is printed as it is: `{{ }}` or, for HTML, `{!! !!}`. */
    public function isPrinted(): bool
    {
        return in_array($this->kind, [self::TEXT, self::HTML, self::NUMBER, self::VALUE], true);
    }

    /**
     * In a few words, for a badge: "text", "image", "list of entries".
     */
    public function name(): string
    {
        return match ($this->kind) {
            self::LIST => 'list of '.match ($this->item?->kind) {
                self::ENTRY => 'entries',
                self::ASSET => 'files',
                self::ROW => 'rows',
                self::TEXT => 'texts',
                default => ($this->item?->name() ?? 'value').'s',
            },
            self::HTML => 'rich text',
            self::BOOL => 'yes or no',
            self::ASSET => 'file',
            self::GLOBALS => 'global set',
            self::MENU_ITEM => 'menu item',
            default => $this->kind,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'kind' => $this->kind,
            'item' => $this->item?->toArray(),
            'of' => $this->of,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
