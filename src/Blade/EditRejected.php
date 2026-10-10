<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

use RuntimeException;

/**
 * An edit that can't be made as asked: the reason is written for the person
 * using the editor. Nothing has been changed when it is thrown.
 */
class EditRejected extends RuntimeException
{
    /** Which operation of a list was refused, counted from 0; null when the edit didn't come from one. */
    public ?int $operation = null;
}
