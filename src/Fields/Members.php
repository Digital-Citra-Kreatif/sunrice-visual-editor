<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * Some of what can be read from a value, under one heading: what every
 * entry has, or the fields of the Articles collection.
 */
final class Members
{
    /**
     * @param  string|null  $subject  collection, listing, fieldset, taxonomy or global when the fields are a blueprint's
     * @param  list<Field>  $fields
     */
    public function __construct(
        public readonly string $title,
        public readonly array $fields,
        public readonly ?string $subject = null,
        public readonly ?string $handle = null,
    ) {}

    public function field(string $handle): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->handle === $handle) {
                return $field;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'subject' => $this->subject,
            'handle' => $this->handle,
            'fields' => array_map(fn (Field $field) => $field->toArray(), $this->fields),
        ], fn ($value) => $value !== null);
    }
}
