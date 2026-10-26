# Fields and variables

A Sunrice template reads its content from variables: `$values` (the page's
fields) and `$entry` on an entry's page, `$block` in a block's partial, the
site's global sets and menus everywhere. The editor knows which of them a template has at each place,
what fields each one holds, and how each is written in Blade. You choose
from a list; it writes the code at the cursor.

## Where the list is

The **Fields** tab, on the right of the page, lists what the open template
can read where the code cursor is. A search box at its top finds a field
by name.

- A click on a field writes it at the cursor, in its usual form, indented
  as the line is. **As…** on its row lists the other forms.
- It reads the code as you have typed it, saved or not: a variable set on
  the line above is in the list a moment after you type the line.
- While the code does not read as a whole (a tag half typed), the list is
  what the template has anywhere in it, and says so.

In the **Template** tab, while you type, the editor offers what a value
has: after `$values['`, `$entry->` or `$row['` the fields of that value, after `{{ `
everything there that can be printed, after `$` the variables. Choosing
one writes it whole, with its `?->` and `?? ''`.

Writing a field takes the [code permission](permissions.md). Without it
the list is there to read.

## What is in the list

The list is for one place in one template. It has three parts.

**At this place**

- the item of every loop around the place (`$article` inside
  `@foreach ($values['related'] ?? [] as $article)`), and its key;
- what the template sets above the place: `@php($image = …)`, or a
  condition that sets what it asks about
  (`@if ($cover = $values['cover'] ?? null)`);
- `$item`, inside `<x-sunrice-cms::entries>` and `<x-sunrice-cms::terms>`,
  and `$results` and `$query` inside `<x-sunrice-cms::search>`.

**This template**

| Template | Has |
| --- | --- |
| an entry's page (`collections/show`, `collections/articles/show`) | `$entry`, `$values` with the fields of its collection, `$title`, `$page` |
| a listing page (`collections/index`, `collections/articles/index`) | `$entries`, `$values` with the fields of the listing page, `$url` |
| a term's page (`taxonomies/show`, `taxonomies/topics/show`) | `$term`, `$values` with the fields of its taxonomy, `$entries` |
| a taxonomy's index (`taxonomies/index`) | `$terms`, `$values` |
| the search page (`search`) | `$query`, `$results` |
| a block (`blocks/hero`) | `$block`, with the fields of the fieldset the file is named after |
| a partial | what the templates that include it pass to it |

Every page also has `$richText`, `$code`, `$preview`, `$draft`, `$fallback`
and `$contentLocale`, as the CMS gives them.

For a partial, the editor reads every `@include` of it in the site's
templates. A card included once with an article and once with a page is
offered the fields of both, and a field only one of them has says so
("Not in Pages").

**The site**

`cms_page()` (the page being drawn: its entry or term, collection,
taxonomy, whether it is the homepage, the page number), `$locale`, every
global set (`cms_global('site')`) and every menu (`cms_menu('main')`).

The fields come from the site's blueprints as they are now. Add a field to
a collection in the admin and it is in the list the next time the list is
opened.

## What a click writes

| Value | Written as |
| --- | --- |
| text, number, select | `{{ $values['summary'] ?? '' }}` |
| rich text | `{!! $values['body'] ?? '' !!}` |
| yes or no | `@if (! empty($values['featured'])) … @endif` |
| date | `{{ ($values['made_on'] ?? null)?->translatedFormat('j F Y') }}` |
| image or file | an `<img>` at the `large` size with its alt text, inside a condition; or a link to the file |
| link | an `<a>` with its label, opening in a new tab when the link is set to, inside a condition |
| several files, entries or terms | a loop: an image, a linked title or a linked name for each |
| a select with several values | all of them with commas, or a loop |
| repeater | a loop with a `<div>` for each row, holding the row's fields |
| flexible content | the block loop: each block drawn by its own template in `blocks/`, or `partials/block` |
| menu | a `<ul>` with a linked item for each entry of the menu |

Built-in values are in the same lists: an entry's title, URL, dates, author
and terms; a file's URL at each size the site makes; a date's year or
machine-readable form.

An image, for instance, is written the way the CMS's own starter templates
write one:

```blade
@if ($cover = $values['cover'] ?? null)
    <img src="{{ $cover->url('large') }}" alt="{{ $cover->altIn() }}">
@endif
```

### Nothing written breaks on an empty field

A template is often shared by several collections, and a field is often
left empty. Whatever the editor writes draws without an error when the
value is empty, or when the entry has no such field at all: `?->` after
anything that may be nothing, `?? ''` where an array is read, a condition
around an image or a link. Both cases are drawn in the package's tests for
a field of every type.

### Names

A loop needs a name for its item. The editor takes it from the field's
label ("Photos" has a `$photo`, "Related articles" an `$article`), never
one that is in use at that place, and never one of Blade's or the CMS's own
(`$loop`, `$entry`, `$slot`).

## When the list does not have it

Anything can still be written by hand in the **Template** tab. That takes
the same [code permission](permissions.md).

A variable the editor can't follow (set by a view composer, or computed by
PHP it does not read) is listed as a plain value: it can be printed, and
nothing is offered from inside it.
