# Working on Sunrice Visual Editor

`SPEC.md` is the source of truth for behaviour. `EXECUTION_PLAN.md` is the source of truth for structure, naming, and order. If they disagree, follow `SPEC.md` and record the conflict in the pull request.

## Rules

- One task per branch and pull request. Branch `task/<task-id>-<short-name>`, PR titled `[<task-id>] <name>`.
- Only start a task when all its dependencies are merged.
- No Composer or npm packages beyond `EXECUTION_PLAN.md` §2. If one seems needed, stop and explain why in the PR instead of adding it.
- Every task ships tests. Before opening a PR run `composer test`, `composer lint`, `composer analyse`, `composer spike`, and for admin tasks `npm run typecheck && npm run test:frontend && npm run build`.
- The round-trip test (`composer spike`) must stay green. Under `src/Blade/`, a template that prints back differently, or an edit that touches bytes it should not, is a bug however small.
- The same holds for stylesheets under `src/Css/`: `composer spike:css` must stay green (`spike/css-fetch.sh` first, to include Bootstrap, Bulma and Pico), and after a change there `npm run test:css` asks a browser whether it reads the same rules.
- Use Sunrice's public API only: the panel's (`sunrice/admin`) and the CMS's (`sunrice/cms`) classes and methods marked `@api`. `src/Support/SunriceBridge.php` is the one file allowed to read the CMS beyond that (today `Sunrice\Cms\Support\Templates`, marked `@internal`), and the only file that reads the CMS's templates, fields and caches at all.
- What the package adds to the panel (permissions, navigation, routes, its page bundle, tools, command-palette search) is registered in `src/VisualEditorPlugin.php`, through the panel's plugin API; no feature detection (`method_exists`, reflection) of the panel or the CMS: `composer.json` states the versions.
- Anything that lets a template be changed goes through `Guard\CodeGuard` for people without `visual-editor.code`. A new operation, or a new way of writing Blade for the person, needs a case in `tests/Feature/CodePermissionTest.php`: code sent through it must not run.
- The editor never writes a file it could not read back exactly, and nothing of the editor is written into a template.
- Update `docs/` when adding developer-facing API. Do commit the compiled admin script in `dist/`.
- `docs/installation.md` is tried on a new Laravel application by `.github/install-check.sh` (the `install` job). A change to how the package is installed, published or turned on changes the page and that script together.

## Conventions

- PHP namespace `Sunrice\VisualEditor\` → `src/`; tests `Sunrice\VisualEditor\Tests\` → `tests/`. Every PHP file starts `declare(strict_types=1);`.
- Config `config/sunrice-visual-editor.php`. Admin routes in `routes/admin.php`, added by the plugin (`$panel->routes()`), named `sunrice.admin.visual-editor.*`. Permissions `visual-editor.*`, AI tools `visual_editor_*`.
- A node is addressed as file path, file version (`sha1` of its bytes) and start byte offset. Every request naming a node names the version.
- Write operations live in single-purpose action classes in `src/Actions/` with a public `handle()`.

## Commands

```bash
composer test      # Pest
composer spike     # round-trip report; rewrites spike/RESULTS.md
composer spike:css # the same for stylesheets; rewrites spike/CSS_RESULTS.md
composer lint      # Pint (test mode)
composer format    # Pint (fix)
composer analyse   # Larastan
composer serve     # build and serve the demo site, panel at /admin (admin@example.com / Sunrice-Demo-2026!)
```

```bash
npm run typecheck
npm run test:frontend   # node --test over tests/Frontend
npm run build           # dist/visual-editor.js and .css, dist/bridge.js
npm run test:bridge     # the built bridge script in Chrome, against tests/Browser/fixtures
BASE_URL=http://127.0.0.1:8000 npm run test:browser   # needs `composer serve` running and Chrome
```

CI rebuilds `dist/` on every pull request and commits it to the branch: pull before pushing again.

Where Packagist and npm can't be reached but GitHub can, all of these still run locally, the browser check included: see the README of the `ci/lock` branch. Do that first; don't work from CI's answers alone.
