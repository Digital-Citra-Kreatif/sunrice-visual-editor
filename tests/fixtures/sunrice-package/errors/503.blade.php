{{--
    Maintenance mode (php artisan down) or an unavailable service. Standalone: no layout, menus or database
    queries, since whatever failed may fail again here. Laravel renders
    resources/views/errors/503.blade.php.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('sunrice::frontend.error_500_title') }}</title>
    @if (is_file(public_path('sunrice-theme/app.css')))
        <link rel="stylesheet" href="{{ asset('sunrice-theme/app.css') }}">
    @endif
</head>
<body>
    <main class="container error-page">
        <p class="error-page__code">503</p>
        <h1>{{ __('sunrice::frontend.error_500_title') }}</h1>
        <p class="muted">{{ __('sunrice::frontend.error_500_text') }}</p>
        <p><a class="button" href="{{ url('/') }}">{{ __('sunrice::frontend.back_home') }}</a></p>
    </main>
</body>
</html>
