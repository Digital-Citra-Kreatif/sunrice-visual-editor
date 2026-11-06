<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use RuntimeException;

/**
 * A custom CSS or JS file was not saved: its code failed the syntax check
 * (`syntax`, with the line when the check named one), or the check itself
 * could not be made (`checker`).
 */
final class AssetCheckFailed extends RuntimeException
{
    /** @param 'syntax'|'checker' $reason */
    public function __construct(string $message, public readonly string $reason = 'syntax', public readonly ?int $sourceLine = null)
    {
        parent::__construct($message);
    }
}
