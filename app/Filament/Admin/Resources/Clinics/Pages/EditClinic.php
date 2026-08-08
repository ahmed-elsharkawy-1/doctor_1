<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Models\Clinic;
use Filament\Resources\Pages\EditRecord;

class EditClinic extends EditRecord
{
    // Records are deactivated, never deleted: history references them.
    protected static string $resource = ClinicResource::class;

    private ?string $ownerPassword = null;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->ownerPassword = isset($data['owner_password']) ? (string) $data['owner_password'] : null;

        unset($data['owner_password']);

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Clinic $clinic */
        $clinic = $this->record;

        app(ProvisionClinicAction::class)->execute($clinic, $this->ownerPassword);
    }
}
