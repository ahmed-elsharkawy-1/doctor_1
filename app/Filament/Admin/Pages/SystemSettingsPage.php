<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Services\Settings\SystemSettings;
use BackedEnum;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Project-wide switches a super admin flips here instead of in the server's
 * .env. Saving takes effect at once; every rule lives in SystemSettings.
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

    public function mount(): void
    {
        $this->form->fill([
            'require_otp' => $this->settings()->requireOtp(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('filament.system_settings.section.self_booking'))
                    ->schema([
                        Toggle::make('require_otp')
                            ->label(__('filament.system_settings.require_otp'))
                            ->helperText(__('filament.system_settings.require_otp_hint')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        /** @var User|null $user */
        $user = Auth::user();

        $this->settings()->set(SystemSettings::REQUIRE_OTP, (bool) $state['require_otp'], $user);

        Notification::make()->success()->title(__('filament.system_settings.saved'))->send();
    }

    private function settings(): SystemSettings
    {
        return app(SystemSettings::class);
    }
}
