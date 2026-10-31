# Installing the visual editor

The visual editor is a package for a site built with
[Sunrice CMS](https://github.com/Digital-Citra-Kreatif/sunrice): a plugin
for the Sunrice panel (`sunrice/admin`), beside the CMS (`sunrice/cms`). It
edits the site's Blade templates and theme stylesheets in the browser, and
writes the files themselves.

## Before you start

- **It is a development tool.** It writes files that run PHP on the server
  and belong in the project's git repository. Use it on a local copy of the
  site, or on a staging copy, then commit and deploy. It is off on
  production unless you turn it on.
- PHP 8.4 or newer, and a site that already runs Sunrice 0.2.0 or later:
  the panel and the CMS, installed
  with `php artisan sunrice:install` (Laravel 13). The entry pages' link
  to the editor needs the CMS's `Cms::entryLinksUsing()` (0.2.0).
- The web server's user must be able to write `resources/views/cms/` and
  `public/cms/`. On a local copy served with `php artisan serve` that is
  already so.

## Install

The package is not on Packagist yet. Tell Composer where it is, then require
it:

```bash
composer config repositories.sunrice-visual-editor '{"type":"vcs","url":"https://github.com/Digital-Citra-Kreatif/sunrice-visual-editor","no-api":true}'
composer require sunrice/visual-editor:dev-main
```

The public VCS install uses Git with `no-api: true`, avoiding GitHub API
metadata requests and anonymous API rate limits. Git must be installed;
a public checkout needs no GitHub token. Private repositories still need
their own Git credentials.

If the application's `composer.json` already declares other GitHub VCS
repositories (for example the Sunrice panel and CMS), add `"no-api": true`
to those entries too. Composer uses the application's repository settings;
settings inside a dependency are not inherited. Keep existing path repositories
as path repositories. The fresh-site CI setup applies this to the Sunrice splits.

For a monorepo or CI, use a local checkout as a path repository instead.
This also avoids enumerating remote branches. From the Laravel application:

```bash
composer config repositories.sunrice-visual-editor '{"type":"path","url":"../sunrice-visual-editor","options":{"symlink":false,"versions":{"sunrice/visual-editor":"dev-main"}}}'
composer require sunrice/visual-editor:dev-main
```

Replace `../sunrice-visual-editor` with the checkout's path. The install CI
uses this path-repository workflow. Until a release is published on
Packagist, use `dev-main`; a `^0.x` package requirement is not yet advertised.

Add its plugin to the panel, after the CMS's. The install command offers
to, and `--plugins` does it without asking:

```bash
php artisan sunrice:install --plugins
```

That adds `->plugin(VisualEditorPlugin::make())` (and its
`use Sunrice\VisualEditor\VisualEditorPlugin;`) to
`app/Providers/AdminPanelProvider.php`, which then reads:

```php
return $panel
    ->path('admin')
    ->plugin(CmsPlugin::make())
    ->plugin(VisualEditorPlugin::make());
```

Then publish its script and stylesheet for the panel, and create its
permissions:

```bash
php artisan vendor:publish --tag=sunrice-visual-editor-assets --force
php artisan sunrice:sync-permissions
```

The first copies the built admin script to
`public/vendor/sunrice-visual-editor/`. Laravel apps publish it again after
every `composer update` (the `laravel-assets` tag), and the panel warns
Super Admins when the copy is out of date.

The permissions exist only where the editor is on, so run the second
command there: on a staging copy, after setting
`SUNRICE_VISUAL_EDITOR=true`.

**Visual editor** now appears in the panel's sidebar, under *Structure*,
for Super Admins and for anyone given one of its permissions. It is also
at `/admin/visual-editor` (`/admin` being the panel's path), and
`/admin/visual-editor?url=/about` opens it on that page. An entry's page
in the admin has **Edit with Visual Editor** in its ⋮ menu, while the
entry is live, and the panel's command palette (Cmd/Ctrl+K) finds the
site's pages by title: **Edit About in the visual editor** opens the
editor on that page.

## Where it is on

| `APP_ENV` | `SUNRICE_VISUAL_EDITOR` | The editor is |
| --- | --- | --- |
| `local` | not set | on |
| anything else | not set | off |
| any | `true` | on |
| any | `false` | off |

When it is off the plugin registers no routes, no permissions, no
navigation and no admin script, and the package changes nothing about how
pages are drawn but the [custom CSS and JS](styles.md) it adds to them.

## Who may use it

Super Admins may do everything. For anyone else, give their role one of
these in **Roles**, under *Visual editor*:

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
| `backups` | `true` | Keeps the previous version of every file the editor writes, in `storage/app/sunrice-cms/template-backups/`, beside the CMS's own. Leave it on: undo for people without the code permission depends on it. |
| `theme_css` | `editor.css` | The file in `public/cms/` that receives CSS rules for classes that have none yet. |
| `custom_css.file` | `custom.css` | The site's own stylesheet in `public/cms/`, written in the editor's **Custom CSS** tab and linked from every page. See [Styles](styles.md#custom-css). |
| `custom_css.inject` | `true` | Links that file from every page, by pushing a `<link>` onto the layout's stack (`SUNRICE_VISUAL_EDITOR_CUSTOM_CSS`). It does so whether or not the editor is on, so the deployed site has the same CSS. Set it to `false` to link the file from the layout yourself. |
| `custom_css.stack` | `head` | The stack the link is pushed onto: the layout's `@stack('head')` in the CMS's starter layout. |
| `custom_js.file` | `custom.js` | The site's own script in `public/cms/`, written in the editor's **Custom JS** tab by people who may edit code, and loaded on every page. See [Styles](styles.md#custom-js). |
| `custom_js.inject` | `true` | Loads that file, deferred, on every page, by pushing a `<script>` onto the layout's stack (`SUNRICE_VISUAL_EDITOR_CUSTOM_JS`), whether or not the editor is on. Set it to `false` to load the file from the layout yourself. |
| `custom_js.stack` | `head` | The stack the script is pushed onto: the layout's `@stack('head')`, as for the CSS; the script is deferred. |
| `widths` | Desktop 1280, Tablet 768, Mobile 390 | The devices the page can be previewed at, from the top bar. Each may have a `media` condition; it is kept in the configuration, and the screen does not use it. |
| `tailwind.preview` | `false` | For a site whose CSS is Tailwind built by Vite: adds Tailwind's browser build to pages drawn for the editor, so a class shows before the CSS is built again (`SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW`). See [Styles](styles.md#sites-that-build-their-css). |
| `tailwind.entry` | `resources/css/app.css` | The site's CSS entry file, which the preview reads for the theme. |
| `tailwind.script` | Tailwind's browser build on jsDelivr | Where the browser fetches the preview's script from. Point it at a copy of your own to work without the network. |
| `build.command` | none | For a site that builds its CSS: the one command the editor's **Build** button runs, in the site's directory, such as `npm run build` (`SUNRICE_VISUAL_EDITOR_BUILD`). Without it there is no button. Node has to be installed on the server for that command. See [Styles](styles.md#building-from-the-editor). |
| `build.timeout` | `120` | Seconds a build may take before it is stopped. |
| `paths.templates`, `paths.theme`, `paths.theme_url` | `null` | Where the site's templates and theme files are, and the URL path the theme is served at, if not the CMS's own places (`resources/views/cms`, `public/cms`, `/cms`). |

## Updating

```bash
composer update sunrice/visual-editor
php artisan vendor:publish --tag=sunrice-visual-editor-assets --force
php artisan sunrice:sync-permissions
```

See the [changelog](../CHANGELOG.md) for what changed.

## Removing

Take `->plugin(VisualEditorPlugin::make())` and its `use` line out of
`app/Providers/AdminPanelProvider.php` first, then:

```bash
composer remove sunrice/visual-editor
rm -rf public/vendor/sunrice-visual-editor
```

Nothing of the editor is in your templates or your database, apart from its
four permissions, which the panel leaves in place and which do nothing
without the package. `public/cms/editor.css`, `public/cms/custom.css` and
`public/cms/custom.js`, if the editor made them, are yours: they hold CSS
and script your pages use. Without the package nothing links them any
more, so link them from the layout if you keep them.
