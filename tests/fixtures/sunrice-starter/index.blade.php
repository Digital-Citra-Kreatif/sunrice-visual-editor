{{--
    Default collection archive (resolved as `sunrice.index`), shown at the
    collection's archive route when "Has archive" is enabled.

    Available: $entries (paginator of Entry, already resolved for $locale),
    $collection, $locale, $pageType.

    $collection->titleIn($locale) is the collection's title in the active
    language (Structure → Collections → Title per language).

    Listing page content is edited from the collection's entries list
    (Listing page button):
      $collection->archiveText($locale)   ['title' => ..., 'intro' => ...] in the
                                          active language (main language fallback)
      $collection->archive('handle')      a field of the collection's listing
                                          blueprint, hydrated like entry fields

    This template assumes these optional listing-blueprint fields:
    `image` (asset) and `description` (rich text).
--}}
@php($listing = $collection->archiveText($locale))
@extends('sunrice.layouts.app', ['seoTitle' => $listing['title'] ?: $collection->titleIn($locale)])

@section('content')
    @php($image = $collection->archive('image'))
    @php($image = $image instanceof \Illuminate\Support\Collection ? $image->first() : $image)
    <section class="hero">
        @if ($image)
            <img src="{{ $image->url('large') }}" alt="{{ $image->alt ?? '' }}">
        @endif
        <h1>{{ $listing['title'] ?: $collection->titleIn($locale) }}</h1>

        @if ($listing['intro'])
            <p class="muted">{{ $listing['intro'] }}</p>
        @endif

        {{-- Rich text is sanitized when saved. --}}
        @if ($description = $collection->archive('description'))
            <div class="prose">{!! $description !!}</div>
        @endif
    </section>

    @if ($entries->isEmpty())
        <p class="muted">{{ __('sunrice::frontend.nothing_yet') }}</p>
    @else
        <ul class="cards">
            @foreach ($entries as $entry)
                @include('sunrice.partials.card', ['entry' => $entry])
            @endforeach
        </ul>

        {{ $entries->links('sunrice.partials.pagination') }}
    @endif
@endsection
