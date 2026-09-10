<?php

namespace App\Livewire\App\Settings;

use App\DTOs\V1\Settings\VisitTypeData;
use App\Models\VisitType;
use App\Services\V1\Settings\VisitTypeService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * What the clinic offers, how long each takes and what it costs.
 *
 * Nothing is ever deleted — bookings reference a visit type for ever, and a
 * past booking's price and duration are its own snapshot. Hiding is the only
 * removal, and the service refuses to hide the last active one.
 */
class VisitTypes extends SettingsComponent
{
    public bool $showHidden = false;

    public ?int $editing = null;

    public string $name = '';

    public string $description = '';

    public string $durationMinutes = '20';

    public string $price = '';

    public bool $isNewPatientType = false;

    public ?int $confirmingHide = null;

    public function render(): View
    {
        return view('livewire.app.settings.visit-types', [
            'visitTypes' => $this->list(),
            'canSeePrices' => auth()->user()?->hasAbility('prices.view') ?? false,
        ])->title(__('app.settings.visit_types'));
    }

    public function startCreating(): void
    {
        $this->reset(['editing', 'name', 'description', 'price', 'isNewPatientType']);
        $this->durationMinutes = '20';
        $this->editing = 0;
    }

    public function startEditing(int $visitTypeId): void
    {
        $visitType = $this->list(includeHidden: true)->firstWhere('id', $visitTypeId);

        if ($visitType === null) {
            return;
        }

        $this->editing = $visitType->id;
        $this->name = $visitType->name;
        $this->description = (string) $visitType->description;
        $this->durationMinutes = (string) $visitType->duration_minutes;
        $this->price = (string) $visitType->price;
        $this->isNewPatientType = (bool) $visitType->is_new_patient_type;
    }

    public function cancelEditing(): void
    {
        $this->reset(['editing', 'name', 'description', 'price', 'isNewPatientType']);
    }

    public function save(): void
    {
        $this->validate();

        $data = VisitTypeData::fromArray([
            'name' => $this->name,
            'description' => $this->description,
            'duration_minutes' => (int) $this->durationMinutes,
            'price' => $this->price === '' ? null : $this->price,
            'is_new_patient_type' => $this->isNewPatientType,
        ], canSetPrice: auth()->user()?->hasAbility('prices.view') ?? false);

        $editing = $this->editing;

        $this->run(function () use ($data, $editing): void {
            $service = app(VisitTypeService::class);

            $editing > 0
                ? $service->update($this->clinic(), $editing, $data)
                : $service->create($this->clinic(), $data);
        }, __('app.settings.saved'));

        if (! $this->failed) {
            $this->cancelEditing();
        }
    }

    public function confirmHide(int $visitTypeId): void
    {
        $this->confirmingHide = $visitTypeId;
    }

    public function dismissHide(): void
    {
        $this->confirmingHide = null;
    }

    public function hide(int $visitTypeId): void
    {
        $this->confirmingHide = null;

        // The service refuses to hide the last active one, and says so.
        $this->run(
            fn () => app(VisitTypeService::class)->hide($this->clinic(), $visitTypeId),
            __('app.settings.visit_type_hidden'),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'durationMinutes' => ['required', 'integer', 'min:5', 'max:480'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function validationAttributes(): array
    {
        return [
            'name' => __('app.settings.vt_name'),
            'description' => __('app.settings.vt_description'),
            'durationMinutes' => __('app.settings.vt_duration'),
            'price' => __('app.settings.vt_price'),
        ];
    }

    /**
     * @return Collection<int, VisitType>
     */
    private function list(?bool $includeHidden = null): Collection
    {
        return app(VisitTypeService::class)->list(
            $this->clinic(),
            $includeHidden ?? $this->showHidden,
        );
    }
}
