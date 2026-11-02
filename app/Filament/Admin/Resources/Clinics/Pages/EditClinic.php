<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Models\Clinic;
use App\Models\Patient;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Reports\ReportCalendar;
use App\Services\V1\Reports\ReportDeliveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Collection;
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
            Action::make('qrPoster')
                ->label(__('filament.qr_poster.title'))
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn (): string => ClinicResource::getUrl('qr-poster', ['record' => $this->record])),

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

            // Operational news for the clinic's patients — a closure, new
            // hours, a new address. Here only: the clinic app never offers it,
            // so what goes out under a doctor's name is always reviewed.
            Action::make('sendAnnouncement')
                ->label(__('filament.clinic.send_announcement'))
                ->icon('heroicon-o-megaphone')
                ->color('gray')
                ->schema([
                    Select::make('audience')
                        ->label(__('filament.clinic.announcement_audience'))
                        ->options([
                            'day' => __('filament.clinic.announcement_audience_day'),
                            'upcoming' => __('filament.clinic.announcement_audience_upcoming'),
                            'all' => __('filament.clinic.announcement_audience_all'),
                        ])
                        ->default('upcoming')
                        ->live()
                        ->required(),
                    DatePicker::make('date')
                        ->label(__('filament.clinic.announcement_date'))
                        ->visible(fn ($get): bool => $get('audience') === 'day')
                        ->required(fn ($get): bool => $get('audience') === 'day'),
                    Textarea::make('text')
                        ->label(__('filament.clinic.announcement_text'))
                        ->helperText(__('filament.clinic.announcement_text_hint'))
                        ->rows(4)
                        ->maxLength(700)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $this->sendAnnouncement((string) $data['audience'], $data['date'] ?? null, (string) $data['text']);
                }),

            Action::make('previewReport')
                ->label(__('filament.clinic.preview_report'))
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->url(fn (): string => route('reports.preview', [
                    'clinic' => $this->record->getKey(),
                    'type' => 'day',
                    'value' => app(ReportCalendar::class)->yesterday($this->record),
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
            // A report is sent about a day that is over; today still is not.
            if (($period['relative'] ?? null) === 'today') {
                continue;
            }

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

    private function sendAnnouncement(string $audience, ?string $date, string $text): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        $patients = $this->announcementAudience($clinic, $audience, $date);

        if ($patients->isEmpty()) {
            Notification::make()->warning()->title(__('filament.clinic.announcement_nobody'))->send();

            return;
        }

        try {
            $outcome = app(WhatsAppMessagingService::class)->sendAnnouncement($clinic, $patients, $text);
        } catch (ApiException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('filament.clinic.announcement_sent', [
            'sent' => $outcome['sent_count'],
            'skipped' => $outcome['skipped_count'],
        ]))->send();
    }

    /**
     * Each patient once, however many bookings they have.
     *
     * @return Collection<int, Patient>
     */
    private function announcementAudience(Clinic $clinic, string $audience, ?string $date): Collection
    {
        $booked = fn ($query) => match ($audience) {
            'day' => $query->whereDate('visit_date', (string) $date)
                ->whereIn('status', BookingStatus::pending()),
            default => $query->upcoming($clinic->currentDay()),
        };

        return $clinic->patients()
            ->when($audience !== 'all', fn ($query) => $query->whereHas('bookings', $booked))
            ->orderBy('id')
            ->get();
    }

    protected function afterSave(): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        app(ProvisionClinicAction::class)->execute($clinic, $this->ownerPassword);
    }
}
