<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Models\Clinic;
use Filament\Resources\Pages\CreateRecord;

class CreateClinic extends CreateRecord
{
    protected static string $resource = ClinicResource::class;

    private ?string $ownerPassword = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->ownerPassword = (string) ($data['owner_password'] ?? '');

        unset($data['owner_password']);

        return $data;
    }

    /**
     * A clinic is only usable once it has visit types and a week. Provisioning
     * runs here so a clinic can never exist in a half-configured state.
     */
    protected function afterCreate(): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        app(ProvisionClinicAction::class)->execute($clinic, $this->ownerPassword);
    }
}
