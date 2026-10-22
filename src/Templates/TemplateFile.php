<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Templates;

/**
 * One Blade file the editor can show: a template of the site, or one of
 * Sunrice's own views.
 */
final class TemplateFile
{
    public const SITE = 'site';

    public const PACKAGE = 'package';

    /**
     * @param  list<array{role: string, subject: string, handle: string}>  $usedBy
     */
    public function __construct(
        /** Inside its directory: "partials/header.blade.php". */
        public readonly string $path,
        /** site: one of the site's templates. package: one of Sunrice's own, read-only. */
        public readonly string $origin,
        /** The name Blade knows it by: "cms.partials.card", "sunrice-cms::entry". */
        public readonly string $view,
        /** layout, partial, block, entry, listing, term, search, error, component or other. */
        public readonly string $role,
        /** The collections and taxonomies whose pages it draws right now. */
        public readonly array $usedBy,
        /** Where it is on disk. */
        public readonly string $file,
        /** For a package view the site can replace: the site path a copy goes to. */
        public readonly ?string $copyTo = null,
    ) {}

    public function editable(): bool
    {
        return $this->origin === self::SITE;
    }

    /**
     * @return array{path: string, origin: string, view: string, role: string, usedBy: list<array{role: string, subject: string, handle: string}>, editable: bool, copyTo: ?string}
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'origin' => $this->origin,
            'view' => $this->view,
            'role' => $this->role,
            'usedBy' => $this->usedBy,
            'editable' => $this->editable(),
            'copyTo' => $this->copyTo,
        ];
    }
}
