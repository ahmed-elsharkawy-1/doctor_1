<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The branches select only saves what it shows: drop assignments to
     * branches of clinics the account left, or all of them once it is the
     * doctor, who needs none (ELA-17).
     */
    protected function afterCreate(): void
    {
        $this->record->pruneBranches();
    }
}
