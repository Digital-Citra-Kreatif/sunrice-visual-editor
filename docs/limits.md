# Limits

What the editor does not do, and where it stops. Version 0.1.

## Where it runs

- **It is not for production.** It writes files that belong in git. Use it
  on a local or staging copy, commit, deploy. On a server where a deploy
  replaces the code, anything changed there with the editor is gone at the
  next deploy.
- The web server's user has to be able to write the templates and the theme
  files.
- One person per file at a time. Two people changing the same template do
  not overwrite each other, but the second save is refused and that person
  reloads; nothing is merged.

## Templates

- Only Blade files in `resources/views/sunrice/`. Other views of the
  application, and views of other packages, are not listed.
- **A file that can't be read into a tree opens as code only.** That is a
  Blade syntax error, or a block whose end the tree can't find. None of
  Sunrice's starter templates or own views is such a file, but a
  hand-written one can be.
- **A tag that does not nest is shown as code.** A `<div>` opened in one
  `@if` and closed in another, or a layout split over a header and a footer
  file, stays where it is and can't be moved or deleted from the tree. The
  attributes of an opening tag can still be changed.
- `@php … @endphp` and directives the editor does not know are one piece of
  code: moved, copied and deleted whole, changed only as code.
- A directive inside a tag (`<li @class([…])>`) is shown as code in that
  element's settings. The rest of the element is edited as usual.
- `<x-sunrice::entry-filter>` is not in the Insert list. Its filters are an
  array written by hand.
- Classes that Blade writes (`class="{{ $x }}"`, `@class`) can't be styled
  from the Style and CSS tabs, which need a class written out.

## What a save can't know

A template that compiles can still fail when a page is drawn: a variable
that is not there on some page, a collection that was deleted. The editor
draws the open page again after each change and says when the server
answers with an error, with the undo beside it. It does not draw every
other page that uses the template.

## Undo

- Undo and redo last as long as the editor is open. Closing the tab ends
  them; the backups in `storage/app/sunrice/template-backups/` and git are
  what is left.
- They are per file. Changing a file some other way meanwhile drops its
  history.
- For someone without the code permission they reach back twelve hours
  ([Permissions](permissions.md)).

## Styles

- **Plain CSS files only**, in `public/sunrice-theme/`, up to 2 MB each. A
  site whose CSS is built (Tailwind, Vite, Sass) gets class editing and
  nothing else.
- Styles are written for a class. There is no styling by id or tag from the
  Style tab, and no inline `style` attribute written.
- The Style tab writes the class's own rule. Rules that only mention the
  class (`.card:hover`, `.card h2`) are in the CSS tab, to change as CSS;
  there are no controls for hover, focus or other states.
- A new rule goes to the end of the editor's own stylesheet, or for a width
  into the file the class's rule is in. It can't be placed elsewhere from
  the screen.
- No design tokens, no colour palette read from the site's custom
  properties, no fonts manager. A custom property can be typed as a value
  (`var(--accent)`).
- Widths are set up by hand in the config. They are not read from the
  site's CSS, though the `@media` conditions the site already uses are
  found and written under.
- No JavaScript files are edited.

## The screen

- Dragging layers uses the browser's own drag and drop, which does not work
  by touch. The buttons and keys do the same.
- Things are dragged in the Layers panel, not on the page itself.
- The code panel is a plain editor with highlighting: no search and
  replace, no folding, no completion.
- The editor is for a desktop browser. It has been checked in Chromium;
  Firefox and Safari have not been tested.
- The editor's own screen is in English only.

## Content

Entries, global sets and menus are edited in the Sunrice admin, not here.
The editor shows the page with its real content and changes how it is
drawn.

## Permissions

- `edit` without `code` keeps new PHP out. It does not keep out HTML or
  scripts, and the check has not had an independent security review. See
  [Permissions](permissions.md#what-you-should-know-before-giving-someone-edit).
- Permissions are per person, not per template or per stylesheet.

## AI agents

- The tools reach agents only on a Sunrice that takes tools from packages
  ([sunrice-cms#40](https://github.com/littleboy130491/sunrice-cms/pull/40)).
- There is no tool for single CSS changes.

## Sunrice itself

- Built against Sunrice's `main` branch as of October 2026; Sunrice has no
  tagged release yet. Sunrice has no public API yet for two things the
  editor needs: which template each collection and taxonomy uses
  ([sunrice-cms#36](https://github.com/littleboy130491/sunrice-cms/issues/36)),
  and a blueprint's fields as data
  ([#37](https://github.com/littleboy130491/sunrice-cms/issues/37)). The
  package reads both from Sunrice's internals, in one file,
  `src/Support/SunriceBridge.php`. A change inside Sunrice can break that
  file; `tests/Unit/Support/SunriceBridgeTest.php` is there to say so
  first.
- `src/Support/RegisteredPermissions.php` works around a fault in Sunrice's
  permission sync, and goes when
  [sunrice-cms#39](https://github.com/littleboy130491/sunrice-cms/pull/39)
  is released.
- It has been run on the starter site and on a demo site in this
  repository. **It has not yet been installed on a real client project.**
  Expect that to turn things up.
