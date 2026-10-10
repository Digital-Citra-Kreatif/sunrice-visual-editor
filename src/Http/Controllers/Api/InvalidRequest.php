<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Http\Controllers\Api;

use RuntimeException;

/** The request doesn't say what it has to: something is missing or of the wrong kind. */
final class InvalidRequest extends RuntimeException {}
