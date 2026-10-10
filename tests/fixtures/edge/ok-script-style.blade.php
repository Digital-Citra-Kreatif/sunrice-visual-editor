<style>
    @media (max-width: 760px) { .menu > li { display: none; } }
    .hero::after { content: "</div>"; }
</style>
<div class="map" data-points='@json($points)'></div>
<script>
    const points = @json($points);
    if (points.length < 3 && window.innerWidth > 760) { document.body.innerHTML += "<p>few</p>"; }
    const tpl = `<li class="{{ $class }}">${points[0]}</li>`;
</script>
