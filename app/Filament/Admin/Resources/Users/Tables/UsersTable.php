<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Enums\UserRole;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('filament.user.name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('filament.user.email'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('role')
                    ->label(__('filament.user.role'))
                    ->badge()
                    ->formatStateUsing(fn (UserRole $state): string => $state->label())
                    ->color(fn (UserRole $state): string => match ($state) {
                        UserRole::SUPER_ADMIN => 'danger',
                        UserRole::CLINIC => 'info',
                    }),

                TextColumn::make('clinics.name')
                    ->label(__('filament.user.clinics'))
                    ->badge()
                    ->placeholder(__('filament.user.no_clinic')),

                IconColumn::make('is_active')
                    ->label(__('filament.common.is_active'))
                    ->boolean(),

                TextColumn::make('created_at')
                    ->label(__('filament.common.created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label(__('filament.user.role'))
                    ->options(UserRole::options()),

                SelectFilter::make('clinics')
                    ->label(__('filament.user.clinics'))
                    ->relationship('clinics', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('is_active')
                    ->label(__('filament.common.is_active')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // Accounts are deactivated, not deleted — bookings record who
            // created them.
            ->toolbarActions([])
            ->defaultSort('name');
    }
}
