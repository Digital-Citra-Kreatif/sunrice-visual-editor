{{--
    Search results (resolved as `sunrice.search`), at /search?q=… and
    /{locale}/search. Search pages are kept out of search engines.

    Available: $query (the searched text), $results (paginator of entries,
    resolved for $locale), $locale, $pageType ('search').

    The search covers published entries that have pages: their title and
    field text. Narrow it in config/sunrice.php (search.collections), or
    search inside a template with
    <x-sunrice::search :results="true" collections="articles">.
--}}
@extends('sunrice.layouts.app', ['seoTitle' => $query === '' ? __('sunrice::frontend.search') : __('sunrice::frontend.search_results_for', ['query' => $query])])

@section('content')
    <section class="search-page">
        <h1>{{ $query === '' ? __('sunrice::frontend.search') : __('sunrice::frontend.search_results_for', ['query' => $query]) }}</h1>

        @include('sunrice.partials.search-form')

        @if ($query === '')
            <p class="muted">{{ __('sunrice::frontend.search_hint') }}</p>
        @elseif ($results->isEmpty())
            <p class="muted">{{ __('sunrice::frontend.search_nothing', ['query' => $query]) }}</p>
        @else
            <p class="muted">{{ trans_choice('sunrice::frontend.results', $results->total(), ['count' => $results->total()]) }}</p>
            <ul class="search-results">
                @foreach ($results as $entry)
                    <li>
                        <a href="{{ $entry->url }}">{{ $entry->title }}</a>
                        <span class="meta">{{ $entry->collection->titleIn($locale) }}</span>
                        @if ($excerpt = $entry->get('excerpt') ?: $entry->get('summary'))
                            <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $excerpt), 180) }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>

            {{ $results->links('sunrice.partials.pagination') }}
        @endif
    </section>
@endsection
