# Sunrice Visual Editor — Spec

A visual editor for the Blade templates of a [Sunrice CMS](https://github.com/littleboy130491/sunrice-cms) site, shipped as its own Composer package.

Today a developer writes the templates in a code editor while fields and content live in the CMS. This package lets template and layout work happen in the browser instead: open a page, see which templates draw it, click one, and change it visually, with the CMS's fields and variables at hand. The idea is borrowed from Sitereal and Elementor; the product is not a copy of either.

`SPEC.md` is the source of truth for behaviour. `EXECUTION_PLAN.md` is the source of truth for structure, naming and order.

## Principles

1. **The Blade file is the only copy of a template.** The editor reads the file, changes it and writes it back. Nothing about a template is stored in the database or in a second file. A developer can edit the same file in a code editor at any time.
2. **Lossless or code.** A file opens in the visual tree only if it can be read into a tree and written back byte for byte. What the tree can't hold is kept as code, exactly as written: one tag, or the whole file. The editor never writes a file it could not read back exactly.
3. **An edit changes only what it names.** Adding a class changes one opening tag. Indentation, comments and everything else in the file stay as they were.
4. **Nothing of the editor ships.** Saved templates contain no editor attributes, wrappers or runtime. Removing the package leaves a normal Sunrice site.
5. **Simplest thing that is good enough**, as in Sunrice itself. The package uses Sunrice's public API only.

## Confirmed behaviour

### Package and installation

- Composer package `sunrice/visual-editor`, requiring `sunrice/cms`. Installing is `composer require` plus publishing the package's admin script. It has no migrations and no tables.
- It adds one screen to the Sunrice admin, **Visual editor**, at `{admin}/visual-editor`, and one sidebar link.
- Its admin script is prebuilt and committed, and uses the admin's own React through `window.Sunrice`, like any Sunrice package.

### Where it runs and who can use it

- The editor writes files that run PHP on the server, and those files live in the project's git repository. It is therefore a development tool: it is meant for a local or staging copy of the site, followed by a commit and a deploy.
- `sunrice-visual-editor.enabled` turns it on. The default is on when `APP_ENV` is `local` and off otherwise; `SUNRICE_VISUAL_EDITOR=true` turns it on elsewhere.
- Who may open it, change things in it and write code in it is decided by its own permissions (see Permissions).
- When it is off, the package registers no routes and changes nothing about how pages render.

### Files it reads and writes

- Templates: `resources/views/sunrice/**/*.blade.php`, the same directory Sunrice resolves site templates from.
- Theme files: `public/sunrice-theme/**` (CSS in the first releases).
- Package fallback views (`sunrice::defaults.*`) are shown read-only, with **Copy to site**, which writes the copy into `resources/views/sunrice/` at the path Sunrice resolves first.
- It never writes anywhere else.

### Opening a template

- Opening a file parses it into a tree of elements, text, Blade blocks (`@if … @endif`, `@foreach`, `@section`, component tags) and single Blade constructs (echoes, `@include`, `@php`).
- Tags that don't nest cleanly are kept as they are written: a tag opened in one `@if` and closed in another, an element left open across `@else`, an end tag left out, the two halves of a layout split over a header file and a footer file. Each such tag is shown as code, with the reason, and stays where it is; so does anything that holds one. When it is an opening tag its attributes can still be edited. Everything around it is visual.
- A file is refused, and opens in the code panel only, when Blade itself can't be read into the tree: a Blade syntax error, or a block (`@if`, a component tag) whose end the tree can't find. The reason is shown.
- What is inserted or pasted has to nest cleanly on its own.
- Every node is one of:

  | Kind | Examples | Edited with |
  | --- | --- | --- |
  | Visual | elements, text, `@if`, `@foreach`, `@include`, `@section`, `<x-sunrice::entries>` | panels |
  | Field | `{{ $entry->title }}`, `{{ $block->get('heading') }}` | the field picker |
  | Label | `{{ __('sunrice::frontend.menu') }}` | a text input for the key |
  | Expression | `{{ $a ?? $b }}`, `{{ $date->format('j F Y') }}` | a one-line code input |
  | Mixed | `<li @class([...])>`, `<a @if (…) target="_blank" @endif>` | panels for the element; the directive in the tag as code |
  | Code | `@php … @endphp`, unknown directives | a code input; moved and deleted as one piece |

### Finding the templates of a page

- The editor shows a real page of the site in a frame. The address bar accepts any public path; drafts open for users who may view drafts.
- While a page renders for the editor, every Blade view that takes part is recorded: the page template, its layout, partials, block partials and component views.
- A **Templates on this page** panel lists them in render order, each marked site file, package file (read-only) or refused (code only).
- Hovering the page outlines the region each template drew, with its name. Clicking a region, or its name in the panel, opens that template. Opening the header from the homepage, changing it and going back is one click each way.
- Regions come from marks the editor adds while rendering for itself. They exist only in that render and are never written to a file or served to visitors.
- The frame has two modes: **Select**, where a click picks an element, and **Browse**, where links work and the panel follows to the new page.

### Editing visually

- With a template open, a **Layers** panel shows its tree and the frame highlights the selected node. Selecting works from either side.
- A **Settings** panel edits the selection: tag, id, classes, attributes, text; the expression of an echo; the condition of an `@if`; the source and item name of a `@foreach`; the view and data of an `@include`.
- **Insert** adds elements (section, container, heading, text, link, image, button, list), Blade structures (condition, loop, include, section), Sunrice components (`<x-sunrice::entries>`, `<x-sunrice::terms>`, `<x-sunrice::form>`) and fields. It goes before, inside or after the selection, and becomes the selection. What is inserted can be drawn as it is: a new condition is true, a new loop goes over nothing, a component is offered for each collection, taxonomy and form the site has, and a partial is offered with the variables it needs.
- Nodes can be moved by dragging in the Layers panel, duplicated, wrapped, unwrapped and deleted; the same from buttons and from the keyboard. Comments are nodes like any other. Text is edited where it is: deleting it empties it. Arms of a block (`@else`, `@empty`, `@case`) are not moved or deleted on their own; each one holds its own children, and what is added to a `@case` goes before the `@break` that ends it.
- Where an edit would put a directive directly against a letter or digit (`@else@endif`, `@elseText`), the editor adds one space so Blade still reads it.
- Pasting HTML inserts it as nodes.

### Fields and variables

- The **Fields** list shows what can be used at the selected position:
  - by file: `$entry` and `$collection` in entry templates, `$entries` in listing templates, `$term` and `$taxonomy` in term templates, `$block` in `blocks/{fieldset}`, and `$sunricePage`, `$locale`, globals and menus everywhere;
  - by position: the item of every enclosing `@foreach`, and `$component` inside a Sunrice component tag.
- Fields come from the blueprints of the collections that use the template (Sunrice's template map), the fieldset for a block, the taxonomy blueprint for a term, and the blueprint of each global set.
- Picking a field writes the Blade that suits its type:

  | Field type | Written as |
  | --- | --- |
  | text, textarea, number, select | `{{ $entry->get('handle') }}` |
  | rich text | `{!! $entry->get('handle') !!}` |
  | toggle | a condition: `@if ($entry->get('handle')) … @endif` |
  | date | `{{ $entry->get('handle')?->translatedFormat('j F Y') }}` |
  | link | `<a href="{{ … ['url'] }}">{{ … ['label'] }}</a>` with `target` when `new_tab` is set |
  | asset | `<img src="{{ …->url('large') }}" alt="{{ …->alt }}">`; a loop when multiple |
  | entries, terms | a loop over the items with title and link |
  | group | its child fields, picked one by one |
  | repeater | a loop over the rows; the row's fields become available inside |
  | flexible content | the block loop: `@includeFirst(['sunrice.blocks.'.$block->type, …])` |

- Every written field is guarded where its absence would break the page (`@if`, `?->`, `??`), as Sunrice's template rules require.
- A template shared by several collections (`sunrice.show`) lists fields per collection and marks those that not every collection has.
- Built-ins are listed with the fields: title, URL, dates, author, terms; globals as `sunrice_global('set')->field`; menus as a loop over `sunrice_menu('handle')`; interface text as `__('…')`.
- Any PHP expression can still be typed by hand.

### Saving

- Edits apply to the file at once; there is no separate publish step for templates.
- Each save states which version of the file it was made against. If the file changed on disk since (someone saved it in a code editor), the save is refused and the editor reloads the file.
- Before writing, the result must open as a tree again (or, from the code panel, compile) and pass the Blade syntax check: compiled with the site's Blade compiler and read by PHP's parser, as Sunrice checks templates written by AI agents. Otherwise nothing is written and the error is shown.
- An edit is sent as an operation: what to do, to which node, with what. Several can be sent together and are applied all or none.
- The previous version is kept in `storage/app/sunrice/template-backups/`, as for templates written by AI agents.
- Undo and redo cover the editing session, per file, for every kind of change. A file that was changed otherwise meanwhile has nothing to undo: an undo never writes over a change the editor has not seen.
- When a change compiles but the page can no longer be drawn with it, the editor says so and offers the undo.
- After a save the frame reloads the page and keeps the selection.

### Styling

- First releases: classes are edited on the element, and a **CSS** panel shows and edits the rule for the selected element's first class in the theme's CSS files. A rule that does not exist yet is added to `public/sunrice-theme/editor.css`, which the layout must link.
- CSS files are held to the same rule as Blade: a rule is changed in place and the rest of the file is untouched.
- Sites that build their CSS (Tailwind, Vite) get class editing only; the build keeps running in the developer's terminal.
- A style panel has controls for layout, size, spacing, text, background and border; each reads and writes one declaration of the rule for a class.
- The page can be shown at several widths side by side. One of the frames is edited; a style set while a width is edited is written under the `@media` condition for that width.

### Permissions

- Three permissions, registered with Sunrice and given to roles in its role editor: `visual-editor.open` (look), `visual-editor.edit` (change templates and stylesheets without writing code), `visual-editor.code` (write code). Each includes the ones before it. Super Admins have all three. None holds while the package is off.
- Code editing is the highest, since it writes PHP. Someone who may edit and not edit code can never make a template run PHP that it did not run before, except code the editor itself writes for a field or a choice. This holds for every way of changing a template, and is checked on what Laravel's Blade compiler makes of the result, not on what was asked for.
- Such a person may move, copy and take out code that is there, and may add any HTML.
- Their undo and redo write the template as it was within the last twelve hours; no older version.

### AI agents

- Later: MCP tools that read a template's tree and apply the same edit operations as the editor, so an agent makes small checked changes instead of rewriting a whole file.

## Out of scope for the first release

- Storing templates, drafts or history in the database.
- Editing templates outside `resources/views/sunrice/`, or non-Blade views.
- A design system, design tokens, motion, or a component library of its own.
- Editing content: entries, globals and menus stay in the Sunrice admin. The editor links to them.
- Rendering the page inside the editor without the server (every change is a real render).
- Multi-user editing of one file at the same time.
- Running on production by default.

## Open decisions

| # | Question | Assumed until decided |
| --- | --- | --- |
| 1 | Is staging allowed by default, or local only? | Local only; staging by `SUNRICE_VISUAL_EDITOR=true`. |
| 2 | Plain CSS or a Tailwind build as the main styling path? | Plain CSS in `public/sunrice-theme/`, as the starter templates use. |
| 3 | May Sunrice core gain the three small public APIs in `EXECUTION_PLAN.md` Phase 0? | Yes; until they merge the package wraps the internals in one adapter class. |
| 4 | Licence of this package. | Same as Sunrice (MIT); not stated in the repository yet. |
| 5 | Is the code panel's own small editor enough, or is CodeMirror wanted (search and replace, several cursors, folding)? | Enough. It can be swapped in without changing anything around it. |
| 6 | Should a draft's banner on the site carry an **Edit visually** link? Sunrice would need a hook for it. | No link; the editor is opened from the admin or by `?url=`. |
