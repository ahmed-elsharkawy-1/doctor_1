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
    /**
     * Platform operator. The only role that reaches the admin panel: creates
     * clinics, doctors and staff accounts, and nothing clinical.
     */
    case SUPER_ADMIN = 'super_admin';

    /**
     * The doctor. Everything inside their own clinic, including money and
     * reports — all of it through the mobile app, never the panel.
     */
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

    /**
     * Roles that sign in to the Filament panel.
     *
     * Only the platform operator. A doctor runs their clinic entirely from the
     * app — including revenue and retention — and never sees the panel.
     */
    public function usesPanel(): bool
    {
        return $this === self::SUPER_ADMIN;
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
