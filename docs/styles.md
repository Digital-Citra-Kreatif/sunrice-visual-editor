# Styles

The editor writes CSS into files that stand in the site's theme. It does
not keep styles in a database, write inline styles, or generate a
stylesheet of its own from settings.

On the screen, CSS is written in one place: the **Custom CSS** tab, a
stylesheet of the site's own. Classes are given to elements in the
template's code, in the **Template** tab.

## Custom CSS

**Custom CSS**, on the right of the page, is one file:
`public/sunrice-theme/custom.css`. It is written as one text, in a code
editor, and saved with Ctrl+S. The page is drawn again with it.

Every page of the site links it, and no template is changed for that.
While a site template is drawn, the package pushes

```html
<link rel="stylesheet" href="…/sunrice-theme/custom.css?v=…" data-sunrice-custom-css>
```

onto the layout's `@stack('head')`, which the starter layout has. The
link is there:

- whether or not the editor is on, so the deployed site has the same CSS
  as the copy it was written on;
- only while the file has something in it;
- once a page, however many templates draw it;
- with the file's version as `?v=`, so a browser fetches it again after a
  save.

A layout without `@stack('head')` gets no link. Add the stack to its
`<head>`, name another one in `custom_css.stack`, or set
`custom_css.inject` to `false` (`SUNRICE_VISUAL_EDITOR_CUSTOM_CSS=false`)
and link the file from the layout yourself. `custom_css.file` names
another file in `public/sunrice-theme/`. See the
[settings](installation.md#settings).

Saving it takes `visual-editor.edit`: CSS runs no PHP. As for any
stylesheet the editor writes, the save is refused when the file has
changed on disk since it was opened, the previous version is kept with the
template backups, and the save is in the activity log.

The API for it is `GET {admin}/visual-editor/api/styles/custom`, which
gives the file's text and version, and
`PUT {admin}/visual-editor/api/styles/files/{path}/source`, which saves it.

## Which files

Every `*.css` file under `public/sunrice-theme/` (up to 2 MB each) can be
changed through the editor's API, and one file of the editor's own takes
rules that do not exist yet: `public/sunrice-theme/editor.css`
(`theme_css` in the [settings](installation.md#settings)). The screen
writes only the custom CSS.

## Devices

![Desktop, tablet and mobile side by side](screenshots/widths.png)

The device switch in the top bar shows the page at **Fit** (as wide as
there is room), at one of the set devices, or at **All** of them side by
side, drawn at one scale. Side by side, **Select here** on a frame makes
it the one clicks go to. Every frame is drawn again after a change.

The devices are the `widths` setting:

```php
'widths' => [
    ['name' => 'Desktop', 'width' => 1280],
    ['name' => 'Tablet', 'width' => 768, 'media' => '(max-width: 1024px)'],
    ['name' => 'Mobile', 'width' => 390, 'media' => '(max-width: 640px)'],
],
```

The `media` of each is kept in the configuration for tools that write CSS
for a device. The screen does not use it.

## What is kept and what is refused

- A change through the API to one declaration changes only that
  declaration. Spacing, comments, the order of rules and every other byte
  of the file stay as written. A minified file stays minified.
- A value that would break out of its declaration (an unclosed quote or
  bracket, a `;`, `{` or `}` of its own) is refused, and nothing is written.
- After every such change the file is read again. The change is refused
  unless the file now says what it said before plus the one thing meant.
- A save is refused when the file has changed on disk since the editor read
  it.
- The previous version of the file is kept with the template backups, and
  the save is in the activity log.

## Sites that build their CSS

A new Laravel application takes its CSS from Vite, with Tailwind: the
layout says `@vite(['resources/css/app.css', 'resources/js/app.js'])`, and
Tailwind writes a rule for each class it finds in the site's files. A class
added to a template has no style until Tailwind has seen it. What the
editor does on such a site today, as tried on one (Tailwind 4, Vite 8, on
every pull request):

**With `npm run dev` running in your terminal, nothing else is needed.**
The editor writes the real template file. Tailwind sees the new class and
the dev server serves its rule; the page in the editor's frame shows it
within a moment. Vite also reloads a page whose Blade file changed, and the
editor stays in step with that: what was selected stays selected.

This needs a Sunrice from 12 October 2026 or later
([sunrice-cms#41](https://github.com/littleboy130491/sunrice-cms/pull/41)).
Before it, Sunrice's admin was blank for as long as the site's dev server
ran, the editor with it: `composer update sunrice/cms` if that is what you
see.

**Without a dev server, the class shows after a build.** The editor's frame
and the site both use the built CSS (`public/build`), which does not have
the new rule until `npm run build` has run again: in your terminal, or
[from the editor](#building-from-the-editor).

**To see it in the editor before building, turn the preview on.**

```
SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW=true
```

Pages drawn for the editor, and no others, then also get Tailwind's own
browser build, which writes a rule for any class the moment it is on the
page. The top bar says **Preview CSS** while that is what you are looking
at. Three things about it:

- It is a preview. It reads your CSS entry file (`resources/css/app.css`)
  for the theme, so your colours and fonts apply. It does not load
  plugins, a `tailwind.config.js` or other files the entry imports. Where
  those matter, what a build makes can differ.
- Visitors never get it, and the site itself still lacks the rule until
  you build.
- It steps aside while a dev server is running, which serves the real CSS.
  The script comes from jsDelivr; `tailwind.script` in the
  [settings](installation.md#settings) can point at a copy of your own.

### Building from the editor

On a server where nobody has a terminal open, the build can be started
from the editor. Name the command:

```
SUNRICE_VISUAL_EDITOR_BUILD="npm run build"
```

and give `visual-editor.build` to the roles that should have it (Super
Admins do; run `php artisan sunrice:sync-permissions` first if the
permission is not listed yet). The top bar then has a **Build** button.

- After a template is changed it reads **Build needed**: the built CSS may
  lack what the change uses. That is said for changes made since the
  editor was opened; the editor does not look into the built files.
- A click runs the command in the site's directory and waits for it. When
  it ends well the page is drawn again with the new CSS, and the bar says
  how long the build took.
- When it fails, what the command printed is shown under the bar: a
  mistyped class in an `@apply`, a missing package. Nothing of the site
  changes because of a failed build beyond what the command itself did.
- While `npm run dev` is running the button stays quiet: the dev server
  already serves the CSS as it is.

Three things to know:

- **Node has to be on the server.** Sunrice and the editor come built and
  need no Node; a build does. The web server's user must find `npm` and
  `node`, and the site's `node_modules` must be installed. Where any of
  that is missing the button says that a program was not found, and what
  the shell printed names it.
- **The web server's path is often short.** The command gets the path the
  web server's own process has. PHP-FPM usually runs with none, and the
  shell then looks in a few system directories only: a Node installed
  there (`/usr/bin`) is found, one installed for a user (nvm, Herd, a
  version manager) is not. The command is a shell command, so it can say
  where Node is:

  ```
  SUNRICE_VISUAL_EDITOR_BUILD="PATH=/home/deploy/.nvm/versions/node/v22.11.0/bin:/usr/bin:/bin npm run build"
  ```

  A full path to `npm` alone is not enough: `npm` starts `node` by name.
- **The web server's user writes the build**, so it needs to be allowed to
  write `public/build`.
- **A build takes the time it takes.** The request waits for it, up to
  `build.timeout` seconds (120). Where the web server or PHP stops a
  request sooner than the build ends, raise their limits or build in a
  terminal.

Running the build takes a
[permission of its own](permissions.md#building). The command is the one
in the site's configuration and nothing else.

### The layout

**The layout opens as usual.** `@vite(…)` is one piece of code in the
tree, like any directive. Tailwind finds
classes in `resources/views/sunrice/` without being told where to look.

Two things to know:

- A layout with `@vite` needs either a build or a running dev server.
  With neither, Laravel can't draw the page at all. That is Laravel's
  rule, not the editor's.
- The custom CSS is a plain CSS file in `public/sunrice-theme/`, linked
  beside what Vite builds. It has nothing to do with classes Tailwind
  generates. On such a site, style with classes in the template's code; a
  rule of your own still goes into the custom CSS, and the editor will not
  edit a built file that the next build writes over.

See [Limits](limits.md#styles) for what the editor does not cover.
