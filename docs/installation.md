# Installing the visual editor

The visual editor is a package for a site built with
[Sunrice CMS](https://github.com/littleboy130491/sunrice-cms). It edits the
site's Blade templates and theme stylesheets in the browser, and writes the
files themselves.

## Before you start

- **It is a development tool.** It writes files that run PHP on the server
  and belong in the project's git repository. Use it on a local copy of the
  site, or on a staging copy, then commit and deploy. It is off on
  production unless you turn it on.
- PHP 8.3 or newer, and a site that already runs Sunrice CMS (Laravel 13).
- The web server's user must be able to write `resources/views/sunrice/` and
  `public/sunrice-theme/`. On a local copy served with `php artisan serve`
  that is already so.

## Install

The package is not on Packagist yet. Tell Composer where it is, then require
it:

```bash
composer config repositories.sunrice-visual-editor vcs https://github.com/littleboy130491/sunrice-visual-editor
composer require sunrice/visual-editor:dev-main
```

Publish its script and stylesheet for the admin, and create its permissions:

```bash
php artisan vendor:publish --tag=sunrice-visual-editor-assets
php artisan sunrice:sync-permissions
```

Run the first command again after every update of the package: it copies
the built admin script to `public/vendor/sunrice-visual-editor/`.

The permissions exist only where the editor is on, so run the second
command there: on a staging copy, after setting
`SUNRICE_VISUAL_EDITOR=true`.

**Visual editor** now appears in the admin's sidebar, under *Manage*, for
Super Admins and for anyone given one of its permissions. It is also at `/cms/visual-editor` (`/cms` being your admin
path), and `/cms/visual-editor?url=/about` opens it on that page. Every
entry's page in the admin also has an **Edit with Visual Editor** button,
which opens the editor on that entry's page.

## Where it is on

| `APP_ENV` | `SUNRICE_VISUAL_EDITOR` | The editor is |
| --- | --- | --- |
| `local` | not set | on |
| anything else | not set | off |
| any | `true` | on |
| any | `false` | off |

When it is off the package registers no routes, no permissions and no admin
script, and changes nothing about how pages are drawn.

## Who may use it

Super Admins may do everything. For anyone else, give their role one of
these in **Users → Roles**, under *Visual editor*:

| Permission | Lets someone |
| --- | --- |
| Open the visual editor | look at the page, its templates, their layers and code |
| Change templates and styles in the visual editor | move, copy and delete in the tree, write the custom CSS, without writing code |
| Edit template code in the visual editor | write Blade and PHP |

Each includes the ones above it. [Permissions](permissions.md) says exactly
where the line between the last two is.

A fourth stands apart from those: **Run the site's build command from the
visual editor**. It does nothing until a [build command](#settings) is
set, and none of the three above includes it.

## Settings

Publish the config file to change any of these:

```bash
php artisan vendor:publish --tag=sunrice-visual-editor-config
```

`config/sunrice-visual-editor.php`:

| Key | Default | What it does |
| --- | --- | --- |
| `enabled` | on when `APP_ENV` is `local` | Turns the editor on or off (`SUNRICE_VISUAL_EDITOR`). |
| `backups` | `true` | Keeps the previous version of every file the editor writes, in `storage/app/sunrice/template-backups/`. Leave it on: undo for people without the code permission depends on it. |
| `theme_css` | `editor.css` | The file in `public/sunrice-theme/` that receives CSS rules for classes that have none yet. |
| `custom_css.file` | `custom.css` | The site's own stylesheet in `public/sunrice-theme/`, written in the editor's **Custom CSS** tab and linked from every page. See [Styles](styles.md#custom-css). |
| `custom_css.inject` | `true` | Links that file from every page, by pushing a `<link>` onto the layout's stack (`SUNRICE_VISUAL_EDITOR_CUSTOM_CSS`). It does so whether or not the editor is on, so the deployed site has the same CSS. Set it to `false` to link the file from the layout yourself. |
| `custom_css.stack` | `head` | The stack the link is pushed onto: the layout's `@stack('head')` in the starter layout. |
| `custom_js.file` | `custom.js` | The site's own script in `public/sunrice-theme/`, written in the editor's **Custom JS** tab by people who may edit code, and loaded on every page. See [Styles](styles.md#custom-js). |
| `custom_js.inject` | `true` | Loads that file, deferred, on every page, by pushing a `<script>` onto the layout's stack (`SUNRICE_VISUAL_EDITOR_CUSTOM_JS`), whether or not the editor is on. Set it to `false` to load the file from the layout yourself. |
| `custom_js.stack` | `scripts` | The stack the script is pushed onto: the layout's `@stack('scripts')`, at the end of the body in the starter layout. |
| `widths` | Desktop 1280, Tablet 768, Mobile 390 | The devices the page can be previewed at, from the top bar. Each may have a `media` condition; it is kept in the configuration, and the screen does not use it. |
| `tailwind.preview` | `false` | For a site whose CSS is Tailwind built by Vite: adds Tailwind's browser build to pages drawn for the editor, so a class shows before the CSS is built again (`SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW`). See [Styles](styles.md#sites-that-build-their-css). |
| `tailwind.entry` | `resources/css/app.css` | The site's CSS entry file, which the preview reads for the theme. |
| `tailwind.script` | Tailwind's browser build on jsDelivr | Where the browser fetches the preview's script from. Point it at a copy of your own to work without the network. |
| `build.command` | none | For a site that builds its CSS: the one command the editor's **Build** button runs, in the site's directory, such as `npm run build` (`SUNRICE_VISUAL_EDITOR_BUILD`). Without it there is no button. Node has to be installed on the server for that command. See [Styles](styles.md#building-from-the-editor). |
| `build.timeout` | `120` | Seconds a build may take before it is stopped. |
| `paths.templates`, `paths.theme` | `null` | Where the site's templates and theme files are, if not in Sunrice's own places. |

## Updating

```bash
composer update sunrice/visual-editor
php artisan vendor:publish --tag=sunrice-visual-editor-assets --force
php artisan sunrice:sync-permissions
```

## Removing

```bash
composer remove sunrice/visual-editor
rm -rf public/vendor/sunrice-visual-editor
```

Nothing of the editor is in your templates or your database, apart from its
four permissions, which Sunrice leaves in place and which do nothing
without the package. `public/sunrice-theme/editor.css` and
`public/sunrice-theme/custom.css`, if the editor made them, are yours: they
hold CSS your pages use. Without the package nothing links `custom.css`
any more, so link it from the layout if you keep it.
