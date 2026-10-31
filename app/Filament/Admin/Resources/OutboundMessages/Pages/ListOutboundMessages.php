<?php

namespace App\Filament\Admin\Resources\OutboundMessages\Pages;

use App\Filament\Admin\Resources\OutboundMessages\OutboundMessageResource;
use App\Models\OutboundMessage;
use Filament\Resources\Pages\ListRecords;

class ListOutboundMessages extends ListRecords
{
    protected static string $resource = OutboundMessageResource::class;

    /**
     * The last 24 hours by status, so a run of failures is visible before
     * anyone filters for it.
     */
    public function getSubheading(): ?string
    {
        $counts = OutboundMessage::query()
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($counts->sum() === 0) {
            return null;
        }

        $parts = collect(OutboundMessageResource::STATUSES)
            ->filter(fn (string $status): bool => isset($counts[$status]))
            ->map(fn (string $status): string => OutboundMessageResource::statusLabel($status).': '.$counts[$status]);

        return __('filament.outbound_message.last_day').'  ·  '.$parts->implode('  ·  ');
    }
}
