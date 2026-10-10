{{-- Entry teaser used by archives and listings. $entry->url is null for
     collections whose entries have no page of their own: no links then. --}}
<li class="card">
    {{-- An asset field with "Allow multiple" returns a collection: use the first. --}}
    @php($image = $entry->get('image'))
    @php($image = $image instanceof \Illuminate\Support\Collection ? $image->first() : $image)
    @if ($image)
        @if ($entry->url)
            <a href="{{ $entry->url }}"><img src="{{ $image->url('medium') }}" alt="{{ $image->alt ?? $entry->title }}" loading="lazy"></a>
        @else
            <img src="{{ $image->url('medium') }}" alt="{{ $image->alt ?? $entry->title }}" loading="lazy">
        @endif
    @endif
    @if ($entry->published_at)
        <time class="meta" datetime="{{ $entry->published_at->toAtomString() }}">{{ $entry->published_at->translatedFormat('j F Y') }}</time>
    @endif
    <h2>@if ($entry->url)<a href="{{ $entry->url }}">{{ $entry->title }}</a>@else{{ $entry->title }}@endif</h2>
    @if ($excerpt = $entry->get('excerpt'))
        <p class="muted">{{ $excerpt }}</p>
    @endif
</li>
