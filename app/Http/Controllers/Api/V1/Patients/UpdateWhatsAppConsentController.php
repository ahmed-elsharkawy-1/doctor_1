<?php

namespace App\Http\Controllers\Api\V1\Patients;

use App\Enums\WhatsAppOptOutSource;
use App\Http\Controllers\Api\V1\V1Controller;
use App\Http\Requests\Api\V1\Patients\UpdateWhatsAppConsentRequest;
use App\Services\Results\V1\Patients\WhatsAppConsentResult;
use App\Services\V1\Patients\PatientSearchService;
use App\Services\V1\Patients\WhatsAppConsentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateWhatsAppConsentController extends V1Controller
{
    public function __construct(
        private readonly PatientSearchService $patients,
        private readonly WhatsAppConsentService $consent,
    ) {}

    public function __invoke(UpdateWhatsAppConsentRequest $request, int $patient): JsonResponse
    {
        $found = $this->patients->find($this->clinic($request), $patient);

        $request->enabled()
            ? $this->consent->resume($found)
            : $this->consent->stop($found, WhatsAppOptOutSource::CLINIC);

        return ApiResponse::success(
            ['whatsapp' => (new WhatsAppConsentResult($found->refresh()))->toArray()],
            __($request->enabled() ? 'patient.whatsapp.resumed_ok' : 'patient.whatsapp.stopped_ok'),
        );
    }
}
