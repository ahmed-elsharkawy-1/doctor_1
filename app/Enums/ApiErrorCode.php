<?php

namespace App\Enums;

/**
 * Business-rule failures for the response envelope's `error.code` — SPEC §6.2.
 *
 * Mirrors {@see AuthErrorCode}'s UPPER_SNAKE convention. Codes are added per
 * failure as phases land; the Flutter app branches on these, never on
 * `message`. Never rename a case, only add.
 */
enum ApiErrorCode: string
{
    // Generic
    case RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';

    // Slots & availability (Phase 2)
    case SLOT_UNAVAILABLE = 'SLOT_UNAVAILABLE';
    case SLOT_OUTSIDE_WINDOW = 'SLOT_OUTSIDE_WINDOW';
    case SLOT_OUTSIDE_WORKING_HOURS = 'SLOT_OUTSIDE_WORKING_HOURS';
    case CLINIC_CLOSED_THAT_DAY = 'CLINIC_CLOSED_THAT_DAY';

    // Visit types & settings (Phase 1)
    case VISIT_TYPE_NOT_FOUND = 'VISIT_TYPE_NOT_FOUND';
    case VISIT_TYPE_INACTIVE = 'VISIT_TYPE_INACTIVE';
    case VISIT_TYPE_LAST_ACTIVE = 'VISIT_TYPE_LAST_ACTIVE';
    case VISIT_TYPE_DUPLICATE_NAME = 'VISIT_TYPE_DUPLICATE_NAME';
    case SCHEDULE_PERIOD_INVALID = 'SCHEDULE_PERIOD_INVALID';
    case SCHEDULE_PERIOD_OVERLAP = 'SCHEDULE_PERIOD_OVERLAP';
    case SCHEDULE_DAY_NOT_FOUND = 'SCHEDULE_DAY_NOT_FOUND';
    case HOLIDAY_ALREADY_EXISTS = 'HOLIDAY_ALREADY_EXISTS';
    case HOLIDAY_NOT_FOUND = 'HOLIDAY_NOT_FOUND';
    case HOLIDAY_HAS_BOOKINGS = 'HOLIDAY_HAS_BOOKINGS';

    // Bookings & queue (Phases 2-3)
    case BOOKING_NOT_FOUND = 'BOOKING_NOT_FOUND';
    case BOOKING_NOT_EDITABLE = 'BOOKING_NOT_EDITABLE';
    case INVALID_STATUS_TRANSITION = 'INVALID_STATUS_TRANSITION';
    case BOOKING_NOT_CANCELLABLE = 'BOOKING_NOT_CANCELLABLE';
    case NOTHING_TO_POSTPONE = 'NOTHING_TO_POSTPONE';

    // Emergency delay. `details.reason` says why a booking cannot start one:
    // `not_today` or `not_waiting`.
    case BOOKING_NOT_DELAYABLE = 'BOOKING_NOT_DELAYABLE';
    case DELAY_PAST_END_OF_DAY = 'DELAY_PAST_END_OF_DAY';

    // Reports (Phase 6)
    case UNKNOWN_REPORT_PERIOD = 'UNKNOWN_REPORT_PERIOD';
    // This account may not open the reports page (no report access, or the
    // clinic has reports switched off).
    case REPORTS_NOT_AVAILABLE = 'REPORTS_NOT_AVAILABLE';

    // Patients (Phases 2-4)
    case PATIENT_NOT_FOUND = 'PATIENT_NOT_FOUND';
    case INVALID_PHONE_NUMBER = 'INVALID_PHONE_NUMBER';
    case DUPLICATE_PHONE = 'DUPLICATE_PHONE';

    // Phone verification, for patient self-booking
    case OTP_RATE_LIMITED = 'OTP_RATE_LIMITED';
    case OTP_INVALID = 'OTP_INVALID';
    case OTP_EXPIRED = 'OTP_EXPIRED';
    case OTP_TOO_MANY_ATTEMPTS = 'OTP_TOO_MANY_ATTEMPTS';
    case OTP_SEND_FAILED = 'OTP_SEND_FAILED';

    // WhatsApp switched off for the clinic by a super admin.
    case WHATSAPP_DISABLED = 'WHATSAPP_DISABLED';

    // Patient self-booking. Reaching either of these from the page itself is
    // not possible — they are what a tampered request gets.
    case PHONE_NOT_VERIFIED = 'PHONE_NOT_VERIFIED';
    case SELF_BOOKING_DISABLED = 'SELF_BOOKING_DISABLED';
    // This patient may not book on the public page (too many no-shows, or
    // blocked by the clinic). The page shows its own screen before this.
    case ONLINE_BOOKING_BLOCKED = 'ONLINE_BOOKING_BLOCKED';

    // A review whose rating needs an explanation, sent without one.
    case REVIEW_COMMENT_REQUIRED = 'REVIEW_COMMENT_REQUIRED';
}
