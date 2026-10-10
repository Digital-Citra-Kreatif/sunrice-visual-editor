{{--
    Default term archive (resolved as `sunrice.taxonomies.show`; add
    `sunrice/taxonomies/{taxonomy}/show.blade.php` for one taxonomy),
    shown at the taxonomy's route when its archive is enabled.

    Available: $term, $taxonomy, $entries (paginator), $locale, $pageType,
    and $collection: the collection this page lists (per-collection term
    pages such as /blog/category/news), or null for one page across all.

    $taxonomy->titleIn($locale) / $collection->titleIn($locale)   titles in the active language
    $term->name, $term->slug, $term->url    in the active language
    $term->urlIn($collection)               the term's page for one collection
    $term->parent / $term->children         for hierarchical taxonomies
    $term->get('handle')                    a field from the taxonomy blueprint (hydrated like entry fields)
--}}
@extends('sunrice.layouts.app', ['seoTitle' => $term->name.' — '.$taxonomy->titleIn($locale)])

@section('content')
    <p class="meta">{{ $collection ? $collection->titleIn($locale).' · ' : '' }}{{ $taxonomy->titleIn($locale) }}</p>
    <h1>{{ $term->name }}</h1>

    @if ($description = $term->get('description'))
        <p class="muted">{{ $description }}</p>
    @endif

    @if ($term->children->isNotEmpty())
        <ul class="tags">
            @foreach ($term->children as $child)
                @php($child->resolveFor($locale))
                <li><a href="{{ $collection ? $child->urlIn($collection) : $child->url }}">{{ $child->name }}</a></li>
            @endforeach
        </ul>
    @endif

    @if ($entries->isEmpty())
        <p class="muted">{{ __('sunrice::frontend.nothing_in_term', ['term' => $term->name]) }}</p>
    @else
        <ul class="cards">
            @foreach ($entries as $entry)
                @include('sunrice.partials.card', ['entry' => $entry])
            @endforeach
        </ul>

        {{ $entries->links('sunrice.partials.pagination') }}
    @endif
@endsection
