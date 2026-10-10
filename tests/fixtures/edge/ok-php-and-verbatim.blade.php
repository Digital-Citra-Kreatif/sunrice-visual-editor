@php
    $open = '<div class="never-rendered">';
    $items = collect($entry->get('items') ?? [])->filter(fn ($i) => $i['n'] > 0);
@endphp
<?php $legacy = '</section>'; ?>
<div class="wrap">
    @verbatim
        <li>{{ handlebars }}</li></ul>
    @endverbatim
    @switch($entry->get('layout'))
        @case('wide')
            <div class="wide">{{ $entry->title }}</div>
            @break
        @default
            <div class="narrow">{{ $entry->title }}</div>
    @endswitch
    <p>Write @{{ name }} and email me@example.com, or @@if literally.</p>
</div>
