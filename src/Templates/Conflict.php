<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

use RuntimeException;

/**
 * The file is not the version the change was made for: someone saved it in
 * the meantime, in a code editor or in another window. Nothing is written.
 */
final class Conflict extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        /** The version the file has now. */
        public readonly string $current,
    ) {
        parent::__construct("\"{$path}\" has changed since it was opened.");
    }
}
