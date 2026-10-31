<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Tables;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Models\OutboundMessage;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OutboundMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // New messages and Meta's updates to them turn up without a
            // reload, so the page can stay open while somebody tests.
            ->poll('30s')
            ->columns([
                TextColumn::make('id')
                    ->label(__('filament.outbound_message.id'))
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label(__('filament.outbound_message.created_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('clinic.name')
                    ->label(__('filament.outbound_message.clinic'))
                    ->toggleable(),

                TextColumn::make('patient.name')
                    ->label(__('filament.outbound_message.patient'))
                    ->searchable(),

                TextColumn::make('patient.phone')
                    ->label(__('filament.outbound_message.phone'))
                    ->searchable()
                    ->copyable(),

                TextColumn::make('template_key')
                    ->label(__('filament.outbound_message.type'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => OutboundMessageResource::typeLabel($state)),

                TextColumn::make('status')
                    ->label(__('filament.outbound_message.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OutboundMessageResource::statusLabel($state))
                    ->color(fn (?string $state): string => OutboundMessageResource::statusColor($state)),

                TextColumn::make('rendered_body')
                    ->label(__('filament.outbound_message.body'))
                    // The whole message is in the details; a full template
                    // here would push every other column off the screen.
                    ->limit(50)
                    ->tooltip(fn (OutboundMessage $record): string => $record->rendered_body)
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('error')
                    ->label(__('filament.outbound_message.error'))
                    ->limit(50)
                    ->tooltip(fn (OutboundMessage $record): ?string => $record->error)
                    ->wrap()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('booking_id')
                    ->label(__('filament.outbound_message.booking'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('delivered_at')
                    ->label(__('filament.outbound_message.delivered_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('read_at')
                    ->label(__('filament.outbound_message.read_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('provider_message_id')
                    ->label(__('filament.outbound_message.provider_message_id'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('clinic_id')
                    ->label(__('filament.outbound_message.clinic'))
                    ->relationship('clinic', 'name')
                    ->searchable()
                    ->preload(),

                SelectFilter::make('template_key')
                    ->label(__('filament.outbound_message.type'))
                    // Every key ever sent, so a template added later is
                    // filterable without touching this list.
                    ->options(fn (): array => OutboundMessage::query()
                        ->distinct()
                        ->orderBy('template_key')
                        ->pluck('template_key')
                        ->mapWithKeys(fn (string $key): array => [$key => OutboundMessageResource::typeLabel($key)])
                        ->all()),

                SelectFilter::make('status')
                    ->label(__('filament.outbound_message.status'))
                    ->options(collect(OutboundMessageResource::STATUSES)
                        ->mapWithKeys(fn (string $status): array => [$status => OutboundMessageResource::statusLabel($status)])
                        ->all()),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('filament.outbound_message.from')),
                        DatePicker::make('until')->label(__('filament.outbound_message.until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
