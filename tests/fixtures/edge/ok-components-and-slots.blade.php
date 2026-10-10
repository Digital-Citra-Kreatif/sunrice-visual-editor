<x-sunrice::entries collection="articles" :paginate="true" :per-page="6" :where="[['price', '>', 10]]">
    <ul class="cards">
        @forelse ($component->entries as $entry)
            @include('sunrice.partials.card', ['entry' => $entry])
        @empty
            <li class="muted">{{ __('sunrice::frontend.nothing_here') }}</li>
        @endforelse
    </ul>
    {{ $component->entries->links('sunrice.partials.pagination') }}
</x-sunrice::entries>
<x-sunrice::seo :default-title="$heading" />
