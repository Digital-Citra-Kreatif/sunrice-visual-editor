<?php

declare(strict_types=1);

namespace Sunrice\VisualEditor\Fields;

/**
 * Something a template can read at one place in it: `$entry`, the `$step`
 * of the loop around that place, a global set.
 */
final class Variable
{
    /** Given by the page: the entry it shows, its collection. */
    public const PAGE = 'page';

    /** Given by whatever includes this template. */
    public const INCLUDED = 'included';

    /** Each item of a loop around the place. */
    public const LOOP = 'loop';

    /** Set by the template itself, before the place. */
    public const SET = 'set';

    /** Given to what is inside a component's tag. */
    public const COMPONENT = 'component';

    /** There in every template of the site: the page being shown, global sets, menus. */
    public const SITE = 'site';

    /**
     * @param  string  $code  how it is written: "$entry", or for what is no variable "sunrice_global('site')"
     * @param  bool  $sure  it is there, and not empty, wherever this place is drawn
     */
    public function __construct(
        public readonly string $code,
        public readonly Shape $shape,
        public readonly string $from,
        public readonly string $title,
        public readonly bool $sure = true,
    ) {}

    /** Its name without the "$"; null for what is no variable. */
    public function name(): ?string
    {
        return preg_match('/^\$([A-Za-z_]\w*)$/', $this->code, $match) === 1 ? $match[1] : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['code' => $this->code, 'shape' => $this->shape->toArray(), 'from' => $this->from, 'title' => $this->title, 'sure' => $this->sure];
    }
}
