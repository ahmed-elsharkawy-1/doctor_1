<?php

namespace Tests\Feature\Clinic;

use App\Enums\DayOfWeek;
use App\Enums\StaffPermission;
use App\Enums\UserRole;
use App\Livewire\App\Settings\General;
use App\Livewire\App\Settings\Holidays;
use App\Livewire\App\Settings\Hours;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-17: the doctor holds every permission; a team member holds the daily
 * work plus what the doctor granted.
 */
class StaffPermissionTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
    }

    private function assistant(array $permissions): User
    {
        return User::factory()->secretary($permissions)->inClinic($this->clinic)->create();
    }

    public function test_the_doctor_holds_every_permission(): void
    {
        foreach (StaffPermission::cases() as $permission) {
            $this->assertTrue($this->owner->hasAbility($permission->value));
        }

        $this->assertTrue($this->owner->isOwner());
        $this->assertFalse($this->owner->isSecretary());
    }

    public function test_a_team_member_holds_only_what_was_granted(): void
    {
        $daily = $this->assistant([]);
        $scheduler = $this->assistant([StaffPermission::MANAGE_SCHEDULE->value]);

        $this->assertTrue($daily->isSecretary());
        $this->assertFalse($daily->hasAbility('schedule.manage'));
        $this->assertFalse($daily->hasAbility('branches.manage'));
        // The daily work needs nothing extra.
        $this->assertTrue($daily->hasAbility('bookings.manage'));
        $this->assertTrue($daily->hasAbility('queue.manage'));

        $this->assertTrue($scheduler->hasAbility('schedule.manage'));
        $this->assertFalse($scheduler->hasAbility('branches.manage'));
    }

    public function test_an_inactive_account_holds_nothing(): void
    {
        $this->owner->update(['is_active' => false]);

        $this->assertSame([], $this->owner->fresh()->effectiveAbilities());
    }

    public function test_working_hours_and_holidays_need_manage_schedule(): void
    {
        $daily = $this->assistant([]);

        Livewire::actingAs($daily)->test(Hours::class)->assertForbidden();
        Livewire::actingAs($daily)->test(Holidays::class)->assertForbidden();
        // General settings stay open to every account, as before ELA-17.
        Livewire::actingAs($daily)->test(General::class)->assertOk();

        Livewire::actingAs($this->secretary)->test(Hours::class)->assertOk();
        Livewire::actingAs($this->secretary)->test(Holidays::class)->assertOk();
    }

    public function test_the_settings_tabs_hide_what_the_account_cannot_open(): void
    {
        $this->actingAs($this->assistant([]))
            ->get(route('app.settings'))
            ->assertOk()
            ->assertDontSee(route('app.settings.hours'))
            ->assertDontSee(route('app.settings.holidays'))
            ->assertSee(route('app.settings.visit-types'));

        $this->actingAs($this->secretary)
            ->get(route('app.settings'))
            ->assertSee(route('app.settings.hours'));
    }

    public function test_the_api_lets_everyone_read_the_schedule_but_only_a_scheduler_change_it(): void
    {
        Sanctum::actingAs($this->assistant([]));

        $this->getJson(route('api.v1.schedule.index'))->assertOk();
        $this->getJson(route('api.v1.holidays.index'))->assertOk();
        $this->putJson(route('api.v1.schedule.update', DayOfWeek::SATURDAY->value), [
            'is_open' => false,
            'periods' => [],
        ])->assertForbidden();
        $this->postJson(route('api.v1.holidays.store'), ['date' => now()->addWeek()->toDateString()])
            ->assertForbidden();
    }

    public function test_me_tells_the_app_who_is_the_doctor_and_what_they_can_do(): void
    {
        Sanctum::actingAs($this->assistant([]));

        $this->getJson(route('api.v1.auth.me'))
            ->assertOk()
            ->assertJsonPath('data.user.is_owner', false)
            ->assertJsonMissingPath('data.abilities.6')
            ->assertJsonFragment(['bookings.manage']);

        Sanctum::actingAs($this->owner);

        $abilities = $this->getJson(route('api.v1.auth.me'))
            ->assertJsonPath('data.user.is_owner', true)
            ->json('data.abilities');

        $this->assertContains('schedule.manage', $abilities);
        $this->assertContains('branches.manage', $abilities);
    }

    public function test_the_backfill_keeps_every_existing_account_able_to_do_what_it_did(): void
    {
        // A clinic with the doctor's own login and an assistant's.
        $doctorLogin = User::factory()->inClinic($this->clinic)->create([
            'doctor_id' => Doctor::factory()->create(['clinic_id' => $this->clinic->id])->id,
        ]);
        $assistant = User::factory()->inClinic($this->clinic)->create(['doctor_id' => null]);

        // A clinic whose doctor and assistant share one login.
        $shared = User::factory()->inClinic($sharedClinic = Clinic::factory()->create())->create(['doctor_id' => null]);
        $admin = User::factory()->superAdmin()->create();

        DB::table('users')->update(['is_owner' => false, 'permissions' => null]);

        (require database_path('migrations/2026_11_25_110000_add_owner_and_permissions_to_users_table.php'))->backfill();

        $this->assertTrue($doctorLogin->fresh()->isOwner());
        $this->assertTrue($shared->fresh()->isOwner());

        $assistant = $assistant->fresh();
        $this->assertTrue($assistant->isSecretary());
        $this->assertTrue($assistant->hasAbility('schedule.manage'));
        $this->assertFalse($assistant->hasAbility('branches.manage'));

        $this->assertFalse((bool) $admin->fresh()->is_owner);
        $this->assertSame(UserRole::SUPER_ADMIN, $admin->fresh()->role);
        $this->assertNotNull($sharedClinic->id);
    }
}
