<?php

namespace App\Mail;

use App\Models\OtpAlarm;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells the platform's admins that verification codes stopped reaching
 * patients, or that they are reaching them again.
 *
 * Arabic, like the admin panel it points to.
 */
class OtpAlarmMail extends Mailable
{
    public function __construct(
        public readonly OtpAlarm $alarm,
        public readonly bool $recovered,
    ) {
        $this->locale('ar');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __($this->recovered ? 'otp_alarm.mail.recovered_subject' : 'otp_alarm.mail.subject', [], 'ar'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.otp-alarm',
            with: [
                'alarm' => $this->alarm,
                'recovered' => $this->recovered,
                'settingsUrl' => url('/admin/system-settings'),
            ],
        );
    }
}
