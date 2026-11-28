<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Enums\BookingStatus;
use App\Exceptions\ApiException;
use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Patient;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Reports\ReportCalendar;
use App\Services\V1\Reports\ReportDeliveryService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
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

            // Operational news for one day's patients — the clinic closes,
            // the doctor is late. Here only: the clinic app never offers it,
            // so what goes out under a doctor's name is always reviewed. Only
            // the patients booked that day get it, and the form says how many
            // before anything is sent: each one is a real, billed message.
            Action::make('sendAnnouncement')
                ->label(__('filament.clinic.send_announcement'))
                ->icon('heroicon-o-megaphone')
                ->color('gray')
                ->schema([
                    DatePicker::make('date')
                        ->label(__('filament.clinic.announcement_date'))
                        ->helperText(__('filament.clinic.announcement_date_hint'))
                        ->default(fn (): string => $this->clinicRecord()->currentDay()->toDateString())
                        ->minDate(fn (): string => $this->clinicRecord()->currentDay()->toDateString())
                        ->live()
                        ->required(),
                    // With branches, which of them (ELA-22): all are ticked
                    // to begin with, and the patients booked at any other
                    // one hear nothing.
                    CheckboxList::make('branches')
                        ->label(__('filament.clinic.announcement_branches'))
                        ->helperText(__('filament.clinic.announcement_branches_hint'))
                        ->options(fn (): array => $this->clinicRecord()->branches()->get()
                            ->mapWithKeys(fn (Branch $branch) => [$branch->id => $branch->is_active
                                ? $branch->name
                                : $branch->name.' ('.__('filament.clinic.announcement_branch_inactive').')'])
                            ->all())
                        ->default(fn (): array => $this->branchIds())
                        ->visible(fn (): bool => $this->announcesByBranch())
                        ->columns(2)
                        ->live()
                        ->required(),
                    TextEntry::make('recipients')
                        ->label(__('filament.clinic.announcement_recipients'))
                        ->state(fn ($get): string => $this->announcementSummary($get('date'), $get('branches'))),
                    Textarea::make('text')
                        ->label(__('filament.clinic.announcement_text'))
                        ->helperText(__('filament.clinic.announcement_text_hint'))
                        ->rows(4)
                        ->maxLength(700)
                        ->required(),
                ])
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $this->sendAnnouncement((string) $data['date'], (string) $data['text'], $data['branches'] ?? null);
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

    private function clinicRecord(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        return $clinic;
    }

    /**
     * What the form shows before sending: how many will get it, and how many
     * are skipped for having no WhatsApp consent — the same rule the send
     * applies (WhatsAppMessagingService::sendAnnouncement).
     */
    private function announcementSummary(?string $date, mixed $branches = null): string
    {
        if (blank($date)) {
            return '—';
        }

        [$patients] = $this->announcementAudience($this->clinicRecord(), (string) $date, $this->announcementScope($branches));

        if ($patients->isEmpty()) {
            return __('filament.clinic.announcement_nobody');
        }

        $sent = $patients->whereNotNull('whatsapp_opt_in_at')->count();

        return __('filament.clinic.announcement_will_send', [
            'sent' => $sent,
            'skipped' => $patients->count() - $sent,
        ]);
    }

    private function sendAnnouncement(string $date, string $text, mixed $branches = null): void
    {
        $clinic = $this->clinicRecord();
        $scope = $this->announcementScope($branches);

        [$patients, $branchOf] = $this->announcementAudience($clinic, $date, $scope);

        if ($patients->isEmpty()) {
            Notification::make()->warning()->title(__('filament.clinic.announcement_nobody'))->send();

            return;
        }

        try {
            $outcome = app(WhatsAppMessagingService::class)->sendAnnouncement(
                $clinic,
                $patients,
                $text,
                // A clinic with one branch sends exactly what it always did:
                // the clinic's phone, even when its only branch has a number
                // of its own. So does one with the multi-branch switch off.
                $this->announcesByBranch() ? $branchOf : [],
                namesBranch: $scope !== null,
            );
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
     * Whether the announcement asks which branches: only once the clinic has
     * more than one.
     */
    private function announcesByBranch(): bool
    {
        return app(BranchService::class)->canAdd() && count($this->branchIds()) > 1;
    }

    /** @var list<int>|null this clinic's branch ids, read once a request */
    private ?array $branchIds = null;

    /** @return list<int> */
    private function branchIds(): array
    {
        return $this->branchIds ??= $this->clinicRecord()->branches()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The branches ticked, as this clinic's own branch ids; null when that is
     * every one of them — or the clinic has no choice of branches.
     *
     * @return list<int>|null
     */
    private function announcementScope(mixed $chosen): ?array
    {
        if (! $this->announcesByBranch()) {
            return null;
        }

        $all = $this->branchIds();
        $ids = array_values(array_intersect($all, array_map('intval', (array) $chosen)));

        return count($ids) === count($all) ? null : $ids;
    }

    /**
     * The patients booked on that day and still expected, at the branches in
     * scope (null: all) — each once, however many bookings they have — and
     * for each the branch of their first one that day.
     *
     * @param  list<int>|null  $branchIds
     * @return array{0: Collection<int, Patient>, 1: array<int, Branch>}
     */
    private function announcementAudience(Clinic $clinic, string $date, ?array $branchIds = null): array
    {
        $bookings = Booking::query()
            ->where('clinic_id', $clinic->id)
            ->whereDate('visit_date', $date)
            ->whereIn('status', BookingStatus::pending())
            ->whereNotNull('patient_id')
            ->when($branchIds !== null, fn ($query) => $query->whereIn('branch_id', $branchIds))
            ->with('branch')
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();

        $branchOf = [];

        foreach ($bookings as $booking) {
            if ($booking->branch !== null) {
                // Its phone falls back to the clinic's: no query per branch.
                $branchOf[$booking->patient_id] ??= $booking->branch->setRelation('clinic', $clinic);
            }
        }

        $patients = $clinic->patients()
            ->whereIn('id', $bookings->pluck('patient_id')->unique()->all())
            ->orderBy('id')
            ->get();

        return [$patients, $branchOf];
    }

    protected function afterSave(): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        app(ProvisionClinicAction::class)->execute($clinic, $this->ownerPassword);
    }
}
