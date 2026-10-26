<?php

namespace App\Services\V1\Messaging;

use App\Models\Booking;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Support\PhoneNumber;

/**
 * Fills one approved WhatsApp template in for one booking.
 *
 * Each template has its own parameter list, fixed by Meta at approval time and
 * matched positionally — a sixth value the template does not declare is
 * rejected outright, and so is a fifth that should have been a sixth. So there
 * is one arm per template here rather than one shared list of "everything a
 * message might need", which is what this replaced.
 *
 * The counts below are the approved ones, read back from the Graph API
 * (2026-10-24), plus the visit-day reminder as submitted that day:
 *
 *   appointment_booking_confirmation_v3  ar   6 body params, URL button
 *   appointment_rating_v3                ar   1 body param, URL button
 *   appointment_reminder                 ar   5 body params, URL button
 *   appointment_reminder_today           ar   5 body params, 2 quick replies
 *   appointment_delayed                  ar   5 body params, URL button
 *   booking_cancellation                 ar   4 body params, no button
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

            /*
            | The hourly reminder — develop's own send, unchanged. The same
            | five values, and the tracking button.
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address. The arrival lead is fixed text in the approved
            | template ("30 دقيقة"), not a parameter — so a clinic that
            | changes its own lead is not reflected here.
            */
            WhatsAppMessagingService::REMINDER_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                    $this->address($clinic),
                ],
                buttonSuffix: $this->suffix('tracking', $booking),
            ),

            /*
            | The visit-day reminder deliberately carries no link. Its only
            | buttons are Confirm and Cancel, and the booking link follows
            | after Confirm. The same five values as the hourly one; the
            | arrival lead is fixed text in its template too.
            */
            WhatsAppMessagingService::DAILY_REMINDER_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                    $this->address($clinic),
                ],
            ),

            WhatsAppMessagingService::VISIT_COMPLETED_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                ],
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
            | Sent after the times have moved, so the booking already carries
            | its new time. The approved template has no delay amount — the
            | new time says it.
            */
            WhatsAppMessagingService::DELAYED_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                    $this->clinicPhone($clinic),
                ],
                buttonSuffix: $this->suffix('tracking', $booking),
            ),

            /*
            | Drafted for the app but never submitted to Meta, so it is seeded
            | inactive and cannot be sent. The mapping matches our own draft
            | wording — {{1}} patient, {{2}} clinic — and must be checked
            | against the approved template if it is ever submitted, since
            | Meta fixes the parameter list at approval, not us.
            */
            'appointment_earlier' => new TemplatePayload(
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

    /**
     * Shown to the patient as the number to call back on, so it is normalised
     * against the clinic's own country rather than printed as typed.
     */
    private function clinicPhone(Clinic $clinic): string
    {
        $phone = $clinic->phone === null
            ? null
            : PhoneNumber::tryParse($clinic->phone, $clinic->country_code)?->e164;

        return $this->clean($phone ?? $clinic->phone, __('messages.fallback.phone'));
    }

    /**
     * "15 سبتمبر 2026" — the same long form the approved sample used.
     *
     * The day the booking is under, even after midnight: the patient is told
     * Monday, and the time says "بعد منتصف الليل" (see time()).
     */
    private function date(Booking $booking, Clinic $clinic): string
    {
        return $this->clean($booking->visit_date->copy()->locale('ar')->isoFormat('D MMMM YYYY'), '—');
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

        $meridiem = match (true) {
            $booking->startsAfterMidnight() => __('schedule.after_midnight'),
            $booking->start_at->format('A') === 'AM' => __('schedule.am'),
            default => __('schedule.pm'),
        };

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
