{{-- The top half of a layout split in two files: what it opens, the footer closes. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? config('app.name') }}</title>
    @stack('styles')
</head>
<body class="{{ $bodyClass ?? '' }}">
    <header class="site-header">
        <a class="logo" href="{{ url('/') }}">{{ config('app.name') }}</a>
        <nav>
            @foreach ($menu as $item)
                <a href="{{ $item->url }}" @class(['active' => $item->active])>{{ $item->title }}</a>
            @endforeach
        </nav>
    </header>
    <main id="content">
