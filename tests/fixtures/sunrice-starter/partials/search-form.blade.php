{{--
    Search box: a GET form to the search page (/search?q=…, /en/search in
    other languages). <x-sunrice::search> gives the address, the field
    name and what was searched. Include it anywhere (header, 404 page).
--}}
<x-sunrice::search>
    <form class="search-form" method="get" action="{{ $component->action }}" role="search">
        <input type="search" name="{{ $component->name }}" value="{{ $component->query }}"
               placeholder="{{ __('sunrice::frontend.search_placeholder') }}" aria-label="{{ __('sunrice::frontend.search') }}">
        <button type="submit">{{ __('sunrice::frontend.search') }}</button>
    </form>
</x-sunrice::search>
