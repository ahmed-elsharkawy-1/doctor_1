<?php

namespace App\Services\V1\Patients;

use App\Enums\WhatsAppOptOutSource;
use App\Models\Patient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Whether a patient gets WhatsApp messages, and stopping or resuming them.
 *
 * Every message to a patient already goes out only while
 * `whatsapp_opt_in_at` is set, so stopping clears it, and that one check
 * covers confirmations, reminders, delays, the waiting list and
 * announcements alike.
 *
 * The stop is stamped separately (`whatsapp_opted_out_at`) because booking
 * again grants consent (PatientService::findOrCreate). Without the stamp a
 * patient who said stop would be messaged again from their next visit on.
 * Only the clinic turns a stopped patient back on, deliberately, from the
 * patient's page.
 */
class WhatsAppConsentService
{
    public function stop(Patient $patient, WhatsAppOptOutSource $source): Patient
    {
        $patient->forceFill([
            'whatsapp_opt_in_at' => null,
            'whatsapp_opted_out_at' => $patient->whatsapp_opted_out_at ?? Carbon::now(),
            'whatsapp_opt_out_source' => $patient->whatsapp_opt_out_source ?? $source,
        ])->save();

        return $patient;
    }

    public function resume(Patient $patient): Patient
    {
        $patient->forceFill([
            'whatsapp_opt_in_at' => Carbon::now(),
            'whatsapp_opted_out_at' => null,
            'whatsapp_opt_out_source' => null,
        ])->save();

        return $patient;
    }

    /**
     * A «إيقاف» from this number: every clinic's record of it stops.
     *
     * One WhatsApp number sends for every clinic, so "stop" can only mean
     * stop all of it — the patient cannot know which clinic's record a
     * message came from.
     *
     * Records already stopped are left as they were, so a second «إيقاف» —
     * or Meta delivering the same callback again — changes nothing.
     *
     * @return Collection<int, Patient> the records this stopped
     */
    public function stopNumber(string $from): Collection
    {
        $digits = preg_replace('/[^0-9]/', '', $from) ?? '';

        if ($digits === '') {
            return collect();
        }

        return Patient::query()
            ->whereIn('phone', ['+'.$digits, $digits])
            ->whereNull('whatsapp_opted_out_at')
            ->get()
            ->each(fn (Patient $patient) => $this->stop($patient, WhatsAppOptOutSource::PATIENT));
    }
}
