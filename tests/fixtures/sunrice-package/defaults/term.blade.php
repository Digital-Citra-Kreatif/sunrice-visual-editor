<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-sunrice::seo />
    <x-sunrice::code position="head" />
</head>
<body @bodyClass>
    <x-sunrice::code position="body_start" />
    <h1>{{ $term->name }}</h1>
    <ul>
        @foreach($entries as $entry)
            <li>@if($entry->url)<a href="{{ $entry->url }}">{{ $entry->title }}</a>@else{{ $entry->title }}@endif</li>
        @endforeach
    </ul>
    @if($entries instanceof \Illuminate\Contracts\Pagination\Paginator)
        {{ $entries->links() }}
    @endif
    <x-sunrice::code position="body_end" />
</body>
</html>
