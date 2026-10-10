{{--
    Recursive menu renderer. Each item is a Sunrice\Frontend\MenuNode:
      $item->label                 label in the active language (falls back to the main language)
      $item->url                   resolved URL — follows slug changes and the active language
      $item->newTab                open in a new tab
      $item->isActive              true on the linked page and the pages below it
      $item->isActiveOrAncestor()  true when this item or one of its children is active
      $item->children              nested MenuNode collection
--}}
<ul>
    @foreach ($items as $item)
        <li @class(['is-active' => $item->isActiveOrAncestor()])>
            <a href="{{ $item->url }}"
               @if ($item->isActive) aria-current="page" @endif
               @if ($item->newTab) target="_blank" rel="noopener" @endif>{{ $item->label }}</a>

            @if ($item->children->isNotEmpty())
                @include('sunrice.partials.menu', ['items' => $item->children])
            @endif
        </li>
    @endforeach
</ul>
