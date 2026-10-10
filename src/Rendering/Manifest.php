<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Rendering;

use Sunrice\VisualEditor\Templates\TemplateFile;

/**
 * The templates that drew one page, in the order they were first rendered.
 * Each has a key: the short name its elements are marked with in the page.
 */
final class Manifest
{
    /** @var array<string, TemplateFile> the templates the editor knows, by real path */
    private array $known = [];

    /** @var array<string, array<string, mixed>> the ones rendered so far, by key */
    private array $files = [];

    /**
     * @param  list<TemplateFile>  $templates  every template of the site and of Sunrice
     */
    public function __construct(array $templates)
    {
        foreach ($templates as $template) {
            $this->known[self::real($template->file)] = $template;
        }
    }

    /** The same file has the same key in every render, so a marked copy can be kept. */
    public static function key(TemplateFile $file): string
    {
        return substr(sha1($file->origin.':'.$file->path), 0, 10);
    }

    /** The template at this path on disk, when it is one the editor knows. */
    public function fileAt(string $path): ?TemplateFile
    {
        return $this->known[self::real($path)] ?? null;
    }

    /**
     * Notes that a template took part in this page.
     *
     * @param  bool  $opens  whether it opened as a tree, and so carries marks
     */
    public function record(TemplateFile $file, bool $opens): void
    {
        $this->files[self::key($file)] ??= ['key' => self::key($file)] + $file->toArray() + ['opens' => $opens];
    }

    /**
     * @return array{files: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return ['files' => array_values($this->files)];
    }

    private static function real(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }
}
