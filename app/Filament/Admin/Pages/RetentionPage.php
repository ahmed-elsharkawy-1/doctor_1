<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\ResolvesReportingClinic;
use App\Filament\Admin\Widgets\RetentionOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class RetentionPage extends Page
{
    use ResolvesReportingClinic;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'reports';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.retention';

    /**
     * Bound to the period selector on the page.
     */
    public string $period = 'this_month';

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.retention.title');
    }

    public function getTitle(): string
    {
        return __('reports.retention.title');
    }

    public function getSubheading(): ?string
    {
        return __('reports.retention.subheading');
    }

    public static function canAccess(): bool
    {
        return static::canAccessReports();
    }

    /**
     * @return array<string, string>
     */
    public function periodOptions(): array
    {
        return [
            'this_week' => __('reports.period.this_week'),
            'this_month' => __('reports.period.this_month'),
            'last_90_days' => __('reports.period.last_90_days'),
            'last_365_days' => __('reports.period.last_365_days'),
        ];
    }

    /**
     * @return list<array{0: class-string, 1: array<string, mixed>}|class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            RetentionOverview::make(['period' => $this->period]),
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
