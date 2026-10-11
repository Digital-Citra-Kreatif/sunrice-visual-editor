# Sunrice Visual Editor — Execution Plan

This plan turns `SPEC.md` into a working package. It follows the format of Sunrice's own plan: each task lists its dependencies, the files to create or change, what they must contain, and when it is done.

`SPEC.md` is the source of truth for behaviour. This plan is the source of truth for structure, naming and order. If they disagree, follow `SPEC.md` and record the conflict in the pull request.

---

## 0. Rules for agents

1. Read `SPEC.md` and this file before starting a task.
2. One task per branch and pull request. Branch `task/<task-id>-<short-name>`, PR titled `[<task-id>] <name>`.
3. Only start a task when its dependencies are merged.
4. No Composer or npm packages beyond §2. If one seems needed, stop and explain why in the PR.
5. Every task ships tests. Before opening a PR run `composer test`, `composer lint`, `composer analyse`, `composer spike`, and for admin tasks `npm run typecheck && npm run build`.
6. `composer spike` (the round-trip test) must stay green. Any change under `src/Blade/` that makes a template print back differently, or an edit touch bytes it should not, is a bug however small.
7. Use Sunrice's public API only (`docs/packages.md` in sunrice-cms). The single exception is `src/Support/SunriceBridge.php` (V0.4), which is the only file allowed to touch Sunrice internals.
8. Update `docs/` when adding developer-facing API.

---

## 1. Conventions

| Item | Value |
| --- | --- |
| Composer package | `sunrice/visual-editor` |
| PHP namespace | `Sunrice\VisualEditor\` → `src/`; tests `Sunrice\VisualEditor\Tests\` → `tests/` |
| Service provider | `Sunrice\VisualEditor\VisualEditorServiceProvider` (auto-discovered) |
| Config | `config/sunrice-visual-editor.php`, key `sunrice-visual-editor` |
| Admin routes | Registered with `Sunrice::adminRoutes()`, under `{admin}/visual-editor`, named `sunrice.admin.visual-editor.*` |
| Admin page | `VisualEditor/Editor`, registered with `window.Sunrice.registerPage()`, `layout = null` (full screen) |
| Admin script | `resources/js` (TypeScript) → `dist/visual-editor.js` and `dist/visual-editor.css`, committed; published to `public/vendor/sunrice-visual-editor` |
| Permissions (Phase 7) | `visual-editor.open`, `visual-editor.edit`, `visual-editor.code` (never a `sunrice.` prefix) |
| Editor marks | Attribute `data-sve="<file>:<offset>"`; only in editor renders |
| File identity | Path relative to `resources/views/sunrice/`, e.g. `partials/header.blade.php` |
| File version | `sha1` of the file's bytes |
| Node identity | The node's start byte offset in that version of the file, as a string (`b<offset>` for an arm of a block, `root` for the root) |
| Strict types | Every PHP file starts with `declare(strict_types=1);` |
| Write operations | Single-purpose action classes in `src/Actions/` with a public `handle()`; controllers stay thin |

A node is addressed as `{file, version, node}`. Offsets are only valid for one version, so every request that names a node also names the version it read; the server refuses it when the file has changed.

---

## 2. Approved dependencies

**Composer — require**

| Package | Used for | Added in |
| --- | --- | --- |
| `php` `^8.3` | | V0.1 |
| `sunrice/cms` | The CMS this extends | V0.1 |
| `stillat/blade-parser` `^2.1` | Finding Blade constructs in a template | done (spike) |

**Composer — require-dev:** `orchestra/testbench`, `pestphp/pest`, `pestphp/pest-plugin-laravel`, `laravel/pint`, `larastan/larastan`.

**npm (admin script only, all dev dependencies):** `typescript`, `esbuild` (pinned to an exact version, so every build of `dist/` is the same), `@types/react`, `@types/react-dom`, `playwright-core` (the browser check; drives the Chrome already on the machine). Named here at first and not used, each for the reason given in its task: `@dnd-kit/core`, `@dnd-kit/sortable` (Layers panel, V4.4), `codemirror`, `@codemirror/lang-html`, `@codemirror/lang-css`, `@codemirror/lang-php` (code panel, V3.4). **Added for Phase 8:** `@codemirror/state`, `@codemirror/view`, `@codemirror/commands`, `@codemirror/search`, `@codemirror/language`, `@codemirror/autocomplete` (the code editor of V8.2; none of the language packages, since the package's own highlighter knows Blade and they do not). They are bundled into `dist/code-editor.js`, a script of its own. React, Inertia and the admin's UI components come from `window.Sunrice` and are never bundled.

**No CSS parser.** V6.1 tried it: `sabberworm/php-css-parser` writes none of twelve stylesheets back as it read them (spacing, comments, the way values are written) and keeps no offsets, so a change through it rewrites the whole file. `src/Css/Stylesheet.php` finds rules and declarations by their place in the text instead, and passes every check of the CSS round-trip test (`spike/CSS_RESULTS.md`), Bootstrap and Bulma included.

---

## 3. Target repository layout

```
sunrice-visual-editor/
├── composer.json, package.json, tsconfig.json, build.mjs
├── config/sunrice-visual-editor.php
├── dist/                              # committed admin script
├── docs/
├── resources/js/                      # editor UI (React via window.Sunrice)
│   ├── index.tsx                      # registerPage('VisualEditor/Editor', …)
│   ├── bridge/                        # script injected into the page frame
│   ├── panels/                        # Templates, Layers, Settings, Fields, Code, Css
│   └── state/                         # open file, selection, history
├── src/
│   ├── Blade/                         # the template tree (exists)
│   ├── Actions/
│   ├── Css/                           # Phase 6
│   ├── Fields/                        # field catalog, scope, emitters
│   ├── Http/Controllers/, Http/Middleware/
│   ├── Mcp/                           # Phase 7
│   ├── Rendering/                     # editor render: engine, marks, manifest
│   ├── Support/SunriceBridge.php
│   ├── Templates/                     # repository, saving, backups
│   └── VisualEditorServiceProvider.php
├── spike/                             # round-trip runner (exists)
├── tests/ (Pest.php, TestCase.php, Feature/, Unit/, fixtures/, Frontend/)
├── workbench/                         # Testbench app with Sunrice, starter templates and seed content
└── AGENTS.md, README.md, CHANGELOG.md, SPEC.md, EXECUTION_PLAN.md
```

---

## 4. Phases

| Phase | Tasks | Depends on | Rough size | Gives you |
| --- | --- | --- | --- | --- |
| 0. Skeleton | V0.1–V0.4 | — | 1 week | An empty editor screen in the admin; CI |
| 1. Template tree | V1.1–V1.7 | V0.2 | 2–3 weeks | Every edit the UI needs, tested without a UI |
| 2. Files and saving | V2.1–V2.4 | V1.7, V0.4 | 1 week | An HTTP API that reads and safely writes templates |
| 3. Page inspector | V3.1–V3.5 | V2.3, V0.3 | 2–3 weeks | **M1:** see a page's templates, click through, edit as code |
| 4. Visual editing | V4.1–V4.6 | Phase 3 | 3–4 weeks | **M2:** layers, settings, insert, move, undo |
| 5. Fields and variables | V5.1–V5.5 | V4.2 (V5.1–V5.3 can start after V1.7) | 2–3 weeks | **M3:** the field picker; the goal in `SPEC.md` is met |
| 6. Styles | V6.1–V6.4 | V4.2 | 3+ weeks | CSS panel, then a style panel and breakpoints |
| 7. Permissions, agents, release | V7.1–V7.4 | M3 | 1–2 weeks | Own permissions, MCP tools, docs, v0.1.0 |
| 8. Code mode, and sites that build their CSS | V8.1–V8.7 | Phase 7 | 2–3 weeks | A real code editor beside the page with tabs and fields at the cursor; Tailwind through Vite: preview and build |

Sizes are rough guesses for one developer working with coding agents. Phases 1 and 2 have no UI and can run alongside Phase 0. V5.1–V5.3 are backend only and can run alongside Phases 3 and 4.

**Where it stands:** Phases 0 to 7 are done and merged, except the release itself (V7.4), which waits for an install on a real project. Phase 8 was added in October 2026 and is next. Where a task was built differently from what was first written here, its text below says what was built and why.

---

## Phase 0 — Skeleton

### V0.1 Composer manifest, provider, config and a first test run
**Depends on:** —
**Files:**
- `composer.json` — `sunrice/cms` (from its GitHub repository under `repositories`, at `dev-main` until Sunrice tags a release), the dev tools, `extra.laravel.providers`, scripts `test`, `lint`, `format`, `analyse`, `spike`.
- `src/VisualEditorServiceProvider.php` — merges and publishes config; does nothing else when the package is off. Routes, the admin script and the sidebar link are added in V0.3, when there is a page to link to.
- `config/sunrice-visual-editor.php` — `enabled` (`env('SUNRICE_VISUAL_EDITOR', env('APP_ENV') === 'local')`), `backups` (bool), `theme_css` (`editor.css`).
- `src/Support/Access.php` — `enabled(): bool`; `allowed(?Authenticatable $user): bool`: enabled and the user has Sunrice's super admin role. Phase 7 replaces the body.
- `tests/Pest.php`, `tests/TestCase.php`, `tests/DisabledTestCase.php` — Testbench with Sunrice's providers and this one; sqlite in memory; `createUser()` and `actingAsSuperAdmin()` helpers. Tests that need the editor off live in `tests/Disabled/`.
- `workbench/app/Models/User.php`, `workbench/database/migrations/` — a user model with roles and the users and permission tables, as in Sunrice's own workbench.
- `phpunit.xml`, `phpstan.neon` (level 6), `pint.json`, `.gitattributes`.
- `.github/workflows/tests.yml` — PHP 8.3 and 8.4, sqlite; runs lint, analyse, test and the round-trip test.
**Done when:** CI is green, with tests showing the provider loads, that only Super Admins are allowed, and that with `enabled = false` nobody is allowed and no route named `sunrice.admin.visual-editor.*` exists.

### V0.2 Round-trip tests in Pest and the workbench site
**Depends on:** V0.1
**Files:**
- `src/Blade/BladeScanner.php` — build the parser explicitly (core directives plus the application's own, read from the Blade compiler when there is one) instead of relying on a global factory, so it behaves the same in an app, in tests and in the spike.
- `tests/Support/RoundTrip.php` — the round-trip checks, used by both the Pest tests and `spike/run.php`, which stays as the report generator.
- `tests/Unit/Blade/RoundTripTest.php` — the checks as Pest datasets over `tests/fixtures/**` and over the starter templates of the installed Sunrice: opens, prints back identical, expected refusals, and the six edits.
- `workbench/`, `testbench.yaml` — app with Sunrice installed, starter templates published, and a seeder: `pages` and `articles` collections, a `hero`/`text`/`gallery` flexible field, `site` and `footer` globals, a `main` menu, a homepage and five articles.
- `tests/Support/WithStarterSite.php` — for a test: the starter templates in a directory of its own plus the workbench content.
- `.github/` — CI repeats the output of a failed step as an annotation, and builds and serves the workbench to check the seeded homepage.
**Done when:** CI is green, including the workbench check (`composer workbench`, `testbench serve`, the homepage shows the seeded site).

### V0.3 Admin script build and empty editor page
**Depends on:** V0.1
**Files:**
- `package.json`, `tsconfig.json`, `build.mjs` — esbuild, one ES module, JSX factory `Sunrice.React.createElement`; `react`, `react-dom` and `react/jsx-runtime` imports, ours or a bundled library's, resolve to the copies on `window.Sunrice`.
- `resources/js/` — `index.tsx` registers `VisualEditor/Editor` with `layout = null`; `Editor.tsx` is the shell: a top bar (back to admin, address field), the page in a frame, and empty side panels. `sunrice.d.ts` types the part of `window.Sunrice` the editor uses.
- `resources/css/visual-editor.css` — the editor's own stylesheet, on the admin's colour variables (the admin's stylesheet only has the classes Sunrice itself uses).
- `src/Http/Controllers/EditorController.php` — `show()`: authorizes with `Access`, renders `VisualEditor/Editor` with the path to open (`?url=`, pages of the site only).
- `routes/admin.php` — `GET visual-editor` inside `Sunrice::adminRoutes()`.
- `src/VisualEditorServiceProvider.php` — when enabled: the route, the admin script and stylesheet (`Sunrice::registerAdminScript()` / `registerAdminStyle()`, with a fingerprint of the build), the sidebar link (`Sunrice::addNavigationItem()`, Super Admins only), and `dist/` published under the tag `sunrice-visual-editor-assets`.
- `tests/Browser/editor.mjs` — signs in to the workbench in a real browser, opens the editor from the sidebar, uses the address field, and saves `docs/screenshots/editor.png`.
- `.github/workflows/assets.yml` — on pull requests: typecheck, frontend tests, build, the browser check, then commits `dist/`, `package-lock.json` and the screenshot to the branch when they changed.
**Done when:** CI is green: a Super Admin opens `/cms/visual-editor` and sees the shell with the site in the frame (browser check); other users get 403 and guests the sign-in page (feature tests). That bundled libraries get the admin's React is checked when the first one is added (V4.4).

### V0.4 Sunrice bridge and core changes
**Depends on:** V0.2
The package needs three things Sunrice has but does not publish. Each is asked for in an issue in sunrice-cms; until it exists, `SunriceBridge` reaches the internal classes, and it is the only file that does.

| | Needed | In Sunrice today | Proposed public API |
| --- | --- | --- | --- |
| C1 ([sunrice-cms#36](https://github.com/littleboy130491/sunrice-cms/issues/36)) | Template directories and the template map (which view each collection and taxonomy uses) | `Sunrice\Mcp\Templates` | `Sunrice::templates()` returning a documented service |
| C2 ([#37](https://github.com/littleboy130491/sunrice-cms/issues/37)) | Field definitions of a collection's blueprint, a listing page, a fieldset, a taxonomy and a global set, with fieldset includes expanded; the lists of collections, global sets and menus | Models and `BlueprintSchema`, undocumented | `Sunrice::schema()->collection($handle)` etc., returning plain arrays |
| C3 ([#38](https://github.com/littleboy130491/sunrice-cms/issues/38)) | Adding MCP tools from a package | `SunriceServer::$tools` is a fixed list | `Sunrice::registerMcpTool(string $class)` |

**Files:**
- `src/Support/SunriceBridge.php` — `templateDirectory()`, `themeDirectory()`, `packageViewDirectories()`, `templateMap()`, `backup(string $file, string $relative)`, `fields(string $kind, string $handle)`, `collections()`, `globals()`, `menus()`. The backup needs nothing internal (the same location Sunrice uses). The syntax check started here and moved to `src/Blade/Syntax.php` in V1.7: it needs Laravel's Blade compiler and nothing of Sunrice. When Sunrice ships C1–C3, the bridge switches to them and nothing else changes.
- `config/sunrice-visual-editor.php` — `paths.templates`, `paths.theme`: null for Sunrice's own places; set by tests, and by a site that keeps its templates elsewhere.
**Done when:** unit tests cover each bridge method against the workbench content, and the three issues are open in sunrice-cms with the proposed signatures.

---

## Phase 1 — Template tree

The spike left `src/Blade/` with: `BladeScanner`, `HtmlLexer`, `TreeBuilder`, `Node`, `Template`, `Classifier`, `Edit`. This phase turns it into the library the editor calls. Every task adds its edits to the round-trip datasets: applied to every node they fit, in every fixture, checked against expected bytes.

### V1.1 Public surface and JSON
**Depends on:** V0.2
**Files:**
- `src/Blade/Template.php` — `open(string $source): self`, `version(): string`, `find(string $id): ?Node`, `print(): string`, `toArray(): array`.
- `src/Blade/Node.php` — `id()`: the start offset as a string; a branch starts where its block does, so its id is `b<offset>`, and the root's is `root`. `toArray()` (built by `TreeData`): `id`, `type`, `kind` (from `Classifier`), `name`, `label` (the tag with its id or first class; text and expressions shortened), `start`, `end`, `can` (`move`, `delete`, `insert`, `attributes`), and by type `attributes`, `text`, `expression`, `arguments`, `source`, `children`. Whitespace between nodes is left out; a code block is one piece of text without children.
- `src/Blade/Rules.php` — what may be done to a node: selectable, movable, holds children, is an arm of its block. The panels ask before offering an action and the edits refuse what it forbids.
**Done when:** `toArray()` of every fixture round-trips through `json_encode`, every id is unique, and every node is found again by `find($id)`.

### V1.2 Attributes
**Depends on:** V1.1
- `src/Blade/Attributes.php`, reached through `Edit::setAttribute`, `removeAttribute`, `addClass`, `removeClass`, `setId`, `setTag` (renames both tags). After each change the opening tag is read again, so several edits can follow one another.
- `src/Blade/Inert.php` — typed text as text: HTML-escaped, and `{{`, `{!!` and `@name` broken with character references so Blade has nothing to run. Values are written this way unless passed as Blade (`{{ $entry->url }}`), which is refused when it would end the attribute.
- `src/Blade/EditRejected.php` — an edit that can't be made as asked, with a reason for the person; nothing is changed when it is thrown.
- `src/Blade/BladeScanner.php` — follows Blade's own rule that `@name` straight after a letter, digit or underscore is not a directive (`joe@if.org`), which the parser alone does not.
- Refused: a second attribute of the same name; classes when the tag has `@class([…])`; renaming to or from a void element, `script`, `style`, `svg` or `math`.
**Done when:** each edit is a minimal change in the opening tag for static, dynamic, unquoted, valueless and absent attributes, on every element of every fixture, and typed text reads back as typed and compiles to no new PHP.

### V1.3 Text, echoes and directive arguments
**Depends on:** V1.2
- `src/Blade/Content.php`, reached through `Edit::setText` (typed text written with `Inert`; not inside `<script>` or `<style>`), `setExpression` (an echo's expression, keeping `{{ }}` or `{!! !!}` and the spacing; must be one PHP expression), `setEchoRaw(bool)`, `setArguments` (what is between a directive's brackets: conditions, loops, includes; brackets and quotes must match up) and `setCode` (a node rewritten by hand; an empty string removes it).
- `src/Blade/Fragment.php` — reads a snippet into nodes with the same builder as a file, and refuses what a file would be refused for.
- `src/Blade/Seams.php` — the seam rule, moved out of `Edit`.
- `src/Blade/BladeScanner.php` — knows every directive the installed Blade compiles (its compiler's `compile…` methods), not only the parser's own list, which misses newer ones such as `@includeIsolated`.
- `src/Blade/TreeBuilder.php` — refuses half a block: an `@if` never closed, an `@endif` or `@else` with nothing before it, `@empty` outside `@forelse`, `@case` outside `@switch`.
**Done when:** round-trip datasets pass, and tests prove text containing `{{ $x }}`, `@php` and `<script>` comes out inert, and that an expression or arguments that would end their construct early are refused.

### V1.4 Structure edits
**Depends on:** V1.2, V1.3
- `src/Blade/Structure.php`, reached through `Edit::insertBefore`, `insertAfter`, `append`, `prepend`, `remove`, `duplicate`, `move(node, target, before|after|inside)`, `wrap(node, wrapper)` and `unwrap`. A wrapper is written as one empty element or block: `<div class="card"></div>`, `@if ($show)@endif`.
- `src/Blade/Layout.php` — how the file is laid out around a node: the indentation of its line (or that it sits inline) and the size of one step here. A node on a line of its own stays that way: what is inserted beside it gets a line and the same indentation, deleting it takes its line with it, wrapping moves it in one step, and an empty element with a line to itself opens up over three lines. Inline nodes are joined and parted without added whitespace. Nothing else is reindented.
- Reordering within one parent moves the nodes and leaves the whitespace between them where it is.
- A snippet is read for where it goes: inside `<svg>`, `<path />` closes itself.
- Text can be wrapped where it stands (`<strong>`, `<a>`), though not moved.
- Seam rule: an argument-less directive followed by `(` after an edit, which a space does not fix, has the bracket written as `&#40;`.
- Guards: arms of a block are not movable; a node cannot move into itself; nothing goes into a void element, a `<script>`, or a code block.
**Done when:** datasets pass, including move between parents and into and out of Blade blocks.

### V1.5 Stray tags instead of refused files
**Depends on:** V1.4, V1.7
The first plan was to wrap the smallest run of nodes around a nesting failure in one code node. That loses too much: with a link opened in one `@if` and closed in another, everything between the two would be code, and the header half of a split layout would be code from top to bottom. Instead the tag without its other half is the code, and nothing else is.
- `src/Blade/TreeBuilder.php` — reading a file, an end tag that closes nothing in its arm, and an opening tag still open when its arm, its parent's end tag or the file ends, become leaves marked `stray` with the reason; what an unclosed element held follows its tag as siblings. A directive the parser left unpaired, a `@case` inside another block, and an `@endif` whose `@if` is inside a tag are kept the same way. A block whose end the tree can't find is still `Unsupported`. Continuing and closing directives are matched to their own block, not to whichever is open.
- Reading a snippet (`Fragment`) stays strict: nothing stray is put into a template.
- `src/Blade/Node.php` — `stray`: why, or null. `Template::strays()` lists them.
- `src/Blade/Rules.php` — a stray node, and any node that holds one, is not moved, copied, deleted, wrapped, unwrapped or rewritten. An opening tag left without its end keeps its attribute edits (`<body class="…">` in a header file); renaming it is refused.
- `src/Blade/Operations.php` — refuses a result with more stray nodes than the source had.
- Fixtures: the five `locked-…` nesting cases become `stray-…`, joined by a split layout (header and footer), a card with a conditional link, a table without end tags, and a condition closed outside its tag. Two `locked-…` fixtures remain: a block closed inside a tag, and Blade the parser can't read. Fixtures from real client templates are still wanted; these are written from the patterns such templates use.
**Done when:** the `stray-…` fixtures open with only the unmatched tags as code, print back identical, compile as before, and their other nodes pass the edit datasets.

### V1.6 `@switch` as a visual block
**Depends on:** V1.4
Treat `@switch` like `@if`, with `@case` and `@default` as arms. This turns the two largest code regions in the starter templates (the form block and the filter form) visual. Make `@empty` an arm of `@forelse` the same way, so the loop's part and the empty part are each something to insert into.
- `src/Blade/TreeBuilder.php` — `@case`, `@default` and `@empty` start the next arm of their block, as `@else` does. The part of a `@switch` before its first `@case` is an arm too, and holds nothing: Blade compiles whatever is there into PHP.
- `src/Blade/Rules.php` — the `@break` directly in a `@case` ends the arm: it is not moved or deleted, nothing is put after it, and what is appended to the arm goes before it. A `@break` in a loop, or in a condition inside an arm, is an ordinary node.
- `src/Blade/Structure.php` — an empty arm opens at the indentation of its own line (`@case`, `@else`), and the line after it keeps its place.
- `src/Blade/Content.php` — `@else` and `@default` take no arguments; `@if`, `@switch`, `@case` and the loops can't lose theirs.
- Arms are added, removed and reordered by rewriting the block as code (`setCode`), like `@else`. The UI can offer more in Phase 4 if it is missed.
- The round-trip harness gains **insert inside**: appended to every element and arm that holds children.
**Done when:** `blocks/form.blade.php` and `partials/entry-filter.blade.php` have no code node from `@switch`, and edits inside the arms pass.

### V1.7 Operations
**Depends on:** V1.2–V1.4
- `src/Blade/Operation.php` — an edit as plain data: `type`, `node` (an id) and what the type takes, one type for each `Edit` method with the same name and the same things in the same order (`['type' => 'move', 'node' => '412', 'target' => 'b96', 'position' => 'inside']`). `Operation::from()` refuses an unknown type, something missing, something of the wrong kind, and something the type doesn't take.
- `src/Blade/Operations.php` — `apply(string $source, array $operations): string` and `run()`, which returns `Applied`: opens the source, finds every node named before anything changes, makes each edit, prints, opens the result again, and checks that it compiles. Any failure throws `EditRejected` with a reason for the person and the position of the operation in the list; nothing is half applied.
- `src/Blade/Applied.php` — the new source, and `focus`: the id, in the new file, of the node the last operation made or changed (what was inserted, the copy, the wrapper, the node where it was moved to). The UI selects it.
- `src/Blade/Syntax.php` — `error(string $blade): ?string`: compiles with the application's Blade compiler and reads the PHP with PHP's parser. A template that names a component the application doesn't have is still checked for the rest, so one missing component doesn't lock a file; an operation that brings one in is refused.
- `src/Blade/ResultRejected.php` — an `EditRejected` for a result that can't be saved: it doesn't open, doesn't compile, or leaves a `@break` or `@continue` with no loop around it (PHP's parser lets that through; the page would not run).
- Nodes are named by their id in the version the operations were written for. In one list, a later operation can name any node an earlier one left in place, but not one it removed, rewrote, or moved to another parent.
- `Edit::move`, `unwrap` and `setCode` return the nodes now in place. A wrapper may hold whitespace (`@auth @endauth` can't be written without it).
**Done when:** every `Edit` method is reachable as an operation, and the round-trip datasets run through `Operations`.

---

## Phase 2 — Files and saving

### V2.1 Template repository
**Depends on:** V0.4, V1.1
- `src/Templates/TemplateRepository.php` — `all()`: the site's templates, then Sunrice's own views, each with `path` (inside its directory), `origin` (site | package), `view` name, `role`, `usedBy` (the collections and taxonomies whose pages it draws now), `editable`, `copyTo`, and whether it opens: `opens`, the refusal `reason`, and the number of `strays`. `read(path, origin)` adds `source`, `version` and `tree`. `find()` returns the `TemplateFile` or throws `TemplateNotFound`.
- A template is named by its path and its origin. Only files found by listing the directories are read, so no path leads outside them.
- `role`: `layout`, `partial`, `block`, `error`, `component` from the folder; otherwise `entry`, `listing` or `term` from the pages that use it; otherwise from the name Sunrice looks for (`show`, `index`, `taxonomies/…`, `search`); otherwise `other`.
- Of Sunrice's own views only `defaults/`, `components/` and `errors/` are listed: the rest are the admin's page and mail. `copyTo` is the site path Sunrice looks at first (`defaults/term` → `taxonomies/show`); views it looks for nowhere in the site have none.
**Done when:** the workbench lists the 19 starter templates with correct roles and `usedBy`.

### V2.2 Saving
**Depends on:** V1.7, V2.1
- `src/Actions/SaveTemplate.php` — `handle(path, expectedVersion, newSource)`: finds the template among the site's (so the path can't lead elsewhere); under a lock of its own for that file, refuses with `Conflict` when the file's current version differs; refuses with `DoesNotCompile` when the new source has an error the old one didn't (`Syntax::brokenBy`); backs up; writes beside the file and renames over it, keeping its permissions; returns the new version. Source equal to the file is a no-op.
- `src/Actions/CopyPackageView.php` — **Copy to site**: copies one of Sunrice's fallback views to its `copyTo` path; `CopyRefused` when the view has none or the site already has that template.
- `src/Templates/Conflict.php` (carries the file's current version), `DoesNotCompile.php`, `CopyRefused.php`.
- `SunriceBridge::backup()` gives a second save within the same second a backup of its own.
**Done when:** tests cover conflict, syntax error (nothing written), backup created, path traversal refused, and a write from a second process between read and save.

### V2.3 HTTP API
**Depends on:** V2.2
Routes under `{admin}/visual-editor/api`, inside Sunrice's admin routes (signed in) and behind `src/Http/Middleware/EditorAccess.php`. A template is named in the address by its path, slashes and all; `src/Http/Controllers/Api/TemplateController.php` answers in JSON.

| Route | Does |
| --- | --- |
| `GET templates` | `TemplateRepository::all()` → `{templates}` |
| `GET templates/{path}` | `{template}`: source, version, tree. `?origin=package` for one of Sunrice's views |
| `POST templates/{path}/operations` | `{version, operations}` → applies and saves → `{template, focus}` |
| `PUT templates/{path}/source` | `{version, source}` from the code panel → saves → `{template}` |
| `POST templates/{path}/copy` | Copy to site → `201 {template}`, the new site template |

A refusal is JSON with a `reason` the screen can act on and a `message` for the person: `forbidden` (403), `not_found` (404), `conflict` (409, with the file's current `version`), `rejected` (422, with the position of the `operation`), `syntax` (422), `invalid` (422: the request itself is wrong). A request carries at most 200 operations, or a source of 1 MB.
A template that is not UTF-8 is listed as not opening, with `source: null`: it can't be sent to the screen as JSON.
**Done when:** feature tests cover each route and each error.

### V2.4 Activity
**Depends on:** V2.2
`SaveTemplate` and `CopyPackageView` record what they wrote in Sunrice's activity log with its documented call, `app(ActivityLogger::class)->record('updated', 'partials/header.blade.php', ['note' => 'Visual editor'], 'template')`, so template changes appear beside content changes as *Updated template “partials/header.blade.php”*. A refused save, or one with nothing to write, records nothing.
**Done when:** a save shows in Manage → Activity log.

---

## Phase 3 — Page inspector

### V3.1 Editor render
**Depends on:** V2.1
How a page is rendered for the editor without touching normal rendering:
- `src/Http/Middleware/EditorRender.php` — added to Sunrice's frontend routes by appending it to `config('sunrice.frontend.middleware')` in the provider's `boot()` (Sunrice reads that list once every provider has booted). Active only when the request carries `sve=1`, the package is enabled and `Access` allows the signed-in user. When active it swaps the Blade engine for the request, `app('view.engine.resolver')->register('blade', …)`, and puts Laravel's back afterwards, also when the page fails: in a long-lived process (Octane, tests) the next render is a normal one. Inactive, it does nothing.
- `src/Rendering/EditorEngine.php` — extends `Illuminate\View\Engines\CompilerEngine`. `get($path, $data)` notes a template the editor knows in the manifest; when it opens as a tree it runs a marked copy, compiled with the application's own Blade compiler (`compileString`) into `storage/framework/views-sve/`, never Laravel's compiled directory. A copy is named after the template's path and its bytes, so a changed template gets a new copy and the old one is removed. A template that doesn't open, and any view that isn't a template of the site or of Sunrice, is rendered by Laravel's engine as always.
- `src/Rendering/Marker.php` — from a `Template`, returns the source with `data-sve="<key>:<id>"` after the tag name of every element, and of every opening tag left stray. The id is the node's id: its offset in the unmarked file. A tag whose name goes on in Blade (`<h{{ $level }}>`) is left alone.
- `src/Rendering/Manifest.php` — the templates that took part in this page, in the order they were first rendered: `key` (a short hash of origin and path, the same in every render), and what `TemplateRepository` says about the file (`path`, `origin`, `view`, `role`, `usedBy`, `editable`, `copyTo`), and `opens`.
- The middleware appends the manifest as `<script type="application/json" id="sve-manifest">` before `</body>` (the bridge script tag joins it in V3.2), sets `Cache-Control: no-store`, `X-Robots-Tag: noindex` and `X-Frame-Options: SAMEORIGIN`, keeps `sve=1` on a redirect to another page of the site, and marks the request `responsecache.doNotCache`, so Sunrice's optional full-page cache (spatie/laravel-responsecache) neither stores the marked page nor, the address being its own, serves one in its place.
**Done when:** a feature test renders the workbench homepage with `sve=1` and finds marks from the layout, header, footer, page template and a block partial, plus the manifest; the same URL without `sve=1`, and with it as a non-admin, is byte-identical to a normal render; Laravel's compiled view directory holds no marks.
**Checked while building:** `@extends` (section content is drawn inside the layout and carries the child's marks), anonymous components (the component's view carries its own marks, the slot the caller's), `@include` in a loop (the same mark on every copy) and `@push`/`@stack`.
**Not tested here:** the full-page cache itself. spatie/laravel-responsecache is not among this package's dev dependencies; the test checks that the request is marked the way that package looks for.

### V3.2 Bridge script
**Depends on:** V3.1
`resources/js/bridge/` → `dist/bridge.js`, loaded only in editor renders (`EditorRender` adds its script tag after the manifest). It draws nothing and changes nothing on the page.
- `protocol.ts` — the messages, shared with the editor's own script, and the helpers without a DOM. Every message is `{sve: 1, message}` by `postMessage`, same origin only; the bridge listens to its parent window and nobody else.
- From the page: `ready` (the address as a visitor has it, the title, the manifest); `hover` and `select` with a target: the nearest marked element, its tag, its box, and the marked elements around it, innermost first; `measured`: the boxes of what the editor watches and what that was (an answer may cross a new question), with the scroll position and page size, sent again whenever the page scrolls, resizes or changes size, once per frame at most; `leaving`.
- To the page: `mode` (`select` | `browse`); `watch`: elements (every copy of each: a partial in a loop is drawn many times) and templates (the outermost elements each one drew, which is its region); `reveal`: scroll an element into view.
- In Select mode a click picks an element and nothing else happens: links, buttons and forms are stopped. In Browse mode they work, and a link or a GET form to another page of the site leads to its editor render (`sve=1` kept); other sites are left alone.
**Done when:** `tests/Frontend` covers the helpers, and `tests/Browser/bridge.mjs` runs the built script in a real browser against a fixture page in a frame and checks each message both ways.

### V3.3 Editor shell and Templates panel
**Depends on:** V3.2, V0.3
- The frame shows `{url}?sve=1`. `resources/js/lib/frame.ts` is the editor's end of the bridge; `lib/api.ts` the template API, with a refusal as an `ApiError` carrying the server's reason.
- **Templates on this page** (`panels/TemplatesPanel.tsx`) lists the manifest the way a page is built: layout, the page's own template, then partials, blocks and components, with *Read-only* and *Code only* marked. Pointing at a row outlines what that template drew; pointing at the page outlines the template under the pointer, with its name. A click on either opens the template.
- **Template** (`panels/TemplatePanel.tsx`), on the right, is the open template: what it is, what it draws, why it is read-only or code only, and **Copy to site** for one of Sunrice's views. A trail leads back to the page's own template in one click. A page opens on its own template.
- Outlines are drawn by the editor above the frame (`canvas/Outlines.tsx`) from the boxes the bridge measures; the page itself is never drawn on.
- **Select / Browse**: in Browse, links on the page work and the address and the list follow.
- **Other templates** lists the rest of the site's templates and Sunrice's views. Opening one that is not on the page brings the page it was last seen on, remembered in the browser (`lib/memory.ts`).
- A page that arrives without `sve=1` (a form posted, a redirect elsewhere) is asked for again with it. A page the editor can't follow, such as a missing one, says so in the panel.
**Done when:** in the workbench, from the homepage: click header → header opens; back → homepage template.

### V3.4 Code panel
**Depends on:** V3.3, V2.3
- `resources/js/panels/CodePanel.tsx` — the open template as code, in a panel under the page (**Edit as code** in the Template panel). Save, or Ctrl/⌘+S → `PUT …/source`; on success the frame shows the page again. A refusal is shown above the code and nothing is written: `syntax` with the compiler's message, `conflict` with **Load the file as it is now**. Tab indents with the file's own indentation. One of Sunrice's views is shown read-only. Leaving a template, or the editor, with unsaved code asks first.
- `resources/js/lib/highlight.ts` — colours for Blade and HTML: comments, echoes, directives with their brackets, PHP, tags, attributes and values. One pass, and the pieces joined are the source again.
- **No CodeMirror.** The plan named it; this is a text field with the colours laid under it, character for character. It adds 7 kB to the script where CodeMirror would add a few hundred, has no dependency to keep up with, and keeps the browser's own undo, selection and find. What it lacks is search-and-replace, several cursors and folding. If those are missed, CodeMirror can take the panel's place behind the same props.
**Done when:** editing the footer as code changes the page in the frame, and a syntax error is shown without writing.

### V3.5 Editor entry points
**Depends on:** V3.3
- `{admin}/visual-editor?url=/about` opens that page (`EditorController::sitePath` keeps it to paths of the site; the address field refuses the admin's own address the same way).
- Sunrice's draft banner (`sunrice::defaults.draft-banner`, put into the page by `PageController::renderDraft`) has no hook for a link of a package's, so there is no **Edit visually** link on it. Not filed with Sunrice yet; if wanted, it would be **C4**: a way for a package to add a link to the banner, such as `Sunrice::addDraftBannerLink(label, fn (Entry $entry, string $locale): ?string)`.
- The README says what works at this milestone and how to open the editor.
**Done when:** the query parameter works. **Milestone M1.**

---

## Phase 4 — Visual editing

### V4.1 Layers panel and selection
**Depends on:** M1
- `resources/js/panels/LayersPanel.tsx` — the open template's tree, under the list of templates: elements, text, Blade blocks and echoes in the order of the file, each with a short badge for its kind and the node's label. Rows fold. A block with one arm (a loop, a lone `@if`) lists what the arm holds directly; one with several lists its arms.
- **Selecting.** A click on a layer selects it and scrolls the page to it. A click on the page selects the element clicked; when that element belongs to another template, the template is opened first. The selection is a node id of the open template's tree.
- **Showing a node on the page** (`lib/tree.ts`, `boxesOf`). An element is outlined as itself, once for every copy the page has of it: a node in a loop is drawn many times. A block or an arm is outlined as the outermost elements inside it. Text, an echo or a directive has no box of its own and is shown, dotted, by the element around it.
- Pointing at a layer outlines it on the page; pointing at the page lights its layer.
- The right panel shows what is selected, in a few words, above the template. Its settings are V4.2.
**Done when:** selection stays in sync both ways, including nodes inside loops and sections.

### V4.2 Settings panel
**Depends on:** V4.1, V2.3
- `resources/js/panels/SettingsPanel.tsx` — the right panel when a node is selected, above the template. A control for each thing that can be changed, by kind of node:

  | Node | Controls |
  | --- | --- |
  | Element | Tag; ID; classes as chips, with one field to add; a row per other attribute, to change or remove; a row to add one. A value Blade prints stays Blade when changed, a plain one stays text. Attributes written as Blade on their own (`@class([…])`, `{{ $attributes }}`) are shown and left to the code |
  | Text | the words, typed as text |
  | Echo | what it prints; **Print as HTML** (`{!! !!}`) |
  | `@if` and its like, `@elseif`, `@case` | the condition |
  | `@foreach`, `@forelse` | what is looped over, and what each one is called |
  | `@include` | the view, and the data passed to it |
  | Any other directive with brackets | what is between them |
  | Code, and anything else that can be moved | **As code**: the node as written, to rewrite |

- A control sends one operation when it is left or Enter is pressed (Ctrl/⌘+Enter where a field has several lines); Escape puts back what was there. The file is saved, the page drawn again and the node stays selected, found again by the id the response gives.
- A refusal is shown as a message, and the controls show the file again. While a change is being saved nothing else is sent and nothing else selected. While the code panel has unsaved changes the controls are off.
- `Editor.redraw()` draws the page again without dropping what the panels show; V4.6 makes it seamless.
**Done when:** each control changes the file with the minimal diff (asserted in a feature test per operation type: `tests/Feature/SettingsOperationsTest.php`).

### V4.3 Insert
**Depends on:** V4.2
- `src/Insert/Library.php` — what there is to insert, for this site, served by `GET {admin}/visual-editor/api/inserts` → `{inserts: [{id, group, title, hint, blade, keywords}]}`. Four groups:

  | Group | Entries |
  | --- | --- |
  | Elements | section, container, heading, paragraph, text, link, button, image, lists, the sectioning elements, divider, video, form fields |
  | Blade | condition (with and without `@else`), loop (`@foreach`, `@forelse`), value, interface text, `@auth`, `@guest`, `@section`, `@yield`, `@push`, `@stack`, note (since V4.4, when comments became deletable), `@php` |
  | Sunrice | `<x-sunrice::entries>` for each collection, `<x-sunrice::terms>` for each taxonomy, `<x-sunrice::form>` for each form, the search box, `<x-sunrice::seo />`, `<x-sunrice::code />` |
  | Partials | `@include` for each partial of the site, with the variables it needs |

- **Every entry can be drawn where it lands.** A condition starts as `@if (true)`, a loop goes over `[]`, and a component names a collection, taxonomy or form the site has: one that names something missing answers the whole page with "not found". A partial is the exception and says so: its hint is **Needs $entry**, read from the file by `src/Blade/Variables.php` (the variables a template reads without setting them, from its compiled PHP: a first pass at what V5.2 does properly).
- `resources/js/panels/InsertPanel.tsx` — **+ Insert** in the top bar puts the list in the left side, in place of the page's templates and above the layers. At its top, where the next thing goes by the selected node: **Before**, **Inside** or **After**. It starts at Inside for something still empty and at After otherwise, so a click after a click builds a structure: what is inserted becomes the selection. A block with one arm takes things into that arm; an arm takes them only inside. With nothing selected only an empty template takes something.
- A field to search titles, hints, keywords and the Blade itself. **Paste HTML or Blade** inserts what is pasted the same way; a refusal (a tag left open) leaves the pasted text in place to be put right.
- `<x-sunrice::entry-filter>` is not in the list: its filters are an array to write by hand, in the code panel or by pasting.
- `Layout::reindent()` now holds back only the lines inside a `<pre>` or `<textarea>`, not the whole snippet: the form component's markup has a one-line `<textarea>` and was put in unindented.
**Done when:** every entry inserts, opens as a tree and compiles (`tests/Unit/Insert/LibraryTest.php`: each entry, six ways in), and is drawn on a page of the workbench site (`tests/Feature/InsertApiTest.php`).

### V4.4 Move, duplicate, wrap, delete
**Depends on:** V4.1
- **Dragging** (`panels/LayersPanel.tsx`). A layer that can be moved is dragged onto another: the top of a row is before it, the bottom after it, and the middle into it, where it takes things. A line shows where it would land, at the depth it would have; for "after" that is under everything the row holds. `lib/move.ts` (`dropAt`) says where a drop goes and refuses the ones the tree would: onto itself, into something inside itself, beside or into what takes nothing there, after the `@break` that ends a `@case`.
- **Buttons** at the top of the Settings panel, for the selected node: move up, move down (one step among what is beside it), **Duplicate**, **Unwrap**, **Delete**, and **Wrap in…** a container, a section, a link or a condition. The same from the keyboard on a layer: Alt+↑, Alt+↓, Ctrl/⌘+D, Delete.
- Each is one operation of V1.4 (`move`, `duplicate`, `wrap`, `unwrap`, `remove`), and what it made or moved stays selected. Text is not picked up like a node: **Delete** empties it (`setText`), and it can be wrapped but not moved.
- **Comments can now be moved, copied and deleted** (`Rules::selectable`): Blade's and HTML's. Until now a comment could only be changed in the code, which left nothing to do with one in the Layers panel. The round trip covers them: 19,289 edits, up from 18,591.
- **No dnd-kit.** The plan named it; this is the browser's own drag and drop, about a hundred lines. It needs no library that brings React with it, so there is no second copy of React to keep out of the script, and nothing to keep up with. What it lacks: it does not work by touch, and the browser draws the thing being dragged. The buttons and keys do the same without dragging. It could also not have been tried before it shipped: where this was built, the npm registry can't be reached.
**Done when:** a move between parents produces exactly the V1.4 result (`tests/Feature/StructureOperationsTest.php`: eight moves, each compared with `Edit::move` on the tree and with the file written out in full).

### V4.5 History
**Depends on:** V4.2
- `resources/js/lib/history.ts` — undo and redo for each template changed since the editor was opened: a stack of the file as it was before each change, with what was selected then. A step is a whole source, so every kind of change is undone the same way: from Settings, Insert, a move, a delete, or a save in the code panel. A hundred steps a template.
- **Undo** and **Redo** in the top bar, Ctrl/⌘+Z and Ctrl/⌘+Shift+Z (or Ctrl+Y). An undo saves the earlier source over the current version (`PUT …/source`), draws the page again and selects what was selected then: a deleted node comes back selected. A change made after an undo ends what could be redone.
- **Keys reach the editor from the page too.** A click on the page gives the page the keyboard, so the bridge hands on undo, redo and Delete while the frame is in Select mode (`shortcutOf` in `bridge/protocol.ts`). Delete on the page deletes the selected node. In a field, and when browsing, the keys are the field's and the page's.
- **A file changed otherwise has no history.** The history holds for the version of the file it last saw. After a change made in a code editor or by someone else, an undo is refused by the server (`conflict`), the file is read again and nothing is offered for it any more: an undo never writes over a change it has not seen.
- **A change that breaks the page.** A value that doesn't exist where the template is drawn compiles and fails on the page; no check before saving can know. The page now says what it was answered with (`status` in the manifest), and when a page that was drawn before a change is not after it, the editor says so with an **Undo** button.
- Leaving with nothing unsaved is always the case (edits save at once), so there is no dirty state to guard except in the code panel, and while the code panel has unsaved changes undo is off.
- The history is the session's: it is gone when the editor is reloaded. The backups in `storage/app/sunrice/template-backups` are what lasts.
**Done when:** ten mixed edits undone return the file byte for byte (`tests/Feature/UndoTest.php` through the API; `tests/Frontend/history.test.mjs` for the stack; three changes of three kinds in the browser check).

### V4.6 Refresh without losing place
**Depends on:** V4.2
- **Two frames.** The stage holds two frames, one over the other. One is seen (`iframe.sve-frame`); the other (`iframe.sve-spare`) is laid out at the same size and never seen. After a change the page is asked for again in the spare frame; when it is ready it is scrolled to where the old one is, and the two change places. The page is never seen to go, to be empty, or to jump to its top. It is still one request to the server for each change.
- **When it is ready** (`lib/redraw.ts`). The new page says `ready` once its document is read, and the frame says `load` once its images are in. It is shown at `load`, or a quarter of a second after `ready` if `load` is slow in coming (one file from another site can hold it back for long). A page that says nothing for ten seconds is given up on, and the page is drawn again where it is, as before.
- **Meanwhile** the old page stays as it is and takes no clicks for the editor: its elements are those of the file before the change. The boxes drawn over it stay too, until the new page has measured what is selected now, so they don't blink either.
- The keys stay with the page when they were there: a Ctrl+Z pressed on the page can be followed by another.
- What is not kept: how far something *inside* the page was scrolled (a list or a table with its own scrollbar), and what was typed into a form on the page.
- Going to another page (the address field, a link when browsing, a template that is on another page) is not this: the frame that is seen goes there, as before.
**Done when:** editing text mid-page keeps the viewport still (`tests/Browser/editor.mjs`: a change to the foot of the page with the page scrolled to it, looked at on every frame the browser draws; `tests/Frontend/redraw.test.mjs` for when a page is ready). **Milestone M2.**

---

## Phase 5 — Fields and variables

### V5.1 Field catalog
**Depends on:** V0.4
- `src/Fields/Shape.php` — what a value in a template is: text, rich text, a number, yes-or-no, a date, a link, a file, an entry, a term, a group, a row of a repeater, a block, a global set, a menu item, a collection, a taxonomy, the page, a list of any of these, or a value nothing is known about. A shape names what it is made of (an entry *of the articles collection*) without holding it: an article can list related articles, so fields are looked up only as far as someone looks.
- `src/Fields/Field.php` — something read from a value: `handle`, `label`, `type` (Sunrice's name for it), its shape, and how it is read from what has it (`from('$entry')` → `$entry->get('excerpt')`). `multiple()` and `children()` are read off the shape.
- `src/Fields/Catalog.php`:
  - `fields(subject, handle)` — the fields of a blueprint, for a collection's entries, its listing page, a fieldset, a taxonomy's terms or a global set. Each kind is read its own way:

    | Fields of | Read as |
    | --- | --- |
    | an entry, a term | `$entry->get('handle')` |
    | a block, a global set | `$block->handle`; `->get('handle')` when the handle can't be a property's name |
    | a listing page | `$collection->archive('handle')` |
    | a group, a row | `$row['handle']` |

  - `members(shape)` — everything that can be read from a value of that shape, under a heading each: first what Sunrice gives every such value (an entry's title, URL, date, author, terms; a file's URL at each size the site makes, its alt text; a link's URL and label; a date in a few forms; a menu item's label, URL and children), then the fields of each blueprint it may be of. An entry of any collection lists every collection.
  - A site's own field type (`CustomField`) counts as the built-in type it is made of; a type nobody knows is a plain value.
- `SunriceBridge::fieldsets()` and `baseType()`.
- `tests/Support/WithEveryField.php` — content with a field of every type on top of the starter site: a `things` collection with a listing page, a `topics` taxonomy, one thing with everything filled and one with nothing. V5.3 draws both.
**Done when:** the catalog of the workbench structure matches its blueprints (`tests/Unit/Fields/CatalogTest.php`).

### V5.2 Scope
**Depends on:** V5.1, V2.1
- `src/Fields/Scope.php` — `at(file, template, node, inside)`: what the template can read at that place, as `Variable`s (`src/Fields/Variable.php`): how it is written (`$entry`, or `sunrice_global('site')` for what is no variable), its shape, where it comes from, a line saying so, and whether it is sure to be there. Three sides:

  | From | What | How it is known |
  | --- | --- | --- |
  | the file | `$entry` and `$collection`; `$entries`; `$term`, `$taxonomy`; `$block`; `$query`, `$results` | The collections and taxonomies whose pages the template draws (`usedBy`); a block's fieldset is the file's name |
  | the file, for a partial | whatever it reads and does not set | From every `@include` of it in the site's templates (and `@includeIf`, `@includeWhen`, `@includeUnless`, `@includeFirst`, `@each`): what the include names for it, and everything else the including template has at that place, which Blade passes on too. An entry of one collection here and another there is an entry of either. With no include to go by, Sunrice's own names say what it is (`$entry`, `$entries`, `$term`, `$terms`, `$block`, `$collection`, `$taxonomy`); anything else is a plain value |
  | the place | the item (and key) of every loop around it | What the loop goes over: a field, `$entries`, a menu, `$component->entries` |
  | the place | what the template sets above it | `@php($image = …)`, the statements of a `@php … @endphp`, a condition that sets what it asks about (`@if ($excerpt = $entry->get('excerpt'))`: sure to be there inside it) |
  | the place | `$component`, inside `<x-sunrice::entries>`, `<x-sunrice::entry-filter>` and `<x-sunrice::terms>` | The tag's `collection` or `taxonomy` |
  | the site | `$sunricePage`, `$locale`, each global set, each menu | Everywhere |

  `inside` is for something that goes into the node, not in its place: a loop's item is there inside the loop, not where the loop stands.
- `src/Fields/Expression.php` — reads a PHP expression as far as it says what its value is, without running it: variables, the ways the catalog says values are read (`->get('h')`, `->h`, `['h']`, `->url('large')`), `??`, `?:`, `a ? b : c`, brackets, `sunrice_global()`, `sunrice_menu()`, `sunrice_entries()`, `collect()`, and what list methods give (`first()` one item, `take(3)` the list again, `count()` a number). Sunrice's own idiom for "the first of several, or the one there is" (`$x instanceof Collection ? $x->first() : $x`) comes out as the one. Anything it does not read is unknown, never a guess. It also takes a call's arguments and a written-out array apart, for the includes.
- A variable set to something that can't be followed, or looped over from it, is listed as a plain value: it can be printed, and nothing is offered from inside it.
- **Not followed:** what a layout gets from the template that extends it (Sunrice's layouts read `$sunricePage`), view composers, a variable set inside an earlier `@if` (it may not have run), `as [$a, $b]`, and Blade's `$loop`.
- `Catalog`: a collection's listing page text is now one member, `archiveText($locale)`, with `title` and `intro` inside; the terms above and below a term are of its taxonomy.
**Done when:** scope is correct at marked positions in every starter template (`tests/Unit/Fields/ScopeTest.php`: 25 places in the 19 starter templates, and a site's own templates with a repeater, a condition on a block, both components, `@each` and a partial that is passed a row; `tests/Unit/Fields/ExpressionTest.php`: over a hundred expressions).

### V5.3 Emitters
**Depends on:** V5.1, V1.4
- `src/Fields/Reading.php` — a value as it is read: the code, its shape, and what can go wrong reading it. It is built from a variable, a field at a time (`Reading::of($entry)->field($cover)->field($alt)`), and guards each step: `?->` after anything that may be nothing (every field of a blueprint may be, and a template shared by two collections reads fields only one of them has), and `(… ?? null)` around what is read from an array before reading on. `printed()`, `looped()` and `asked()` write it for a text, a loop and a condition.
- `src/Fields/Emit.php` — `inserts(reading, label, taken)`: the ways a value can be put into a template (`Insert`: a title, a kind, the Blade), the usual one first; `printed(reading)`: how it is written where a text goes, for a text control or an attribute.

  | Value | Usual insert | Others |
  | --- | --- | --- |
  | text, number, select, an unknown value | `{{ $entry->get('summary') }}` | only when it is set |
  | rich text | `{!! … !!}` | only when it is set |
  | yes or no | `@if (…) @endif` | only when it is off |
  | date | `{{ …?->translatedFormat('j F Y') }}`; the other forms are read on from it | only when it is set |
  | link | `@if (($more = …) && ! empty($more['url'])) <a href… target when new_tab>label, or the URL</a> @endif` | only when it is set |
  | file | `@if ($cover = …) <img src="{{ $cover->url('large') }}" alt="{{ $cover->alt }}"> @endif` | a link to the file; only when it is set |
  | several files, entries, terms | a loop with an image, a linked title or a linked name for each | only when there are any |
  | a select with several | `{{ implode(', ', …) }}` | a loop |
  | repeater | a loop with a `<div>` for each row, holding every field of the row that can be shown on its own | only when there are any |
  | flexible content | the loop that draws each block by its own template (`@includeFirst`) | only when there are any |
  | a menu | `<ul>` with a linked item each | |
  | group, entry, term, global set, collection, the page | none: its fields are picked from inside it | |

- An entry is linked only when every collection it may be of has pages for its entries; a term only when its taxonomies have term pages (`SunriceBridge::taxonomies()` now says). Terms that are not a field's value (an entry's own, a term's children) are put in the page's language first (`resolveFor($locale)`), as Sunrice's templates do.
- A value is held in a variable while it is shown, as Sunrice's own templates do (`@if ($cover = $entry->get('cover'))`), named after the field's label. A loop's item is named after one of them (`$photo` of Photos, `$article` of Related articles). Neither takes a name already in use at the place, nor one of Blade's or Sunrice's own (`$entry`, `$loop`, `$term`…): a loop over related entries that called each one `$entry` would leave the page with the last of them.
- What is written is always something the tree holds and `Fragment` takes: it is inserted with the `insert` operation like anything else.
**Done when:** every field type emits Blade that compiles and renders against workbench content with the field filled and empty (`tests/Feature/EmitRenderTest.php`: 287 pieces of Blade, every insert and every text of every field and of what is inside it, put into a template and drawn as pages: for a thing with everything, with what each has to draw; for a thing with nothing, where each has to draw nothing at all; and in the template two collections share, for a page that is no thing. `tests/Unit/Fields/EmitTest.php` and `tests/fixtures/emit/things.txt`: the Blade itself).

### V5.4 Fields panel
**Depends on:** V5.2, V5.3, V4.3
- `src/Fields/Picker.php` — the scope, the catalog and the emitter put together for one place: each variable there with what can be read from it under its headings, and for every value what it is, the ways it is inserted, and how it is written where a text goes. It reads on into files, links, dates, groups and the page's own entry, and stops at lists (they are looped over) and at an entry reached from an entry. A field only some of the collections have says so (`note`: "Not in Pages"). The names in use at the place are passed to the emitter, so a new variable takes none of them. A block's own fields come before its type, key and id.
- `GET {admin}/visual-editor/api/templates/{path}/fields?version=&node=&inside=1` → `{version, variables}`. `conflict` when the version is not the file's, `invalid` for a node that is not there, `rejected` for a template without a tree.
- **Insert → Fields** (`panels/FieldsPanel.tsx`, in the Insert panel beside **Elements**). The list is for where the next thing goes, as chosen under **Where**: going into a loop it has the loop's item, going beside the loop it has not. Three parts: *At this place* (loop items, what the template set, what a component found), *This template* (`$entry`, `$block`, what a partial is passed), *The site* (the page, global sets, menus; closed until opened). A click on a value inserts it the usual way and it becomes the selection, like anything inserted; **As…** on its row lists the other ways. A value with nothing to insert as itself (an entry, a group, a global set) opens instead. The field to find things looks through the way to a value ("cover alt"), its code and what it is.
- **Use a field…** in the Settings panel (`FieldChooser`), under the text of a text node, what an echo prints, and the value of each attribute: a list of what can be written as text at the selected node, to fill the control from. A text is replaced by the field's echo; an echo gets the field's expression, and is printed as HTML or not as the field wants; an attribute's value becomes `{{ … }}` (`lib/fields.ts`, `binding`).
- **What a value is bound to** is said above it: an echo of `$entry->get('cover')?->alt` shows **$entry › Cover › Alt text**, and so does an attribute whose value is that one echo.
- The Settings panel asks for the fields at each node that is selected: one request for a selection.
**Done when:** building the hero block from an empty file takes only clicks (`tests/Browser/editor.mjs`: a section, a heading and a paragraph from Elements, their words filled from the block's fields, the button's link from Fields, and the file then compared in full; an attribute filled from a field; a menu found by typing and inserted, with its item offered inside its loop and not beside it. `tests/Feature/FieldsApiTest.php` for the endpoint, `tests/Frontend/fields.test.mjs` for finding and binding).

### V5.5 Conditions and loops
**Depends on:** V5.4
- **A condition built from choices** (`BlockSettings`, `lib/builders.ts`). On an `@if` or `@elseif`: **Ask about a field…** lists every value at the place; choosing one writes "it is set". The value then shows as a chip with a list of what can be asked of it, and a field for what to compare with where that is needed:

  | Value | Can be asked |
  | --- | --- |
  | text, rich text | is set, is not set, is, is not, contains |
  | number | is set, is not set, is, is not, is more than, is less than |
  | yes or no | is on, is off |
  | date | is set, is not set, is in the past, is in the future |
  | list | has any, has none; a select with several values also: has |
  | anything else | is set, is not set |

  What is written is plain PHP (`$entry->get('price') > 100`, `! empty($step['label'])`), with the same care as the emitters. It is read back into the choices when it was written by them; a condition written by hand stays code, in the field below, and is never rewritten.
- **A loop's source from a list** (`BlockSettings`): **Loop over a field…** lists what can be gone over at the loop (list fields, `$entries`, menus) and writes it as the loop's source, keeping the item's name. A loop whose source is one of those says which.
- **Entries from choices** (`EntriesSettings`). On `<x-sunrice::entries>`: **From** (the collection), **In the order** (as the collection is set, newest or oldest first, by title, as ordered in the admin, by any field of the collection), **Show** (how many) or **In pages** with how many on each, and **Only those where**: filters of a field, a comparison and a value, written as the component's `where`. A `where` written another way is left as it is. `<x-sunrice::terms>` has **Of** (the taxonomy).
- **A component's tag takes attribute edits** (`Rules::isComponentTag`, `Attributes`): `setAttribute` and `removeAttribute` work on the opening tag of a component, bound (`:limit="3"`) or plain, changing only the attribute named. The tree lists a component's attributes on its tag, and the Settings panel shows them under **What it is given**. A component is still not an element: it has no tag to rename, no classes of its own, and is not marked when the page is drawn for the editor.
- `GET {admin}/visual-editor/api/site` → the collections with their fields, and the taxonomies, for those lists. The picker also says how each value is asked about, compared and gone over (`asked`, `compared`, `looped`).
- **Insert → Elements → Entries** now calls each entry `$item`, not `$entry`: on an entry's page `$entry` is the page's own, and the loop left it the last of the list.
- A menu loop needs nothing new: **Insert → Fields → The site → Main menu**.
**Done when:** a "latest three articles" loop and a menu loop are built without typing (`tests/Browser/editor.mjs`: the entries of a collection inserted, three of them, ordered, from another collection and back, all from lists, with the tag then compared; a condition wrapped round it, asked of a field, turned round and compared; a menu inserted from the list. `tests/Frontend/builders.test.mjs`: every condition written is read back; `tests/Unit/Blade/ComponentAttributesTest.php`: attribute edits on every component of every fixture). **Milestone M3.**

---

## Phase 6 — Styles

### V6.1 CSS round-trip test
**Depends on:** —
**Status:** done. The answer is a small rule locator of our own and no CSS library; numbers in `spike/CSS_RESULTS.md`.

The same question as the Blade spike, for CSS: can a rule be found by selector and one declaration changed with nothing else touched?

- `src/Css/Stylesheet.php` reads a CSS file for where things are in it: `Rule`s (style rules, at-rules with a block and without, rules nested in rules) and the `Declaration`s of each, with byte offsets. It is no CSS parser: it does not know what a property means. It minds what can hide a `;` or a brace: comments, quoted text, brackets, `url()` without quotes, escapes, custom properties whose value holds braces.
- `styling('.card')` gives the rules one of whose selectors is exactly that, under any `@media` or the like; `Rule::within()` says under which.
- Four changes, each a cut into the text: `withValue()`, `withDeclaration()` (written as the declarations before it are: on its own line with their indent, on the same line, minified), `without()` (with its line when the line was its own), `withRule()` (at the end of the file). The file's line endings and byte order mark stay.
- After every change the file is read again, and the change is refused with `CssRejected` unless the file then says what it said before plus the one thing meant. A value has to be whole on its own: `red; } body { display: none` is refused, and so is an unclosed quote, bracket or comment, wherever in a file it would go.
- `composer spike:css` (`spike/css.php`) runs every kind of change over the fixtures in `tests/fixtures/css/` (the starter theme as written and minified, edge cases, nested rules, a byte order mark with Windows line endings, a file that is never closed) and, when `spike/css-fetch.sh` has fetched them, over normalize.css, Pico, Bulma and Bootstrap as written and minified. `npm run test:css` (`spike/css-browser.mjs`) then puts the same files and the same changes to a browser: is every rule the browser has one that was found, in the same order, and after each change has exactly the rule meant changed for it?
- It also tries the one maintained CSS library for PHP, `sabberworm/php-css-parser`: parse a file and write it out again. None of the files comes out as it went in, and the library keeps no offsets to cut at instead. A library is therefore not an option for changing a file in place (§2).

**Tests:** `tests/Unit/Css/StylesheetTest.php`: what is read from the fixtures and where; each change on small cases written in every style; every declaration of every fixture set and taken out; the refusals. CI runs `composer spike:css` (with other people's stylesheets on PHP 8.4) and `npm run test:css`.

### V6.2 CSS panel
**Depends on:** V6.1, V4.2
**Status:** done.

For a selected element, **Settings → CSS** shows the rules of the theme's stylesheets for one of its classes and changes them in place.

- **Which files.** `Css\ThemeRepository`: every `*.css` under `public/sunrice-theme` (found by listing the directory, so a path can't lead elsewhere; up to 2 MB), and the editor's own stylesheet, `theme_css` in the config (`editor.css`), which may not exist yet.
- **Which rules.** `Css\Styles::for($class)`: the rules one of whose selectors is the class itself (`Selector::forClass()` writes it as CSS does, `sm:flex` → `.sm\:flex`), then the rules that only mention it (`.card h2`, `.card:hover`), each with the at-rules it is inside, its line and its declarations. The first class of the element is shown; the others are a click away.
- **Changes.** `Css\Changes::run($source, $changes)` is the one way a change from the editor reaches a CSS file: `set`, `rename`, `remove` (a declaration, named by the byte it starts at), `add` (to a rule), `rule` (a new one at the end). A list names places of one version of the file and is applied from the end of the file to its start, all or none; a refusal says which change (`CssRejected::$change`). `Stylesheet::withProperty()` is new for `rename`.
- **Saving.** `Actions\SaveStylesheet`, as for a template: only onto the version the change was made for, the previous file kept in `storage/app/sunrice/template-backups/…/sunrice-theme/`, written in one step, a line in the activity log. The lock and the write are shared with `SaveTemplate` (`Support\Files`).
- **A class without a rule** gets one in the editor's own stylesheet, which the first rule creates. When the page does not link that file, the panel says so and `Actions\LinkStylesheet` adds the link to the layout's `<head>`, after the stylesheets linked already and written as the starter writes its own (`@if (is_file(…)) <link … ?v={{ filemtime(…) }}> @endif`). That is a change to the layout like any other: saved through `SaveTemplate`, with its undo.
- **API** (`Api\StyleController`): `GET styles?class=`, `POST styles/files/{path}/changes`, `PUT styles/files/{path}/source` (the whole text: undo and redo), `POST styles/link`. Refusals as for templates; both controllers answer through `Api\AnswersAsJson`.
- **On the page.** A stylesheet change does not draw the page again. The editor tells the bridge the version of each stylesheet (`styles`); the bridge puts the version into the address of each `<link>` to it, fetches the new copy beside the old and drops the old once the new has loaded, and says which stylesheets the page links. A browser that would have kept its copy of an unchanged address can't.
- **In the panel** (`panels/CssPanel.tsx`, `lib/styles.ts`): a row for each declaration, property and value each a field that saves when it is left; an empty row adds one, or several pasted as CSS writes them; `×` takes one out. Changes wait their turn, each made on the file as the one before left it, so typing on while one is being saved loses nothing. `↶ ↷` beside each file undo and redo its changes (per file, like templates; up to 600 KB, beyond which a file is not sent back to keep).

**Tests:** `tests/Unit/Css/ChangesTest.php`, `SelectorTest.php`; `tests/Feature/StylesApiTest.php` (what is listed; a change changes one declaration and nothing else; the editor's stylesheet is made; conflicts, refusals, paths that lead elsewhere; undo; the link in the layout, and the page drawn with it; who may); `tests/Frontend/styles.test.mjs`; the bridge check (a stylesheet served as cacheable is still shown as it is now); six steps of the browser check, from a value changed on the page as it stands to a new rule linked from the layout.

**Not in this task:** fields in place of typed CSS (V6.3), writing into a breakpoint's `@media` (V6.4), a rule added anywhere but the end of the editor's stylesheet, and elements whose classes Blade writes.

### V6.3 Style panel
**Depends on:** V6.2
**Status:** done.

**Settings → Style**: a control for each of the declarations most often written, in six groups (layout, size, spacing, text, background, border), each reading and writing one declaration of the rule for a class.

- **Which rule.** `targetRule()`: the rule for the class itself outside any `@media`; where several are (one in `app.css`, one in `editor.css`), the last, whose word counts over the others'. The tab says which file and line it writes. A class without a rule gets one in the editor's own stylesheet from the first thing set (V6.2's `create`).
- **The controls** (`lib/styleFields.ts`, `STYLE_GROUPS`) are data: a property, a label, a kind (`text`, `choice`, `colour`), and for some `when: 'flex' | 'grid'`, shown only for an element laid out so. Adding a control is adding a line there.
- **Reading.** `declared()`: what the rule says for the property, the last time it says it. What is typed is CSS as the file would have it; nothing is parsed into numbers and units, so `clamp()`, `var()` and `calc()` are as good as `1rem`.
- **Writing.** One edit, `{kind: 'style', property, value}`, resolved when its turn comes into V6.2's changes: `set` where the rule declares the property, `add` where it does not, `remove` when the control is emptied. Nothing new on the server.
- **What the page has.** An empty control shows in grey what the element has for the property from elsewhere (another rule, the browser). The editor asks the bridge (`computed`, with the element and the properties); the bridge answers from `getComputedStyle`, and again whenever a stylesheet has been fetched anew. Not for what is short for many things at once (`border`, `box-shadow`, `background-image`).
- **Colours** are typed, or picked: the picker starts from the colour set (or the page's) when it is a plain one, and hands the colour on when it is done with, not for every shade on the way.
- The tabs are now **Settings · Style · CSS**; Style and CSS share the choice of class, the note about a stylesheet the page does not link, and the queue changes wait in.

**Tests:** `tests/Frontend/styleFields.test.mjs` (the target rule, what a rule declares, when flex and grid controls show, colours, and each control's edit as changes); the bridge check (what the page has, said again after a stylesheet changed); three steps of the browser check (what is set and what the page has; one declaration from each kind of control, on the page and in the file with nothing else changed; emptying a control, the same rule in the CSS tab, and its undo back to the file as it was).

**Not in this task:** a side of a margin or a padding on its own (the shorthand is typed whole), units as a separate choice, and rules inside `@media` (V6.4).

### V6.4 Breakpoints
**Depends on:** V6.3
**Status:** done.

The page at several widths side by side; a style set at a width is written under the `@media` condition for it.

- **Widths** are a setting (`widths`: Desktop 1280, Tablet 768 with `(max-width: 1024px)`, Mobile 390 with `(max-width: 640px)`), read through `Support\Widths`, which keeps what can be used of whatever the setting holds. The editor's page gets them as a prop.
- **The view** (`lib/widths.ts`): as wide as the room (as before), one width, or all side by side. `layout()` gives each frame its width in the page's own pixels and the size it is shown at; frames side by side share one scale. A frame is as large as its page and drawn smaller with a CSS transform; the outlines are measured in the page's pixels and multiplied by the scale, so their labels stay readable.
- **One frame is edited, the others are looked at.** The edited frame is the pair that was there before (the page and its spare), with the bridge, the outlines and the swap after a change. The frames beside it show the same address; they draw the page again after a template change and are told of the stylesheets like the edited one, and what they say is not listened to. **Edit here** makes another width the edited one by handing the widths around: no frame moves in the document or loads anew (a frame that is moved does), `order` says where each is shown.
- **Which `@media`.** `lib/media.ts`: `widthRange()` reads a condition as a range of widths, or as none when it is about anything else (print, a colour scheme, a list). `breakpoints()` are the width conditions the theme's stylesheets use (`Styles` now lists the `@media` blocks at the top of each file); `mediaFor()` picks, for a width, the one for the fewest widths that applies. When none does, the width's own `media` from the setting. **For** in the Style tab shows it and lets another be chosen, or every width.
- **Writing.** The Style tab's edit carries the condition. With a rule for the class directly inside such a block, it is changed like any other. Without one, the first thing set makes it (`createFor()`): in the stylesheet the class's own rule is in and after that rule, so that it counts over it: at the end of a block for the condition that comes later in the file (`{type: "rule", in: <block>}`, `Stylesheet::withRuleIn()`), else in a new block at the file's end (`{type: "rule", media: "@media …"}`, `withRule(…, $media)`). Both are read back and refused unless the file says what it said before plus the one rule, inside its block.
- What the page has (the grey values) is asked again by the bridge when the frame changes width.

**Tests:** `tests/Unit/Css/StylesheetTest.php` (a rule added to every block of every fixture, and only there; each way of writing a block; refusals) and `ChangesTest.php`; `tests/Feature/StylesApiTest.php` (the blocks listed; a rule into a block, and into a new one) and `EditorPageTest.php` (the setting); `tests/Frontend/widths.test.mjs` (the layout, conditions as ranges, which applies, where a rule for a width goes); the bridge check (the page's values said again at another width); five steps of the browser check: one width, side by side, a style for narrow pages that shows there and nowhere else, another width edited without a page loading, a template change drawn in every frame.

**Not in this task:** the frames beside are not scrolled along with the edited one; a condition that is a list (`…, print`) or about more than width is not offered; a rule inside a block inside a block is changed in the CSS tab only.

---

## Phase 7 — Permissions, agents, release

### V7.1 Permissions
**Depends on:** M3
**Status:** done.

Three permissions, registered with `Sunrice::registerPermission()` under "Visual editor": `visual-editor.open`, `.edit`, `.code`. Each includes the ones before it (`Support\Access::may()`); Super Admins pass through Sunrice's own gate.

- **Routes.** Reading takes `open`; everything that writes a file takes `edit` (`EditorAccess::class.':edit'`). The editor's page gets `can: {edit, code}` and offers no more than that: without `edit` everything is there to look at; without `code` the code panel shows and does not take typing, "As code" is gone, and controls that are typed code (what an echo prints, a condition, a loop's source, an include, an attribute written in Blade) are shown and not changed, with the lists beside them still to choose from.
- **The line between `edit` and `code`** is drawn on the server, by `Guard\CodeGuard`, for operations and for whole texts alike: *the template runs no PHP afterwards that it did not run before, except code the editor writes itself.* Two checks, the second not relying on the first:
  1. **What may be new.** Each operation is looked at for the code it brings. It counts only when it is exactly what the editor offers at that place: an insert from the picker (`Picker::at()` for that node) or from the Insert library; an expression the picker lists, or a text written out; an attribute bound to such an expression; a condition `condition()` in `lib/builders.ts` builds about a value at the place, compared with a plain value (`Guard\Literal`); a loop over a list at the place under a plain name; one of the four wrappers; for Sunrice's own components, plain values. What was typed counts for nothing.
  2. **What is new.** `Guard\Compiled::islands()` compiles the template before and after with the application's Blade compiler and reads the result with PHP's tokenizer: each run of code between `<?php` and `?>` is a piece. Every piece after the change has to be one from before, or one that compiling what (1) counted gives. This is the check that holds whatever (1) or the rest of the package gets wrong: PHP that the compiler would not produce is not PHP the page runs.
- Blade reads a template's text before PHP does, and takes a PHP tag, an echo or `@php` for its own wherever it stands, inside a quoted text too. So a "plain value" has none of `<?`, `?>`, `@`, `{{`, `}}`, `{!!`, `!!}` in it, nothing counted in (1) may hold `<?`, and a condition or loop that compiles to more than one piece of PHP is not counted.
- **Whole texts** (`PUT …/source`) from someone without `code`: taken when they run nothing new (static edits, and code rearranged), or when the template was exactly that text within the last twelve hours (`Templates\Backups::had()`): their undo and redo.
- **For everyone:** `Syntax::brokenBy()` now refuses a change that makes Blade pair a `@php(…)` with an `@endphp` further down. Blade compiles that to PHP that parses, runs none of the code between the two and prints it on the page. Found while testing the guard; the Insert library's "PHP" block did it in any template with a `@php(…)` above.
- The Insert library leaves the "PHP" block out for those without `code`.
- **A fault in Sunrice worked around.** `SyncPermissions` creates a package's permission again on every sync after the first and fails ([sunrice-cms#39](https://github.com/littleboy130491/sunrice-cms/pull/39) mends it). Until that is released, `Support\RegisteredPermissions` is the registry the sync is handed: the same, less the packages' permissions that exist. It is the one file besides `SunriceBridge` that touches Sunrice's internals.

**Tests:** `tests/Feature/CodePermissionTest.php`, 226 tests: who may what; fifteen pieces of code against each operation that could carry one (inserted, as text, as an attribute, as a name, as an expression, a condition, a loop, a node rewritten, a wrapper, a whole text, a component's attribute): none runs, and each is refused or written as text; texts Blade would make something of inside a quoted value; and the other side: everything the Insert library and the picker offer goes through (every insert of every value at two places, every expression, conditions of every kind for every value, loops, wrappers, the Entries controls), and undo and redo. `tests/Unit/Guard/CompiledTest.php`: what compiles to which pieces; what is a plain value. Three steps of the browser check as a seeded *Designer* (`designer@example.com`).

**Not in this task:** what `edit` allows is not narrowed further (no "may not add scripts"); permissions are per person, not per template.

### V7.2 MCP tools
**Depends on:** V2.3, C3
**Status:** done. Sunrice merged [sunrice-cms#40](https://github.com/littleboy130491/sunrice-cms/pull/40) (C3) on 11 October 2026: on a Sunrice from that day on, the tools reach agents.

Two tools for AI agents, so that an agent makes checked edits to a template in place of rewriting the file.

- `get_template_tree` (`Mcp\GetTemplateTree`, read-only). Without a path: the templates, and whether each opens as a tree. With one: its version and its tree, as `Mcp\Tree::compact()` gives it: every node with its id, what it holds and what may be done to it, without what only a screen needs. Takes `visual-editor.open`.
- `edit_template` (`Mcp\EditTemplate`): a path, the version it was read at, and a list of the operations of V1.7. Applied all or none through the same three steps as the editor's API: `Operations::run`, `CodeGuard` for a user without `visual-editor.code`, `SaveTemplate`. Answers with the new version, the tree and the node to go on from; a refusal says why, and which operation. Takes `visual-editor.edit`, and nothing where the site keeps agents from templates (`sunrice.mcp.templates`).
- The tools extend laravel/mcp's `Tool`, which Sunrice requires. They run as the token's user, so what they write is in the activity log under that user with the source "ai", as for Sunrice's own tools.
- **Registration.** `SunriceBridge::registerMcpTools()` calls `Sunrice::registerMcpTool()` when Sunrice has it. That method is C3, asked for in V0.4 and now written as sunrice-cms#40. On a Sunrice without it the tools are there and tested, and not listed to agents.
- Like the editor itself, the tools exist only where the editor is on (local by default).
- **Tried on Sunrice's own server** (during V7.4), on a new Laravel application with sunrice-cms#40 checked out and the package installed as its page says, over `php artisan mcp:start sunrice` as a user with `visual-editor.edit` only: both tools are in `tools/list` beside Sunrice's 37; `get_template_tree` answers with the tree; `edit_template` adds a class and one line of the file differs; code inserted is refused with the guard's reason and nothing is written or run; the change is in the activity log under that user, source "ai".

**Tests:** `tests/Feature/McpToolsTest.php`, 22 tests against a server of the test's own holding the two tools: the list and the tree; one change that changes one thing; several together and going on from the answer; a version that is not the file's; all or none; what does not compile; templates that are not the site's; each permission; code only for a user who may edit code; the site's switch; every operation named in the tool's description.

**Not in this task:** tools for stylesheets (`Css\Changes` would be the same pattern), and a tool for what the picker lists at a node.

### V7.3 Docs
**Depends on:** V7.1, V7.2
**Status:** done.

Seven pages in `docs/`, written for someone installing the package on a site, and linked from the README:

- `installation.md` — requiring it from GitHub, publishing the admin script, the permissions, where it is on, every setting, updating, removing.
- `how-it-works.md` — lossless or code, the tree, operations, what is checked before a file is written and in which order, what a save can't know, the editor render, undo, the two round-trip tests.
- `fields.md` — what the lists hold at a place and where that comes from, the Blade a click writes for each kind of value, conditions, entries.
- `styles.md` — which files, the CSS and Style tabs, a class without a rule, widths and `@media`, what is refused, sites that build their CSS.
- `permissions.md` — the three permissions, why code is separate, what `edit` can and can't do, how the line is held, and what to know before giving someone `edit`.
- `agents.md` — the two MCP tools, the operations, what is checked, who may.
- `limits.md` — what the editor does not do, by area.

The plan named four pages. Styles, permissions and agents got their own, since each is what one kind of reader comes for, and the other pages link to them.

The README's "Using it" now points to these pages in place of saying the same more briefly.

**Tests:** `tests/Unit/DocsTest.php` keeps the pages from going stale where a test can tell: every link between the pages, to a file of the repository and to a heading resolves; every key of the config file is in the settings table; every operation the package takes is in the agents page; the three permissions are named as `Access` names them.

### V7.4 Release
**Depends on:** V7.3
**Status:** prepared; not tagged. Tagging waits for the install on a real project, which only someone with a client project can do.

- `CHANGELOG.md` — 0.1.0: what it does, how it keeps files safe, the limits to know first. Marked "not tagged yet".
- **The installation page, tried.** `.github/install-check.sh` makes a new Laravel application, installs Sunrice as its own page says (with starter templates and three demo articles), then does what `docs/installation.md` says with this checkout in place of `dev-main`: require, publish, sync the permissions (twice: Sunrice's sync used to fail the second time). It then checks:
  - what Composer is given (`git archive`): the built script is in it, the tests and the workbench are not;
  - where it is on: routes on `local`, none on `production`, there again with `SUNRICE_VISUAL_EDITOR=true`, none on `local` with `false`;
  - the site as installed (`.github/install/InstalledTest.php`, copied into the new application and run on its own database): the script published and the three permissions made; a Super Admin opens the editor; a page is marked for the editor and for nobody else; someone without a permission is kept out; a designer adds a class and only that changes in the file, with a backup; a designer's code is refused and does not run; a declaration is changed in the theme's own CSS file and only its value's bytes differ;
  - removing: `composer remove`, no routes left, Sunrice's commands still run.

  It is the `install` job of the `tests` workflow, on every pull request.
- **No version number in the files.** A Composer package's version is its tag; a number written into `composer.json` would only be able to disagree with it.

**What a new application showed.** Nothing had to change in the package. The page had four things wrong before V7.3 was merged, found by reading it against the code; run for real, the steps held as written.

**Left for the release itself** (for a person, in this order):

1. Install it on a staging copy of one real client project (`docs/installation.md`), use it there for a day, and fix what that turns up. This is the step the plan asks for and nothing here could stand in for it: the package has only seen Sunrice's starter templates, and a real project has templates nobody wrote with an editor in mind.
2. Decide the licence (open decision 4 in `SPEC.md`; Sunrice says MIT in its `composer.json`). Add a `LICENSE` file and `"license"` to `composer.json`. Until then the repository is public without one, which lets nobody else use it.
3. ~~Merge sunrice-cms#39 and #40.~~ Merged on 11 October 2026. What they made unnecessary stays until Sunrice tags a release this package can require, since a site may still have a Sunrice from before: `src/Support/RegisteredPermissions.php`, and the check for `registerMcpTool()` in `SunriceBridge`. With a tag, both go and `composer.json` asks for that version.
4. Put the date on 0.1.0 in `CHANGELOG.md`, tag `v0.1.0`, and submit the repository to Packagist. The installation page's `composer config repositories…` line and `:dev-main` then come out.

### After V7.4: five findings from a scan
**Status:** done.

A scan of the repository by an AI agent reported five things. Each was checked against the code before anything was changed; all five were there, two of them weighing differently from how they were reported.

- **Copy to site wrote the template where it stands** (`Actions\CopyPackageView`): made empty, then filled by one write nobody checked. From the moment that file is there the site uses it in place of Sunrice's view, so a page drawn in between got an empty template, and a write cut short left half of one that could not be asked for again. Now the check and the write are under the lock a save takes, and the write is `Files::replace()`: the file is there whole or not at all.
- **A whole text from someone without `code` was checked against the file as it was a moment before, not against the version it then replaced** (`TemplateController::source()`). Sent with the version the file was *about* to have, it could be checked while the template still had some code and saved once someone had taken that code out: the code came back. Now the text compared with has to be the version named, as for operations. A test lands the other save in the middle of the check (`CodePermissionTest`); it fails on the code as it was. Reported as medium; in effect less, since undo already lets such a person write the template as it was within twelve hours, on purpose. That is the wider question, and it is not changed here.
- **A tab in the page's address** (`EditorController::sitePath()`): `?url=/<tab>/example.com` passed as a path of the site, and a browser drops the tab and reads `//example.com`. The frame was never sent there (`editorAddress()` checks where an address leads), but the **View page** link pointed there until the page had loaded. Control characters are refused, and the link is made from the address as the screen itself checked it.
- **Pages drawn for the editor at once took each other's files away** (`EditorEngine::replace()`). Before writing its copy of a template, each request removed every file of that template's copies, which included the one another request was halfway through writing. Reported as low; it weighs more, since side by side the editor draws three frames at once after every change, each compiling the changed template. The checks here never saw it because `testbench serve` answers one request at a time. Eight requests at once, sixty times over, against a server with eight workers: 4 in 200 failed before (`rename(): No such file or directory`), none in 480 after. A copy's own files are now left alone, copies of earlier versions go once they are a minute old, and a directory two requests both find missing is made by one and found by the other (`Files::directory()`, also used for the lock and the backups).
- **An answer that came late was shown as the open template** (`Editor.tsx`: a save, an undo, a change). With another template opened while the server was still answering, the panels showed the first template's tree under the second one's name, and the next change was refused as made for another file. The answer's history and the page are still taken; the panels take it only when it is for the template that is open. A step of the browser check holds an answer back, opens another template and lets it go; it fails on the screen as it was.

**Sunrice's main moved while this was open.** sunrice-cms#39 and #40 were merged the same afternoon. Static analysis then failed on every branch of this package: the check that `Sunrice::registerMcpTool()` exists is always true against a Sunrice that has it. The check stays, for a site whose Sunrice is an older checkout (there is no tagged release to require), with a note for the analyser. `McpToolsTest` now also asks Sunrice itself whether the two tools are among those it offers.

---

## Phase 8 — Code mode, and sites that build their CSS

Asked for in October 2026, after Phase 7: a code editor as a second way of working beside the visual one, with the page, the templates of the page and the fields still there; and a way to work on a site whose CSS is Tailwind built by Vite. `SPEC.md` has both (*Editing as code*, *Sites that build their CSS*).

Two things were decided before any of it was built:

- **CodeMirror 6, not Ace or Monaco.** Monaco is several megabytes and built for an application of its own. Ace would do, and is one file; CodeMirror is smaller for what is used here, is put together from parts (no language package is needed, see §2), handles touch and screen readers, and maps a place in the saved text to its place in the text being typed, which click-to-line needs. Bundled from source with everything Phase 8 uses it comes to 339 KB, 113 KB gzipped: more than the whole editor today (107 KB), so it is a script of its own, fetched when Code mode is first opened.
- **The editor never runs a dev server.** `npm run dev` stays in the developer's terminal, where it can be seen and stopped. A watcher started from a web request outlives the request, holds a port, and is one more way for the admin to run things on the server. A build is different: one command from the site's configuration, run once, with an end.

### V8.1 Vite and Tailwind, as they are
**Depends on:** V7.4
**Status:** done.

What the editor does on a site with Laravel's Vite plugin and Tailwind 4, found out on one before anything is built for it.

- `.github/vite-check.sh`, the `vite` job beside `install`: a new Laravel application (which comes with Vite 8 and Tailwind 4), Sunrice, the package, and the layout given `@vite([...])`. `.github/new-site.sh` now makes the site for both checks. `.github/vite/ViteTest.php` runs inside the site, a few tests at a time between builds; `.github/vite/browser.mjs` drives the editor in Chrome.
- **What it found, all of it without a change to the package:**
  - *A build.* A class Tailwind had no rule for is added to the header through the editor's API. The built CSS does not have it; after `npm run build` it does. Tailwind finds classes in `resources/views/sunrice/` by itself.
  - *The dev server.* With Vite running, a page drawn for the editor links the dev server and still carries its marks, and a second class added through the API is in the dev server's CSS within a moment, with no build.
  - *The layout.* It opens as a tree, with `@vite(…)` as one piece of code.
  - *In a browser, with the dev server running:* a class typed into **Settings** colours the element in the frame, and while Vite reloads the page on top of the editor drawing it again, the element stays selected and the page stays marked.
- **What it found in Sunrice.** The admin was blank for as long as the site's dev server ran: its page asked that server for the admin's own script. Mended in [sunrice-cms#41](https://github.com/littleboy130491/sunrice-cms/pull/41) (one line: the admin looks for a hot file of its own). The browser part of the check was run against that branch and passed; on a Sunrice without it the check skips that part and says so, so it starts running by itself once #41 is merged.
- The dev server will not start where `CI` is set unless `LARAVEL_BYPASS_ENV_CHECK=1` says it is meant; the check sets it.
- `docs/styles.md`, *Sites that build their CSS*: rewritten from this. `docs/limits.md` and the README follow.

**Not here:** anything that makes a class show without a dev server (V8.5), or builds (V8.6).

### V8.2 A code editor in place of the text field
**Depends on:** V8.1
**Status:** done.

- `resources/js/code/index.ts` — the editor, with no React in it: `mount(parent, {text, readOnly, indent, label, onChange, onSave})` → `{text(), setText(), setReadOnly(), focus(), destroy()}`. CodeMirror with line numbers, undo of its own, search and replace (Ctrl/⌘+F), several cursors, bracket matching, Tab indenting by what the file itself uses, Ctrl/⌘+S. Its colours are the admin's own, so it follows light and dark.
- **Colours from `lib/highlight.ts`**, which knows Blade: `code/text.ts` turns its tokens into stretches of the text, and the editor marks them with the classes the code panel already had. No language package, and `@codemirror/autocomplete` waits for V8.4, which is what uses it.
- **A file keeps its line breaks.** The text field made every line break `\n` once something was typed (a browser's text field does). The editor writes `\r\n` in a file whose lines all end that way, and in a file that mixes the two treats `\r` as a character of the line, shown as one. Either way what is saved differs from the file only by what was typed.
- `build.mjs` builds it into `dist/code-editor.js`: 331 KB, where the editor's own script is 108 KB. `EditorController` sends its address (`codeEditor`); `lib/codeEditor.ts` fetches it once with `import()`, the first time code is shown. The admin's other pages never fetch it.
- `panels/CodePanel.tsx` — the bar, Save, Revert and what a refused save says are as they were. Under them is the code editor (`RichCode`), or the plain field of before (`PlainCode`) when the script has not come within three seconds or can't be fetched. Once the plain field is shown it stays: nobody has the field changed under their hands.
- The editor can be asked from the element it is in (`element.sveCodeEditor`): it draws only the lines in view, so the browser check reads the text from the editor and not from the page.
- `package.json`: `@codemirror/state`, `view`, `commands`, `search`, `language`.

**Tests:** `tests/Frontend/code.test.mjs` (line breaks, indentation, where the colours are). The browser check's steps for the code panel on the new editor: the footer as the file has it, coloured and numbered; typed at the end and saved with Ctrl+S; find and replace, undone with the editor's undo; a refused save and Revert; a footer written with `\r\n` typed in and saved, with only what was typed different; the plain field when the script is kept from arriving, saving from there; read-only for the designer, who types and changes nothing. `EditorPageTest`: the address is sent, and the admin's own pages do not carry the script. The install check: the script is in what Composer is given and is published.

**Where this was built** the npm registry can't be reached, so CodeMirror was bundled here from its source on GitHub to try it in a browser; the `dist/code-editor.js` that is committed is the one CI builds from the packages.

### V8.7 Panels that hide and show
**Depends on:** V8.2
**Status:** done. Asked for while V8.2 was being built, and built straight after it, before Code mode, which is laid out with it.

Everything beside the page can be put out of the way and brought back with one click (`SPEC.md`, *Room for the page*).

- `lib/layout.ts` — how the editor is laid out for whoever uses it: which side is shown, which panels and sections are folded. Read from the browser's store when the editor opens and written when it changes; anything unreadable there is "everything shown".
- `panels/Panel.tsx` — `Folds`, a context the editor fills from the layout, and `useFold(name)`. `Panel` has its title as a button that folds it to the title; `Group` (a fieldset of controls) and `Section` (a titled part of a list) fold the same way. What is folded is hidden, not taken away, so it keeps its state. A panel's button is named "Layers panel": a button inside a panel often has the panel's own name ("Settings", "Insert").
- Folding everywhere there is a title: the five panels; the groups of **Settings** and **Style**; the kinds in **Insert**; the three origins in **Fields**.
- **Three buttons in the top bar**, drawn as the editor with one part filled in: the panels on the left, the code under the page, the panels on the right. `aria-pressed` says what is shown. A hidden side's column is zero wide, and the page takes the room; the outlines follow, as they do when the window is resized.
- **Insert** in the bar shows the left side and opens the Insert panel when either is out of sight, in place of switching inserting off.
- No keys for it: every combination that would suit is the browser's own for something else.

**Tests:** `tests/Frontend/layout.test.mjs` (folding, sides, what is remembered, a store with nonsense in it or none at all). Five steps of the browser check: a panel folded and opened with its selection kept; both sides hidden, the page wider by their room and the outline still on its element; the editor found as it was left after opening it again; the left side brought back by **Insert**; the code shown and hidden from the bar and a group of the Style tab folded alone.

### V8.3 Code mode
**Depends on:** V8.2, V8.7
**Status:** done.

A second way of working, chosen in the top bar: **Visual | Code** (`mode` in `lib/layout.ts`, kept with the rest of the layout).

- **The code beside the page.** In Code mode the code panel stands left of the page at its full height and is always there; the line between them is dragged, or moved with the arrow keys, to give the code a fifth to four fifths of the room (`share`). The sides and their panels stay as they are, to show or hide as in Visual mode: the templates of the page on the left, the selection's settings on the right.
- **Tabs.** Every template opened since the editor was is a tab in the code panel's bar, in both modes. What is typed in a template's code and not saved is kept per template (`drafts` in `Editor.tsx`), so opening another no longer asks whether to throw it away: the tab shows a dot, and the text is there on coming back. Closing a tab with such text asks first; leaving the editor with any asks, as before.
- **One editor, a text per tab** (`code/index.ts`, `show(name, …)`): a text that is left is put aside with its undo, its cursor and where it was scrolled to, and is the same on coming back. The panel stays in place while another template is being read, showing the one before read-only for that moment, so the editor is not made anew for every template.
- **Click to line.** A click on the page, or on a layer, sends the cursor to that node's place in the file (`CodePlace`: the node's first byte). The editor keeps, for each text, the file as saved and every change since (`since`), and carries the place through them (`ChangeSet.mapPos`): with a line typed above and not saved, the cursor still lands on the element. In Code mode the keyboard goes to the code too; with the code under the page it stays on the page.
- **Cursor to page.** A moment after the cursor stops in another node, that node is the selection: outlined on the page, marked in the layers, its settings on the right (`lib/tree.ts`, `nodeAt`). Places go back through the same changes.
- `code/text.ts` — `indexOfByte`, `byteOfIndex` (nodes count bytes, the editor characters), `difference` (the one change between two texts, for a text that comes with no record of how it was typed).

**Fixed on the way:** with the code open, going to another template while one had unsaved code threw that code away after a confirm. Now it stays with its template.

**Tests:** `tests/Frontend/code.test.mjs` (bytes and characters, the one change between two texts, the node at a place), `layout.test.mjs` (the mode and the share). Eight steps of the browser check: the code beside the page with the open template as a tab; a click on the page opening the template as a tab with the cursor on the element; text left unsaved in one tab while another is saved, and found as it was; a place clicked on the page found under a line typed above it; the editor's undo in a tab that was left; the element under the cursor outlined on the page; a tab closed, with a question first when it has unsaved text; the line dragged, kept when the editor is opened again, and moved by a key.

### V8.4 Fields and variables at the cursor
**Depends on:** V8.3
**Status:** done.

- `Template::at(int $offset)` — the place a position in the source is at, as places are said elsewhere: a node, and whether the position is inside it, after everything it holds, or where the node stands. A position in a tag, a directive's own line or a text is where that stands; between the arms of a block it is in the arm it comes after.
- `Scope::here()` — what goes *inside* a node goes after everything that is there, so what the node's own children set (`@php($x = …)`) is above the place too. That was missing for the visual editor's **Inside** as well, and is there for both now. `Scope::anywhere()` and `Picker::anywhere()` say what a template has without its tree: what the file is given, and what every template has.
- `POST templates/{path}/fields` with `{source, offset}` (`TemplateController::fieldsAt`) — the variables at a place in text that is not saved. The text is read and nothing else: not saved, not compiled, not run. A text that does not open as a tree is answered with `anywhere()` and `exact: false`. It takes `visual-editor.code` (`EditorAccess` now knows `:code`), as everything that types code does.
- **Fields beside the code** (`Editor.tsx`): in Code mode the right side has a **Fields** panel, the same list as **Insert → Fields**, asked for where the cursor is in the text as typed, a moment after the typing or the cursor stops. A click writes at the cursor what the picker writes, every line after the first indented as the cursor's line is (`lib/completions.ts`, `indented`). Not shown to someone who may not edit code, nor for Sunrice's own views.
- **Completion** (`lib/completions.ts`, `offersFor`; `@codemirror/autocomplete` in the editor): after `$entry->` or `$row['`, what that value has, each written whole and guarded as the picker writes it, in place of the variable and what was typed; after `{{ ` or `{!! `, everything at the place that can be printed, the place and the template before the site; after a `$` and the start of a name, the variables there. Nothing else is offered: no guesses at HTML or PHP.

**Tests:** `tests/Unit/Blade/PlaceTest.php` (seventeen positions: in a tag, a text, an echo, a loop's own line, an arm, at the end). `tests/Feature/FieldsApiTest.php`: a variable set on a line that is not saved is there below it and not above; a loop only just typed gives its item inside and not beside or in its own line; half-typed text still answers; text with no tree answers with what the file is given and says so; who may ask. `tests/Frontend/completions.test.mjs`. Three steps of the browser check: the list at the cursor with a line typed above it; a field and a link written at the cursor by a click, the link's lines at the cursor's depth; offers while `$block->` is typed, narrowed by typing on, and the one chosen written. The designer's step: in Code mode no list of fields.

### V8.5 Tailwind preview without a build
**Depends on:** V8.1
- `config`: `tailwind.preview` (`false`), `tailwind.entry` (`resources/css/app.css`), `tailwind.script` (the address of `@tailwindcss/browser`, version 4; a site may keep its own copy).
- `Rendering\Preview` — for a page drawn for the editor, and only then, when the preview is on and no dev server is running (`public/hot` is not there): the script, and the entry file's text as `<style type="text/tailwindcss">` so the site's theme applies. A dev server already gives the real thing, and the preview stays out of its way.
- The editor's top bar says **Preview CSS** while it is in use, with what that means on pointing at it.
- `docs/styles.md`: what the preview is and is not.
**Done when:** on the site of V8.1 with no dev server and no build, a class with no rule in the built CSS is styled in the editor's frame and not on the page a visitor gets (browser check in the Vite job).

### V8.6 Build
**Depends on:** V8.1, V7.1
- `config`: `build.command` (`null`: no button), `build.timeout` (120 seconds).
- Permission `visual-editor.build`, on its own (`Access::BUILD`): not part of `code`, and no part of it.
- `Actions\Build` — runs the configured command in the site's directory with Symfony's Process (which Laravel has), one at a time (the lock of `Support\Files`), stopped at the time limit. Answers with whether it ended well, how long it took and the end of what it printed. A command that can't be started says what was missing. A line in the activity log.
- `POST {admin}/visual-editor/api/build`. Nothing in the request names a command.
- **Build** in the top bar, there only when a command is set and the person may. After a template or a stylesheet is saved it reads **Build needed**, until a build ends well; not while a dev server is running. What the command printed opens under the bar when it fails.
- **Tools for agents:** none. An agent that may run commands has Sunrice's own tool for that.
- `docs/installation.md` (the settings, the permission), `docs/styles.md`, `docs/permissions.md`, `docs/limits.md`.
**Done when:** on the site of V8.1, a class added in the editor has no rule in the built CSS, **Build needed** shows, the button builds, the rule is there and the notice gone; with the command set to something that is not there, the button says so (Vite job, and `tests/Feature/BuildTest.php` with a command of the test's own).

### Later, not in this phase
- Tailwind's class names offered in the class field of the Settings panel, from the site's theme.
- Search across the site's templates.
- The stylesheet files of the theme opened in Code mode, as templates are.
