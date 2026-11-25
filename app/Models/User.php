<?php

namespace App\Models;

use App\Enums\StaffPermission;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_owner',
        'permissions',
        'last_branch_id',
        'phone',
        'doctor_id',
        'locale',
        'is_active',
        'can_access_reports',
    ];

    /** Abilities nobody but the doctor holds, whatever was granted. */
    public const OWNER_ONLY_ABILITIES = ['team.manage'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_owner' => 'boolean',
            'permissions' => 'array',
            'is_active' => 'boolean',
            'can_access_reports' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Clinic, $this> */
    public function clinics(): BelongsToMany
    {
        return $this->belongsToMany(Clinic::class)->withTimestamps();
    }

    /**
     * The branches a team member is assigned to (ELA-17). The doctor works at
     * every branch without being listed here; use accessibleBranches().
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class)->withTimestamps();
    }

    /**
     * The branches this account may work at, active ones first. The doctor
     * may use all of them; a team member only the assigned ones. Inactive
     * branches stay in: their existing bookings still need running.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(Clinic $clinic): Collection
    {
        $query = $clinic->branches()->reorder()
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('id');

        if (! $this->isOwner()) {
            $query->whereIn('branches.id', $this->branches()->select('branches.id'));
        }

        return $query->get();
    }

    public function canUseBranch(Branch $branch): bool
    {
        return $this->isOwner()
            ? $this->belongsToClinic($branch->clinic_id)
            : $this->branches()->whereKey($branch->id)->exists();
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /**
     * The clinic this account acts on. v1 assigns exactly one; when a user can
     * hold several, this becomes an explicit "active clinic" selection.
     */
    public function activeClinic(): ?Clinic
    {
        return $this->relationLoaded('clinics')
            ? $this->clinics->first()
            : $this->clinics()->first();
    }

    public function activeClinicId(): ?int
    {
        return $this->activeClinic()?->id;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === UserRole::SUPER_ADMIN;
    }

    /**
     * The doctor: every ability, at every branch (ELA-17).
     */
    public function isOwner(): bool
    {
        return $this->role === UserRole::CLINIC && $this->is_owner;
    }

    /**
     * A team member who is not the doctor. Does the daily work, plus whatever
     * extra the doctor granted (StaffPermission).
     */
    public function isSecretary(): bool
    {
        return $this->role === UserRole::CLINIC && ! $this->is_owner;
    }

    public function hasPermission(StaffPermission $permission): bool
    {
        return $this->isOwner()
            || in_array($permission->value, $this->permissions ?? [], true);
    }

    /**
     * Whether the account may use this ability now.
     *
     * The role says which abilities exist for it at all. On top of that, an
     * ability that is also a StaffPermission is the doctor's unless granted.
     */
    public function hasAbility(string $ability): bool
    {
        if (! $this->is_active || ! $this->role->can($ability)) {
            return false;
        }

        // Managing the team stays with the doctor: it is how permissions are
        // handed out, so it can't be one of them.
        if (in_array($ability, self::OWNER_ONLY_ABILITIES, true)) {
            return $this->isOwner();
        }

        $permission = StaffPermission::tryFrom($ability);

        return $permission === null || $this->hasPermission($permission);
    }

    /**
     * Every ability this account can use right now, for clients that hide
     * what the account can't do.
     *
     * @return list<string>
     */
    public function effectiveAbilities(): array
    {
        return array_values(array_filter(
            $this->role->abilities(),
            fn (string $ability): bool => $this->hasAbility($ability),
        ));
    }

    public function belongsToClinic(int $clinicId): bool
    {
        return $this->clinics()->whereKey($clinicId)->exists();
    }

    /**
     * Filament panel access — super admins and owners only (SPEC §2).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $this->role->usesPanel();
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param Builder<self> $query */
    public function scopeRole(Builder $query, UserRole $role): void
    {
        $query->where('role', $role);
    }
}
