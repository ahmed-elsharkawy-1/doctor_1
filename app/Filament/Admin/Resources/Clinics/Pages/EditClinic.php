<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Filament\Admin\Resources\Clinics\ClinicResource;
use Filament\Resources\Pages\EditRecord;

class EditClinic extends EditRecord
{
    // Records are deactivated, never deleted: history references them.
    protected static string $resource = ClinicResource::class;
}
