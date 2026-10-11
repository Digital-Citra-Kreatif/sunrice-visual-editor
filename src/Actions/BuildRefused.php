<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Actions;

use RuntimeException;

/** The site's build was not run: no command is set, or one is running already. */
final class BuildRefused extends RuntimeException {}
