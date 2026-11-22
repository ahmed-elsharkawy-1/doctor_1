<?php

namespace App\Filament\Admin\Pages;

use App\Models\Clinic;
use App\Models\User;
use App\Services\Results\V1\Statistics\ClinicStatisticsResult;
use App\Services\V1\Statistics\ClinicStatisticsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * One clinic's performance numbers for a date range, shown to doctors as
 * evidence. Every rule (what is counted, the minimum sample, the missing
 * baseline) lives in ClinicStatisticsService. This page holds the filters
 * and renders the cards, and the CSV export reads the same result.
 */
class ClinicStatisticsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'reports';

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'clinic-statistics';

    protected string $view = 'filament.admin.clinic-statistics';

    /** @var array{clinic_id?: int|string|null, from?: ?string, to?: ?string}|null */
    public ?array $filters = [];

    /** One computation per request, whichever of the cards or the action asks first. */
    private ?string $memoKey = null;

    private ?ClinicStatisticsResult $memo = null;

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('statistics.title');
    }

    public function getTitle(): string
    {
        return __('statistics.title');
    }

    public function getSubheading(): ?string
    {
        return __('statistics.subheading');
    }

    public function mount(): void
    {
        $today = Carbon::today(config('clinic.defaults.timezone'));

        $this->form->fill([
            'clinic_id' => request()->integer('clinic') ?: null,
            'from' => $today->copy()->subDays(89)->toDateString(),
            'to' => $today->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('clinic_id')
                    ->label(__('statistics.filters.clinic'))
                    ->options(fn () => Clinic::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->live(),
                DatePicker::make('from')
                    ->label(__('statistics.filters.from'))
                    ->native(false)
                    ->displayFormat('Y-m-d')
                    ->maxDate(fn ($get) => $get('to'))
                    ->required()
                    ->live(),
                DatePicker::make('to')
                    ->label(__('statistics.filters.to'))
                    ->native(false)
                    ->displayFormat('Y-m-d')
                    ->minDate(fn ($get) => $get('from'))
                    ->required()
                    ->live(),
            ])
            ->columns(3)
            ->statePath('filters');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('statistics.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->disabled(fn (): bool => $this->statistics() === null)
                ->action(fn (): ?StreamedResponse => $this->export()),
        ];
    }

    public function export(): ?StreamedResponse
    {
        $result = $this->statistics();

        if ($result === null) {
            return null;
        }

        $name = sprintf('clinic-%d-statistics-%s-to-%s.csv', $result->clinicId, $result->from, $result->to);

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            // Excel reads a BOM-less UTF-8 file as the local code page, which
            // turns Arabic into question marks.
            fwrite($out, "\xEF\xBB\xBF");

            foreach ($result->csvRows() as $row) {
                fputcsv($out, array_map(self::safeCell(...), $row));
            }

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A comment is patient text. A cell starting with =, +, - or @ would run
     * as a formula in Excel, so it is quoted as text.
     */
    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** The current filters' numbers, or null until a clinic and a valid range are chosen. */
    public function statistics(): ?ClinicStatisticsResult
    {
        $clinic = filled($this->filters['clinic_id'] ?? null)
            ? Clinic::query()->find($this->filters['clinic_id'])
            : null;

        $from = $this->date($this->filters['from'] ?? null);
        $to = $this->date($this->filters['to'] ?? null);

        if ($clinic === null || $from === null || $to === null || $from->greaterThan($to)) {
            return null;
        }

        $key = $clinic->id.'|'.$from->toDateString().'|'.$to->toDateString();

        if ($this->memoKey !== $key) {
            $this->memo = app(ClinicStatisticsService::class)->for($clinic, $from, $to);
            $this->memoKey = $key;
        }

        return $this->memo;
    }

    private function date(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'result' => $this->statistics(),
            'minSample' => max(1, (int) config('clinic.statistics.min_sample')),
        ];
    }
}
