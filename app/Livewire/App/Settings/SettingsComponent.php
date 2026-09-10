<?php

namespace App\Livewire\App\Settings;

use App\Exceptions\ApiException;
use App\Livewire\App\ClinicComponent;

/**
 * Shared ground for the four settings screens.
 *
 * Each one changes something a clinic runs on, and each rule that decides
 * whether a change is allowed already lives in a service — closing the last
 * visit type, closing a day that has patients on it. The screens ask, report
 * what comes back, and decide nothing themselves.
 */
abstract class SettingsComponent extends ClinicComponent
{
    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        $this->requireAbility('settings.manage');
    }

    /**
     * ApiException renders itself as a JSON envelope, which would break a
     * Livewire response. Caught here and shown on the page; the message is
     * already translated.
     *
     * @return array<string, mixed>|null the exception's details, when it carried any
     */
    protected function run(callable $action, string $success): ?array
    {
        $this->requireAbility('settings.manage');

        try {
            $action();
            $this->notice = $success;
            $this->failed = false;

            return null;
        } catch (ApiException $e) {
            $this->notice = $e->getMessage();
            $this->failed = true;

            return $e->details;
        }
    }
}
