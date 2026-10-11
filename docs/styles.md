# Styles

The editor changes the theme's CSS files where they stand. It does not keep
styles in a database, write inline styles, or generate a stylesheet of its
own from settings. A rule you change is the rule in your file, with one
declaration different.

## Which files

Every `*.css` file under `public/sunrice-theme/` (up to 2 MB each), and one
file of the editor's own for rules that do not exist yet:
`public/sunrice-theme/editor.css` (`theme_css` in the
[settings](installation.md#settings)).

## Classes first

Styles are written for a class. Select an element, give it a class in
**Settings**, and style the class. An element without a class has nothing
to style yet, and the Style and CSS tabs say so.

When an element has several classes, the first is shown and the others are
one click away.

## The CSS tab

![The CSS tab: the rule for .muted in app.css, a row for each declaration](screenshots/css.png)

The rules of the theme's stylesheets for the chosen class, a row for each
declaration:

- first the rule for the class itself (`.card`);
- then the rules that only mention it (`.card h2`, `.card:hover`);
- each with the file and line it is on and the `@media` it stands under.

Change a value or a property, add a declaration in the empty row, take one
out with **×**. Several declarations can be pasted into the empty row as
CSS writes them (`color: red; margin: 0`).

**↶ ↷** beside each file undo and redo its changes.

## The Style tab

![The Style tab: controls for layout, size, spacing, text, background and border](screenshots/style.png)

The same rule as controls: display and the flex and grid settings, width
and height, margin and padding, font, size, weight, alignment and colour,
background, border, radius and shadow.

- Each control reads one declaration of the class's own rule and writes
  one. What you type is CSS (`1rem`, `0 auto`, `var(--accent)`).
- Emptying a control takes its declaration out.
- A control the rule says nothing for shows in grey what the page has for
  it from elsewhere (a parent, another rule), so you can see what there is
  to override.

Both tabs write the same file through the same changes. What one sets, the
other shows.

## A class without a rule

The first thing you set makes the rule, in `editor.css`. If the page does
not link that file yet, the tab says so, and one click links it from the
layout's `<head>`, written as the starter's layout writes its own
stylesheet link. That is a change to the layout template like any other,
with its own undo.

## Widths

![Desktop, tablet and mobile side by side](screenshots/widths.png)

The width choice in the top bar shows the page as wide as there is room, at
one of the set widths, or at all of them side by side. Side by side, one
frame is being edited and the others follow every change; **Edit here** on
another frame makes it the edited one.

A style set in the Style tab while a width is being edited is written for
that width:

- into the class's rule under the `@media` condition of your own CSS that
  applies at that width, when there is one;
- otherwise under the condition the width is set up with
  (`(max-width: 640px)` for Mobile).

**For**, at the top of the tab, says which condition is being written
under, and can be set to another or to every width. A rule for a width is
put after the class's own rule, at the end of a `@media` block that is
already there, or in a new block at the end of the file.

The widths and their conditions are the `widths` setting. If your CSS is
written mobile first (`min-width`), set them up that way:

```php
'widths' => [
    ['name' => 'Mobile', 'width' => 390],
    ['name' => 'Tablet', 'width' => 768, 'media' => '(min-width: 768px)'],
    ['name' => 'Desktop', 'width' => 1280, 'media' => '(min-width: 1024px)'],
],
```

## What is kept and what is refused

- Only the declaration that was changed changes. Spacing, comments, the
  order of rules and every other byte of the file stay as written. A
  minified file stays minified.
- A value that would break out of its declaration (an unclosed quote or
  bracket, a `;`, `{` or `}` of its own) is refused, and nothing is written.
- After every change the file is read again. The change is refused unless
  the file now says what it said before plus the one thing meant.
- A save is refused when the file has changed on disk since the editor read
  it.
- The previous version of the file is kept with the template backups, and
  the save is in the activity log.

## Sites that build their CSS

A site whose CSS is made by a build (Vite, Tailwind, Sass) is styled by its
classes: add and remove them on the element in Settings, and let the build
run in your terminal as before. The editor changes CSS files, not what a
build makes from other files, and it would be wrong to edit a built file
that the next build writes over.

See [Limits](limits.md#styles) for what the Style tab does not cover.
