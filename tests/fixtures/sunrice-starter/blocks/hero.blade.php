{{--
    Flexible-content block for a fieldset with handle `hero`.
    Fields: `heading` (text), `subheading` (textarea), `image` (asset), `button` (link).
--}}
<section class="block hero" id="block-{{ $block->id }}">
    @php($image = $block->image instanceof \Illuminate\Support\Collection ? $block->image->first() : $block->image)
    @if ($image)
        <img src="{{ $image->url('large') }}" alt="{{ $image->alt ?? '' }}">
    @endif
    <h1>{{ $block->heading }}</h1>
    @if ($block->subheading)
        <p class="muted">{{ $block->subheading }}</p>
    @endif
    {{-- Link fields hydrate to ['url' => ..., 'label' => ..., 'new_tab' => bool];
         links to entries already point at the entry's URL in the active language. --}}
    @if (! empty($block->button['url']))
        <a class="button" href="{{ $block->button['url'] }}" @if (! empty($block->button['new_tab'])) target="_blank" rel="noopener" @endif>
            {{ ($block->button['label'] ?? null) ?: __('sunrice::frontend.learn_more') }}
        </a>
    @endif
</section>
