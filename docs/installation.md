# Installing the visual editor

The visual editor is a plugin for a [Sunrice](https://github.com/Digital-Citra-Kreatif/sunrice)
site: the panel (`sunrice/admin`) with the CMS (`sunrice/cms`). It edits the
site's Blade templates and theme stylesheets in the browser, and writes the
files themselves.

## Before you start

- **It is a development tool.** It writes files that run PHP on the server
  and belong in the project's git repository. Use it on a local copy of the
  site, or on a staging copy, then commit and deploy. It is off on
  production unless you turn it on.
- PHP 8.4 or newer, and a site that already runs the Sunrice panel with the
  CMS plugin (Laravel 13).
- The web server's user must be able to write `resources/views/cms/` (the
  site's templates) and `public/cms/` (its stylesheets and scripts). On a
  local copy served with `php artisan serve` that is already so.

## Install

The package is not on Packagist yet, and neither are Sunrice's packages: a
site gets both from their repositories. With Sunrice checked out beside the
site (`../sunrice`) and this package beside it too:

```bash
composer config repositories.sunrice path '../sunrice/packages/*'
composer config repositories.sunrice-visual-editor path ../sunrice-visual-editor
composer require sunrice/visual-editor:@dev
```

Add the plugin to the panel, after the CMS, in the site's panel provider:

```php
use Sunrice\Cms\CmsPlugin;
use Sunrice\VisualEditor\VisualEditorPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        ->path('admin')
        ->plugin(CmsPlugin::make())
        ->plugin(VisualEditorPlugin::make());
}
```

Publish its scripts and stylesheet, and create its permissions:

```bash
php artisan vendor:publish --tag=sunrice-visual-editor-assets
php artisan sunrice:sync-permissions
```

The assets are also published under the `laravel-assets` tag, which Laravel
apps publish after every `composer update`. When the published copy is
older than the package's build, super admins see a warning at the top of
the panel saying so, with the command to run.

**Visual editor** now appears in the panel's sidebar, under *Structure*,
for anyone who may open it. It is at `/admin/visual-editor` (`/admin` being
the panel's path), and `/admin/visual-editor?url=/about` opens it on that
page. The ⋮ menu of a live entry's page in the panel has **Edit with Visual
Editor**, which opens the editor on that entry's page.

## Where it is on

| `APP_ENV` | `SUNRICE_VISUAL_EDITOR` | The editor is |
| --- | --- | --- |
| `local` | not set | on |
| anything else | not set | off |
| any | `true` | on |
| any | `false` | off |

When it is off the plugin adds no routes, no sidebar item, no script and no
tools for agents, and changes nothing about how pages are drawn. Its
permissions stay registered, so roles keep them.

## Who may use it

Super admins, and the built-in administrator role, may do everything. For
anyone else, give their role one of these in **Users → Roles**, under
*Visual editor*:

| Permission | Lets someone |
| --- | --- |
| Open the visual editor | look at the page, its templates, their layers and code |
| Change templates and styles in the visual editor | move, copy and delete in the tree, write the custom CSS, without writing code |
| Edit template code in the visual editor | write Blade and PHP |

Each includes the ones above it. [Permissions](permissions.md) says exactly
where the line between the last two is. Every one of them also needs the
panel's own *Open the admin panel* (`sunrice.access`).

A fourth stands apart from those: **Run the site's build command from the
visual editor**. It does nothing until a [build command](#settings) is
set, and none of the three above includes it. Getting it, or the code
permission, is a privileged permission: the panel's security alerts email
about it.

## Settings

Publish the config file to change any of these:

```bash
php artisan vendor:publish --tag=sunrice-visual-editor-config
```

`config/sunrice-visual-editor.php`:

| Key | Default | What it does |
| --- | --- | --- |
| `enabled` | on when `APP_ENV` is `local` | Turns the editor on or off (`SUNRICE_VISUAL_EDITOR`). |
| `agents` | `true` | Lets AI agents change templates with `visual_editor_edit_template`, held to the token's user's permissions in the editor (`SUNRICE_VISUAL_EDITOR_AGENTS`). See [AI agents](agents.md). |
| `backups` | `true` | Keeps the previous version of every file the editor writes, in `storage/app/sunrice-cms/template-backups/`, beside the CMS's copies of templates written by agents. Leave it on: undo for people without the code permission depends on it. |
| `theme_css` | `editor.css` | The file in `public/cms/` that receives CSS rules for classes that have none yet. |
| `custom_css.file` | `custom.css` | The site's own stylesheet in `public/cms/`, written in the editor's **Custom CSS** tab and linked from every page. See [Styles](styles.md#custom-css). |
| `custom_css.inject` | `true` | Links that file from every page, by pushing a `<link>` onto the layout's stack (`SUNRICE_VISUAL_EDITOR_CUSTOM_CSS`). It does so whether or not the editor is on, so the deployed site has the same CSS. Set it to `false` to link the file from the layout yourself. |
| `custom_css.stack` | `head` | The stack the link is pushed onto: the layout's `@stack('head')` in the CMS's starter layout. |
| `custom_js.file` | `custom.js` | The site's own script in `public/cms/`, written in the editor's **Custom JS** tab by people who may edit code, and loaded on every page. See [Styles](styles.md#custom-js). |
| `custom_js.inject` | `true` | Loads that file, deferred, on every page, by pushing a `<script>` onto the layout's stack (`SUNRICE_VISUAL_EDITOR_CUSTOM_JS`), whether or not the editor is on. Set it to `false` to load the file from the layout yourself. |
| `custom_js.stack` | `head` | The stack the script is pushed onto: the layout's `@stack('head')` in the CMS's starter layout. Deferred, it runs once the page is read. |
| `widths` | Desktop 1280, Tablet 768, Mobile 390 | The devices the page can be previewed at, from the top bar. Each may have a `media` condition; it is kept in the configuration, and the screen does not use it. |
| `tailwind.preview` | `false` | For a site whose CSS is Tailwind built by Vite: adds Tailwind's browser build to pages drawn for the editor, so a class shows before the CSS is built again (`SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW`). See [Styles](styles.md#sites-that-build-their-css). |
| `tailwind.entry` | `resources/css/app.css` | The site's CSS entry file, which the preview reads for the theme. |
| `tailwind.script` | Tailwind's browser build on jsDelivr | Where the browser fetches the preview's script from. Point it at a copy of your own to work without the network. |
| `build.command` | none | For a site that builds its CSS: the one command the editor's **Build** button runs, in the site's directory, such as `npm run build` (`SUNRICE_VISUAL_EDITOR_BUILD`). Without it there is no button. Node has to be installed on the server for that command. See [Styles](styles.md#building-from-the-editor). |
| `build.timeout` | `120` | Seconds a build may take before it is stopped. |
| `paths.templates`, `paths.theme` | `null` | Where the site's templates and theme files are, if not in the CMS's own places (`resources/views/cms`, `public/cms`). |

## Updating

```bash
composer update sunrice/visual-editor
php artisan vendor:publish --tag=sunrice-visual-editor-assets --force
php artisan sunrice:sync-permissions
```

## Removing

Take `VisualEditorPlugin::make()` out of the panel provider, then:

```bash
composer remove sunrice/visual-editor
rm -rf public/vendor/sunrice-visual-editor
php artisan sunrice:sync-permissions
```

Nothing of the editor is in your templates or your database, apart from its
four permissions, which the last command removes. `public/cms/editor.css`
and `public/cms/custom.css`, if the editor made them, are yours: they hold
CSS your pages use. Without the package nothing links `custom.css` any
more, so link it from the layout if you keep it.
