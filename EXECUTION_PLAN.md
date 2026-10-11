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

**npm (admin script only, all dev dependencies):** `typescript`, `esbuild` (pinned to an exact version, so every build of `dist/` is the same), `@types/react`, `@types/react-dom`, `playwright-core` (the browser check; drives the Chrome already on the machine). Named here at first and not used, each for the reason given in its task: `@dnd-kit/core`, `@dnd-kit/sortable` (Layers panel, V4.4), `codemirror`, `@codemirror/lang-html`, `@codemirror/lang-css`, `@codemirror/lang-php` (code panel, V3.4). React, Inertia and the admin's UI components come from `window.Sunrice` and are never bundled.

A CSS parser is deliberately not chosen yet: V6.1 decides it with a round-trip test.

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

Sizes are rough guesses for one developer working with coding agents. Phases 1 and 2 have no UI and can run alongside Phase 0. V5.1–V5.3 are backend only and can run alongside Phases 3 and 4.

**Where it stands:** Phases 0 to 3 are done and merged, which is milestone M1. Phase 4 is next. Where a task was built differently from what was first written here, its text below says what was built and why.

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
`src/Fields/Emit.php` — field plus variable → fragment, per the table in `SPEC.md`, always guarded. Three shapes where it applies: inline echo, element (image, link), structure (condition, loop).
**Done when:** every field type emits Blade that compiles and renders against workbench content with the field filled and empty.

### V5.4 Fields panel
**Depends on:** V5.2, V5.3, V4.3
Lists the scope at the selection, grouped by variable; search; click inserts the emitted fragment; a bind button beside text and attribute controls replaces the value with a field. Bound values show as chips. Shared templates show fields per collection and flag those some collections lack.
**Done when:** building the hero block from an empty file takes only clicks.

### V5.5 Conditions and loops
**Depends on:** V5.4
Builders that write the expression: a condition from a field (is set, equals, greater than), a loop from a list field, a collection query (`<x-sunrice::entries>`: collection, limit, order, filters) or a menu.
**Done when:** a "latest three articles" loop and a menu loop are built without typing. **Milestone M3.**

---

## Phase 6 — Styles

### V6.1 CSS round-trip test
**Depends on:** —
The same question as the Blade spike, for CSS: can a rule be found by selector and one declaration changed with nothing else touched, across `stubs/theme/app.css`, minified files, nested rules and media queries? Decide between a library and a small rule locator on the result.
**Done when:** results are written up like `spike/RESULTS.md`, and the choice is recorded in §2.

### V6.2 CSS panel
**Depends on:** V6.1, V4.2
Shows the rule for the selection's first class from the theme CSS files as text; saves in place; creates the rule in `public/sunrice-theme/editor.css` when missing and offers to add the `<link>` to the layout.
**Done when:** changing a declaration changes only that declaration in the file.

### V6.3 Style panel
**Depends on:** V6.2
Fields for layout, size, spacing, typography, background and border, each reading and writing one declaration of the same rule.

### V6.4 Breakpoints
**Depends on:** V6.3
Frames at several widths side by side; editing in a frame writes into that width's media query.

---

## Phase 7 — Permissions, agents, release

### V7.1 Permissions
**Depends on:** M3
Register `visual-editor.open`, `visual-editor.edit` and `visual-editor.code` with `Sunrice::registerPermission()`; `Access` checks them; without `code`, code nodes, expressions typed by hand, the code panel and Paste HTML are unavailable and the API refuses those operations.
**Done when:** a role with `edit` but not `code` cannot get PHP into a template by any API route (tested per operation).

### V7.2 MCP tools
**Depends on:** V2.3, C3
`get_template_tree` and `edit_template` (operations), so agents make checked edits. Needs C3 in Sunrice.

### V7.3 Docs
`docs/installation.md`, `docs/how-it-works.md` (lossless or code, what is refused and why, the editor render), `docs/fields.md`, `docs/limits.md`.

### V7.4 Release
Changelog, version `0.1.0`, tag. Install it on one real client project on a staging copy and fix what that turns up before tagging.
