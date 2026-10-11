# How the visual editor works

## The file is the only copy

The editor has no model of your page in a database and no second file
beside the template. It opens the Blade file, changes it, and writes it
back. A developer can open the same file in a code editor a minute later
and find it as they would have written it, with one thing changed.

That rests on one rule, which the package calls **lossless or code**:

- Everything the editor understands, it can change without touching
  anything else. A class added to an element changes those bytes of the
  file and no others: the indentation, the comments and the odd spacing two
  lines down stay.
- Everything it does not understand, it shows as code and leaves alone. It
  never guesses.

## From file to tree

Opening a template reads it into a tree:

| Node | What it is |
| --- | --- |
| element | an HTML tag with what is inside it |
| text | text between tags |
| block | a paired Blade construct: `@if … @endif`, `@foreach … @endforeach`, a component tag with content |
| branch | one arm of a block: the `@if` part, the `@else` part |
| atom | a single construct: `{{ $entry->title }}`, `@include(…)`, `@php(…)`, a self-closing component |

Every node keeps the exact text it was read from. Printing an unchanged
tree gives back the file byte for byte, and this is checked every time a
file is opened: a file that would not print back identically is not edited
as a tree at all.

Blade constructs are found with
[stillat/blade-parser](https://github.com/Stillat/blade-parser); the HTML
around them is read by the package's own small lexer, which records where
everything is.

### Tags that don't nest

Hand-written Blade often opens a tag in one place and closes it in another:
a `<div>` opened inside an `@if` and closed after the `@endif`, or a layout
split over a header and a footer file. The tree keeps each such tag as
written, marked as "stray". It is shown as code and stays where it is;
everything around it is edited as usual.

### Files that can only be edited as code

A file is refused as a tree when Blade itself can't be read into one: a
syntax error, or a block whose end sits inside a tag. The editor says why,
and the file opens in the code panel.

## A change is an operation

Everything the screen does to a template is sent as an operation: what to
do, to which node, with what.

```json
{"type": "addClass", "node": "412", "class": "hero"}
{"type": "move", "node": "412", "target": "b96", "position": "inside"}
```

A node's id is the byte it starts at in the version of the file that was
read. Every request names that version (a hash of the file). If the file
has changed on disk since, because someone saved it in a code editor, the
request is refused and the editor reads the file again. It never writes
over a change it has not seen.

Several operations can be sent together. They are applied all or none.

The same operations are what AI agents send through the
[`edit_template` tool](agents.md).

## Before anything is written

In order:

1. The operations are applied to the tree.
2. The result must open as a tree again, with no more stray tags than
   before.
3. It must compile: Blade to PHP with the site's own Blade compiler, and
   the PHP read by PHP's parser.
4. For someone who may not edit code, it must run no PHP that it did not
   run before, except code the editor writes itself. See
   [Permissions](permissions.md).
5. The file must still be the version the change was made for.

If any of these fails, nothing is written and the editor shows the reason.

Then the previous version is copied to
`storage/app/sunrice/template-backups/`, the new file is written beside the
old one and renamed over it (so a page drawn at that moment sees the old
file or the new one, never half of each), Laravel's compiled copy is
dropped, and a line goes into Sunrice's activity log.

## What a change can still get wrong

A template that compiles can still fail when the page is drawn: a variable
that is not there on this page, a collection that does not exist. No check
before saving can know that. After each change the editor draws the page
again; if the server now answers with an error where it did not before, it
says so and offers the undo.

## How the page knows its templates

The page in the editor's frame is a real page of the site, drawn by the
site, with `?sve=1` in its address. For such a request, and only for
someone who may use the editor, the package swaps in its own view engine:

- Each template is compiled from a copy in which every element carries a
  mark, `data-sve="<file>:<node>"`: which template it came from and which
  node of it. The copies live in the compiled-view cache; your template
  files are never touched.
- A list of the templates that took part (the manifest) is added to the
  page.
- A small script (the bridge) is added. It tells the editor what the
  pointer is over and what was clicked, and measures the elements the
  editor asks about.

Everything drawn over the page, the outlines and their labels, is drawn by
the editor above the frame. Nothing is drawn into your page. A visitor
never sees any of this: without `?sve=1` and a signed-in editor, the
package does nothing at all to a request.

After a change, the page is drawn again in a second, hidden frame, scrolled
to where the first one is, and the two change places. That is why the page
does not jump or go blank.

## Undo

Undo and redo are kept per file for as long as the editor is open. A step
is the whole file as it was, so an undo is exact whatever kind of change
made the difference. If the file is changed some other way in between,
its history is dropped rather than written over something the editor has
not seen.

## Stylesheets

CSS files in `public/sunrice-theme/` are held to the same rule as
templates: a rule is changed in place and the rest of the file is
untouched. The package reads a stylesheet only for where its rules and
declarations are, and cuts into the text there. After every change it reads
the file again and refuses the change unless the file now says what it said
before, plus the one thing that was meant. See [Styles](styles.md).

## What was done to test this

Two test runs sit in `spike/` and run on every pull request:

- **Templates** (`composer spike`): every Sunrice starter template, every
  Sunrice package view, Laravel's own Blade views and a set of edge cases
  are opened and printed back, then each kind of edit is made on every node
  that can take it. About 19,000 edits; each must change exactly the bytes
  it should and leave a file that opens and compiles.
- **Stylesheets** (`composer spike:css`): the same for CSS, on the starter
  theme and on Bootstrap, Bulma, Pico and normalize.css. About 7,500
  changes. A real browser is then asked whether it reads the same rules and
  sees the same changes.

The numbers are in [`spike/RESULTS.md`](../spike/RESULTS.md) and
[`spike/CSS_RESULTS.md`](../spike/CSS_RESULTS.md).
