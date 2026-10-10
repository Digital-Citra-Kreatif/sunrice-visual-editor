<article class="card">
    @if ($entry->url)
        <a class="card-link" href="{{ $entry->url }}">
    @else
        <div class="card-link">
    @endif
        <h3>{{ $entry->title }}</h3>
        <p>{{ $entry->excerpt }}</p>
    @if ($entry->url)
        </a>
    @else
        </div>
    @endif
</article>
