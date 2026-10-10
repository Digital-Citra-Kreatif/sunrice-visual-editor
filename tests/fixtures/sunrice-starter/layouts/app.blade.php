{{--
    Base layout for every Sunrice page.

    Child templates `@extends('sunrice.layouts.app')` and fill:
      - @section('content')   the page body
    and may pass `seoTitle`, the title used when the page has no meta title of its own (e.g. @extends('sunrice.layouts.app', ['seoTitle' => '...'])).

    Variables Sunrice passes to every template: $locale, $pageType
    ('entry' | 'archive' | 'term'), plus $entry / $entries / $collection /
    $term / $taxonomy depending on the page type, and $sunricePage — the
    same page info (->entry, ->term, ->collection, ->taxonomy) that a
    template's own loops can't overwrite. Layouts and partials read the
    page from $sunricePage: a child's `@foreach ($entries as $entry)`
    leaves $entry set to the last card when the layout renders.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale ?? app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- SEO tags for the current page (entry, term or listing page): title, description, robots, canonical, Open Graph, hreflang. --}}
    <x-sunrice::seo :default-title="$seoTitle ?? null" />

    {{-- Starter styles: public/sunrice-theme/app.css (published with the templates). Replace with your own CSS (Vite, Tailwind…). --}}
    @if (is_file($sunriceCss = public_path('sunrice-theme/app.css')))
        <link rel="stylesheet" href="{{ asset('sunrice-theme/app.css') }}?v={{ filemtime($sunriceCss) }}">
    @endif
    {{-- Starter script: the mobile menu button (public/sunrice-theme/app.js). --}}
    @if (is_file($sunriceJs = public_path('sunrice-theme/app.js')))
        <script src="{{ asset('sunrice-theme/app.js') }}?v={{ filemtime($sunriceJs) }}"></script>
    @endif
    @stack('head')
    <x-sunrice::code position="head" />
</head>
<body @bodyClass>
    <x-sunrice::code position="body_start" />
    @include('sunrice.partials.header')

    <main class="container">
        @yield('content')
    </main>

    @include('sunrice.partials.footer')
    @stack('scripts')
    <x-sunrice::code position="body_end" />
</body>
</html>
