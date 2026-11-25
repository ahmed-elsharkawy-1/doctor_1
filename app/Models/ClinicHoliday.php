<?php

namespace App\Models;

use Database\Factories\ClinicHolidayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClinicHoliday extends Model
{
    /** @use HasFactory<ClinicHolidayFactory> */
    use HasFactory;

    protected $fillable = [
        'clinic_id',
        'branch_id',
        'group_id',
        'date',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /** @return BelongsTo<Clinic, $this> */
    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    /**
     * The one branch this closes, or null when it closes all of them,
     * including any added later (ELA-20).
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isForAllBranches(): bool
    {
        return $this->branch_id === null;
    }

    /**
     * Holidays that close this branch: its own, and the clinic's for all.
     *
     * @param  Builder<self>  $query
     */
    public function scopeClosing(Builder $query, Branch $branch): void
    {
        $query->where('clinic_id', $branch->clinic_id)
            ->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branch->id));
    }
}
