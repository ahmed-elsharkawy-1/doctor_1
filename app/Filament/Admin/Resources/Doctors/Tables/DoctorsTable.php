<?php

namespace App\Filament\Admin\Resources\Doctors\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DoctorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament.doctor.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('clinic.name')
                    ->label(__('filament.doctor.clinic'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('clinic.specialty.name_ar')
                    ->label(__('filament.clinic.specialty'))
                    ->badge(),

                TextColumn::make('phone')
                    ->label(__('filament.doctor.phone'))
                    ->searchable()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('filament.common.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('clinic_id')
                    ->label(__('filament.doctor.clinic'))
                    ->relationship('clinic', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label(__('filament.common.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Bookings reference doctors — deactivate, never delete.
            ->toolbarActions([])
            ->defaultSort('name');
    }
}
