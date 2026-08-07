<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\ResolvesReportingClinic;
use App\Filament\Admin\Widgets\CompletedVisitsTable;
use App\Filament\Admin\Widgets\RevenueOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class RevenuePage extends Page
{
    use ResolvesReportingClinic;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'reports';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.revenue';

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.reports');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.revenue.title');
    }

    public function getTitle(): string
    {
        return __('reports.revenue.title');
    }

    public function getSubheading(): ?string
    {
        // Stated plainly, because with fixed prices and no payment tracking
        // this is not cash collected (SPEC §5.5).
        return __('reports.revenue.subheading');
    }

    public static function canAccess(): bool
    {
        return static::canAccessReports();
    }

    /**
     * @return list<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            RevenueOverview::class,
            CompletedVisitsTable::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
