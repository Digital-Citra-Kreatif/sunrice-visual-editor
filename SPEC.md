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
- When it is off, the package registers no routes and changes nothing about how pages render, except the link to the site's custom CSS (see Styling).

### Files it reads and writes

- Templates: `resources/views/sunrice/**/*.blade.php`, the same directory Sunrice resolves site templates from.
- Theme files: `public/sunrice-theme/**` (CSS in the first releases), the site's custom CSS among them.
- Package fallback views (`sunrice::defaults.*`) are shown read-only, with **Copy to site**, which writes the copy into `resources/views/sunrice/` at the path Sunrice resolves first.
- It never writes anywhere else.

### Opening a template

- Opening a file parses it into a tree of elements, text, Blade blocks (`@if … @endif`, `@foreach`, `@section`, component tags) and single Blade constructs (echoes, `@include`, `@php`).
- Tags that don't nest cleanly are kept as they are written: a tag opened in one `@if` and closed in another, an element left open across `@else`, an end tag left out, the two halves of a layout split over a header file and a footer file. Each such tag is shown as code, with the reason, and stays where it is; so does anything that holds one. When it is an opening tag its attributes can still be edited. Everything around it is visual.
- A file is refused, and opens in the code panel only, when Blade itself can't be read into the tree: a Blade syntax error, or a block (`@if`, a component tag) whose end the tree can't find. The reason is shown.
- What is inserted or pasted has to nest cleanly on its own.
- Every node is one of (the last column is what the operations of the API offer for it; on the screen every kind is edited in the code):

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

### The screen

The editor is three parts side by side: the tree on the left, the page in the middle, the code on the right (decided October 2026, in place of the panels of the first design: Settings, Insert, Style and CSS).

- **Left, the tree.** *Templates on this page*, and **Layers**: the open template as a tree. Clicking a row selects it, outlines it on the page, scrolls the page to it (to the middle of the frame where the page can scroll that far) and puts the code's cursor on it. Selecting works from either side: a click on the page selects the row and the code.
- Rows are moved by dragging, and by keys on a row: Alt+Up and Alt+Down move, Ctrl+D duplicates, Delete deletes. Comments are nodes like any other. Arms of a block (`@else`, `@empty`, `@case`) are not moved or deleted on their own; what is added to a `@case` goes before the `@break` that ends it. Where an edit would put a directive directly against a letter or digit (`@else@endif`), the editor adds one space so Blade still reads it.
- **Middle, the page**, at the width of the room or at one of the configured devices (Desktop, Tablet, Mobile by default), or at all of them side by side, drawn at one scale. Side by side, one frame takes the clicks and the others show the same page; every frame is drawn again after a change.
- **Right, in tabs:** the open template's code (**Template**), what it can read at the cursor (**Fields**), the content of the entry the page shows (**Content**), and the site's **Custom CSS**.

### Room for the page

- Each side folds to a narrow strip at its edge, named, by a button on the side or in the top bar; a click on the strip opens it again. A folded side gives its room to the page.
- The line between the page and the code is dragged to share the room between them.
- Every panel on the left (Templates on this page, Layers) folds to its title by a click on the title.
- The editor opens the way it was left: what is folded and how wide the code is are remembered in the browser.

### Editing as code

- The open template is text in a code editor beside the page: line numbers, colours for Blade, HTML and PHP, undo of its own, search and replace, several cursors.
- Templates open as tabs. Each keeps the text that was typed and not saved, so the header can be looked at halfway through a change to the page's template. A tab with unsaved text says so, and leaving the editor with one asks first.
- A click on the page opens the template that drew what was clicked and puts the cursor, and the keyboard, on that element. The element the cursor is in is outlined on the page and selected in the tree.
- When the file changes otherwise (from the tree, an undo, read again) with nothing typed, the code shows it as it is now; its own undo does not take that change back.
- After `$entry->` and the like, the editor offers what that value has.
- Saving is one step for a tab (Ctrl+S): the text is checked as any save is (it compiles, the file is still the version it was read as), written, and the page drawn again. Nothing is saved while typing.
- Writing code is for people who may edit code (`visual-editor.code`). Others can read the code and not change it.
- The code editor is CodeMirror, in a script of its own that is fetched the first time the editor opens: the rest of the admin does not carry it.
- It does not try to be an IDE: no search across files, no git, no terminal.

### Content beside the page

- The **Content** tab shows Sunrice's own admin page for the entry the page in the frame is of, in a frame: its fields, validation, drafts, revisions and publishing are Sunrice's. A save there draws the page again. A published entry keeps what is saved as a draft until it is published, as in the admin.
- The editor learns which entry it is while the page renders for it: the entry its page template was given. A page of no single entry (a listing, a search, an error) says so.

### From the admin

- Every entry's page in the Sunrice admin has an **Edit with Visual Editor** button beside its own buttons, for people who may open the editor. It opens the editor on the entry's public page, in the language being edited. A new entry, which has no page yet, has none. Sunrice has no place for a package's button there: the package's admin script adds it, and where the page is not as expected it floats in a corner.

### Fields and variables

- The **Fields** tab lists what can be read where the code's cursor is, in text that is not saved yet too, and a click writes it there:
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
- The server API keeps every operation of the first design (attributes, text, conditions, loops, includes, inserting elements, components and fields), for AI agents and for what a later screen may offer; each is checked as any change is.

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

- Classes are edited in the template's code. CSS files are held to the same rule as Blade: what the editor writes changes only what it names.
- **Custom CSS**: a stylesheet of the site's own, `public/sunrice-theme/custom.css` (setting `custom_css.file`), written as one text in the editor and saved with Ctrl+S. Every page of the site links it: the package pushes a `<link>` to it onto the layout's `@stack('head')` while a site template renders, once a page, only while the file has something in it, with the file's version in its address. No template is changed for it. With `custom_css.inject` off the site links the file itself.
- The link is added whether or not the editor is on: the CSS is the site's, written on a local copy and deployed with it. It is the one thing the package adds to pages when the editor is off; removing the package drops the link, and the file stays.
- Saving the custom CSS takes `visual-editor.edit`: CSS runs no PHP.
- The server API that changes the rules of the theme's stylesheets in place, and links a stylesheet from the layout, is kept for tools that use it.

### Sites that build their CSS (Vite, Tailwind)

Tailwind writes CSS only for the classes it finds in the site's files, so a class added to a template has no style until the CSS is built again. Three situations, each with its own answer:

- **A dev server is running** (`npm run dev` in the developer's terminal). Nothing of the editor is needed: it writes real files, Vite and Tailwind see the change and the page in the frame gets the new CSS. The editor never starts or stops that process.
- **No dev server, and a look is wanted before building.** A setting, off by default, adds Tailwind's own browser build to pages drawn for the editor, and only to those: any class shows at once. It is a preview: it reads the site's CSS entry file for the theme, and plugins may behave differently from a real build. The editor says that a preview is what is shown. Visitors never get it.
- **The CSS has to be built** for the site itself to show the change. A setting names one command (`npm run build`). When it is set, the editor has a **Build** button that runs exactly that command, shows what it printed and whether it worked, and says so plainly when it can't be started (no Node on the server). After a template is saved and until the next build, the editor says the built CSS may be behind. The button is off until the command is set, and takes a permission of its own.
- The command comes from the site's configuration and never from a request. One build runs at a time, with a time limit.
- Tailwind 4 with Laravel's Vite plugin is what this is built and checked for.
- The Style and CSS tabs are for sites with plain CSS files. On a Tailwind site styling is done with classes.

### Permissions

- Three permissions, registered with Sunrice and given to roles in its role editor: `visual-editor.open` (look), `visual-editor.edit` (change templates and stylesheets without writing code), `visual-editor.code` (write code). Each includes the ones before it. Super Admins have all three. None holds while the package is off.
- A fourth, apart from those three: `visual-editor.build` (run the site's build command from the editor). It is not included in any of the others, since it runs a program on the server, and it does nothing unless a command is set.
- Code editing is the highest, since it writes PHP. Someone who may edit and not edit code can never make a template run PHP that it did not run before, except code the editor itself writes for a field or a choice. This holds for every way of changing a template, and is checked on what Laravel's Blade compiler makes of the result, not on what was asked for.
- Such a person may move, copy and take out code that is there, and may add any HTML.
- Their undo and redo write the template as it was within the last twelve hours; no older version.

### AI agents

- Later: MCP tools that read a template's tree and apply the same edit operations as the editor, so an agent makes small checked changes instead of rewriting a whole file.

## Out of scope for the first release

- Storing templates, drafts or history in the database.
- Editing templates outside `resources/views/sunrice/`, or non-Blade views.
- A design system, design tokens, motion, or a component library of its own.
- Editing content with a form of the editor's own: entries are edited with Sunrice's own entry page (the Content tab); globals and menus stay in the Sunrice admin.
- Rendering the page inside the editor without the server (every change is a real render).
- Multi-user editing of one file at the same time.
- Running on production by default.

## Open decisions

| # | Question | Assumed until decided |
| --- | --- | --- |
| 1 | Is staging allowed by default, or local only? | Local only; staging by `SUNRICE_VISUAL_EDITOR=true`. |
| 2 | Plain CSS or a Tailwind build as the main styling path? | Both are served: plain CSS in `public/sunrice-theme/` with the Style and CSS tabs, as the starter templates use; Tailwind through Vite with classes, a preview and a build button (decided October 2026). |
| 3 | May Sunrice core gain the three small public APIs in `EXECUTION_PLAN.md` Phase 0? | Yes; until they merge the package wraps the internals in one adapter class. |
| 4 | Licence of this package. | Same as Sunrice (MIT); not stated in the repository yet. |
| 5 | Is the code panel's own small editor enough, or is CodeMirror wanted (search and replace, several cursors, folding)? | CodeMirror, always beside the page (decided October 2026). |
| 6 | Should a draft's banner on the site carry an **Edit visually** link? Sunrice would need a hook for it. | No link on the site; the editor is opened from the admin (an entry's page has **Edit with Visual Editor**) or by `?url=`. |
