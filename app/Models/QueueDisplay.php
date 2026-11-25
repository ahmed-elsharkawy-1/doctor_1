<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A waiting-room TV, showing one branch's queue for today (ELA-19).
 */
class QueueDisplay extends Model
{
    public const TOKEN_LENGTH = 40;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'name',
        'token',
        'last_seen_at',
    ];

    /** The token is the TV's whole access: never serialised by accident. */
    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $display): void {
            $display->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public function url(): string
    {
        return route('tv.show', $this->token);
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
