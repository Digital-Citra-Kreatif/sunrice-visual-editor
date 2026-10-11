<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

use RuntimeException;

/** A change to a stylesheet that can't be made: the message says why, for the person. Nothing has been changed when it is thrown. */
class CssRejected extends RuntimeException
{
    /** Which change of a list was refused, counted from 0; null when it didn't come from one. */
    public ?int $change = null;
}
