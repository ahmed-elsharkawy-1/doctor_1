<?php

namespace App\Filament\Admin\Resources\Clinics\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ClinicsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament.clinic.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('specialty.name_ar')
                    ->label(__('filament.clinic.specialty'))
                    ->badge()
                    ->sortable(),

                TextColumn::make('doctor.name')
                    ->label(__('filament.clinic.doctor'))
                    ->placeholder(__('filament.clinic.no_doctor'))
                    ->searchable(),

                TextColumn::make('visit_types_count')
                    ->label(__('filament.clinic.visit_types'))
                    ->counts('visitTypes')
                    ->badge(),

                TextColumn::make('staff_count')
                    ->label(__('filament.clinic.staff'))
                    ->counts('staff')
                    ->badge(),

                TextColumn::make('phone')
                    ->label(__('filament.clinic.phone'))
                    ->searchable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('filament.clinic.is_active'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label(__('filament.common.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('specialty_id')
                    ->label(__('filament.clinic.specialty'))
                    ->relationship('specialty', 'name_ar')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label(__('filament.clinic.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Clinics are never deleted — deactivate instead. Bookings,
            // patients and revenue history all hang off them.
            ->toolbarActions([])
            ->defaultSort('name');
    }
}
