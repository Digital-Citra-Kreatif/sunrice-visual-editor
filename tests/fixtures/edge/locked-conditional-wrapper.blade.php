{{-- A link opened in one @if and closed in another: common in hand-written Blade. --}}
@if ($url) <a href="{{ $url }}"> @endif
    <img src="{{ $image->url('medium') }}" alt="">
@if ($url) </a> @endif
