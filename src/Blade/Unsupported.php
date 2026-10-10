<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use RuntimeException;

/**
 * The template can't be represented as a tree without guessing. The editor
 * opens such a file in the code panel instead of the visual tree.
 */
class Unsupported extends RuntimeException
{
    public function __construct(string $reason, public readonly int $offset = 0)
    {
        parent::__construct($reason);
    }
}
