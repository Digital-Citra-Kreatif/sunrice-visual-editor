<p class="{{ $count > 10 ? 'many' : "few" }}" data-ok="{{ $a < $b }}">{{ $n < 3 ? '<' : '>' }}</p>
@if ($count > 1 && $count < 20)
    <b>{{ $count >= 5 ? __('sunrice::frontend.some') : $entry->title }}</b>
@elseif ($count === 0)
    <i>none</i>
@else
    <u>{{ str_repeat('<>', 2) }}</u>
@endif
@foreach (array_filter($rows, fn ($r) => $r['n'] > 0) as $row)
    <span>{{ $row['label'] }}</span>
@endforeach
