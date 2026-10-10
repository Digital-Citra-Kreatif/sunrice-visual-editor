{{--
    Page not found, with the site's header and footer. Laravel renders
    resources/views/errors/404.blade.php for any 404; Sunrice marks the page
    noindex. Published with the starter templates.
--}}
@php($locale = app()->getLocale())
@extends('sunrice.layouts.app', ['seoTitle' => __('sunrice::frontend.error_404_title')])

@section('content')
    <section class="error-page">
        <p class="error-page__code">404</p>
        <h1>{{ __('sunrice::frontend.error_404_title') }}</h1>
        <p class="muted">{{ __('sunrice::frontend.error_404_text') }}</p>

        @if (Route::has('sunrice.frontend.search'))
            @include('sunrice.partials.search-form')
        @endif

        <p><a class="button" href="{{ url(\Sunrice\Support\Locales::prefix($locale) ?: '/') }}">{{ __('sunrice::frontend.back_home') }}</a></p>
    </section>
@endsection
