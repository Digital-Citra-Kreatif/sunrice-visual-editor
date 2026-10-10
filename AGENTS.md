# Working on Sunrice Visual Editor

`SPEC.md` is the source of truth for behaviour. `EXECUTION_PLAN.md` is the source of truth for structure, naming, and order. If they disagree, follow `SPEC.md` and record the conflict in the pull request.

## Rules

- One task per branch and pull request. Branch `task/<task-id>-<short-name>`, PR titled `[<task-id>] <name>`.
- Only start a task when all its dependencies are merged.
- No Composer or npm packages beyond `EXECUTION_PLAN.md` §2. If one seems needed, stop and explain why in the PR instead of adding it.
- Every task ships tests. Before opening a PR run `composer test`, `composer lint`, `composer analyse`, `composer spike`, and for admin tasks `npm run typecheck && npm run build`.
- The round-trip test (`composer spike`) must stay green. Under `src/Blade/`, a template that prints back differently, or an edit that touches bytes it should not, is a bug however small.
- Use Sunrice's public API only. `src/Support/SunriceBridge.php` is the one file allowed to touch Sunrice internals.
- The editor never writes a file it could not read back exactly, and nothing of the editor is written into a template.
- Update `docs/` when adding developer-facing API. Do commit the compiled admin script in `dist/`.

## Conventions

- PHP namespace `Sunrice\VisualEditor\` → `src/`; tests `Sunrice\VisualEditor\Tests\` → `tests/`. Every PHP file starts `declare(strict_types=1);`.
- Config `config/sunrice-visual-editor.php`. Admin routes through `Sunrice::adminRoutes()`, named `sunrice.admin.visual-editor.*`.
- A node is addressed as file path, file version (`sha1` of its bytes) and start byte offset. Every request naming a node names the version.
- Write operations live in single-purpose action classes in `src/Actions/` with a public `handle()`.

## Commands

```bash
php spike/run.php --check   # round-trip test; rewrites spike/RESULTS.md
```

`composer test`, `lint`, `analyse` and the npm scripts arrive with tasks V0.1–V0.3.
