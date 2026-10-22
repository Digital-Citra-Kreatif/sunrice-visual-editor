{{-- The gallery block: a title and its images. Gets $block. --}}
<div class="gallery">
    @if ($block->title)
        <h2>{{ $block->title }}</h2>
    @endif
    <ul>
        @foreach ($block->images ?? [] as $image)
            <li><img src="{{ $image->url('medium') }}" alt="{{ $image->altIn() }}"></li>
        @endforeach
    </ul>
</div>
