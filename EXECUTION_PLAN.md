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
| Node identity | The node's start byte offset in that version of the file |
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

**npm (admin script only, all dev dependencies):** `typescript`, `esbuild` (pinned to an exact version, so every build of `dist/` is the same), `@types/react`, `@types/react-dom`, `playwright-core` (the browser check; drives the Chrome already on the machine). Added when first used: `@dnd-kit/core`, `@dnd-kit/sortable` (Layers panel, V4.4), `codemirror`, `@codemirror/lang-html`, `@codemirror/lang-css`, `@codemirror/lang-php` (code panel, V3.4). React, Inertia and the admin's UI components come from `window.Sunrice` and are never bundled.

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
**Depends on:** V0.1
The package needs three things Sunrice has but does not publish. Each is a small pull request in sunrice-cms; until it merges, `SunriceBridge` reaches the internal class.

| | Needed | In Sunrice today | Proposed public API |
| --- | --- | --- | --- |
| C1 | Template directories, the template map (which view each collection and taxonomy uses), safe path resolution, Blade syntax check, backups | `Sunrice\Mcp\Templates`, `WriteTemplate::backup()` | `Sunrice::templates()` returning a documented service |
| C2 | Field definitions of a collection's blueprint, a fieldset, a taxonomy and a global set, with fieldset includes expanded | Models and `BlueprintSchema`, undocumented | `Sunrice::schema()->collection($handle)` etc., returning plain arrays |
| C3 | Adding MCP tools from a package | `SunriceServer::$tools` is a fixed list | `Sunrice::registerMcpTool(string $class)` |

**Files:** `src/Support/SunriceBridge.php` — `templateDirectory()`, `themeDirectory()`, `packageViewDirectories()`, `templateMap()`, `syntaxError(string $blade)`, `backup(string $file)`, `fields(string $kind, string $handle)`, `globals()`, `menus()`. Uses the public API when the installed Sunrice has it (`method_exists`), the internals otherwise.
**Done when:** unit tests cover each bridge method against the workbench content, and the three issues are opened in sunrice-cms with the proposed signatures.

---

## Phase 1 — Template tree

The spike left `src/Blade/` with: `BladeScanner`, `HtmlLexer`, `TreeBuilder`, `Node`, `Template`, `Classifier`, `Edit`. This phase turns it into the library the editor calls. Every task adds its edits to the round-trip datasets: applied to every node they fit, in every fixture, checked against expected bytes.

### V1.1 Public surface and JSON
**Depends on:** V0.2
**Files:**
- `src/Blade/Template.php` — `open(string $source): self`, `version(): string`, `find(int $offset): ?Node`, `print(): string`, `toArray(): array`.
- `src/Blade/Node.php` — `toArray()`: `id` (start offset), `type`, `kind` (from `Classifier`), `name`, `label` (tag plus first class, or the directive with shortened arguments), `attributes`, `text`, `expression`, `editable` flags (`move`, `delete`, `attributes`, `children`), `children`.
**Done when:** `toArray()` of every fixture round-trips through `json_encode`, and every selectable node is found again by `find($id)`.

### V1.2 Attributes
**Depends on:** V1.1
`Edit::setAttribute`, `removeAttribute`, `addClass`, `removeClass`, `setId`, `setTag` (renames both tags). Values are HTML-escaped unless passed as a Blade expression. An attribute whose value contains Blade is replaced whole or left alone. Directives in the tag (`@class`, `@if … @endif`) are never rewritten; `setTag` and edits to other attributes still work.
**Done when:** each edit is a minimal change in the opening tag for static, dynamic, unquoted, valueless and absent attributes, on every element of every fixture.

### V1.3 Text, echoes and directive arguments
**Depends on:** V1.1
`Edit::setText` (exists; add HTML escaping and escaping of `{{` and `@` so typed text can never become Blade), `setExpression` (echo, keeping `{{ }}` or `{!! !!}`), `setEchoRaw(bool)`, `setArguments` (directive arguments, for conditions, loops and includes), `setCode` (replace a code node's text).
**Done when:** round-trip datasets pass, and a test proves text containing `{{ $x }}`, `@php` and `<script>` comes out inert.

### V1.4 Structure edits
**Depends on:** V1.2, V1.3
- `src/Blade/Fragment.php` — parses a snippet of HTML/Blade into nodes with the same builder; refuses what a file would be refused for.
- `Edit::insert(Node $parent, int $index, Fragment)`, `move(Node, Node $parent, int $index)`, `duplicate`, `remove` (exists), `wrap` (exists; take a fragment), `unwrap`.
- Indentation: an inserted or moved node takes the indentation of its new siblings; nothing else is reindented.
- Seam rule: extend `Edit::unsafeSeam` to an argument-less directive followed by `(`, which a space does not fix; refuse that edit with a message.
- Guards: arms of a block and the block's closer are not movable; a node cannot move into itself; nothing moves into a code node.
**Done when:** datasets pass, including move between parents and into and out of Blade blocks.

### V1.5 Code regions instead of refused files
**Depends on:** V1.4
When nesting fails, find the smallest run of sibling nodes that contains the failure and nests cleanly as a whole, and represent it as one `code` node; refuse the file only when that run is the whole file.
**Done when:** the `locked-…` fixtures open with one code node around the offending part, still print back identical, and their other nodes pass the edit datasets. Add fixtures from real client templates.

### V1.6 `@switch` as a visual block
**Depends on:** V1.4
Treat `@switch` like `@if`, with `@case` and `@default` as arms and `@break` kept at the end of each arm. This turns the two largest code regions in the starter templates (the form block and the filter form) visual.
**Done when:** `blocks/form.blade.php` and `partials/entry-filter.blade.php` have no code node from `@switch`, and edits inside the arms pass.

### V1.7 Operations
**Depends on:** V1.2–V1.4
- `src/Blade/Operation.php` — a serializable edit: `type` and parameters, naming nodes by id.
- `src/Blade/Operations.php` — `apply(string $source, array $operations): string`: opens the source, applies each operation, prints, reopens the result, runs the Blade syntax check, and throws `EditRejected` with a readable reason if any step fails.
**Done when:** every `Edit` method is reachable as an operation, and the round-trip datasets run through `Operations::apply()`.

---

## Phase 2 — Files and saving

### V2.1 Template repository
**Depends on:** V0.4, V1.1
`src/Templates/TemplateRepository.php` — `all()`: site templates and package views with `path`, `view` name, `origin` (site | package), `role` (layout, partial, block, entry, listing, term, error, other; from the path and the template map), `usedBy` (collections and taxonomies), `opens` and the refusal reason. `read(string $path)`: source, version, tree.
**Done when:** the workbench lists the 19 starter templates with correct roles and `usedBy`.

### V2.2 Saving
**Depends on:** V1.7, V2.1
`src/Actions/SaveTemplate.php` — `handle(path, expectedVersion, newSource)`: resolves the path safely inside the template directory; refuses with `Conflict` when the file's current version differs; runs the syntax check; backs up; writes atomically (temp file plus rename); returns the new version. `src/Actions/CopyPackageView.php` — **Copy to site**.
**Done when:** tests cover conflict, syntax error (nothing written), backup created, path traversal refused, and a write from a second process between read and save.

### V2.3 HTTP API
**Depends on:** V2.2
Routes under `{admin}/visual-editor/api`, all behind `Access`:

| Route | Does |
| --- | --- |
| `GET templates` | `TemplateRepository::all()` |
| `GET templates/{path}` | source, version, tree |
| `POST templates/{path}/operations` | `{version, operations}` → applies and saves → new source, version, tree |
| `PUT templates/{path}/source` | `{version, source}` from the code panel → saves |
| `POST templates/{path}/copy` | Copy to site |

Errors are JSON with a `reason` the UI can show: `conflict`, `rejected`, `syntax`, `forbidden`.
**Done when:** feature tests cover each route and each error.

### V2.4 Activity
**Depends on:** V2.2
Record each save in Sunrice's activity log with its documented call, `app(ActivityLogger::class)->record('updated', 'Template partials/header.blade.php', […], 'template')`, so template changes appear beside content changes.
**Done when:** a save shows in Manage → Activity log.

---

## Phase 3 — Page inspector

### V3.1 Editor render
**Depends on:** V2.1
How a page is rendered for the editor without touching normal rendering:
- `src/Http/Middleware/EditorRender.php` — added to Sunrice's frontend routes by appending it to `config('sunrice.frontend.middleware')` in the provider's `register()` (Sunrice reads that list when the app has booted). Active only when the request carries `sve=1`, the package is enabled and `Access` allows the signed-in user. When active it swaps the Blade engine: `app('view.engine.resolver')->register('blade', fn () => new EditorEngine(…))`. Inactive, it does nothing.
- `src/Rendering/EditorEngine.php` — extends `Illuminate\View\Engines\CompilerEngine`. `get($path, $data)` records the view in the manifest; for a file that opens as a tree it compiles a marked copy into `storage/framework/views-sve/` (never Laravel's own compiled directory) and evaluates that; otherwise it defers to the parent.
- `src/Rendering/Marker.php` — from a `Template`, returns the source with `data-sve="<file>:<offset>"` added after the tag name of every element. Offsets are those of the unmarked file.
- `src/Rendering/Manifest.php` — views rendered in this request, in order: file index, path, view name, origin, opens.
- The middleware appends the manifest as `<script type="application/json" id="sve-manifest">` and the bridge script tag before `</body>`, sets `Cache-Control: no-store` and `X-Robots-Tag: noindex`, and keeps the response out of Sunrice's optional full-page cache (spatie/laravel-responsecache runs after this middleware; mark the request as not cacheable and test with `sunrice.cache.full_page` on).
**Done when:** a feature test renders the workbench homepage with `sve=1` and finds marks from the layout, header, footer, page template and a block partial, plus the manifest; the same URL without `sve=1`, and with it as a non-admin, is byte-identical to a normal render; Laravel's compiled view directory holds no marks.
**Risk:** this is the least proven piece. Build it first in the phase, and check `@extends` (section content is drawn inside the layout but must carry the child's marks), components, `@include` in loops, and `@push`/`@stack`.

### V3.2 Bridge script
**Depends on:** V3.1
`resources/js/bridge/` → `dist/bridge.js`, loaded only in editor renders. It reports hover and click on the closest `[data-sve]` to the parent window (`postMessage`, same origin) with file, offset and bounding boxes; draws nothing itself; reports page size and scroll; in Select mode cancels link clicks and form submits, in Browse mode lets them through with `sve=1` kept on same-site links.
**Done when:** `tests/Frontend` covers the message protocol with a DOM fixture.

### V3.3 Editor shell and Templates panel
**Depends on:** V3.2, V0.3
The frame loads `{url}?sve=1`. The **Templates on this page** panel lists the manifest; hovering a row outlines that file's elements in the frame; hovering the frame shows the file's name on the region. Clicking either opens the template (`GET templates/{path}`), with a breadcrumb back. Select/Browse toggle. The last URL is remembered per file, so opening `partials/header` later shows a page that uses it.
**Done when:** in the workbench, from the homepage: click header → header opens; back → homepage template.

### V3.4 Code panel
**Depends on:** V3.3, V2.3
CodeMirror with Blade/HTML highlighting for the open file; Save → `PUT …/source`; shows `syntax` and `conflict` errors; reloads the frame on success. This is how refused files and package copies are edited.
**Done when:** editing the footer as code changes the page in the frame, and a syntax error is shown without writing.

### V3.5 Editor entry points
**Depends on:** V3.3
`{admin}/visual-editor?url=/about` opens that page. Add an **Edit visually** link to the draft banner shown to signed-in editors if Sunrice offers a hook; otherwise document the URL.
**Done when:** the query parameter works. **Milestone M1.**

---

## Phase 4 — Visual editing

### V4.1 Layers panel and selection
**Depends on:** M1
The open file's tree from `toArray()`, with icons per kind and the node label. Selecting a layer highlights every instance in the frame (a loop draws one node many times); clicking the frame selects the layer. Selection is `{file, version, node}`.
**Done when:** selection stays in sync both ways, including nodes inside loops and sections.

### V4.2 Settings panel
**Depends on:** V4.1, V2.3
Controls by node kind, each sending one operation: tag, id, class chips, attribute rows (static or expression), text; echo expression and raw toggle; condition; loop source and item name; include view and data; code node text. After each response the tree and frame refresh and the selection is restored by finding the edited node in the new tree (the operation response returns its new id).
**Done when:** each control changes the file with the minimal diff (asserted in a feature test per operation type).

### V4.3 Insert
**Depends on:** V4.2
A searchable list: elements, Blade structures, Sunrice components, and **Paste HTML**. Each entry is a fragment (V1.4) inserted inside or after the selection.
**Done when:** every entry inserts, opens as a tree and compiles.

### V4.4 Move, duplicate, wrap, delete
**Depends on:** V4.1
Drag in the Layers panel (dnd-kit) with drop targets limited to what V1.4 allows; duplicate, wrap, unwrap, delete from a menu and the keyboard. dnd-kit is the first bundled library that imports React: the browser check must show it running on the admin's copy (no second React, no hook errors).
**Done when:** a move between parents produces exactly the V1.4 result.

### V4.5 History
**Depends on:** V4.2
Undo and redo per file for the session, as a stack of source versions; undo is a save of the earlier source against the current version. Leaving with nothing unsaved is always the case (edits save at once), so there is no dirty state to guard except in the code panel.
**Done when:** ten mixed edits undone return the file byte for byte.

### V4.6 Refresh without losing place
**Depends on:** V4.2
After an edit, reload the frame's document, restore scroll and selection, and avoid a flash (swap the document when the new one is ready).
**Done when:** editing text mid-page keeps the viewport still. **Milestone M2.**

---

## Phase 5 — Fields and variables

### V5.1 Field catalog
**Depends on:** V0.4
`src/Fields/Catalog.php` — for a collection, fieldset, taxonomy or global set: fields with `handle`, `label`, `type`, `multiple`, `children` (group, repeater, flexible's fieldsets). Plus built-ins per subject (entry: title, url, published_at, author, terms; term: name, url; asset: url sizes, alt).
**Done when:** the catalog of the workbench structure matches its blueprints.

### V5.2 Scope
**Depends on:** V5.1, V2.1
`src/Fields/Scope.php` — `at(Template, Node): list<Variable>`. From the file's role and `usedBy`: `$entry`, `$entries`, `$term`, `$block`, `$sunricePage`, `$locale`, globals, menus. From the position: each enclosing `@foreach ($source as $item)` adds `$item`, typed when `$source` is a known field or query (repeater rows, entries, terms, assets, `$component->entries` with the tag's `collection`); enclosing component tags add `$component`. Simple assignments (`@php($image = $entry->get('image'))`) are followed; anything else is listed untyped.
**Done when:** scope is correct at marked positions in every starter template (a table-driven test).

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
