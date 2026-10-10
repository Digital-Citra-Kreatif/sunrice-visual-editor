<!DOCTYPE html>
<html lang="{{ $locale ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <x-sunrice::seo :default-title="__('sunrice::frontend.search')" />
    <x-sunrice::code position="head" />
</head>
<body @bodyClass('page-search')>
    <x-sunrice::code position="body_start" />
    <h1>{{ $query === '' ? __('sunrice::frontend.search') : __('sunrice::frontend.search_results_for', ['query' => $query]) }}</h1>
    <form method="get" role="search">
        <input type="search" name="q" value="{{ $query }}" aria-label="{{ __('sunrice::frontend.search') }}">
        <button type="submit">{{ __('sunrice::frontend.search') }}</button>
    </form>
    @if ($query !== '' && $results->isEmpty())
        <p>{{ __('sunrice::frontend.search_nothing', ['query' => $query]) }}</p>
    @endif
    <ul>
        @foreach ($results as $entry)
            <li><a href="{{ $entry->url }}">{{ $entry->title }}</a></li>
        @endforeach
    </ul>
    {{ $results->links() }}
    <x-sunrice::code position="body_end" />
</body>
</html>
