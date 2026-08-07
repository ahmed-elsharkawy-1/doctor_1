<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var User|null $actor */
        $actor = Auth::user();
        $isSuperAdmin = $actor?->isSuperAdmin() ?? false;

        return $schema->components([
            Section::make(__('filament.user.section.account'))
                ->schema([
                    TextInput::make('name')
                        ->label(__('filament.user.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label(__('filament.user.email'))
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label(__('filament.user.phone'))
                        ->tel()
                        ->maxLength(20),

                    TextInput::make('password')
                        ->label(__('filament.user.password'))
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->maxLength(255)
                        // Required on create; on edit, leaving it blank keeps
                        // the existing password.
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? __('filament.user.password_hint')
                            : null),
                ])
                ->columns(2),

            Section::make(__('filament.user.section.access'))
                ->schema([
                    Select::make('role')
                        ->label(__('filament.user.role'))
                        ->options(fn (): array => $isSuperAdmin
                            ? UserRole::options()
                            : [UserRole::SECRETARY->value => UserRole::SECRETARY->label()])
                        ->required()
                        ->live()
                        ->default(UserRole::SECRETARY->value),

                    Select::make('clinics')
                        ->label(__('filament.user.clinics'))
                        ->relationship('clinics', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        // Super admins are platform-wide; everyone else must
                        // belong to at least one clinic.
                        ->visible(fn ($get): bool => $get('role') !== UserRole::SUPER_ADMIN->value)
                        ->required(fn ($get): bool => $get('role') !== UserRole::SUPER_ADMIN->value)
                        ->disabled(! $isSuperAdmin)
                        ->default(fn (): array => $isSuperAdmin ? [] : array_filter([$actor?->activeClinicId()])),

                    Select::make('doctor_id')
                        ->label(__('filament.user.doctor'))
                        ->relationship('doctor', 'name')
                        ->searchable()
                        ->preload()
                        ->visible(fn ($get): bool => $get('role') === UserRole::OWNER->value)
                        ->helperText(__('filament.user.doctor_hint')),

                    Select::make('locale')
                        ->label(__('filament.user.locale'))
                        ->options(array_combine(
                            config('clinic.api.locales'),
                            config('clinic.api.locales'),
                        ))
                        ->default(config('clinic.api.default_locale')),

                    Toggle::make('is_active')
                        ->label(__('filament.common.is_active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }
}
