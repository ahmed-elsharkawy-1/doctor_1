<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Models\Clinic;
use App\Services\V1\Reports\ReportCalendar;
use App\Services\V1\Reports\ReportDeliveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Throwable;

class EditClinic extends EditRecord
{
    // Records are deactivated, never deleted: history references them.
    protected static string $resource = ClinicResource::class;

    private ?string $ownerPassword = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->ownerPassword = isset($data['owner_password']) ? (string) $data['owner_password'] : null;

        unset($data['owner_password']);

        return $data;
    }

    /**
     * Sending a clinic's report by hand — when the morning send failed, or to
     * check a clinic before switching it on — and previewing what it shows.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendReport')
                ->label(__('filament.clinic.send_report'))
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    Select::make('period')
                        ->label(__('filament.clinic.report_period'))
                        ->options(fn (): array => $this->reportPeriods())
                        ->required(),
                    Toggle::make('resend')
                        ->label(__('filament.clinic.report_resend'))
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $this->sendReport((string) $data['period'], (bool) ($data['resend'] ?? false));
                }),

            Action::make('previewReport')
                ->label(__('filament.clinic.preview_report'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => route('reports.preview', [
                    'clinic' => $this->record->getKey(),
                    'type' => 'day',
                    'value' => app(ReportCalendar::class)->days($this->record)[0]['value'],
                ]))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Every period the reports page offers, as "type:value" => label.
     *
     * @return array<string, string>
     */
    private function reportPeriods(): array
    {
        $calendar = app(ReportCalendar::class);
        $options = [];

        foreach ([...$calendar->days($this->record), ...$calendar->periods($this->record)] as $period) {
            $options[$period['type'].':'.$period['value']] = $period['label'];
        }

        return $options;
    }

    private function sendReport(string $period, bool $resend): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        if (! $clinic->reports_enabled) {
            Notification::make()->warning()->title(__('filament.clinic.report_not_enabled'))->send();

            return;
        }

        [$type, $value] = explode(':', $period, 2);

        try {
            $outcome = app(ReportDeliveryService::class)->deliver($clinic, $type, $value, force: $resend);
        } catch (Throwable $e) {
            report($e);
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        if ($outcome === []) {
            Notification::make()->warning()->title(__('filament.clinic.report_no_recipients'))->send();

            return;
        }

        $queued = array_keys(array_filter($outcome, fn (string $state) => $state === 'queued'));
        $skipped = array_keys(array_filter($outcome, fn (string $state) => $state === 'already_sent'));

        if ($queued !== []) {
            Notification::make()->success()->title(__('filament.clinic.report_queued', ['to' => implode('، ', $queued)]))->send();
        }

        if ($skipped !== []) {
            Notification::make()->warning()->title(__('filament.clinic.report_already_sent', ['to' => implode('، ', $skipped)]))->send();
        }
    }

    protected function afterSave(): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        app(ProvisionClinicAction::class)->execute($clinic, $this->ownerPassword);
    }
}
