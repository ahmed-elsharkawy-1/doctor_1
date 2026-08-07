<?php

namespace App\Filament\Admin\Resources\Clinics;

use App\Filament\Admin\Resources\Clinics\Pages\CreateClinic;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Filament\Admin\Resources\Clinics\Pages\ListClinics;
use App\Filament\Admin\Resources\Clinics\Schemas\ClinicForm;
use App\Filament\Admin\Resources\Clinics\Tables\ClinicsTable;
use App\Models\Clinic;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class ClinicResource extends Resource
{
    protected static ?string $model = Clinic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'platform';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('filament.clinic.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.clinic.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ClinicForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClinicsTable::configure($table);
    }

    public static function canViewAny(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    /**
     * Never deleted — bookings, patients and revenue history hang off a clinic.
     * Deactivate instead.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClinics::route('/'),
            'create' => CreateClinic::route('/create'),
            'edit' => EditClinic::route('/{record}/edit'),
        ];
    }
}
