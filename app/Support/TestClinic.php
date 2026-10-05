<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The one clinic that exists for testing, identical on every environment.
 *
 * Same name, address and logins on local, staging and production, so a tester
 * never has to remember which account lives where. Defined once, here: the
 * `clinic:test-clinic` command applies it anywhere, and DemoClinicSeeder
 * builds its demo data on top of it (local and staging only).
 *
 * Not a real doctor. On production it carries `is_test`, which labels its
 * public page and keeps it out of search — and changes nothing else.
 */
final class TestClinic
{
    public const NAME = 'عيادة د. سارة أحمد';

    public const SLUG = 'dr-sara-ahmed';

    public const DOCTOR_NAME = 'د. سارة أحمد';

    public const DOCTOR_EMAIL = 'sara@elayadah.com';

    public const DOCTOR_PASSWORD = 'sara1234';

    public const ASSISTANT_NAME = 'نور محمد';

    public const ASSISTANT_EMAIL = 'nour@elayadah.com';

    public const ASSISTANT_PASSWORD = 'nour1234';

    /**
     * Logins the test clinic has gone by before, so an environment that still
     * has an older demo clinic is found and brought up to date.
     */
    private const FORMER_DOCTOR_EMAILS = ['doctor@doctor1.test'];

    private const FORMER_ASSISTANT_EMAILS = ['nour@doctor1.test'];

    /**
     * The clinic that plays the test clinic's part, or null when there is
     * none — or when more than one could be it, which is for a person to sort
     * out, not for this to guess.
     */
    public static function find(): ?Clinic
    {
        $marked = Clinic::where('is_test', true)->get();

        if ($marked->count() === 1) {
            return $marked->first();
        }

        if ($marked->count() > 1) {
            return null;
        }

        $owners = User::whereIn('email', [self::DOCTOR_EMAIL, ...self::FORMER_DOCTOR_EMAILS])
            ->get()
            ->map(fn (User $user) => $user->activeClinic()?->id)
            ->filter()
            ->unique();

        return $owners->count() === 1 ? Clinic::find($owners->first()) : null;
    }

    /**
     * Gives `$clinic` the test clinic's identity and logins. Renames and sets
     * flags only: no booking, patient or setting is created or removed.
     */
    public static function apply(Clinic $clinic): void
    {
        DB::transaction(function () use ($clinic): void {
            $clinic->update([
                'name' => self::NAME,
                'slug' => self::SLUG,
                'is_test' => true,
                'reports_enabled' => true,
            ]);

            $doctor = $clinic->doctor;
            $doctor?->update(['name' => self::DOCTOR_NAME]);

            $owner = self::accountIn($clinic, [self::DOCTOR_EMAIL, ...self::FORMER_DOCTOR_EMAILS])
                ?? new User(['role' => UserRole::CLINIC, 'locale' => 'ar']);

            $owner->fill([
                'name' => self::DOCTOR_NAME,
                'email' => self::DOCTOR_EMAIL,
                'password' => self::DOCTOR_PASSWORD,
                'role' => UserRole::CLINIC,
                'doctor_id' => $doctor?->id,
                'is_active' => true,
                'can_access_reports' => true,
            ])->save();

            $assistant = self::accountIn($clinic, [self::ASSISTANT_EMAIL, ...self::FORMER_ASSISTANT_EMAILS])
                ?? new User(['role' => UserRole::CLINIC, 'locale' => 'ar']);

            $assistant->fill([
                'name' => self::ASSISTANT_NAME,
                'email' => self::ASSISTANT_EMAIL,
                'password' => self::ASSISTANT_PASSWORD,
                'role' => UserRole::CLINIC,
                'doctor_id' => null,
                'is_active' => true,
                'can_access_reports' => false,
            ])->save();

            $owner->clinics()->syncWithoutDetaching([$clinic->id]);
            $assistant->clinics()->syncWithoutDetaching([$clinic->id]);
        });
    }

    /**
     * @param  list<string>  $emails
     */
    private static function accountIn(Clinic $clinic, array $emails): ?User
    {
        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();

            if ($user !== null && $user->activeClinic()?->id === $clinic->id) {
                return $user;
            }
        }

        return null;
    }
}
