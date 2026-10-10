{{-- Shown above an unpublished entry for signed-in users with sunrice.view-drafts. Inline styles: independent of the site's CSS. --}}
<div role="status" data-sunrice-draft-banner style="position:sticky;top:0;z-index:2147483000;display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:6px 14px;padding:9px 16px;background:#1c1917;color:#fafaf9;font:500 13px/1.4 ui-sans-serif,system-ui,-apple-system,'Segoe UI',sans-serif;text-align:center;box-shadow:0 1px 0 rgba(0,0,0,.2)">
    <span style="display:inline-flex;align-items:center;gap:6px;padding:2px 8px;border-radius:999px;background:#f59e0b;color:#1c1917;font-weight:600;font-size:11px;letter-spacing:.04em;text-transform:uppercase">{{ __('sunrice::frontend.draft') }}</span>
    <span>{{ $reason }} {{ __('sunrice::frontend.draft_signed_in') }}</span>
    <a href="{{ \Sunrice\Admin\AdminUrls::entry($entry) }}" style="color:#fafaf9;text-decoration:underline;text-underline-offset:2px">{{ __('sunrice::frontend.edit_entry') }}</a>
</div>
