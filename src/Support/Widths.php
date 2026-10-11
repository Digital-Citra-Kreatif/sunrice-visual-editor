<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Support;

/**
 * The widths the editor shows the page at (config `widths`), as far as
 * they can be used: a setting written by hand may hold anything.
 */
final class Widths
{
    /** More frames than this side by side show nothing of any of them. */
    private const MOST = 6;

    /**
     * @return list<array{name: string, width: int, media: string|null}>
     */
    public static function configured(): array
    {
        $widths = [];
        foreach ((array) config('sunrice-visual-editor.widths', []) as $each) {
            $name = is_array($each) && is_string($each['name'] ?? null) ? trim($each['name']) : '';
            $width = is_array($each) && is_numeric($each['width'] ?? null) ? (int) $each['width'] : 0;
            if ($name === '' || mb_strlen($name) > 40 || $width < 200 || $width > 4000 || count($widths) >= self::MOST) {
                continue;
            }
            $media = is_string($each['media'] ?? null) ? trim((string) preg_replace('/^\s*@media\b/i', '', $each['media'])) : '';
            // A condition as a @media block starts with: in brackets, and nothing that would end the block.
            $widths[] = ['name' => $name, 'width' => $width, 'media' => preg_match('/^\([^{};]{1,200}\)$/', $media) === 1 ? $media : null];
        }

        return $widths;
    }
}
