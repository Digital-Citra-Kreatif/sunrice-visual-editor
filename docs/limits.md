# Limits

What the editor does not do, and where it stops. Version 0.2, for Sunrice
0.2 (the panel, `sunrice/admin`, and the CMS, `sunrice/cms`).

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

- Only Blade files in `resources/views/cms/`, the CMS's templates. Views
  the CMS also looks for under `resources/views/pages/`, other views of
  the application, and views of other packages are not listed.
- **A file that can't be read into a tree opens as code only.** That is a
  Blade syntax error, or a block whose end the tree can't find. None of
  the CMS's starter templates or own views is such a file, but a
  hand-written one can be.
- **A tag that does not nest is shown as code.** A `<div>` opened in one
  `@if` and closed in another, or a layout split over a header and a footer
  file, stays where it is and can't be moved or deleted from the tree. The
  attributes of an opening tag can still be changed.
- `@php … @endphp` and directives the editor does not know are one piece of
  code: moved, copied and deleted whole, changed only as code.
- **On the screen, a template is changed in two ways only**: in its code,
  and by moving, duplicating and deleting rows of the tree. Classes,
  attributes, text and inserts are written in the code. (The API and the
  [tools for AI agents](agents.md) take those as operations.)
- **The CMS's own views are read-only.** Its page views (`entry`,
  `listing`, `term`, `taxonomy-index`, `search`) are used while the site
  has no template of its own for that page, and **Copy to site** makes one
  (`collections/show`, `collections/index`, `taxonomies/show`,
  `taxonomies/index`, `search`). Its component views (`components/form`,
  `components/search`, …), `draft-banner` and its comment views are drawn
  by the CMS itself, which never looks for them among the site's
  templates: they can't be changed in the editor. Style what they draw
  with the [custom CSS](styles.md#custom-css), or change the template
  around them. The code's banner says so.
- The CMS's page views are whole pages of their own, with no
  `@stack('head')`: while the site draws a page with one of them, the
  custom CSS and JS are not on it. A copy made with **Copy to site** can
  be given the site's layout.

## What a save can't know

A template that compiles can still fail when a page is drawn: a variable
that is not there on some page, a collection that was deleted. The editor
draws the open page again after each change and says when the server
answers with an error, with the undo beside it. It does not draw every
other page that uses the template.

## Undo

- Undo and redo last as long as the editor is open. Closing the tab ends
  them; the backups in `storage/app/sunrice-cms/template-backups/` and git are
  what is left.
- They are per file. Changing a file some other way meanwhile drops its
  history.
- For someone without the code permission they reach back twelve hours
  ([Permissions](permissions.md)).

## Styles

- **The screen writes one stylesheet**, the
  [custom CSS](styles.md#custom-css), as text. There are no controls for
  declarations, no rules found for a class, and no styles written for one
  device. The theme's other CSS files are changed in the code editor of
  your choice, or through the editor's API.
- The custom CSS and JS are added through the layout's `@stack('head')`.
  A layout without that stack, or another named in `custom_css.stack` or
  `custom_js.stack`, gets neither.
- **Plain CSS files only**, in `public/cms/`, up to 2 MB each. A
  site whose CSS is built (Tailwind through Vite) is styled with classes:
  with a dev server running they show at once, otherwise after a build
  ([Styles](styles.md#sites-that-build-their-css)). An opt-in preview
  shows a new class in the editor before a build; it loads no Tailwind
  plugins. The editor does not start a dev server.
- **The Build button needs Node on the server**, and a command set for it.
  It says **Build needed** only for changes made since the editor was
  opened, and for templates: it does not compare the built files with the
  templates. The request waits for the build to end.
  ([Styles](styles.md#building-from-the-editor))
- No design tokens, no colour palette read from the site's custom
  properties, no fonts manager.
- Devices are set up by hand in the config (`widths`). They are not read
  from the site's CSS.
- No JavaScript files are edited but the custom JS.

## The screen

- Dragging layers uses the browser's own drag and drop, which does not work
  by touch. The keys do the same.
- Things are dragged in the Layers tree, not on the page itself.
- A tab's undo and its unsaved text last as long as the editor is open:
  closing the browser tab asks first, and then they are gone.
- The code editor has search and replace, undo of its own and several
  cursors, and offers the CMS's fields while a variable is typed. It does
  not fold code, complete HTML or PHP, or search across files. Its script
  is fetched when code is first shown; when that fails, the code is in a
  plain text field, which still saves.
- What is folded and how wide the code is are kept in that browser, not
  for the person.
- There is no button on the CMS's entry pages: a plugin's script runs
  only on its own pages. The panel's command palette finds a page by its
  title and opens the editor on it; a new entry with no published page is
  not found.
- The editor is for a desktop browser. It has been checked in Chromium;
  Firefox and Safari have not been tested.
- The editor's own screen is in English only.

## Content

- The **Content** tab opens the CMS's own admin page for the entry the
  page shows, in a new browser tab: the panel can't be shown in a frame.
  It edits that one entry; global sets and menus are edited in the admin
  as usual.
- A published entry keeps a saved change as a draft until it is
  published, as the CMS does anywhere: the page shows it after
  **Publish**.
- A page that is no single entry (a listing, the search page, an error
  page) has no content in the tab.

## Permissions

- `edit` without `code` keeps new PHP out. It does not keep out HTML or
  scripts, and the check has not had an independent security review. See
  [Permissions](permissions.md#what-you-should-know-before-giving-someone-edit).
- Permissions are per person, not per template or per stylesheet.
- `visual-editor.build` lets someone start the site's build command, which
  runs as the web server's user. They can't change what the command is
  ([Permissions](permissions.md#building)).

## AI agents

- Changing a template needs the CMS's `sunrice-cms.ai.templates` on, as
  for the CMS's own template tools.
- There is no tool for single CSS changes.

## Sunrice itself

- Built against Sunrice 0.2 (`Digital-Citra-Kreatif/sunrice`, October
  2026). The package uses the panel's and the CMS's `@api` classes, with
  one exception: where the site's templates are and which of them draws
  each page come from the CMS's `Sunrice\Cms\Support\Templates`, marked
  `@internal`. All of it is read in one file,
  `src/Support/SunriceBridge.php`; a change inside the CMS can break that
  file, and `tests/Unit/Support/SunriceBridgeTest.php` is there to say so
  first.
- It has been run on the starter site and on a demo site in this
  repository. **It has not yet been installed on a real client project.**
  Expect that to turn things up.
