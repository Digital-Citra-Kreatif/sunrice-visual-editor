{{-- The hero block: a heading, a line under it and a button. Gets $block. --}}
<div class="hero">
    <h1 class="hero-heading">{{ $block->heading }}</h1>
    @if ($block->subheading)
        <p class="hero-subheading">{{ $block->subheading }}</p>
    @endif
    @if ($block->button && $block->button['url'])
        <a class="button" href="{{ $block->button['url'] }}">{{ $block->button['label'] ?? $block->button['url'] }}</a>
    @endif
</div>
