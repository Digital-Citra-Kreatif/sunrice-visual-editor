{{--
    Site footer: a "template part" global + a footer menu + a repeater.

    Admin setup this partial expects (all optional):
      - Global set `footer` (group: Template parts) with fields:
          `text`   (rich text)
          `social` (repeater with `label` text + `url` text)
      - Menu `footer`
--}}
@php
    $footer = sunrice_global('footer');
    $footerMenu = sunrice_menu('footer');
@endphp

<footer class="site-footer">
    <div class="container">
        @if ($footerMenu->isNotEmpty())
            <nav aria-label="{{ __('sunrice::frontend.footer_menu') }}">
                @include('sunrice.partials.menu', ['items' => $footerMenu])
            </nav>
        @endif

        {{-- Rich text is sanitized when saved, so it is safe to print unescaped. --}}
        @if ($footer->text)
            <div>{!! $footer->text !!}</div>
        @endif

        {{-- Repeaters hydrate to a collection of rows (arrays). --}}
        @if (collect($footer->social)->isNotEmpty())
            <ul class="tags">
                @foreach ($footer->social as $link)
                    <li><a href="{{ $link['url'] ?? '#' }}" rel="me noopener">{{ $link['label'] ?? $link['url'] ?? '' }}</a></li>
                @endforeach
            </ul>
        @endif

        <p>&copy; {{ now()->year }} {{ sunrice_global('site')->get('name', config('app.name')) }}</p>
    </div>
</footer>
