<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Settings\SystemSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Project-wide settings a super admin changes here instead of in the
 * server's .env. Each field shows what the app is using now; saving stores
 * only what was changed, so everything else keeps following .env. Every rule
 * lives in SystemSettings.
 */
class SystemSettingsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'platform';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'system-settings';

    protected string $view = 'filament.admin.system-settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user?->isSuperAdmin() ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('filament.nav.platform');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament.system_settings.title');
    }

    public function getTitle(): string
    {
        return __('filament.system_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('filament.system_settings.subheading');
    }

    public function mount(): void
    {
        $this->form->fill($this->settings()->formState());
    }

    public function form(Schema $schema): Schema
    {
        $toggle = fn (string $key) => Toggle::make($key)
            ->label(__("filament.system_settings.fields.{$key}"))
            ->helperText(__("filament.system_settings.hints.{$key}"));

        $number = fn (string $key, int $min, int $max) => TextInput::make($key)
            ->label(__("filament.system_settings.fields.{$key}"))
            ->helperText(__("filament.system_settings.hints.{$key}"))
            ->numeric()
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->required();

        $text = fn (string $key) => TextInput::make($key)
            ->label(__("filament.system_settings.fields.{$key}"))
            ->maxLength(255);

        return $schema
            ->components([
                Section::make(__('filament.system_settings.section.self_booking'))
                    ->schema([
                        $toggle(SystemSettings::REQUIRE_OTP),
                        $toggle('self_booking.show_price'),
                        $number('self_booking.otp.resend_cooldown', 30, 600),
                        $number('self_booking.otp.max_per_phone_hour', 1, 20),
                        $number('self_booking.otp.max_per_ip_hour', 1, 100),
                    ])
                    ->columns(2),

                Section::make(__('filament.system_settings.section.no_show_block'))
                    ->description(__('filament.system_settings.section.no_show_block_hint'))
                    ->schema([
                        $number('self_booking.no_show_block.threshold', 1, 10),
                        $number('self_booking.no_show_block.window_days', 7, 180),
                    ])
                    ->columns(2),

                Section::make(__('filament.system_settings.section.queue'))
                    ->schema([
                        $toggle('tracking.show_estimate'),
                        $number('turn_alert.first_patients_lead_minutes', 0, 120),
                        $number('waiting_list.max_per_day', 1, 100),
                    ])
                    ->columns(2),

                Section::make(__('filament.system_settings.section.reports'))
                    ->schema([
                        $toggle('reports.morning_whatsapp'),
                        TimePicker::make('reports.send_at')
                            ->label(__('filament.system_settings.fields.reports.send_at'))
                            ->helperText(__('filament.system_settings.hints.reports.send_at'))
                            ->seconds(false)
                            ->format('H:i')
                            ->rule('date_format:H:i')
                            ->validationMessages(['date_format' => __('filament.system_settings.time_format')])
                            ->required(),
                    ])
                    ->columns(2),

                Section::make(__('filament.system_settings.section.site'))
                    ->description(__('filament.system_settings.section.site_hint'))
                    ->schema([
                        $text('site.whatsapp')->tel(),
                        $text('site.phone')->tel(),
                        $text('site.email')->email(),
                        $text('site.social.facebook')->url(),
                        $text('site.social.instagram')->url(),
                        $text('site.social.linkedin')->url(),
                        $toggle('site.show_login'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        /** @var User|null $user */
        $user = Auth::user();

        $settings = $this->settings();

        // Only what changed is stored: an untouched field keeps following
        // .env, instead of freezing today's value into the database.
        foreach (array_keys(SystemSettings::DEFINITIONS) as $key) {
            $submitted = Arr::get($state, $key);

            if ($this->normalise($submitted) !== $this->normalise($settings->value($key))) {
                $settings->set($key, $submitted, $user);
            }
        }

        $this->form->fill($settings->formState());

        Notification::make()->success()->title(__('filament.system_settings.saved'))->send();
    }

    private function normalise(mixed $value): mixed
    {
        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value,
            default => (string) $value,
        };
    }

    private function settings(): SystemSettings
    {
        return app(SystemSettings::class);
    }
}
