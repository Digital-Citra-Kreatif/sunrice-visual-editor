<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Sunrice\Cms\Models\EntryTranslation;
use Sunrice\VisualEditor\Http\Controllers\EditorController;

/**
 * "Edit with Visual Editor" in the ⋮ menu of an entry's page in the
 * panel (Cms::entryLinksUsing()): the editor, open on that entry's page
 * of the site.
 */
final class Launch
{
    /** @return list<array{label: string, href: string}> none when the entry has no live page, or the person may not open the editor */
    public static function link(EntryTranslation $entry, Authorizable $user): array
    {
        $path = self::path($entry);
        if ($path === null || ! Access::allowed($user)) {
            return [];
        }

        return [['label' => __('Edit with Visual Editor'), 'href' => self::editorUrl($path)]];
    }

    /**
     * The site path the editor opens on: the entry's page in the language
     * being edited, else in its main language. Null while it has none (a
     * draft never published, a collection without pages, the trash).
     */
    public static function path(EntryTranslation $entry): ?string
    {
        if ($entry->entry->trashed()) {
            return null;
        }
        foreach ([$entry, $entry->entry->mainTranslation()] as $translation) {
            if ($translation instanceof EntryTranslation && $translation->isLive()) {
                $path = self::pathOf($translation->url());
                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    /** The editor's address for a site path, under the panel's path. */
    public static function editorUrl(string $path): string
    {
        return route('sunrice.admin.visual-editor.show', ['url' => $path], false);
    }

    /** An address of the site as a path with its query; null for anything the editor would not open. */
    private static function pathOf(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        $query = parse_url($url, PHP_URL_QUERY);
        $path = ($path === '' ? '/' : $path).(is_string($query) && $query !== '' ? '?'.$query : '');

        return EditorController::sitePath($path) === $path ? $path : null;
    }
}
