{{-- Shown at the top of every admin page while the SMS balance needs
     attention. Nothing at all when it is fine, unconfigured, or unreadable. --}}
@if ($balance !== null && $balance->alert() !== null)
    <x-filament::callout
        :color="$balance->alertColor()"
        icon="heroicon-o-exclamation-triangle"
        :heading="__('filament.sms_balance.heading')"
        :description="$balance->alertMessage()"
        style="margin-bottom: 1rem"
        data-sms-balance-alert="{{ $balance->alert() }}"
    />
@endif
