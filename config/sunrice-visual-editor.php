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
    | AI agents
    |--------------------------------------------------------------------------
    |
    | Whether agents may change templates with visual_editor_edit_template,
    | on the panel's MCP server. They are held to what the token's user may
    | do in the editor: changing takes visual-editor.edit, Blade or PHP of
    | their own visual-editor.code.
    |
    */

    'agents' => env('SUNRICE_VISUAL_EDITOR_AGENTS', true),

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | Keep the previous version of every file the editor writes, in
    | storage/app/sunrice-cms/template-backups/, beside the CMS's copies of
    | templates written by AI agents.
    |
    */

    'backups' => true,

    /*
    |--------------------------------------------------------------------------
    | Editor stylesheet
    |--------------------------------------------------------------------------
    |
    | File in public/cms/ that receives CSS rules the editor
    | creates. Rules that already exist are changed where they are.
    |
    */

    'theme_css' => 'editor.css',

    /*
    |--------------------------------------------------------------------------
    | Custom CSS
    |--------------------------------------------------------------------------
    |
    | A stylesheet of the site's own, in public/cms/, written in the
    | editor's Custom CSS tab. It is linked from every page of the site by
    | pushing a <link> onto the layout's `@stack('head')` (the CMS's starter
    | layout has one): no template is changed for it.
    |
    | The link is added whether or not the editor is on, so the deployed
    | site gets the same CSS as the copy it was written on. Set `inject` to
    | false to link the file from the layout yourself instead.
    |
    */

    'custom_css' => [
        'file' => 'custom.css',
        'inject' => env('SUNRICE_VISUAL_EDITOR_CUSTOM_CSS', true),
        'stack' => 'head',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom JS
    |--------------------------------------------------------------------------
    |
    | A script of the site's own, in public/cms/, written in the editor's
    | Custom JS tab by people who may edit code. Every page of the site
    | loads it (deferred) from a <script> pushed onto the layout's
    | `@stack('head')` (the CMS's starter layout has one): no template is
    | changed for it. For a script one template needs, write
    | `@push('head')` in that template instead.
    |
    | As for the custom CSS, it is added whether or not the editor is on.
    | Set `inject` to false to load the file from the layout yourself.
    |
    */

    'custom_js' => [
        'file' => 'custom.js',
        'inject' => env('SUNRICE_VISUAL_EDITOR_CUSTOM_JS', true),
        'stack' => 'head',
    ],

    /*
    |--------------------------------------------------------------------------
    | Widths
    |--------------------------------------------------------------------------
    |
    | The devices the editor previews the page at, one at a time or side by
    | side, from the top bar. `media` is the @media condition the device
    | stands for, kept for tools that write CSS for it.
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
    | Build
    |--------------------------------------------------------------------------
    |
    | For a site whose CSS is built (Tailwind through Vite). When a command
    | is set here, the editor has a Build button that runs exactly this
    | command in the site's directory, shows what it printed, and says when
    | the built CSS is behind the templates. Without one there is no button.
    |
    | The command is this setting and nothing else: nothing a request sends
    | is ever run. Running it takes a permission of its own
    | (visual-editor.build), and it is stopped after `timeout` seconds.
    | Node has to be installed where the site runs: Sunrice itself does not
    | need it.
    |
    */

    'build' => [
        'command' => env('SUNRICE_VISUAL_EDITOR_BUILD'),
        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Paths
    |--------------------------------------------------------------------------
    |
    | Where the site's templates and theme files are. Leave both null for
    | the CMS's own places: resources/views/cms and public/cms. The
    | templates directory must be named "cms" and its parent must be a view
    | path, since templates are the views "cms.…".
    |
    */

    'paths' => [
        'templates' => null,
        'theme' => null,
    ],

];
