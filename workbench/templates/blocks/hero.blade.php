{{--
    A "hero" block (the fieldset of that handle), drawn by cms.partials.blocks for each such block.
    Fields: `heading` (text), `subheading` (textarea), `button` (link).
--}}
<section class="hero">
    <h1>{{ $block->heading }}</h1>
    @if ($block->subheading)
        <p class="muted">{{ $block->subheading }}</p>
    @endif
    {{-- A link field is ['type', 'url', 'label', 'title', 'new_tab']; a link to an entry has the entry's URL in the page's language. --}}
    @php($link = $block->button)
    @if (! empty($link['url']))
        <a class="button" href="{{ $link['url'] }}" @if (! empty($link['new_tab'])) target="_blank" rel="noopener" @endif>
            {{ ($link['label'] ?? null) ?: __('Learn more') }}
        </a>
    @endif
</section>
