{{--
    Language switcher: a link to this page in every language.

    Included by partials/header; @include it anywhere else too (footer,
    mobile menu). Styles: the .lang-switch rules in
    public/sunrice-theme/app.css.

    - sunrice_locale_urls() returns ['id' => '/tentang', 'en' => '/en/about']:
      this entry, term page or listing in each language.
    - Language names come from Settings → Languages; the code is the fallback.
    - $sunricePage describes the page itself ($entry/$term may be leftovers
      of a template loop).
--}}
@php
    $page = $sunricePage ?? null;
    $languages = sunrice_locale_urls($page?->entry ?? $page?->term, $page?->term ? $page->collection : null);
@endphp

@if (count($languages) > 1)
    <nav class="lang-switch" aria-label="{{ __('sunrice::frontend.languages') }}">
        @foreach ($languages as $code => $href)
            <a href="{{ $href }}" hreflang="{{ $code }}" lang="{{ $code }}" @if ($code === $locale) aria-current="true" @endif>{{ \Sunrice\Support\Locales::name($code) }}</a>
        @endforeach
    </nav>
@endif
