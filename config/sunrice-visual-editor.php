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
