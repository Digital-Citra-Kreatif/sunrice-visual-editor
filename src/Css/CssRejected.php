<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Css;

use RuntimeException;

/** A change to a stylesheet that can't be made: the message says why, for the person. */
class CssRejected extends RuntimeException {}
