<?php

namespace App\Filament\Admin\Resources\OutboundMessages;

use App\Filament\Admin\Resources\OutboundMessages\Pages\ListOutboundMessages;
use App\Filament\Admin\Resources\OutboundMessages\Schemas\OutboundMessageInfolist;
use App\Filament\Admin\Resources\OutboundMessages\Tables\OutboundMessagesTable;
use App\Models\OutboundMessage;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use UnitEnum;

/**
 * Every WhatsApp message the platform sent, and what Meta said became of it.
 *
 * The table already exists for sending: each message is a row from the moment
 * it is queued, and Meta's delivery callbacks update that same row. This only
 * gives the team a way to read it without the server.
 *
 * Read-only on purpose: a record of what was sent is only worth anything if
 * nobody here can rewrite or remove it.
 */
class OutboundMessageResource extends Resource
{
    protected static ?string $model = OutboundMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'platform';

    protected static ?int $navigationSort = 6;

    /** Statuses in the order a message moves through them. */
    public const STATUSES = ['queued', 'sent', 'delivered', 'read', 'failed', 'skipped'];

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('filament.outbound_message.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.outbound_message.plural_label');
    }

    /**
     * A readable name for a template key. A key nobody has named yet — a new
     * template — still shows, as itself, rather than disappearing.
     */
    public static function typeLabel(?string $key): string
    {
        $translation = 'filament.outbound_message.types.'.$key;

        return $key !== null && Lang::has($translation) ? __($translation) : (string) $key;
    }

    public static function statusLabel(?string $status): string
    {
        $translation = 'filament.outbound_message.statuses.'.$status;

        return $status !== null && Lang::has($translation) ? __($translation) : (string) $status;
    }

    public static function statusColor(?string $status): string
    {
        return match ($status) {
            'read' => 'success',
            'delivered' => 'info',
            'sent' => 'primary',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return OutboundMessagesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OutboundMessageInfolist::configure($schema);
    }

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutboundMessages::route('/'),
        ];
    }
}
