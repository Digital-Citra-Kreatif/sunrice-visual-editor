<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Guard;

use Sunrice\VisualEditor\Blade\EditRejected;

/**
 * A change that would make a template run code it did not run before,
 * asked for by someone who may not edit code. Nothing has been changed.
 */
final class CodeRefused extends EditRejected {}
