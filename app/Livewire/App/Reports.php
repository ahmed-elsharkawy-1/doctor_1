<?php

namespace App\Livewire\App;

use App\Exceptions\ApiException;
use App\Services\V1\Reports\ClinicReportService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;

/**
 * Revenue and retention — SPEC §5.5, §5.6.
 *
 * Not a line of arithmetic here. The screen asks ClinicReportService for the
 * same two payloads the mobile app's reports screen is drawn from, so the two
 * can never disagree about a month's takings: equal-length comparisons, a
 * daily series with no gaps, a business week that starts on Saturday.
 *
 * Money is gated a second time on `prices.view`: an account may be allowed to
 * see how busy the clinic is without being shown what it earned.
 */
class Reports extends ClinicComponent
{
    #[Url(as: 'period', except: '')]
    public string $period = '';

    public ?string $notice = null;

    public bool $failed = false;

    public function mount(): void
    {
        $this->requireAbility('reports.view');

        if ($this->period === '') {
            $this->period = config('clinic.retention.default_period');
        }
    }

    public function render(): View
    {
        $reports = app(ClinicReportService::class);

        return view('livewire.app.reports', [
            'revenue' => $this->showsMoney()
                ? $reports->revenue($this->clinic())->toArray()
                : null,
            'retention' => $this->retention($reports),
            'periods' => $reports->availablePeriods(),
        ])->title(__('app.reports.title'));
    }

    public function selectPeriod(string $period): void
    {
        $this->period = $period;
        $this->notice = null;
        $this->failed = false;
    }

    public function showsMoney(): bool
    {
        return auth()->user()?->hasAbility('prices.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    private function retention(ClinicReportService $reports): array
    {
        $this->requireAbility('reports.view');

        try {
            return $reports->retention($this->clinic(), $this->period)->toArray();
        } catch (ApiException $e) {
            // An unknown period arrived in the query string. Fall back to the
            // default rather than showing the secretary an error page.
            $this->notice = $e->getMessage();
            $this->failed = true;
            $this->period = config('clinic.retention.default_period');

            return $reports->retention($this->clinic(), $this->period)->toArray();
        }
    }
}
