<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

use RuntimeException;

/** There is no template at that path, or the path is not one a template can have. */
final class TemplateNotFound extends RuntimeException {}
