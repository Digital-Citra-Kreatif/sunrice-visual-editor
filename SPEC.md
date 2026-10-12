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

### Room for the page

- Everything beside the page can be put out of the way and brought back with one click, so the page, or the code, has the room when it is what is being looked at.
- Every panel (Templates on this page, Layers, Insert, Settings, Template) folds to its title by a click on the title, and opens again the same way. So does every titled group inside a panel: the groups of controls in Settings and Style, the kinds of thing in Insert, where a field comes from in Fields.
- Three buttons in the top bar show and hide the panels on the left, the code under the page, and the panels on the right. A side that is hidden gives its room to the page.
- A hidden side takes no room at all, and can be looked into without giving it any: a strip at the page's edge, in the room around the page, flies the side out **over** the page. The page keeps its width, so nothing on it moves. The side goes away again by Escape, by its own button, or by a click on the page or in the code; **Keep open** gives it its room back. Only one side is flown out at a time, and none is when the editor is opened again.
- Asking for something that lives in a hidden or folded place brings the place back: **Insert** shows the left side.
- The editor opens the way it was left: what is hidden and what is folded is remembered in the browser.
- What is folded keeps its state: a list stays scrolled, a field half typed stays typed.

### Editing as code

- The editor has two modes, chosen in the top bar: **Visual** (layers, settings, insert) and **Code**. Both work on the same files, show the same page and find the same templates.
- In Code mode the open template is text in a code editor beside the page: line numbers, colours for Blade, HTML and PHP, undo of its own, search and replace, several cursors.
- Templates open as tabs. Each keeps the text that was typed and not saved, so the header can be looked at halfway through a change to the page's template. A tab with unsaved text says so, and leaving the editor with one asks first.
- The templates of the page are listed as in Visual mode, and a click on the page opens the template that drew what was clicked and puts the cursor on that element. The element the cursor is in is outlined on the page.
- **Fields** lists what can be read where the cursor is, in text that is not saved yet too, and a click writes the same Blade there as it would in Visual mode. After `$entry->` and the like, the editor offers what that value has.
- Saving is one step for a tab (Ctrl+S): the text is checked as any save is (it compiles, the file is still the version it was read as), written, and the page drawn again. Nothing is saved while typing.
- Code mode is for people who may edit code (`visual-editor.code`). Others can read the code and not change it.
- The code editor is CodeMirror, in a script of its own that is fetched the first time Code mode is opened: the rest of the admin does not carry it.
- It does not try to be an IDE: no search across files, no git, no terminal.

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
- Sites that build their CSS get class editing; see *Sites that build their CSS* below.
- A style panel has controls for layout, size, spacing, text, background and border; each reads and writes one declaration of the rule for a class.
- The page can be shown at several widths side by side. One of the frames is edited; a style set while a width is edited is written under the `@media` condition for that width.

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
- Editing content: entries, globals and menus stay in the Sunrice admin. The editor links to them.
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
| 5 | Is the code panel's own small editor enough, or is CodeMirror wanted (search and replace, several cursors, folding)? | CodeMirror, with a Code mode around it (decided October 2026). |
| 6 | Should a draft's banner on the site carry an **Edit visually** link? Sunrice would need a hook for it. | No link; the editor is opened from the admin or by `?url=`. |
