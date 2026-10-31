<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Schemas;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

/**
 * One message in full: who it went to, what it said, and each step Meta
 * reported, with Meta's own reason when it failed.
 */
class OutboundMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('id')
                    ->label(__('filament.outbound_message.id')),

                TextEntry::make('status')
                    ->label(__('filament.outbound_message.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => OutboundMessageResource::statusLabel($state))
                    ->color(fn (?string $state): string => OutboundMessageResource::statusColor($state)),

                TextEntry::make('clinic.name')
                    ->label(__('filament.outbound_message.clinic')),

                TextEntry::make('template_key')
                    ->label(__('filament.outbound_message.type'))
                    ->formatStateUsing(fn (?string $state): string => OutboundMessageResource::typeLabel($state)),

                TextEntry::make('patient.name')
                    ->label(__('filament.outbound_message.patient')),

                TextEntry::make('patient.phone')
                    ->label(__('filament.outbound_message.phone'))
                    ->copyable(),

                TextEntry::make('booking_id')
                    ->label(__('filament.outbound_message.booking'))
                    ->placeholder('—'),

                TextEntry::make('provider_message_id')
                    ->label(__('filament.outbound_message.provider_message_id'))
                    ->copyable()
                    ->placeholder('—'),

                TextEntry::make('created_at')
                    ->label(__('filament.outbound_message.created_at'))
                    ->dateTime('Y-m-d H:i:s'),

                TextEntry::make('sent_at')
                    ->label(__('filament.outbound_message.sent_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—'),

                TextEntry::make('delivered_at')
                    ->label(__('filament.outbound_message.delivered_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—'),

                TextEntry::make('read_at')
                    ->label(__('filament.outbound_message.read_at'))
                    ->dateTime('Y-m-d H:i:s')
                    ->placeholder('—'),

                TextEntry::make('error')
                    ->label(__('filament.outbound_message.error'))
                    ->placeholder('—')
                    ->columnSpanFull(),

                TextEntry::make('rendered_body')
                    ->label(__('filament.outbound_message.body'))
                    ->prose()
                    ->extraAttributes(['style' => 'white-space: pre-line'])
                    ->columnSpanFull(),
            ]);
    }
}
