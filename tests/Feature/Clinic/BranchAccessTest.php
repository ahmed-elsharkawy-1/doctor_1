<?php

namespace Tests\Feature\Clinic;

use App\Livewire\App\Settings\Branches;
use App\Livewire\App\Settings\Team;
use App\Models\Branch;
use App\Models\User;
use App\Services\V1\Branches\ActiveBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-17: each team member works only at their branches, the doctor at all
 * of them, and the branch is always decided on the server.
 */
class BranchAccessTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $dokki;

    private Branch $gedeila;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->dokki = $this->clinic->branches()->firstOrFail();
        $this->gedeila = Branch::factory()->create(['clinic_id' => $this->clinic->id, 'name' => 'عيادة جديلة']);
    }

    private function gedeilaOnly(array $permissions = []): User
    {
        return User::factory()->secretary($permissions)->inClinic($this->clinic)->atBranches($this->gedeila)->create();
    }

    public function test_the_doctor_works_at_every_branch_and_a_member_only_at_theirs(): void
    {
        $this->assertSame([$this->dokki->id, $this->gedeila->id], $this->owner->accessibleBranches($this->clinic)->pluck('id')->all());
        $this->assertSame([$this->gedeila->id], $this->gedeilaOnly()->accessibleBranches($this->clinic)->pluck('id')->all());
    }

    public function test_the_api_never_acts_on_a_branch_the_account_cannot_use(): void
    {
        Sanctum::actingAs($this->gedeilaOnly());

        $this->withHeader('X-Branch-Id', (string) $this->dokki->id)
            ->getJson(route('api.v1.bootstrap'))
            ->assertOk()
            // Told which branch it actually got.
            ->assertHeader('X-Branch-Id', (string) $this->gedeila->id);
    }

    public function test_the_api_uses_the_branch_asked_for_when_allowed(): void
    {
        Sanctum::actingAs($this->owner);

        $this->withHeader('X-Branch-Id', (string) $this->gedeila->id)
            ->getJson(route('api.v1.bootstrap'))
            ->assertHeader('X-Branch-Id', (string) $this->gedeila->id);

        // And opens there next time, without asking.
        $this->assertSame($this->gedeila->id, $this->owner->fresh()->last_branch_id);
    }

    public function test_the_apps_open_on_the_last_used_branch(): void
    {
        $this->owner->update(['last_branch_id' => $this->gedeila->id]);

        $this->assertSame($this->gedeila->id, app(ActiveBranch::class)->resolve($this->owner->fresh(), $this->clinic)?->id);
    }

    public function test_one_tap_switches_the_web_app_and_resets_the_screen(): void
    {
        $this->actingAs($this->owner)
            ->from(route('app.queue', ['date' => '2026-12-01']))
            ->post(route('app.branch.switch'), ['branch' => $this->gedeila->id])
            ->assertRedirect(route('app.queue'))
            ->assertSessionHas(ActiveBranch::SESSION_KEY, $this->gedeila->id);

        $this->assertSame($this->gedeila->id, $this->owner->fresh()->last_branch_id);
    }

    public function test_switching_to_a_branch_the_account_cannot_use_changes_nothing(): void
    {
        $member = $this->gedeilaOnly();

        $this->actingAs($member)
            ->post(route('app.branch.switch'), ['branch' => $this->dokki->id])
            ->assertSessionHas(ActiveBranch::SESSION_KEY, $this->gedeila->id);
    }

    public function test_the_switcher_shows_only_with_more_than_one_branch(): void
    {
        $this->actingAs($this->owner)->get(route('app.settings'))
            ->assertSee(route('app.branch.switch'), false)
            ->assertSee('عيادة جديلة');

        $this->actingAs($this->gedeilaOnly())->get(route('app.settings'))
            ->assertDontSee(route('app.branch.switch'), false);
    }

    public function test_someone_taken_off_their_branch_moves_to_another(): void
    {
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->dokki, $this->gedeila)->create();
        $this->actingAs($member)->withSession([ActiveBranch::SESSION_KEY => $this->gedeila->id]);

        $member->branches()->detach($this->gedeila->id);

        $this->get(route('app.settings'))
            ->assertOk()
            ->assertSessionHas(ActiveBranch::SESSION_KEY, $this->dokki->id);
    }

    public function test_someone_taken_off_every_branch_is_signed_out_with_a_reason(): void
    {
        $member = $this->gedeilaOnly();
        $member->branches()->detach();

        $this->actingAs($member)->get(route('app.queue'))
            ->assertRedirect(route('app.login'))
            ->assertSessionHasErrors(['email' => __('auth.branch_not_assigned')]);
        $this->assertGuest('web');

        Sanctum::actingAs($member);
        $this->getJson(route('api.v1.bootstrap'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'BRANCH_NOT_ASSIGNED');
    }

    public function test_me_lists_the_branches_for_the_apps_switcher(): void
    {
        Sanctum::actingAs($this->gedeilaOnly());

        $this->getJson(route('api.v1.auth.me'))
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonPath('data.branches.0.name', 'عيادة جديلة')
            ->assertJsonPath('data.branch_id', $this->gedeila->id);
    }

    public function test_branch_management_stays_within_the_members_branches(): void
    {
        config(['clinic.branches.multi_enabled' => true]);
        $manager = $this->gedeilaOnly(['branches.manage']);
        Sanctum::actingAs($manager);

        $this->getJson(route('api.v1.branches.index'))->assertJsonCount(1, 'data.branches');
        $this->putJson(route('api.v1.branches.update', $this->dokki), [
            'name' => 'x', 'address' => 'y', 'latitude' => 30, 'longitude' => 31,
        ])->assertNotFound();

        $this->postJson(route('api.v1.branches.store'), [
            'name' => 'عيادة الترعة', 'address' => 'الترعة', 'latitude' => 31.1, 'longitude' => 31.4,
        ])->assertCreated();

        // A member who adds a branch works at it.
        $this->assertSame(2, $manager->branches()->count());
    }

    public function test_the_doctor_sets_a_members_branches_and_permissions(): void
    {
        $member = $this->gedeilaOnly();
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.team.update', $member), [
            'branch_ids' => [$this->dokki->id, $this->gedeila->id],
            'permissions' => ['schedule.manage'],
        ])
            ->assertOk()
            ->assertJsonPath('data.branch_ids', [$this->dokki->id, $this->gedeila->id])
            ->assertJsonPath('data.permissions', ['schedule.manage']);

        $member = $member->fresh();
        $this->assertTrue($member->hasAbility('schedule.manage'));
        $this->assertCount(2, $member->accessibleBranches($this->clinic));
    }

    public function test_every_member_keeps_at_least_one_branch_of_this_clinic(): void
    {
        $member = $this->gedeilaOnly();
        $foreign = $this->otherClinic()->branches()->firstOrFail();
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.team.update', $member), ['branch_ids' => [], 'permissions' => []])
            ->assertUnprocessable();
        $this->putJson(route('api.v1.team.update', $member), ['branch_ids' => [$foreign->id], 'permissions' => []])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'TEAM_BRANCH_REQUIRED');

        $this->assertSame([$this->gedeila->id], $member->branches()->pluck('branches.id')->all());
    }

    public function test_the_doctors_own_row_is_not_editable(): void
    {
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.team.update', $this->owner), ['branch_ids' => [$this->dokki->id], 'permissions' => []])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'TEAM_MEMBER_IS_OWNER');
    }

    public function test_managing_the_team_is_the_doctors_alone(): void
    {
        Sanctum::actingAs($this->gedeilaOnly(['branches.manage', 'schedule.manage']));

        $this->getJson(route('api.v1.team.index'))->assertForbidden();
        Livewire::actingAs($this->secretary)->test(Team::class)->assertForbidden();
    }

    public function test_the_team_screen_saves_a_members_branches(): void
    {
        $member = $this->gedeilaOnly();

        Livewire::actingAs($this->owner)
            ->test(Team::class)
            ->assertSee($member->name)
            ->set("members.{$member->id}.branches", [(string) $this->dokki->id])
            ->set("members.{$member->id}.permissions", ['branches.manage'])
            ->call('save', $member->id)
            ->assertSet('failed', false);

        $member = $member->fresh();
        $this->assertSame([$this->dokki->id], $member->branches()->pluck('branches.id')->all());
        $this->assertTrue($member->hasAbility('branches.manage'));
    }

    public function test_the_backfill_assigns_every_existing_member_to_their_clinics_branches(): void
    {
        DB::table('branch_user')->delete();

        (require database_path('migrations/2026_11_26_100000_create_branch_user_table.php'))->backfill();

        $this->assertEqualsCanonicalizing(
            [$this->dokki->id, $this->gedeila->id],
            $this->secretary->branches()->pluck('branches.id')->all(),
        );
        // The doctor needs no rows: every branch is theirs.
        $this->assertSame(0, $this->owner->branches()->count());
    }

    public function test_saving_a_member_here_leaves_their_branches_in_another_clinic(): void
    {
        $other = $this->otherClinic();
        $member = User::factory()->secretary()->inClinic($this->clinic)->atBranches($this->gedeila)->create();
        $member->clinics()->attach($other->id);
        $member->branches()->attach($other->branches()->first()->id);
        Sanctum::actingAs($this->owner);

        $this->putJson(route('api.v1.team.update', $member), ['branch_ids' => [$this->dokki->id], 'permissions' => []])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$this->dokki->id, $other->branches()->first()->id],
            $member->branches()->pluck('branches.id')->all(),
        );
    }

    public function test_only_a_choice_changes_the_branch_the_apps_open_on(): void
    {
        $this->owner->update(['last_branch_id' => $this->gedeila->id]);

        // A browser pinned to another branch, and an app call that asks for
        // nothing, don't move it: two devices on one login stay apart.
        $this->actingAs($this->owner)->withSession([ActiveBranch::SESSION_KEY => $this->dokki->id])->get(route('app.settings'))->assertOk();
        Sanctum::actingAs($this->owner);
        $this->getJson(route('api.v1.bootstrap'))->assertHeader('X-Branch-Id', (string) $this->gedeila->id);
        $this->assertSame($this->gedeila->id, $this->owner->fresh()->last_branch_id);

        // Asking for one is a choice.
        $this->withHeader('X-Branch-Id', (string) $this->dokki->id)->getJson(route('api.v1.bootstrap'));
        $this->assertSame($this->dokki->id, $this->owner->fresh()->last_branch_id);
    }

    public function test_a_screen_left_open_after_losing_a_branch_cannot_act_on_it(): void
    {
        $manager = User::factory()->secretary(['branches.manage'])->inClinic($this->clinic)->atBranches($this->dokki, $this->gedeila)->create();
        $this->gedeila->update(['is_active' => false]);

        $screen = Livewire::actingAs($manager)->test(Branches::class);
        $manager->branches()->detach($this->gedeila->id);

        $screen->call('activate', $this->gedeila->id)->assertSet('failed', true);
        $this->assertFalse($this->gedeila->fresh()->is_active);
    }

    public function test_an_assignment_to_another_clinics_branch_gives_nothing(): void
    {
        $foreign = $this->otherClinic()->branches()->first();
        $member = $this->gedeilaOnly();
        $member->branches()->attach($foreign->id);

        $this->assertFalse($member->canUseBranch($foreign));

        $member->pruneBranches();
        $this->assertSame([$this->gedeila->id], $member->branches()->pluck('branches.id')->all());
    }

    public function test_the_switcher_only_returns_to_app_pages(): void
    {
        $this->actingAs($this->owner)
            ->from('http://localhost/apple-pie')
            ->post(route('app.branch.switch'), ['branch' => $this->gedeila->id])
            ->assertRedirect(route('app.queue'));
    }

    public function test_someone_with_one_branch_of_several_is_told_which(): void
    {
        $this->actingAs($this->gedeilaOnly())->get(route('app.settings'))
            ->assertSee('class="branch-here"', false)
            ->assertSee('عيادة جديلة');

        // A clinic with one branch shows nothing about branches.
        $this->gedeila->delete();
        $this->actingAs($this->secretary)->get(route('app.settings'))
            ->assertDontSee('class="branch-here"', false);
    }
}
