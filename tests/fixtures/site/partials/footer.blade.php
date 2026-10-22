{{--
    A footer of the site's own: the `footer` global set's text and the `footer` menu.
    @include('cms.partials.footer')
--}}
@php
    $footer = cms_global('footer');
    $footerMenu = cms_menu('footer');
@endphp

<footer class="site-footer">
    <div class="container">
        @if ($footerMenu !== [])
            <nav aria-label="{{ __('Footer') }}">
                @include('cms.partials.menu', ['items' => $footerMenu])
            </nav>
        @endif

        @if (is_string($text = $footer['text'] ?? null) && $text !== '')
            <p>{{ $text }}</p>
        @endif

        <p>&copy; {{ now()->year }} {{ \Sunrice\Cms\Content::site()->name() }}</p>
    </div>
</footer>
