<?php

namespace App\Filament\Admin\Resources\Specialties\Pages;

use App\Filament\Admin\Resources\Specialties\SpecialtyResource;
use Filament\Resources\Pages\EditRecord;

class EditSpecialty extends EditRecord
{
    // Records are deactivated, never deleted: history references them.
    protected static string $resource = SpecialtyResource::class;
}
