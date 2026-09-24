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
 */
class LogOtpSender implements OtpSender
{
    public function send(Clinic $clinic, string $phone, string $code): void
    {
        if (app()->isProduction()) {
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
