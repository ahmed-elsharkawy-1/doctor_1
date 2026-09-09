<?php

namespace App\Filament\Admin\Resources\Doctors\Schemas;

use App\Enums\DoctorSex;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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

                    Select::make('sex')
                        ->label(__('filament.doctor.sex'))
                        ->options(DoctorSex::options())
                        ->native(false)
                        ->required()
                        ->helperText(__('filament.doctor.sex_hint')),

                    TextInput::make('title')
                        ->label(__('filament.doctor.title'))
                        ->helperText(__('filament.doctor.title_hint'))
                        ->maxLength(255),

                    Toggle::make('is_active')
                        ->label(__('filament.common.is_active'))
                        ->default(true),
                ])
                ->columns(2),

            Section::make(__('filament.doctor.section.public_page'))
                ->description(__('filament.doctor.section.public_page_hint'))
                ->schema([
                    FileUpload::make('photo_path')
                        ->label(__('filament.doctor.photo'))
                        ->image()
                        ->imageEditor()
                        ->disk(config('clinic.public.disk'))
                        ->directory('doctors')
                        ->maxSize(config('clinic.public.photo_max_kb'))
                        ->columnSpanFull(),

                    Textarea::make('bio')
                        ->label(__('filament.doctor.bio'))
                        ->rows(4)
                        ->maxLength(2000)
                        ->columnSpanFull(),

                    Repeater::make('treatmentAreas')
                        ->label(__('filament.doctor.treatment_areas'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->schema([
                            TextInput::make('title')
                                ->label(__('filament.doctor.area_title'))
                                ->required()
                                ->maxLength(255),

                            Select::make('icon')
                                ->label(__('filament.doctor.area_icon'))
                                ->options(array_combine(
                                    config('clinic.public.icons'),
                                    config('clinic.public.icons'),
                                ))
                                ->native(false),

                            TextInput::make('description')
                                ->label(__('filament.doctor.area_description'))
                                ->maxLength(255)
                                ->columnSpanFull(),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ])
                ->columns(2),
        ]);
    }
}
