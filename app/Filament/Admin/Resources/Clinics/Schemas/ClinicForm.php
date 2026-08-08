<?php

namespace App\Filament\Admin\Resources\Clinics\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClinicForm
{
    public static function configure(Schema $schema): Schema
    {
        $defaults = config('clinic.defaults');

        return $schema->components([
            Section::make(__('filament.clinic.section.details'))
                ->schema([
                    Select::make('specialty_id')
                        ->label(__('filament.clinic.specialty'))
                        ->relationship('specialty', 'name_ar')
                        ->searchable()
                        ->preload()
                        ->required()
                        // The specialty seeds the clinic's visit types on
                        // creation, so it must not drift afterwards.
                        ->disabledOn('edit')
                        ->helperText(__('filament.clinic.specialty_hint')),

                    TextInput::make('name')
                        ->label(__('filament.clinic.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('address')
                        ->label(__('filament.clinic.address'))
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label(__('filament.clinic.phone'))
                        ->tel()
                        ->required()
                        ->maxLength(20),

                    TextInput::make('owner_password')
                        ->label(__('filament.clinic.owner_password'))
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->maxLength(255)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText(fn (string $operation): ?string => $operation === 'edit'
                            ? __('filament.clinic.owner_password_hint')
                            : __('filament.clinic.owner_password_create_hint')),

                    Toggle::make('is_active')
                        ->label(__('filament.clinic.is_active'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('filament.clinic.section.settings'))
                ->description(__('filament.clinic.section.settings_hint'))
                ->schema([
                    Select::make('timezone')
                        ->label(__('filament.clinic.timezone'))
                        ->options(array_combine(
                            timezone_identifiers_list(),
                            timezone_identifiers_list(),
                        ))
                        ->searchable()
                        ->required()
                        ->default($defaults['timezone']),

                    Select::make('country_code')
                        ->label(__('filament.clinic.country_code'))
                        ->options(array_combine(
                            array_keys(config('clinic.phone.countries')),
                            array_keys(config('clinic.phone.countries')),
                        ))
                        ->required()
                        ->default(config('clinic.phone.default_country')),

                    TextInput::make('booking_window_days')
                        ->label(__('filament.clinic.booking_window_days'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(90)
                        ->required()
                        ->default($defaults['booking_window_days']),

                    TextInput::make('first_visit_only_days')
                        ->label(__('filament.clinic.first_visit_only_days'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(730)
                        ->required()
                        ->default($defaults['first_visit_only_days'])
                        ->helperText(__('filament.clinic.first_visit_only_days_hint')),

                    TextInput::make('slot_step_minutes')
                        ->label(__('filament.clinic.slot_step_minutes'))
                        ->numeric()
                        ->minValue(5)
                        ->maxValue(60)
                        ->required()
                        ->default($defaults['slot_step_minutes'])
                        ->helperText(__('filament.clinic.slot_step_minutes_hint')),
                ])
                ->columns(2),
        ]);
    }
}
