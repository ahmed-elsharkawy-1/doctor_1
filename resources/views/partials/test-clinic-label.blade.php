{{--
    On a test clinic's public pages only. It is a live page on production —
    anyone who reaches it must be able to tell this is not a real doctor.
    Inline styles: dropped into pages with different stylesheets, and must
    not inherit from or lose a specificity fight with any of them.
--}}
@if ($clinic?->is_test)
    <div role="note" dir="rtl" style="background: #FFF4E5; color: #8A4B00; border-bottom: 1px solid #F3D3A6; text-align: center; font: 600 13px/1.4 Tajawal, system-ui, sans-serif; padding: 6px 16px;">
        {{ __('landing.test_clinic') }}
    </div>
@endif
