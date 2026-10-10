<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * What a list of operations left: the new file, and the node to select in
 * it.
 */
final class Applied
{
    public function __construct(
        /** The template after the operations, checked: it opens and compiles. */
        public readonly string $source,
        /**
         * The id, in the new file, of the node the last operation made or
         * changed: what was inserted, the copy, the wrapper, the node where
         * it was moved to. Null when there is none (a node was removed).
         */
        public readonly ?string $focus = null,
    ) {}
}
