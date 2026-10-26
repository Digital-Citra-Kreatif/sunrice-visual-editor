<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * A value as it is read in a template: the code that reads it, what it
 * is, and what can go wrong reading it.
 *
 * `$values['cover']?->url('large')` is a reading: from a variable,
 * through a field, to a file's URL, with a `?->` because an entry need not
 * have a cover.
 */
final class Reading
{
    /** What may well be nothing when read from a field: reading on from these is done with `?->`. */
    private const MAY_BE_NOTHING = [Shape::ASSET, Shape::DATE, Shape::USER, Shape::ENTRY, Shape::TERM, Shape::TERM_TRANSLATION, Shape::COLLECTION, Shape::TAXONOMY, Shape::LINK, Shape::VALUE];

    /**
     * @param  bool  $maybe  it may be nothing (null) where the template is drawn
     * @param  bool  $loose  it is read from an array by a key that may not be there: PHP warns unless it is read with `??`, isset() or empty()
     * @param  bool  $fromField  it is the value of a blueprint's field, which the CMS has made ready for the page's language
     */
    public function __construct(
        public readonly string $code,
        public readonly Shape $shape,
        public readonly bool $maybe = false,
        public readonly bool $loose = false,
        public readonly bool $fromField = false,
    ) {}

    public static function of(Variable $variable): self
    {
        return new self($variable->code, $variable->shape, maybe: ! $variable->sure);
    }

    /** The reading of one of its fields. */
    public function field(Field $field): self
    {
        if (str_starts_with($field->read, '%s[')) {
            // $row['label']: nothing of it is sure, the row may not have the key.
            return new self(sprintf($field->read, $this->code), $field->shape, maybe: true, loose: true, fromField: ! $field->builtIn);
        }

        $owner = $this->loose ? '('.$this->code.' ?? null)' : $this->code;

        return new self(
            $field->from($owner, ownerMayBeMissing: $this->maybe || $this->loose),
            $field->shape,
            maybe: $this->maybe || $this->loose || ! $field->builtIn || in_array($field->shape->kind, self::MAY_BE_NOTHING, true),
            // $entry->values()['excerpt']: read by a key the fields may not have.
            loose: str_ends_with($field->read, "']"),
            fromField: ! $field->builtIn,
        );
    }

    /** One of the items when this is a list, read as a variable of a loop. */
    public function item(string $variable): self
    {
        return new self('$'.$variable, $this->shape->item ?? Shape::of(Shape::VALUE));
    }

    /** As it is written where a text is wanted: in `{{ }}`, in an attribute. */
    public function printed(): string
    {
        return $this->loose ? $this->code." ?? ''" : $this->code;
    }

    /** As it is written to be looped over: nothing is no items. */
    public function looped(): string
    {
        return $this->maybe || $this->loose ? $this->code.' ?? []' : $this->code;
    }

    /** As it is written in a condition that asks whether there is anything. */
    public function asked(): string
    {
        return match (true) {
            $this->shape->isList() => $this->maybe || $this->loose ? 'collect('.$this->code.($this->loose ? ' ?? []' : '').')->isNotEmpty()' : $this->code.'->isNotEmpty()',
            $this->loose => '! empty('.$this->code.')',
            default => $this->code,
        };
    }
}
