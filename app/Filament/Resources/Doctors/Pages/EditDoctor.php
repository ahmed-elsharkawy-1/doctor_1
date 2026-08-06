<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Resources\Pages\EditRecord;

class EditDoctor extends EditRecord
{
    // Records are deactivated, never deleted: history references them.
    protected static string $resource = DoctorResource::class;
}
