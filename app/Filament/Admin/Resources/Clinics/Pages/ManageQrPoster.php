<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Models\Clinic;
use App\Services\V1\QrPoster\QrPosterService;
use App\Services\V1\QrPoster\QrScanService;
use Filament\Actions\Action;
use Filament\Forms\Components\Slider;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * A clinic's printable QR poster: a live preview, the few adjustments that
 * make a long name or title fit, and a PNG download.
 *
 * Every rule — defaults, limits, what is stored, the layout — lives in
 * QrPosterService. This page only shows the form and hands its state over.
 * The PNG is drawn by the browser from the same SVG the preview shows.
 */
class ManageQrPoster extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ClinicResource::class;

    protected string $view = 'filament.admin.clinics.qr-poster';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->form->fill($this->posters()->settings($this->clinic()));
    }

    public static function canAccess(array $parameters = []): bool
    {
        return ClinicResource::canViewAny();
    }

    public function getTitle(): string
    {
        return __('filament.qr_poster.title');
    }

    public function getSubheading(): ?string
    {
        return $this->clinic()->name;
    }

    public function form(Schema $schema): Schema
    {
        $defaults = $this->posters()->defaults($this->clinic());
        $size = fn (string $element, string $field) => Slider::make($field)
            ->label(__("filament.qr_poster.{$field}"))
            ->range($this->posters()->size($element)['min'], $this->posters()->size($element)['max'])
            ->step(5)
            ->tooltips()
            // Always has a value; the asterisk would only suggest otherwise.
            ->markAsRequired(false)
            ->live();

        return $schema
            ->components([
                Section::make(__('filament.qr_poster.section.text'))
                    ->description(__('filament.qr_poster.section.text_hint'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('filament.qr_poster.name'))
                            ->placeholder($defaults['name'])
                            ->maxLength(120)
                            ->live(debounce: 600),
                        Textarea::make('title')
                            ->label(__('filament.qr_poster.title_line'))
                            ->helperText(__('filament.qr_poster.line_break_hint'))
                            ->placeholder($defaults['title'])
                            ->rows(3)
                            ->maxLength(400)
                            ->live(debounce: 600),
                        Textarea::make('call_to_action')
                            ->label(__('filament.qr_poster.call_to_action'))
                            ->placeholder($defaults['call_to_action'])
                            ->rows(2)
                            ->maxLength(200)
                            ->live(debounce: 600),
                    ]),
                Section::make(__('filament.qr_poster.section.sizes'))
                    ->schema([
                        $size('name', 'name_size'),
                        $size('title', 'title_size'),
                        $size('qr', 'qr_size'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $this->posters()->save($this->clinic(), $this->form->getState());

        $this->form->fill($this->posters()->settings($this->clinic()));

        Notification::make()->success()->title(__('filament.qr_poster.saved'))->send();
    }

    public function resetToDefaults(): void
    {
        $this->posters()->reset($this->clinic());

        $this->form->fill($this->posters()->settings($this->clinic()));

        Notification::make()->success()->title(__('filament.qr_poster.reset_done'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openPage')
                ->label(__('filament.qr_poster.open_page'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): string => route('landing', ['slug' => $this->clinic()->slug]))
                ->openUrlInNewTab(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'poster' => $this->posters()->build($this->clinic(), $this->data),
            'fonts' => $this->posters()->fontFaces(),
            'stats' => app(QrScanService::class)->stats($this->clinic()),
        ];
    }

    private function clinic(): Clinic
    {
        /** @var Clinic $clinic */
        $clinic = $this->getRecord();

        return $clinic;
    }

    private function posters(): QrPosterService
    {
        return app(QrPosterService::class);
    }
}
