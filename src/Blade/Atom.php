<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * One Blade construct in the source: an echo, a comment, a directive, a PHP
 * block or a component tag. Offsets are bytes; $end is exclusive.
 */
final class Atom
{
    public const OPEN = 'open';

    public const BRANCH = 'branch';

    public const CLOSE = 'close';

    public const SINGLE = 'single';

    /** The directive or component tag this one continues or closes (@else → @if). */
    public ?Atom $openedBy = null;

    public function __construct(
        /** echo | raw_echo | comment | directive | php | verbatim | component */
        public readonly string $kind,
        public readonly int $start,
        public readonly int $end,
        /** Directive name without "@", or component tag name without "x-". */
        public readonly string $name = '',
        /** Directive arguments or echo expression, without the delimiters. */
        public readonly ?string $args = null,
        public string $role = self::SINGLE,
    ) {}
}
