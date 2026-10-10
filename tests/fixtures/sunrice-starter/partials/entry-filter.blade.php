{{--
    Filter form for <x-sunrice::entry-filter>: one control per declared
    filter, the sort choice and the applied filters with remove links.
    Plain HTML (a GET form), so it works without JavaScript; style it with
    the .entry-filter classes or rewrite it freely.

    Usage, e.g. in a collection's listing template:

    <x-sunrice::entry-filter collection="products"
        :filters="[
            'q' => ['type' => 'search', 'fields' => ['title', 'summary']],
            'category' => ['type' => 'terms', 'taxonomy' => 'categories'],
            'brand' => 'select',
            'price' => 'range',
            'in_stock' => ['type' => 'toggle', 'label' => 'In stock only'],
        ]"
        :sorts="['newest' => '-published_at', 'price' => ['label' => 'Lowest price', 'order' => 'price']]">
        @include('sunrice.partials.entry-filter', ['filter' => $component])
        @foreach ($component->entries as $entry)
            @include('sunrice.partials.card', ['entry' => $entry])
        @endforeach
        {{ $component->entries->links('sunrice.partials.pagination') }}
    </x-sunrice::entry-filter>
--}}
<form class="entry-filter" method="get" action="{{ $filter->action }}" role="search">
    @foreach ($filter->filters as $f)
        <fieldset class="entry-filter__group entry-filter__group--{{ $f->type }}">
            <legend>{{ $f->label }}</legend>

            @switch($f->type)
                @case('search')
                    <input type="search" name="{{ $f->inputs['value'] }}" value="{{ $f->value }}" aria-label="{{ $f->label }}">
                    @break

                @case('range')
                @case('date_range')
                    @php($bounds = ['min' => __('sunrice::frontend.filter_min'), 'max' => __('sunrice::frontend.filter_max'), 'from' => __('sunrice::frontend.filter_from'), 'to' => __('sunrice::frontend.filter_to')])
                    @foreach ($f->inputs as $key => $input)
                        <label>
                            {{ $bounds[$key] }}
                            <input type="{{ $f->type === 'range' ? 'number' : 'date' }}" name="{{ $input }}" value="{{ $f->value[$key] }}" @if ($f->type === 'range') step="any" @endif>
                        </label>
                    @endforeach
                    @break

                @case('toggle')
                    <label><input type="checkbox" name="{{ $f->inputs['value'] }}" value="1" @checked($f->value)> {{ $f->label }}</label>
                    @break

                @default
                    {{-- terms / select: checkboxes for several values, a dropdown for one. --}}
                    @if ($f->multiple)
                        @foreach ($f->options as $option)
                            <label class="entry-filter__option entry-filter__option--depth-{{ $option['depth'] ?? 0 }}">
                                <input type="checkbox" name="{{ $f->inputs['value'] }}" value="{{ $option['value'] }}" @checked($option['selected'])>
                                {{ $option['label'] }}
                                @isset($option['count'])<span class="entry-filter__count">({{ $option['count'] }})</span>@endisset
                            </label>
                        @endforeach
                    @else
                        <select name="{{ $f->inputs['value'] }}" aria-label="{{ $f->label }}">
                            <option value="">{{ __('sunrice::frontend.filter_any') }}</option>
                            @foreach ($f->options as $option)
                                <option value="{{ $option['value'] }}" @selected($option['selected'])>{{ str_repeat('— ', $option['depth'] ?? 0) }}{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    @endif
            @endswitch
        </fieldset>
    @endforeach

    @if (count($filter->sorts) > 1)
        <label class="entry-filter__sort">
            {{ __('sunrice::frontend.sort_by') }}
            <select name="{{ $filter->sortParam }}">
                @foreach ($filter->sorts as $sort)
                    <option value="{{ $sort->value }}" @selected($sort->selected)>{{ $sort->label }}</option>
                @endforeach
            </select>
        </label>
    @endif

    <button type="submit">{{ __('sunrice::frontend.filter_apply') }}</button>
    @if ($filter->filtered())
        <a href="{{ $filter->clearUrl }}">{{ __('sunrice::frontend.filter_clear') }}</a>
    @endif
</form>

@if ($filter->filtered())
    <ul class="entry-filter__active">
        @foreach ($filter->active as $active)
            <li><a href="{{ $active->remove_url }}" aria-label="{{ __('sunrice::frontend.filter_remove', ['filter' => $active->label.': '.$active->value]) }}">{{ $active->label }}: {{ $active->value }} ×</a></li>
        @endforeach
    </ul>
@endif

<p class="entry-filter__total">{{ trans_choice('sunrice::frontend.results', $filter->entries->total(), ['count' => $filter->entries->total()]) }}</p>
