# Working on Sunrice Visual Editor

`SPEC.md` is the source of truth for behaviour. `EXECUTION_PLAN.md` is the source of truth for structure, naming, and order. If they disagree, follow `SPEC.md` and record the conflict in the pull request.

## Rules

- One task per branch and pull request. Branch `task/<task-id>-<short-name>`, PR titled `[<task-id>] <name>`.
- Only start a task when all its dependencies are merged.
- No Composer or npm packages beyond `EXECUTION_PLAN.md` §2. If one seems needed, stop and explain why in the PR instead of adding it.
- Every task ships tests. Before opening a PR run `composer test`, `composer lint`, `composer analyse`, `composer spike`, and for admin tasks `npm run typecheck && npm run test:frontend && npm run build`.
- The round-trip test (`composer spike`) must stay green. Under `src/Blade/`, a template that prints back differently, or an edit that touches bytes it should not, is a bug however small.
- The same holds for stylesheets under `src/Css/`: `composer spike:css` must stay green (`spike/css-fetch.sh` first, to include Bootstrap, Bulma and Pico), and after a change there `npm run test:css` asks a browser whether it reads the same rules.
- The package is a plugin of the Sunrice panel (`sunrice/admin`) that requires the CMS (`sunrice/cms`), both from the monorepo checked out beside it (`../sunrice`, Composer's path repository). Use only their public API: classes and methods marked `@api`, and conventions their docs promise. `src/Support/SunriceBridge.php` is the one file that reads Sunrice; `src/VisualEditorPlugin.php` is what the package adds to the panel. If something needed isn't `@api`, add it to Sunrice as deliberate public API in its own pull request there (as `Cms::entryLinksUsing()` was) instead of reaching in, and no feature detection (`method_exists`) of Sunrice's API.
- Anything that lets a template be changed goes through `Guard\CodeGuard` for people without `visual-editor.code`. A new operation, or a new way of writing Blade for the person, needs a case in `tests/Feature/CodePermissionTest.php`: code sent through it must not run.
- The editor never writes a file it could not read back exactly, and nothing of the editor is written into a template.
- Update `docs/` when adding developer-facing API. Do commit the compiled admin script in `dist/`.
- `docs/installation.md` is tried on a new Laravel application by `.github/install-check.sh` (the `install` job). A change to how the package is installed, published or turned on changes the page and that script together.

## Conventions

- PHP namespace `Sunrice\VisualEditor\` → `src/`; tests `Sunrice\VisualEditor\Tests\` → `tests/`. Every PHP file starts `declare(strict_types=1);`.
- Config `config/sunrice-visual-editor.php`. Admin routes through `$panel->routes()` (in the plugin), named `sunrice.admin.visual-editor.*`. Permissions `visual-editor.*`, AI tools `visual_editor_*`. The site's templates are the views `cms.*` (`resources/views/cms`), its theme files `public/cms`.
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
composer serve     # build and serve the demo site, admin at /cms
```

```bash
npm run typecheck
npm run test:frontend   # node --test over tests/Frontend
npm run build           # dist/visual-editor.js and .css, dist/bridge.js
npm run test:bridge     # the built bridge script in Chrome, against tests/Browser/fixtures
BASE_URL=http://127.0.0.1:8000 npm run test:browser   # needs `composer serve` running and Chrome
```

CI rebuilds `dist/` on every pull request and commits it to the branch: pull before pushing again.

Before anything else: `git clone https://github.com/Digital-Citra-Kreatif/sunrice ../sunrice` (CI does the same, at `vars.SUNRICE_REF` or `main`), then `composer install`. Run the checks locally; don't work from CI's answers alone.
