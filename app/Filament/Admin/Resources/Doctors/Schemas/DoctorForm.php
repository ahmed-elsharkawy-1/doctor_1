<?php

namespace App\Filament\Admin\Resources\Doctors\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DoctorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament.doctor.section.details'))
                ->schema([
                    Select::make('clinic_id')
                        ->label(__('filament.doctor.clinic'))
                        ->relationship('clinic', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),

                    TextInput::make('name')
                        ->label(__('filament.doctor.name'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label(__('filament.doctor.phone'))
                        ->tel()
                        ->maxLength(20),

                    Toggle::make('is_active')
                        ->label(__('filament.common.is_active'))
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }
}
