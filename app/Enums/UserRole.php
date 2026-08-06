<?php

namespace App\Enums;

/**
 * Staff roles — see SPEC §2.
 *
 * Deliberately a small fixed set rather than a dynamic permission system.
 * Adding a role is an enum case plus the ability map below.
 */
enum UserRole: string
{
    /** Platform operator. Manages clinics, doctors and staff. No clinical screens. */
    case SUPER_ADMIN = 'super_admin';

    /** The doctor. Everything inside their own clinic, including money. */
    case OWNER = 'owner';

    /** Front desk. Bookings, queue, patients. No revenue, no retention, no prices. */
    case SECRETARY = 'secretary';

    public function label(): string
    {
        return __('user.role.'.$this->value);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => [
                'clinics.manage',
                'staff.manage',
            ],
            self::OWNER => [
                'bookings.manage',
                'queue.manage',
                'patients.view',
                'settings.manage',
                'prices.view',
                'reports.view',
                'staff.manage',
            ],
            self::SECRETARY => [
                'bookings.manage',
                'queue.manage',
                'patients.view',
                'settings.manage',
            ],
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** Roles that sign in to the mobile app. */
    public function usesMobileApp(): bool
    {
        return $this !== self::SUPER_ADMIN;
    }

    /** Roles that sign in to the Filament panel. */
    public function usesPanel(): bool
    {
        return in_array($this, [self::SUPER_ADMIN, self::OWNER], true);
    }

    /** Whether the account is scoped to one or more clinics. */
    public function requiresClinic(): bool
    {
        return $this !== self::SUPER_ADMIN;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $case) => $carry + [$case->value => $case->label()],
            [],
        );
    }
}
