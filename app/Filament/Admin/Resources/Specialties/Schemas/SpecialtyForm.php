<?php

namespace App\Filament\Admin\Resources\Specialties\Schemas;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SpecialtyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('filament.specialty.section.details'))
                ->schema([
                    TextInput::make('name_ar')
                        ->label(__('filament.specialty.name_ar'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('filament.specialty.name_en'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('slug')
                        ->label(__('filament.specialty.slug'))
                        ->required()
                        ->alphaDash()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),

                    TextInput::make('sort_order')
                        ->label(__('filament.common.sort_order'))
                        ->numeric()
                        ->default(0),

                    Toggle::make('is_active')
                        ->label(__('filament.common.is_active'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('filament.specialty.section.default_visit_types'))
                ->description(__('filament.specialty.section.default_visit_types_hint'))
                ->schema([
                    Repeater::make('defaultVisitTypes')
                        ->hiddenLabel()
                        ->relationship()
                        ->schema([
                            TextInput::make('name_ar')
                                ->label(__('filament.visit_type.name_ar'))
                                ->required()
                                ->maxLength(255),

                            TextInput::make('name_en')
                                ->label(__('filament.visit_type.name_en'))
                                ->required()
                                ->maxLength(255),

                            TextInput::make('duration_minutes')
                                ->label(__('filament.visit_type.duration_minutes'))
                                ->numeric()
                                ->minValue(5)
                                ->maxValue(480)
                                ->required(),
                        ])
                        ->columns(3)
                        ->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->addActionLabel(__('filament.visit_type.add')),
                ]),
        ]);
    }
}
