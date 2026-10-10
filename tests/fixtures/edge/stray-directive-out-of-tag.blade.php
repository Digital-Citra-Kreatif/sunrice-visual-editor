{{-- A condition opened inside a tag and closed outside it: Blade compiles it. --}}
<ul @if ($open) class="open">
    <li>One</li>
@endif
</ul>
<p>After</p>
