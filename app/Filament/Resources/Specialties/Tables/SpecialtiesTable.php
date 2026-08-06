<?php

namespace App\Filament\Resources\Specialties\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class SpecialtiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('filament.specialty.name_ar'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_en')
                    ->label(__('filament.specialty.name_en'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('default_visit_types_count')
                    ->label(__('filament.specialty.default_visit_types'))
                    ->counts('defaultVisitTypes')
                    ->badge(),

                TextColumn::make('clinics_count')
                    ->label(__('filament.specialty.clinics'))
                    ->counts('clinics')
                    ->badge(),

                IconColumn::make('is_active')
                    ->label(__('filament.common.is_active'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('filament.common.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Specialties are referenced by clinics — deactivate, never delete.
            ->toolbarActions([])
            ->defaultSort('sort_order');
    }
}
