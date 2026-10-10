{{--
    Site header: logo and name from a global set, the main menu, the search
    box (partials/search-form) and the language switcher
    (partials/language-switcher).

    On small screens the menu, search and languages fold into a "Menu"
    button (public/sunrice-theme/app.js toggles it; without JavaScript
    everything simply stays visible). Sub-menus open as dropdowns on wide
    screens and are listed indented on small ones.

    Admin setup this partial expects (all optional — it renders without them):
      - Global set `site` with fields: `name` (text), `logo` (asset, single image)
      - Menu `main`
--}}
@php
    $site = sunrice_global('site');           // GlobalData: read fields as properties or ->get('field', 'default')
    $mainMenu = sunrice_menu('main');          // Collection of MenuNode (label, url, newTab, children)
@endphp

<header class="site-header">
    <div class="container">
        <a class="brand" href="{{ url(\Sunrice\Support\Locales::prefix($locale) ?: '/') }}">
            @if ($site->logo)
                {{-- Asset fields hydrate to Sunrice\Models\Asset: url(), url('thumbnail'|'medium'|'large'), alt, title --}}
                <img src="{{ $site->logo->url('medium') }}" alt="{{ $site->logo->alt ?? $site->get('name') }}">
            @endif
            <span>{{ $site->get('name', config('app.name')) }}</span>
        </a>

        <button class="menu-toggle" type="button" aria-controls="site-menu" aria-expanded="false" data-menu-toggle>
            <span class="menu-toggle__icon" aria-hidden="true"></span>
            {{ __('sunrice::frontend.menu') }}
        </button>

        <div class="site-menu" id="site-menu">
            @if ($mainMenu->isNotEmpty())
                <nav class="main-nav" aria-label="{{ __('sunrice::frontend.main_menu') }}">
                    @include('sunrice.partials.menu', ['items' => $mainMenu])
                </nav>
            @endif

            @if (Route::has('sunrice.frontend.search'))
                @include('sunrice.partials.search-form')
            @endif

            @include('sunrice.partials.language-switcher')
        </div>
    </div>
</header>
