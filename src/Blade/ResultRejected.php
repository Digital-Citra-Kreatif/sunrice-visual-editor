<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Blade;

/**
 * The edits were made, and the file they leave can't be saved: it no longer
 * opens as a template, or no longer compiles. The source is untouched.
 */
final class ResultRejected extends EditRejected {}
