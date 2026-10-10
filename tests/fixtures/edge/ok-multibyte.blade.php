{{-- Tanda baca dan aksara non-ASCII: — … “ ” é ü 日本語 🙂 --}}
<section class="sambutan">
    <h1>Selamat datang — {{ $entry->title }} 🙂</h1>
    <p title="Harga: Rp 10.000 · “promo”">{{ $entry->get('ringkasan') }} … <em>日本語</em></p>
    @if ($entry->get('catatan'))
        <p>Catatan — {!! $entry->get('catatan') !!}</p>
    @endif
</section>
