<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    // Records are deactivated, never deleted: history references them.
    protected static string $resource = UserResource::class;
}
