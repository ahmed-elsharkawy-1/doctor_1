<?php

namespace App\Filament\Admin\Resources\Clinics\Pages;

use App\Filament\Admin\Resources\Clinics\ClinicResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinics extends ListRecords
{
    protected static string $resource = ClinicResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
