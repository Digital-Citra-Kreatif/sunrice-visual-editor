# Changelog

Versions follow [semantic versioning](https://semver.org). Before 1.0, a
minor version may change behaviour; what changes is said here.

## 0.1.0 — not tagged yet

The first version: everything in the plan's seven phases. It is tagged once
it has been installed on a real project (see the end of
[EXECUTION_PLAN.md](EXECUTION_PLAN.md)).

### What it does

- **A screen in the Sunrice admin** that shows a page of the site in a
  frame and lists the Blade files that draw it. Pointing at the page
  outlines what each template drew; a click opens the template.
- **Templates as code**: the source of a template under the page, saved
  only when it compiles and only onto the version that was opened.
- **Templates as a tree**: layers, and settings for what is selected (tag,
  id, classes, attributes, text, echoes, conditions, loops, includes).
  Insert elements, Blade structures, Sunrice's components, the site's
  partials and pasted HTML. Move, duplicate, wrap, unwrap and delete, by
  dragging, by button or by key. Undo and redo.
- **Fields from lists**: what a template can read at each place (the entry
  with its collection's fields, a block's fields, loop items, what a
  partial is passed, global sets, menus), each written as the Blade that
  suits its type and is safe on an empty value. Conditions and loops built
  from choices; `<x-sunrice::entries>` set up from lists.
- **Styles**: the rules of the theme's CSS files for a class, changed in
  place, as rows of declarations or as controls. The page at several
  widths side by side, and a style written under the `@media` condition
  for the width being edited.
- **Permissions**: `visual-editor.open`, `visual-editor.edit`,
  `visual-editor.code`. Someone who may edit and not edit code can't make a
  template run PHP it did not run before.
- **Tools for AI agents**: `get_template_tree` and `edit_template`, for a
  Sunrice that takes MCP tools from packages.
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
- The tools for agents wait for
  [sunrice-cms#40](https://github.com/littleboy130491/sunrice-cms/pull/40).
