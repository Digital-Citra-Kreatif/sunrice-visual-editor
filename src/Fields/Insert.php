<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * One way of putting a value into a template: the Blade for it, and a
 * word or two saying what it is.
 */
final class Insert
{
    /** Printed where it stands: `{{ }}` or `{!! !!}`. */
    public const PRINT = 'print';

    /** An element of its own: an image, a link. */
    public const ELEMENT = 'element';

    /** A loop or a condition, for other things to go into. */
    public const STRUCTURE = 'structure';

    public function __construct(
        public readonly string $title,
        public readonly string $kind,
        public readonly string $blade,
    ) {}

    /** @return array{title: string, kind: string, blade: string} */
    public function toArray(): array
    {
        return ['title' => $this->title, 'kind' => $this->kind, 'blade' => $this->blade];
    }
}
