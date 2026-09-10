<?php

namespace App\Services\V1\Messaging;

use App\Models\Booking;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use Illuminate\Support\Carbon;

/**
 * Fills one approved WhatsApp template in for one booking.
 *
 * Each template has its own parameter list, fixed by Meta at approval time and
 * matched positionally — a sixth value the template does not declare is
 * rejected outright, and so is a fifth that should have been a sixth. So there
 * is one arm per template here rather than one shared list of "everything a
 * message might need", which is what this replaced.
 *
 * The counts below are the approved ones, read back from the Graph API:
 *
 *   appointment_booking_confirmation  ar      6 body params, URL button
 *   appointment_rating                en_US   0 body params, URL button
 *   booking_cancellation              ar      4 body params, no button
 */
class TemplatePayloadResolver
{
    public function for(MessageTemplate $template, Booking $booking, Clinic $clinic): TemplatePayload
    {
        return match ($template->key) {
            WhatsAppMessagingService::CONFIRMATION_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                    $this->address($clinic),
                    $this->arrivalLead($clinic),
                ],
                buttonSuffix: $this->suffix('tracking', $booking),
            ),

            // Nothing to fill in: the whole message is fixed text, and the
            // only thing that varies is where the button goes.
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => new TemplatePayload(
                body: [],
                buttonSuffix: $this->suffix('review', $booking),
            ),

            'day_cancelled' => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    // This template supplies "الدكتور" itself, so the name must
                    // arrive bare — otherwise it reads "الدكتور د. سارة".
                    $this->doctorNameBare($clinic),
                    $this->date($booking, $clinic),
                    $this->clinicPhone($clinic),
                ],
            ),

            /*
            | Drafted for the app but never submitted to Meta, so they are
            | seeded inactive and cannot be sent. The mapping matches our own
            | draft wording — {{1}} patient, {{2}} clinic — and must be checked
            | against the approved template if these are ever submitted, since
            | Meta fixes the parameter list at approval, not us.
            */
            'appointment_earlier', 'appointment_delayed' => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->clean($clinic->name, '—'),
                ],
            ),

            // A template nobody has mapped yet. Sending an empty body to Meta
            // would be rejected anyway; failing here says why.
            default => throw new \RuntimeException(
                "No WhatsApp parameter mapping for template [{$template->key}].",
            ),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | The values
    |--------------------------------------------------------------------------
    */

    private function patientName(Booking $booking): string
    {
        return $this->clean($booking->patient?->name, __('messages.fallback.patient'));
    }

    /**
     * "د. سارة النجار" — for a template that prints the name under its own
     * label and expects the honorific to come with it.
     *
     * Doctors are not stored consistently: one clinic types the honorific into
     * the name and another does not, so it is normalised here rather than
     * trusted. "د." is the same abbreviation for either sex.
     */
    private function doctorName(Clinic $clinic): string
    {
        $name = $this->doctorNameBare($clinic);

        return $name === $clinic->name ? $name : 'د. '.$name;
    }

    /**
     * The name with any honorific taken off, for a template that prints one of
     * its own.
     */
    private function doctorNameBare(Clinic $clinic): string
    {
        $name = $this->clean($clinic->doctor?->name, $clinic->name);

        return trim(preg_replace(
            '/^(?:ال)?(?:د\.|د\/|د\s|دكتورة|دكتور|الدكتورة|الدكتور)\s*/u',
            '',
            $name,
        ) ?: $name);
    }

    private function address(Clinic $clinic): string
    {
        return $this->clean($clinic->address, $clinic->name);
    }

    private function clinicPhone(Clinic $clinic): string
    {
        return $this->clean($clinic->phone, __('messages.fallback.phone'));
    }

    /**
     * "15 سبتمبر 2026" — the same long form the approved sample used.
     */
    private function date(Booking $booking, Clinic $clinic): string
    {
        $date = $booking->visit_date instanceof Carbon
            ? $booking->visit_date
            : Carbon::parse((string) $booking->visit_date, $clinic->timezone);

        return $this->clean($date->locale('ar')->isoFormat('D MMMM YYYY'), '—');
    }

    /**
     * "06:30 مساءً". An emergency has no slot, so it says so rather than
     * sending an empty parameter, which Meta rejects.
     */
    private function time(Booking $booking, Clinic $clinic): string
    {
        if ($booking->start_at === null) {
            return __('messages.fallback.no_time');
        }

        $meridiem = $booking->start_at->format('A') === 'AM'
            ? __('schedule.am')
            : __('schedule.pm');

        return $this->clean($booking->start_at->format('h:i').' '.$meridiem, '—');
    }

    /**
     * "15 دقيقة" — how early the clinic asks patients to arrive.
     */
    private function arrivalLead(Clinic $clinic): string
    {
        return __('messages.minutes', [
            'count' => (int) $clinic->patient_arrival_lead_minutes,
        ]);
    }

    /**
     * The path that rides on the template button, appended to its fixed base.
     */
    private function suffix(string $page, Booking $booking): string
    {
        $path = trim((string) config("clinic.{$page}.path"), '/');

        return $path.'/'.$booking->tracking_token;
    }

    /**
     * Meta rejects a parameter that is empty, or that carries a newline or a
     * tab. A patient's name is typed by hand, so neither is hypothetical.
     */
    private function clean(?string $value, string $fallback): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        $value = $this->westernDigits($value);

        return $value === '' ? $fallback : $value;
    }

    /**
     * Everything this class generates — dates, times, minutes — is in Western
     * digits, but a clinic's address is typed by hand and may not be. Mixing
     * both inside one message looks like a bug to the patient, so stored text
     * is brought into line with the generated text.
     */
    private function westernDigits(string $value): string
    {
        return strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
    }
}
