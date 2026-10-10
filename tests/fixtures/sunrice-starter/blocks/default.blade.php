{{--
    Fallback for block types without their own template. Create
    resources/views/sunrice/blocks/{type}.blade.php to render a block type.
--}}
@if (config('app.debug'))
    <section class="block muted" id="block-{{ $block->id }}">
        <p><strong>No template for block “{{ $block->type }}”.</strong>
           Create <code>resources/views/sunrice/blocks/{{ $block->type }}.blade.php</code>.</p>
    </section>
@endif
