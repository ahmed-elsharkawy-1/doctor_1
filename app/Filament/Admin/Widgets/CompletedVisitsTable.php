<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Admin\Concerns\ResolvesReportingClinic;
use App\Models\Booking;
use App\Services\Reports\ReportPeriod;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The visits behind this month's number, so the doctor can see what the total
 * is made of rather than being asked to trust it.
 */
class CompletedVisitsTable extends TableWidget
{
    use ResolvesReportingClinic;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('reports.revenue.recent_visits'))
            ->query($this->query())
            ->columns([
                TextColumn::make('visit_date')
                    ->label(__('reports.column.date'))
                    ->date()
                    ->sortable(),

                TextColumn::make('start_at')
                    ->label(__('reports.column.time'))
                    ->time('H:i'),

                TextColumn::make('patient.name')
                    ->label(__('reports.column.patient'))
                    ->searchable(),

                TextColumn::make('patient.code')
                    ->label(__('reports.column.code'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('visitType.name')
                    ->label(__('reports.column.visit_type'))
                    ->badge(),

                TextColumn::make('price')
                    ->label(__('reports.column.price'))
                    ->money('EGP')
                    ->sortable()
                    ->summarize(
                        Sum::make()
                            ->label(__('reports.column.total'))
                            ->money('EGP'),
                    ),
            ])
            ->defaultSort('start_at', 'desc')
            ->paginated([10, 25, 50]);
    }

    /**
     * @return Builder<Booking>
     */
    private function query(): Builder
    {
        $clinic = $this->reportingClinic();

        if ($clinic === null) {
            return Booking::query()->whereRaw('1 = 0');
        }

        $period = ReportPeriod::thisMonth($clinic);

        return Booking::query()
            ->with(['patient', 'visitType'])
            ->where('clinic_id', $clinic->id)
            ->where('status', BookingStatus::DONE)
            ->whereBetween('visit_date', [
                $period->from->toDateString(),
                $period->to->toDateString(),
            ]);
    }
}
