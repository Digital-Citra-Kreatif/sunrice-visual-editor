{{--
    A "gallery" block: `title` (text), `images` (media, several): a collection of Sunrice\Admin\Media\Media.
--}}
@if ($block->title)
    <h2>{{ $block->title }}</h2>
@endif
<div class="gallery">
    @foreach ($block->images ?? [] as $image)
        <a href="{{ $image->url() }}">
            <img src="{{ $image->url('thumbnail') }}" alt="{{ $image->altIn() ?? '' }}" loading="lazy">
        </a>
    @endforeach
</div>
