<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * Something that can be read from a value in a template: a field of a
 * blueprint (an article's `excerpt`), or what Sunrice gives every value
 * of that kind (an entry's title, an image's alt text).
 */
final class Field
{
    /**
     * @param  string  $type  Sunrice's name for the field type ("rich_text"), or what a built-in is like ("text", "date")
     * @param  string  $read  how it is read from what has it, with %s for that: "%s->get('excerpt')", "%s->title", "%s['label']"
     */
    public function __construct(
        public readonly string $handle,
        public readonly string $label,
        public readonly string $type,
        public readonly Shape $shape,
        public readonly string $read,
        public readonly bool $builtIn = false,
    ) {}

    /**
     * The expression that reads it: from "$entry", "$entry->get('excerpt')".
     *
     * @param  bool  $ownerMayBeMissing  read with "?->", so nothing is read from nothing
     */
    public function from(string $owner, bool $ownerMayBeMissing = false): string
    {
        $read = $ownerMayBeMissing && str_starts_with($this->read, '%s->') ? '%s?->'.substr($this->read, 4) : $this->read;

        return sprintf($read, $owner);
    }

    /** Whether it holds several: a list of images, of rows, of blocks. */
    public function multiple(): bool
    {
        return $this->shape->isList();
    }

    /**
     * The fields inside it: a group's, or those of each row of a repeater.
     *
     * @return list<Field>
     */
    public function children(): array
    {
        return $this->shape->isList() ? ($this->shape->item->fields ?? []) : $this->shape->fields;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'handle' => $this->handle,
            'label' => $this->label,
            'type' => $this->type,
            'multiple' => $this->multiple(),
            'builtIn' => $this->builtIn,
            'shape' => $this->shape->toArray(),
            'children' => array_map(fn (Field $field) => $field->toArray(), $this->children()),
        ], fn ($value) => $value !== [] && $value !== false);
    }
}
