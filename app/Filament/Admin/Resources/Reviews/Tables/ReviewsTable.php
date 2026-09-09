<?php

namespace App\Filament\Admin\Resources\Reviews\Tables;

use App\Enums\ReviewRating;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('submitted_at', 'desc')
            ->columns([
                TextColumn::make('doctor.name')
                    ->label(__('filament.review.doctor'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('clinic.name')
                    ->label(__('filament.review.clinic'))
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('rating')
                    ->label(__('filament.review.rating'))
                    ->badge()
                    ->formatStateUsing(fn (ReviewRating $state): string => $state->label())
                    ->color(fn (ReviewRating $state): string => match ($state) {
                        ReviewRating::VERY_GOOD => 'success',
                        ReviewRating::GOOD => 'info',
                        ReviewRating::BAD => 'danger',
                    }),

                TextColumn::make('comment')
                    ->label(__('filament.review.comment'))
                    // Long notes would otherwise push every other column off
                    // the screen; the full text is one click away.
                    ->limit(60)
                    ->tooltip(fn ($state): ?string => $state)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('patient.name')
                    ->label(__('filament.review.patient'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('patient.code')
                    ->label(__('filament.review.patient_code'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('submitted_at')
                    ->label(__('filament.review.submitted_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('doctor_id')
                    ->label(__('filament.review.doctor'))
                    ->relationship('doctor', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('clinic_id')
                    ->label(__('filament.review.clinic'))
                    ->relationship('clinic', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('rating')
                    ->label(__('filament.review.rating'))
                    ->options(ReviewRating::options()),
            ]);
    }
}
