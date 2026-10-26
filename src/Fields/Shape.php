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

    /** An entry in a language (Sunrice\Cms\Models\EntryTranslation): what pages, lists and entries fields give. */
    public const ENTRY = 'entry';

    /** A term (Sunrice\Cms\Models\Term): an entry's terms, a terms field, a term's parent and children. */
    public const TERM = 'term';

    /** A term in a language (Sunrice\Cms\Models\TermTranslation): the term a term page shows. */
    public const TERM_TRANSLATION = 'term_translation';

    /** The fields of something, as an array by handle: a page's `$values`, an entry's `values()`. */
    public const VALUES = 'values';

    /** Values of blueprints of more than one kind: their `of` are "subject:handle". */
    public const MIXED = 'mixed';

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
     * @param  list<string>  $of  entry: its collections; term: its taxonomies; block: its fieldsets; global: its set; values: the blueprints' subjects. None means any.
     * @param  list<Field>  $fields  group, row: the fields it holds, which belong to nothing else
     * @param  string|null  $subject  values: what the fields are of (collection, listing, taxonomy, index, fieldset or global)
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?Shape $item = null,
        public readonly array $of = [],
        public readonly array $fields = [],
        public readonly ?string $subject = null,
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

    /** @param  list<string>  $taxonomies */
    public static function termTranslation(array $taxonomies = []): self
    {
        return new self(self::TERM_TRANSLATION, of: $taxonomies);
    }

    /**
     * The fields of a blueprint, by handle: an entry page's `$values` are
     * values('collection', ['articles']).
     *
     * @param  string  $subject  collection, listing, taxonomy, index, fieldset or global; MIXED with "subject:handle"s
     * @param  list<string>  $of  the handles of the collections (taxonomies…) they may be of; none for any
     */
    public static function values(string $subject, array $of = []): self
    {
        return new self(self::VALUES, of: $of, subject: $subject);
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
            self::TERM_TRANSLATION => 'term',
            self::VALUES => 'fields',
            default => $this->kind,
        };
    }

    /**
     * Exactly what it is, in one line: "list<entry(articles)>",
     * "row{label, text, icon}", "block(hero|text)".
     */
    public function spell(): string
    {
        return match (true) {
            $this->isList() => 'list<'.($this->item?->spell() ?? '?').'>',
            $this->fields !== [] => $this->kind.'{'.implode(', ', array_map(fn (Field $field) => $field->handle, $this->fields)).'}',
            $this->of !== [] => $this->kind.($this->subject === null ? '' : ':'.$this->subject).'('.implode('|', $this->of).')',
            $this->subject !== null => $this->kind.':'.$this->subject,
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
            'subject' => $this->subject,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
        ], fn ($value) => $value !== null && $value !== []);
    }
}
