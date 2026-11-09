<?php

namespace App\Services\V1\Messaging;

use App\Models\Booking;
use App\Models\Clinic;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Models\WaitingListEntry;
use App\Support\PhoneNumber;
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
 * The shapes below follow the Nov 2, 2026 template spec, as submitted to Meta
 * on 2026-11-05 (template editor; read each back from the Graph API once it
 * is approved):
 *
 *   appointment_booking_confirmation_v4  ar   5 body params, URL button
 *   visit_guidelines                     ar   2 body params, quick reply
 *   appointment_reminder_today_v2        ar   4 body params, 2 quick replies
 *   appointment_reminder_v2              ar   1 body param,  URL button
 *   appointment_delayed_v2               ar   4 body params, URL button
 *   booking_cancellation_v2              ar   4 body params, URL button
 *   appointment_rating_v4                ar   1 body param,  2 URL buttons
 *
 * Unchanged by that spec:
 *
 *   waiting_list_slot_booked             ar   5 body params, URL button
 *
 * The waiting-list messages are about an entry, not a booking, and the
 * announcement about a patient — see forWaitingList() and forAnnouncement():
 *
 *   waiting_list_joined                  ar   2 body params, no button
 *   waiting_list_no_slot_opened_v3       ar   2 body params, URL button
 *   clinic_announcement_v2               ar   4 body params, no button
 */
class TemplatePayloadResolver
{
    public function for(MessageTemplate $template, Booking $booking, Clinic $clinic): TemplatePayload
    {
        return match ($template->key) {
            /*
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} doctor
            | {{5}} address. The arrival lead moved to the visit guidelines,
            | which follow this message.
            */
            WhatsAppMessagingService::CONFIRMATION_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                    $this->address($clinic),
                ],
                buttons: [$this->suffix('tracking', $booking)],
            ),

            /*
            | Sent right after the confirmation. {{1}} the arrival lead as a
            | bare number — the template prints "دقيقة" itself — and {{2}} the
            | tracking link written out in the body, not on a button. Its one
            | button is the quick-reply Cancel, which takes no parameter.
            */
            WhatsAppMessagingService::VISIT_GUIDELINES_KEY => new TemplatePayload(
                body: [
                    (string) max(0, (int) $clinic->patient_arrival_lead_minutes),
                    $booking->trackingUrl(),
                ],
            ),

            /*
            | The turn-approaching alert: the patient's name and nothing else.
            | The live count, date and address are on the tracking page. When
            | it goes out is separate: by the live count of patients ahead,
            | see SendBookingRemindersCommand.
            */
            WhatsAppMessagingService::REMINDER_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                ],
                buttons: [$this->suffix('tracking', $booking)],
            ),

            /*
            | The confirm-or-cancel reminder deliberately carries no link. Its
            | only buttons are Confirm and Cancel, and the booking link follows
            | after Confirm. {{1}} patient · {{2}} date · {{3}} time
            | {{4}} doctor — no address.
            */
            WhatsAppMessagingService::DAILY_REMINDER_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                ],
            ),

            /*
            | {{1}} patient. Two URL buttons, in this order: rate the visit,
            | then book a new appointment on the clinic's page.
            */
            WhatsAppMessagingService::VISIT_COMPLETED_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                ],
                buttons: [
                    $this->suffix('review', $booking),
                    $this->bookingPage($clinic),
                ],
            ),

            /*
            | {{1}} patient · {{2}} date · {{3}} time · {{4}} clinic phone, and
            | a button to rebook on the clinic's page — the rebooking is the
            | point of the message.
            */
            WhatsAppMessagingService::CANCELS_THE_DAY_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->clinicPhone($clinic),
                ],
                buttons: [$this->bookingPage($clinic)],
            ),

            /*
            | The secretary booked a waiting-list patient after calling them.
            | Same five values and button as the confirmation; only the
            | headline differs.
            */
            WhatsAppMessagingService::WAITING_LIST_SLOT_BOOKED_KEY => new TemplatePayload(
                body: [
                    $this->patientName($booking),
                    $this->date($booking, $clinic),
                    $this->time($booking, $clinic),
                    $this->doctorName($clinic),
                    $this->address($clinic),
                ],
                buttons: [$this->suffix('tracking', $booking)],
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

            // The delay needs the old time, which the booking no longer holds
            // — see forDelay(). A template nobody has mapped yet: sending an
            // empty body to Meta would be rejected anyway; failing here says
            // why.
            default => throw new \RuntimeException(
                "No WhatsApp parameter mapping for template [{$template->key}].",
            ),
        };
    }

    /**
     * The emergency delay, sent after the times have moved: the booking
     * already carries its new time, and `$minutes` is how far it moved.
     * {{1}} patient · {{2}} new time · {{3}} old time · {{4}} clinic phone.
     */
    public function forDelay(Booking $booking, Clinic $clinic, int $minutes): TemplatePayload
    {
        return new TemplatePayload(
            body: [
                $this->patientName($booking),
                $this->time($booking, $clinic),
                $this->clockTime(
                    $booking->start_at?->copy()->subMinutes(max(0, $minutes)),
                    $booking,
                ),
                $this->clinicPhone($clinic),
            ],
            buttons: [$this->suffix('tracking', $booking)],
        );
    }

    /**
     * The two waiting-list messages: {{1}} patient · {{2}} the day asked for.
     * "No slot opened" carries the clinic's booking page on its button, so a
     * dead end becomes a rebooking.
     */
    public function forWaitingList(MessageTemplate $template, WaitingListEntry $entry, Clinic $clinic): TemplatePayload
    {
        $body = [
            $this->clean($entry->patient?->name, __('messages.fallback.patient')),
            $this->clean($entry->visit_date->copy()->locale('ar')->isoFormat('dddd D MMMM'), '—'),
        ];

        return match ($template->key) {
            WhatsAppMessagingService::WAITING_LIST_JOINED_KEY => new TemplatePayload(body: $body),

            WhatsAppMessagingService::WAITING_LIST_NO_SLOT_KEY => new TemplatePayload(
                body: $body,
                buttons: [$this->bookingPage($clinic)],
            ),

            default => throw new \RuntimeException(
                "No WhatsApp parameter mapping for waiting-list template [{$template->key}].",
            ),
        };
    }

    /**
     * {{1}} doctor · {{2}} patient · {{3}} the announcement · {{4}} clinic
     * phone. The doctor's name is the same one the "الطبيب" line of the
     * other templates prints. The text is one paragraph: Meta refuses a
     * parameter with a newline, so clean() folds any into spaces.
     */
    public function forAnnouncement(Clinic $clinic, Patient $patient, string $text): TemplatePayload
    {
        return new TemplatePayload(body: [
            $this->doctorName($clinic),
            $this->clean($patient->name, __('messages.fallback.patient')),
            $this->clean($text, '—'),
            $this->clinicPhone($clinic),
        ]);
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
        return $this->clockTime($booking->start_at, $booking);
    }

    /**
     * Any time on the booking's evening, read the way time() reads its own
     * start: a time past midnight says so, since the date printed with it is
     * the evening's, not the next day's.
     */
    private function clockTime(?Carbon $at, Booking $booking): string
    {
        if ($at === null) {
            return __('messages.fallback.no_time');
        }

        $meridiem = match (true) {
            $at->toDateString() !== $booking->visit_date->toDateString() => __('schedule.after_midnight'),
            $at->format('A') === 'AM' => __('schedule.am'),
            default => __('schedule.pm'),
        };

        return $this->clean($at->format('g:i').' '.$meridiem, '—');
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
     * The clinic's own booking page, for a "book a new appointment" button:
     * `<slug>/book`.
     */
    private function bookingPage(Clinic $clinic): string
    {
        return $clinic->slug.'/'.trim((string) config('clinic.self_booking.path'), '/');
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
