# Sunrice visual editor

A visual template editor for [Sunrice CMS](https://github.com/littleboy130491/sunrice-cms), as a separate package.

The plan is for the editor to work on the Blade files themselves: no database
copy of a template and no second file beside it. A developer can keep editing
the same file in a code editor. That only works if the editor can read a Blade
file into a tree, change one thing, and write the file back with nothing else
disturbed. This repository holds the test of that idea and the code it needed.

## Result

The approach holds. Full numbers are in [spike/RESULTS.md](spike/RESULTS.md).

| Templates | Files | Open and print back byte for byte | Refused | Edits passed |
| --- | ---: | ---: | ---: | ---: |
| Sunrice starter templates (`stubs/templates`) | 19 | 19 | 0 | 1,042 of 1,042 |
| Sunrice package views (defaults, components, error pages) | 11 | 11 | 0 | 654 of 654 |
| Laravel framework's own Blade views | 129 | 127 | 2 | 3,164 of 3,164 |
| Edge cases written for this test | 12 | 7 | 5, as intended | 181 of 181 |

The two Laravel files refused are test fixtures that are malformed on purpose.

In the 19 starter templates, 87% of the 333 nodes are fully visual (elements,
text, conditions, loops, includes, plain field echoes, translation strings),
5% are echoes of a longer PHP expression, 2% are elements with a directive
inside the tag, and 6% are code. Code regions cover 17% of the bytes; three
quarters of that is two `@switch` blocks (the form block and the filter form),
which this test treats as code wholesale.

## What was tested

For every template:

1. **It opens as a tree and the tree prints back the same bytes.**
   `Template::open()` refuses the file otherwise.
2. **Six kinds of edit, on every node they apply to**, each on a fresh tree:
   add a class, change text, delete, reorder with the next sibling, wrap in a
   `<div>`, and add a field (`<p>{{ $entry->get('…') }}</p>`) as last child.
   An edit passes when the output is exactly the expected bytes, computed
   from the original file and the node's offsets, opens as a tree again, and
   compiles with Laravel's Blade compiler to valid PHP.
3. **Templates the tree can't hold are refused, not damaged** (the `locked-…`
   edge fixtures).

## What it showed

- **stillat/blade-parser finds the Blade, not the HTML.** It returns echoes,
  directives, comments, PHP blocks and component tags with positions, pairs
  `@if`/`@else`/`@endif` and component tags, and treats everything else as
  plain text. The HTML layer is written here: `HtmlLexer` and `TreeBuilder`.
- **Its positions count characters, not bytes.** `BladeScanner` converts them;
  the multibyte fixture and the filter form (which contains "—") cover this.
- **It runs without a Laravel application**, through its parser factory hook.
- **Joining two pieces can break a directive.** Deleting the echo in
  `@else{{ $entry->title }}@endif` leaves `@else@endif`, which Blade no longer
  reads as two directives, so the file stops compiling. This turned up in
  `partials/card.blade.php` and two package views. `Edit::unsafeSeam()` now
  detects it and puts one space at the join.
- **What gets refused.** A tag opened inside one `@if` and closed in another,
  an element left open across `@else`, and end tags left out (`<li>` without
  `</li>`). None of the 30 Sunrice templates do this. The file as a whole is
  refused; locking only the affected region would be a later refinement.

## Not covered

- The check is on bytes and on compiling, not on rendered pages.
- Component tags (`<x-sunrice::entries>`) are parsed and paired, but the
  compile check runs without a Laravel application, so it leaves them as text.
- Attribute editing beyond adding a class, and CSS.
- An argument-less directive followed by text starting with `(` after a
  deletion (`@else` + `(note)`) is not handled; a space doesn't fix that one.
- Edits are checked to be syntactically safe, not meaningful: moving a
  `@php($image = …)` line below the place that uses `$image` still compiles.

## Running it

With Composer:

```bash
composer install
php spike/run.php --check
```

That path is untested: the test was written where Packagist wasn't reachable.
There it runs from source checkouts instead, which `spike/bootstrap.php` loads
when there is no `vendor/` directory:

```bash
git clone --depth 1 --branch v2.1.0 https://github.com/Stillat/blade-parser ../deps/blade-parser
git clone --depth 1 --branch 13.x https://github.com/laravel/framework ../deps/framework
php spike/run.php --check    # or SPIKE_DEPS=/path/to/deps php spike/run.php --check
```

It prints a summary, rewrites `spike/RESULTS.md` and `spike/results.json`, and
with `--check` exits 1 if anything fails. The Laravel views are only tested
when a framework checkout is present.

## Layout

```
src/Blade/
  BladeScanner.php   Blade constructs, from stillat/blade-parser, as atoms with byte offsets
  HtmlLexer.php      tags, comments and doctypes around them, with exact ranges
  TreeBuilder.php    the tree; strict about nesting, throws Unsupported otherwise
  Node.php           a tree node; owns its source text
  Template.php       open(): tree, or Unsupported
  Classifier.php     visual / field / label / expression / mixed / code
  Edit.php           the edits, and the directive seam rule
spike/               the test runner and its results
tests/fixtures/      sunrice-starter and sunrice-package (copied from sunrice-cms at 8c9794e), edge
```
