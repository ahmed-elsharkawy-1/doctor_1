{{--
    Staging says what it is on every page a person can land on. It has no
    password in front of it, so a patient handed the wrong link must be able
    to tell before booking that nothing here reaches a clinic.

    Inline styles on purpose: this is dropped into six pages with six
    different stylesheets, and must not inherit — or lose a specificity
    fight with — any of them.
--}}
@if (app()->environment('staging'))
    <div role="note" dir="rtl" style="position: sticky; top: 0; z-index: 9999; background: #b45309; color: #fff; text-align: center; font: 600 13px/1.4 system-ui, sans-serif; padding: 6px 16px;">
        {{ __('app.test_site_banner') }}
    </div>
@endif
