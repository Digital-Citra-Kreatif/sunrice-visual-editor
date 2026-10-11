# Fields and variables

A Sunrice template reads its content from variables: `$entry` on an entry's
page, `$block` in a block's partial, the site's global sets and menus
everywhere. The editor knows which of them a template has at each place,
what fields each one holds, and how each is written in Blade. You choose
from a list; it writes the code.

## Where the lists are

- **Insert → Fields** lists what can be read where the next thing will go.
  A click inserts the value in its usual form; **As…** on its row lists the
  other forms.
- **Use a field…** in Settings, under a text, under what an echo prints and
  under each attribute's value, fills that one thing from a field.
- On a condition, **Ask about a field…**; on a loop, **Loop over a
  field…**.

A value that came from a field says so: an echo of
`$entry->get('cover')?->alt` is shown as **$entry › Cover › Alt text**.

## In Code mode

Beside the code there is a **Fields** panel with the same list, for where
the cursor is. It reads the code as you have typed it, saved or not: a
variable set on the line above is in the list a moment after you type the
line.

- A click on a field writes it at the cursor, the same Blade as in Visual
  mode, indented as the line is.
- While you type, the editor offers what a value has: after `$entry->` or
  `$row['` the fields of that value, after `{{ ` everything there that can
  be printed, after `$` the variables. Choosing one writes it whole, with
  its `?->` and `?? ''`.
- While the code does not read as a whole (a tag half typed), the list is
  what the template has anywhere in it, and says so.

This needs the [code permission](permissions.md).

## What is in the list

The list is for one place in one template. It has three parts.

**At this place**

- the item of every loop around the place (`$article` inside
  `@foreach ($entry->get('related') as $article)`), and its key;
- what the template sets above the place: `@php($image = …)`, or a
  condition that sets what it asks about
  (`@if ($cover = $entry->get('cover'))`);
- `$component`, inside `<x-sunrice::entries>`, `<x-sunrice::terms>` and
  `<x-sunrice::entry-filter>`.

**This template**

| Template | Has |
| --- | --- |
| an entry's page (`show`, `articles/show`) | `$entry`, `$collection` |
| a listing page (`index`) | `$entries`, `$collection` |
| a term's page (`taxonomies/show`) | `$term`, `$taxonomy`, `$entries` |
| the search page | `$query`, `$results` |
| a block (`blocks/hero`) | `$block`, with the fields of the fieldset the file is named after |
| a partial | what the templates that include it pass to it |

For a partial, the editor reads every `@include` of it in the site's
templates. A card included once with an article and once with a page is
offered the fields of both, and a field only one of them has says so
("Not in Pages").

**The site**

`$sunricePage`, `$locale`, every global set (`sunrice_global('site')`) and
every menu (`sunrice_menu('main')`).

The fields come from the site's blueprints as they are now. Add a field to
a collection in the Sunrice admin and it is in the list the next time the
list is opened.

## What a click writes

| Value | Written as |
| --- | --- |
| text, number, select | `{{ $entry->get('summary') }}` |
| rich text | `{!! $entry->get('body') !!}` |
| yes or no | `@if ($entry->get('featured')) … @endif` |
| date | `{{ $entry->get('made_on')?->translatedFormat('j F Y') }}` |
| image or file | an `<img>` at the `large` size with its alt text, inside a condition; or a link to the file |
| link | an `<a>` with its label, opening in a new tab when the link is set to, inside a condition |
| several files, entries or terms | a loop: an image, a linked title or a linked name for each |
| a select with several values | all of them with commas, or a loop |
| repeater | a loop with a `<div>` for each row, holding the row's fields |
| flexible content | the block loop: each block drawn by its own template in `blocks/` |
| menu | a `<ul>` with a linked item for each entry of the menu |

Built-in values are in the same lists: an entry's title, URL, dates, author
and terms; a file's URL at each size the site makes; a date's year or
machine-readable form.

An image, for instance, is written the way Sunrice's own starter templates
write one:

```blade
@if ($cover = $entry->get('cover'))
    <img src="{{ $cover->url('large') }}" alt="{{ $cover->alt }}">
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
one that is in use at that place, and never one of Blade's or Sunrice's own
(`$loop`, `$entry`, `$slot`).

## Conditions

Choose a value, then what to ask of it:

| Value | Can be asked |
| --- | --- |
| text, rich text | is set, is not set, is, is not, contains |
| number | is set, is not set, is, is not, is more than, is less than |
| yes or no | is on, is off |
| date | is set, is not set, is in the past, is in the future |
| list | has any, has none; a select with several values also: has |
| anything else | is set, is not set |

What is written is plain PHP, such as `$entry->get('price') > 100`. A
condition the editor wrote is read back into the choices when the template
is opened again. One written by hand stays as code in the field below and
is never rewritten.

## Entries from the database

`<x-sunrice::entries>` is set from lists: the collection, the order (as the
collection is set, newest or oldest first, by title, by any of its fields),
how many, in pages or not, and filters ("only those where *Featured* is
on"). Inside the tag, `$component->entries` is in the Fields list, and a
loop over it has the collection's fields.

## When the list does not have it

Anything can still be written by hand: in an echo's expression field, in a
condition's code field, or in the code panel. That takes the
[code permission](permissions.md).

A variable the editor can't follow (set by a view composer, or computed by
PHP it does not read) is listed as a plain value: it can be printed, and
nothing is offered from inside it.
