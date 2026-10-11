<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | The editor writes Blade files, which run PHP on the server and belong
    | in the project's git repository. It is a development tool: on by
    | default on a local copy of the site only. Set SUNRICE_VISUAL_EDITOR=true
    | to use it elsewhere (a staging copy), false to turn it off locally.
    |
    | When off, the package registers no routes and changes nothing about
    | how pages render.
    |
    */

    'enabled' => env('SUNRICE_VISUAL_EDITOR', env('APP_ENV', 'production') === 'local'),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Keep the previous version of every file the editor writes, in
    | storage/app/sunrice/template-backups/, like templates written by AI
    | agents.
    |
    */

    'backups' => true,

    /*
    |--------------------------------------------------------------------------
    | Editor stylesheet
    |--------------------------------------------------------------------------
    |
    | File in public/sunrice-theme/ that receives CSS rules the editor
    | creates. Rules that already exist are changed where they are.
    |
    */

    'theme_css' => 'editor.css',

    /*
    |--------------------------------------------------------------------------
    | Widths
    |--------------------------------------------------------------------------
    |
    | The widths the editor shows the page at, one at a time or side by side.
    | A style set while a width is being edited is written for that width:
    | under the @media condition of the site's own CSS that applies there,
    | or, when none does, under the `media` given here.
    |
    */

    'widths' => [
        ['name' => 'Desktop', 'width' => 1280],
        ['name' => 'Tablet', 'width' => 768, 'media' => '(max-width: 1024px)'],
        ['name' => 'Mobile', 'width' => 390, 'media' => '(max-width: 640px)'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tailwind preview
    |--------------------------------------------------------------------------
    |
    | For a site whose CSS is Tailwind built by Vite. Tailwind writes a rule
    | only for the classes it has found, so a class added in the editor has
    | no style until the CSS is built again. With `preview` on, pages drawn
    | for the editor (and no others) also get Tailwind's own browser build:
    | any class shows at once.
    |
    | It is a preview: it reads `entry` for the site's theme and loads no
    | plugins. It is left out while the site's Vite dev server is running,
    | which serves the real CSS as it changes. `script` is fetched by the
    | browser: point it at a copy of your own to work without the network.
    |
    */

    'tailwind' => [
        'preview' => env('SUNRICE_VISUAL_EDITOR_TAILWIND_PREVIEW', false),
        'entry' => 'resources/css/app.css',
        'script' => 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4',
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Where the site's templates and theme files are. Leave both null for
    | Sunrice's own places: resources/views/sunrice and public/sunrice-theme.
    | The templates directory must be named "sunrice" and its parent must be
    | a view path, since templates are the views "sunrice.…".
    |
    */

    'paths' => [
        'templates' => null,
        'theme' => null,
    ],

];
