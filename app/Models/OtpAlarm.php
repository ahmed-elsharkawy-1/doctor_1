<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A stretch of time when verification codes were sent and nobody typed
 * them. See OtpAlarmMonitor.
 */
class OtpAlarm extends Model
{
    protected $fillable = [
        'started_at',
        'ended_at',
        'codes',
        'phones',
        'refused',
        'details',
        'emailed_at',
        'whatsapped_at',
        'recovery_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'codes' => 'integer',
            'phones' => 'integer',
            'refused' => 'integer',
            'details' => 'array',
            'emailed_at' => 'datetime',
            'whatsapped_at' => 'datetime',
            'recovery_sent_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('ended_at');
    }

    /** The alarm still going, if there is one. */
    public static function current(): ?self
    {
        return static::query()->open()->latest('id')->first();
    }
}
