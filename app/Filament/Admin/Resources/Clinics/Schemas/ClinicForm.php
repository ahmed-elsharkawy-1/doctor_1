<?php

namespace App\Filament\Admin\Resources\Clinics\Schemas;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Clinic;
use App\Models\User;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
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

                    TextInput::make('slug')
                        ->label(__('filament.clinic.slug'))
                        ->helperText(__('filament.clinic.slug_hint'))
                        ->required()
                        ->maxLength(120)
                        ->rules(['regex:/^[a-z0-9][a-z0-9-]*$/'])
                        ->notIn(config('clinic.landing.reserved'))
                        ->unique(ignoreRecord: true),

                    TextInput::make('address')
                        ->label(__('filament.clinic.address'))
                        ->maxLength(255),

                    TextInput::make('city')
                        ->label(__('filament.clinic.city'))
                        ->helperText(__('filament.clinic.city_hint'))
                        ->maxLength(120),

                    TextInput::make('latitude')
                        ->label(__('filament.clinic.latitude'))
                        ->numeric()
                        ->helperText(__('filament.clinic.coords_hint')),

                    TextInput::make('longitude')
                        ->label(__('filament.clinic.longitude'))
                        ->numeric(),

                    TextInput::make('phone')
                        ->label(__('filament.clinic.phone'))
                        ->tel()
                        ->required()
                        ->maxLength(20),

                    // The login lives on its user account, not on the clinic.
                    // Shown here so nobody has to guess where to change it.
                    TextEntry::make('owner_login')
                        ->label(__('filament.clinic.owner_login'))
                        ->visibleOn('edit')
                        ->state(fn (?Clinic $record): ?string => self::owner($record)?->email)
                        ->placeholder(__('filament.clinic.owner_login_missing'))
                        ->url(fn (?Clinic $record): ?string => ($owner = self::owner($record))
                            ? UserResource::getUrl('edit', ['record' => $owner])
                            : null)
                        ->helperText(__('filament.clinic.owner_login_hint')),

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

            Section::make(__('filament.clinic.section.public_page'))
                ->description(__('filament.clinic.section.public_page_hint'))
                ->schema([
                    Repeater::make('photos')
                        ->label(__('filament.clinic.photos'))
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->defaultItems(0)
                        ->collapsed()
                        ->itemLabel(fn (array $state): ?string => $state['caption'] ?? null)
                        ->schema([
                            FileUpload::make('path')
                                ->label(__('filament.clinic.photo'))
                                ->image()
                                ->imageEditor()
                                ->disk(config('clinic.public.disk'))
                                ->directory('clinics')
                                ->maxSize(config('clinic.public.photo_max_kb'))
                                ->required(),

                            TextInput::make('caption')
                                ->label(__('filament.clinic.photo_caption'))
                                ->maxLength(255),
                        ])
                        ->columns(2)
                        ->columnSpanFull(),
                ]),

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

                    // The master switch for the public booking page. Off by
                    // default and for every clinic already running: opening a
                    // doctor's day to a public form is a decision somebody
                    // makes, never something a deploy does on their behalf.
                    Toggle::make('self_booking_enabled')
                        ->label(__('filament.clinic.self_booking_enabled'))
                        ->default(false)
                        ->helperText(__('filament.clinic.self_booking_enabled_hint')),

                    // Every WhatsApp message this clinic's patients would get.
                    // On by default; switched off while a clinic pilots the
                    // app. The panel is super-admin-only, so clinics cannot
                    // flip it themselves.
                    Toggle::make('whatsapp_enabled')
                        ->label(__('filament.clinic.whatsapp_enabled'))
                        ->default(true)
                        ->helperText(__('filament.clinic.whatsapp_enabled_hint')),

                    // The doctor's report page and morning WhatsApp. Off until
                    // somebody decides this clinic gets it; who may read it is
                    // set per account on the user screen.
                    Toggle::make('reports_enabled')
                        ->label(__('filament.clinic.reports_enabled'))
                        ->default(false)
                        ->helperText(__('filament.clinic.reports_enabled_hint')),

                    // Patients get a shorter horizon than the secretary, so
                    // she keeps room to place the people who phone her. The
                    // rule is enforced again in Clinic::patientBookingWindowDays()
                    // — this only catches it at the form.
                    TextInput::make('patient_booking_window_days')
                        ->label(__('filament.clinic.patient_booking_window_days'))
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(90)
                        ->rules(['lte:booking_window_days'])
                        ->default($defaults['patient_booking_window_days'])
                        ->helperText(__('filament.clinic.patient_booking_window_days_hint')),

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
                        // Left empty, each visit type sets its own grid from
                        // its own length, which is what a clinic expects.
                        ->placeholder(__('filament.clinic.slot_step_minutes_auto'))
                        ->helperText(__('filament.clinic.slot_step_minutes_hint')),
                ])
                ->columns(2),
        ]);
    }

    private static function owner(?Clinic $clinic): ?User
    {
        return $clinic?->staff()->role(UserRole::CLINIC)->first();
    }
}
