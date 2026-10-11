<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

use RuntimeException;

/** The theme has no such stylesheet, or it is not one the editor changes. */
final class StylesheetNotFound extends RuntimeException {}
