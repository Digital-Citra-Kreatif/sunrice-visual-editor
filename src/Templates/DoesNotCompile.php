<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

use RuntimeException;

/** The new source is not Blade that compiles; the message says where. Nothing is written. */
final class DoesNotCompile extends RuntimeException {}
