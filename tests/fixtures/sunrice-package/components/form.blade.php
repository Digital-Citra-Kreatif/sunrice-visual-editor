{{-- Inside a class component's own view, public properties ($form) and methods ($success()) are plain variables. --}}
@if ($success())
    <div class="sunrice-form-success" id="{{ $anchor }}" role="status">{{ $form->setting('success_message') ?: __('sunrice::frontend.thank_you') }}</div>
@else
    <form method="POST" action="{{ route('sunrice.frontend.forms.submit', $form->handle) }}" enctype="multipart/form-data" id="{{ $anchor }}" {{ $attributes }}>
        @csrf
        <x-honeypot />
        <input type="hidden" name="_form" value="{{ $form->handle }}">
        <input type="hidden" name="_locale" value="{{ $locale }}">
        {{ $slot }}
        @if ($widget = $captcha())
            <div class="sunrice-form-captcha {{ $widget['class'] }}" data-sitekey="{{ $widget['site_key'] }}" @if ($widget['provider'] === 'turnstile') data-language="{{ $locale }}" @endif></div>
            @if ($captchaMessage = $captchaError())
                <p class="error">{{ $captchaMessage }}</p>
            @endif
            @once
                <script src="{{ $widget['script'] }}" async defer></script>
            @endonce
        @endif
        <button type="submit">{{ __('sunrice::frontend.submit') }}</button>
    </form>
@endif
