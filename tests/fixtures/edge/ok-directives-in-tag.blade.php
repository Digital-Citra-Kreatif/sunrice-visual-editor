<ul {{ $attributes }}>
    @foreach ($items as $item)
        <li @class(['is-active' => $item->active, 'has-children' => $item->children]) data-id="{{ $item->id }}">
            <a href="{{ $item->url }}" @if ($item->newTab) target="_blank" rel="noopener" @endif @disabled($item->off)>{{ $item->label }}</a>
            <input type="checkbox" name="pick[]" value="{{ $item->id }}" @checked($item->picked) @required($loop->first)>
        </li>
    @endforeach
</ul>
