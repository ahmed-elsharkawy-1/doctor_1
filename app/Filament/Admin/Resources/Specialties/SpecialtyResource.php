<?php

namespace App\Filament\Admin\Resources\Specialties;

use App\Filament\Admin\Resources\Specialties\Pages\CreateSpecialty;
use App\Filament\Admin\Resources\Specialties\Pages\EditSpecialty;
use App\Filament\Admin\Resources\Specialties\Pages\ListSpecialties;
use App\Filament\Admin\Resources\Specialties\Schemas\SpecialtyForm;
use App\Filament\Admin\Resources\Specialties\Tables\SpecialtiesTable;
use App\Models\Specialty;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class SpecialtyResource extends Resource
{
    protected static ?string $model = Specialty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'platform';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.platform');
    }

    public static function getModelLabel(): string
    {
        return __('filament.specialty.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament.specialty.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return SpecialtyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SpecialtiesTable::configure($table);
    }

    /**
     * Platform-level reference data — super admins only.
     */
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

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSpecialties::route('/'),
            'create' => CreateSpecialty::route('/create'),
            'edit' => EditSpecialty::route('/{record}/edit'),
        ];
    }
}
