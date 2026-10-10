{{--
    Page links for any list: {{ $entries->links('sunrice.partials.pagination') }}
    (entries, search results, <x-sunrice::entry-filter>…). Laravel passes
    $paginator and $elements (page numbers, with "..." gaps). Plain markup,
    styled by .pagination in public/sunrice-theme/app.css; without a view
    name, ->links() uses Laravel's own Tailwind view.
--}}
@if ($paginator->hasPages())
    <nav class="pagination" aria-label="{{ __('Pagination Navigation') }}">
        <ul>
            <li>
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true">{!! __('pagination.previous') !!}</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev">{!! __('pagination.previous') !!}</a>
                @endif
            </li>

            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span aria-disabled="true">{{ $element }}</span></li>
                @else
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach

            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next">{!! __('pagination.next') !!}</a>
                @else
                    <span aria-disabled="true">{!! __('pagination.next') !!}</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
