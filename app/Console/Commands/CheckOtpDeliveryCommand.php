<?php

namespace App\Console\Commands;

use App\Services\Messaging\OtpAlarmMonitor;
use Illuminate\Console\Command;

/**
 * Raises, or closes, the alarm for verification codes that are not reaching
 * patients. See OtpAlarmMonitor.
 */
class CheckOtpDeliveryCommand extends Command
{
    protected $signature = 'clinic:check-otp-delivery';

    protected $description = 'Alarm when verification codes are sent and nobody types them';

    public function handle(OtpAlarmMonitor $monitor): int
    {
        $alarm = $monitor->check();

        if ($alarm !== null) {
            $this->warn("Codes are not reaching patients since {$alarm->started_at} (alarm #{$alarm->id}).");
        }

        return self::SUCCESS;
    }
}
