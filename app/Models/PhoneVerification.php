<?php

namespace App\Models;

use Database\Factories\PhoneVerificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time code sent to a phone, and what became of it.
 */
class PhoneVerification extends Model
{
    /** @use HasFactory<PhoneVerificationFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'phone',
        'code_hash',
        'expires_at',
        'sent_at',
        'attempts',
        'verified_at',
        'ip',
    ];

    /**
     * Never serialised. It is a hash, but there is nothing a client could
     * usefully do with it either.
     */
    protected $hidden = [
        'code_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * Still worth trying: not expired, not already used, guesses left.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query, ?Carbon $now = null): void
    {
        $query->whereNull('verified_at')
            ->where('expires_at', '>', $now ?? Carbon::now())
            ->where('attempts', '<', (int) config('clinic.self_booking.otp.max_attempts'));
    }

    /** @param Builder<self> $query */
    public function scopeForPhone(Builder $query, int $clinicId, string $phone): void
    {
        $query->where('clinic_id', $clinicId)->where('phone', $phone);
    }

    public function hasExpired(?Carbon $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= (int) config('clinic.self_booking.otp.max_attempts');
    }
}
