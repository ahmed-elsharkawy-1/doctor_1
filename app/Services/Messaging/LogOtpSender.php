<?php

namespace App\Services\Messaging;

use App\Models\Clinic;
use Illuminate\Support\Facades\Log;

/**
 * Writes the code to the log instead of sending it.
 *
 * This is how the booking flow is exercised before an authentication template
 * exists: read the code out of `storage/logs` and carry on. The same trick the
 * WhatsApp log driver already relies on.
 *
 * It refuses to run in production. A code in a log file is a code anybody with
 * log access can use, and the one thing this whole mechanism is for is proving
 * that a stranger is not the person they claim to be.
 *
 * `CLINIC_OTP_ALLOW_LOG_IN_PRODUCTION` opens that door deliberately, for
 * walking the flow on a live host before an authentication template exists.
 * The codes stay random: whoever runs the test reads theirs out of the log,
 * and a stranger — who cannot read the log — still cannot verify a number
 * they do not own. Unset it the moment the test is over.
 */
class LogOtpSender implements OtpSender
{
    public function send(Clinic $clinic, string $phone, string $code, string $reference): void
    {
        if (app()->isProduction() && ! config('clinic.self_booking.otp.allow_log_in_production')) {
            throw new \RuntimeException(
                'The log OTP driver must never run in production — it would write '
                .'live verification codes to the log. Set CLINIC_OTP_DRIVER.',
            );
        }

        Log::info('OTP log driver code issued.', [
            'clinic_id' => $clinic->id,
            'to' => $phone,
            'code' => $code,
        ]);
    }
}
