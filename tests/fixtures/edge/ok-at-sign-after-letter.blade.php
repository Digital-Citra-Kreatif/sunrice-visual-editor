{{-- "@" straight after a letter or digit is not a directive to Blade: these are addresses. --}}
<ul class="contacts">
    @foreach ($people as $person)
        <li><a href="mailto:joe@if.org" title="sales@section.com">joe@if.org</a>, or me@include.example</li>
    @endforeach
    <li>x@endif and 9@yield('none') stay text; {{ $person->email }} and a real @if ($show) <b>yes</b> @endif do not.</li>
</ul>
