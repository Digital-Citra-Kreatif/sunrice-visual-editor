<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

use RuntimeException;

/** A view of Sunrice's can't be copied to the site: there is nowhere for it, or a template is already there. */
final class CopyRefused extends RuntimeException {}
