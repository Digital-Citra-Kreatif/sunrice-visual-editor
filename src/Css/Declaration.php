<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

/**
 * One declaration in a stylesheet, `color: red;`, with where each part of
 * it is in the file. Offsets are bytes; ends are exclusive.
 */
final class Declaration
{
    /**
     * @param  string  $property  as written: "color", "--accent"
     * @param  string  $value  as written, without the space around it; "!important" is part of it
     * @param  int  $start  of the property's name
     * @param  int  $end  after its ";" when it has one, else after the value
     */
    public function __construct(
        public readonly string $property,
        public readonly string $value,
        public readonly int $start,
        public readonly int $valueStart,
        public readonly int $valueEnd,
        public readonly int $end,
    ) {}

    public function important(): bool
    {
        return preg_match('/!\s*important\s*$/i', $this->value) === 1;
    }
}
