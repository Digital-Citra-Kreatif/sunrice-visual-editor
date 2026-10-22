# Changelog

Versions follow [semantic versioning](https://semver.org). Before 1.0, a
minor version may change behaviour; what changes is said here.

## Unreleased

### On the new Sunrice: a plugin of the panel, for the CMS

Sunrice is now two packages, the panel (`sunrice/admin`) and the CMS
(`sunrice/cms`) as its plugin, and the editor is rebuilt as another plugin
of the panel that requires the CMS. It uses only their public (`@api`) API.

**Breaking changes**, with what to do:

- **Add the plugin to the panel**, after the CMS:
  `$panel->plugin(CmsPlugin::make())->plugin(VisualEditorPlugin::make())`.
  The service provider no longer adds anything to the admin by itself.
- **Requires** `sunrice/admin` and `sunrice/cms` (the monorepo), PHP 8.4 and
  Laravel 13. Neither Sunrice package is on Packagist yet: Composer takes
  them from a checkout of the monorepo (a path repository, `../sunrice`).
- **Templates are the CMS's**: `resources/views/cms` (the views `cms.…`),
  not `resources/views/sunrice`. The CMS's own views (`sunrice-cms::entry`,
  `listing`, `term`, `search`, `comment-unsubscribe`) are the read-only
  fallbacks, and **Copy to site** puts a copy at `cms/entry.blade.php` and
  so on. Which view each page uses follows the CMS's rules (an entry's own
  template, the collection's, `cms.{handle}`, `cms.entry`).
- **Theme files are in `public/cms/`** (beside the starter's `site.css`),
  not `public/sunrice-theme/`: the custom CSS and JS, and `editor.css`. Move
  yours there.
- **The custom JS is pushed onto `@stack('head')`**, deferred, as the CMS's
  starter layout has no `@stack('scripts')` (`custom_js.stack`).
- **The editor is at `/admin/visual-editor`** (the panel's path), in the
  sidebar under *Structure*. Its page is a plugin bundle of the panel
  (runtime 3), loaded only on the editor's own page.
- **Edit with Visual Editor** is in the ⋮ menu of an entry's page, through
  the CMS's new `Cms::entryLinksUsing()`. The admin script that added a
  button to every entry page is gone.
- **AI tools** are on the panel's MCP server as
  `visual_editor_get_template_tree` and `visual_editor_edit_template`. The
  new setting `agents` (`SUNRICE_VISUAL_EDITOR_AGENTS`) replaces Sunrice's
  `sunrice.mcp.templates` for them.
- **Fields and inserts are the CMS's**: a page's fields are `$values['…']`,
  an entry in a list has them from `$item->values()['…']`, global sets and
  menus are `cms_global('…')['…']` and `cms_menu('…')`, the page is
  `cms_page()`, files are the panel's media (`->url('large')`,
  `->altIn()`), terms read `->title()`, and the components are
  `<x-sunrice-cms::entries>`, `terms`, `form`, `search`, `seo`, `code`
  (`place="head"`) and `comments`. Blocks are drawn by `cms/blocks/{type}`,
  else the starter's `cms.partials.block`.
- **Backups** go to `storage/app/sunrice-cms/template-backups/`, beside the
  CMS's copies of templates written by agents. Saves are in the panel's
  activity log and clear the CMS's page cache; pages drawn for the editor
  are never kept in it.
- **Permissions** keep their names and are registered with the panel even
  where the editor is off, so roles keep them; `visual-editor.code` and
  `visual-editor.build` are privileged (security alerts). The workaround for
  the old Sunrice's permission sync (`RegisteredPermissions`) is gone.
- The round-trip report covers the CMS's starter templates and own views
  too.

### A simpler screen

- **Three parts**: the tree on the left (templates on the page, and the
  open template's layers), the page in the middle, the code on the right.
  A click on a row selects it, outlines it and scrolls the page to it,
  and puts the code's cursor there; a click on the page does the same the
  other way. Saving the code (Ctrl+S) draws the page again.
- **Tabs on the right**: Template (the code), **Fields** (what the template
  can read at the cursor; a click writes it there), **Content** (Sunrice's
  own page for the entry the page shows, beside it; a save there draws the
  page again), **Custom CSS** and **Custom JS**.
- **Custom CSS**: `public/sunrice-theme/custom.css`, written in the editor
  and linked from every page of the site through the layout's
  `@stack('head')`, with no template changed for it. New settings
  `custom_css.file`, `custom_css.inject` and `custom_css.stack`. The link
  is added whether or not the editor is on.
- **Custom JS**: `public/sunrice-theme/custom.js`, loaded (deferred) on
  every page through the layout's `@stack('scripts')`. Writing it takes
  `visual-editor.code`. Settings `custom_js.file`, `custom_js.inject` and
  `custom_js.stack`.
- **Devices**: Fit, Desktop, Tablet, Mobile (the `widths` setting) or all
  of them side by side, from the top bar.
- **Sides fold to a strip** at the edge, named, and open again from it.
- **Edit with Visual Editor** on every entry's page in the admin, for
  people who may open the editor.
- Sunrice's own views say why they are read-only: a fallback offers Copy
  to site, a view Sunrice never looks for in the site says it can't be
  changed here.

### Taken off the screen

- The Settings, Insert, Style and CSS panels, Visual and Code modes, and
  sides flown out over the page. The API behind them, and the tools for
  AI agents, are unchanged.

### Fixed

- A change made from the tree, an undo, or a tab closed with unsaved text
  could leave the code editor's own undo able to take the file's new text
  back, leaving the code saying what the file no longer did, and later
  clicks on the page putting the cursor at the end of the file.

## 0.1.0 — not tagged yet

The first version: everything in the plan's seven phases. It is tagged once
it has been installed on a real project (see the end of
[EXECUTION_PLAN.md](EXECUTION_PLAN.md)).

### What it does

- **A screen in the Sunrice admin** that shows a page of the site in a
  frame and lists the Blade files that draw it. Pointing at the page
  outlines what each template drew; a click opens the template.
- **Templates as code**: the source of a template in a code editor, under
  the page or, in Code mode, beside it, with templates as tabs that keep
  unsaved text, a click on the page putting the cursor on that element, and
  the fields at the cursor listed beside it and offered as they are typed.
  Saved only when it compiles and only onto the version that was opened.
- **Templates as a tree**: layers, and settings for what is selected (tag,
  id, classes, attributes, text, echoes, conditions, loops, includes).
  Insert elements, Blade structures, Sunrice's components, the site's
  partials and pasted HTML. Move, duplicate, wrap, unwrap and delete, by
  dragging, by button or by key. Undo and redo.
- **Room for the page**: every panel and every titled group in one folds to
  its title; the two sides and the code hide from the top bar, and a
  hidden side flies out over the page when it is needed; the editor
  opens the way it was left.
- **Fields from lists**: what a template can read at each place (the entry
  with its collection's fields, a block's fields, loop items, what a
  partial is passed, global sets, menus), each written as the Blade that
  suits its type and is safe on an empty value. Conditions and loops built
  from choices; `<x-sunrice::entries>` set up from lists.
- **Styles**: the rules of the theme's CSS files for a class, changed in
  place, as rows of declarations or as controls. The page at several
  widths side by side, and a style written under the `@media` condition
  for the width being edited.
- **Sites that build their CSS** (Tailwind through Vite): a class added in
  the editor shows at once while `npm run dev` runs. Without a dev server,
  an opt-in preview shows it in the editor before a build, and a **Build**
  button runs the site's own build command, when one is set, and shows
  what it printed.
- **Permissions**: `visual-editor.open`, `visual-editor.edit`,
  `visual-editor.code`. Someone who may edit and not edit code can't make a
  template run PHP it did not run before. Apart from those,
  `visual-editor.build` for running the build command.
- **Tools for AI agents**: `get_template_tree` and `edit_template`, on
  Sunrice's MCP server (Sunrice from 11 October 2026 or later).
- **Copy to site** for Sunrice's own views, which are otherwise read-only.

### How it keeps files safe

- A template or stylesheet is the only copy. A change touches the bytes it
  names and no others; a file that can't be read back exactly is not
  edited as a tree.
- Nothing is written unless the result opens, compiles, and is for the
  version of the file that was read.
- The previous version of every file written is kept in
  `storage/app/sunrice/template-backups/`, and every save is in the
  activity log.
- Off unless `APP_ENV` is `local` or `SUNRICE_VISUAL_EDITOR=true`.

### Known limits

In [docs/limits.md](docs/limits.md). The ones to know first:

- Not installed on a real client project yet.
- Built against Sunrice's `main` branch; reads two things from Sunrice's
  internals until Sunrice has a public API for them.
- Plain CSS files only. A site that builds its CSS gets class editing.
- The line between `edit` and `code` has had no independent security
  review.
