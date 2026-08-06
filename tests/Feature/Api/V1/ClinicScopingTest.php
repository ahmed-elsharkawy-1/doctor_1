<?php

namespace Tests\Feature\Api\V1;

use App\Models\Clinic;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The `clinic` middleware is what makes clinic_id un-spoofable — every Phase 1+
 * endpoint sits behind it, so it is worth pinning down on its own.
 */
class ClinicScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'clinic'])
            ->get('/api/v1/_test/clinic', fn () => ApiResponse::success([
                'clinic_id' => request()->attributes->get('clinic')->id,
            ]));
    }

    public function test_the_callers_clinic_is_put_on_the_request(): void
    {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->secretary()->inClinic($clinic)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/_test/clinic')
            ->assertOk()
            ->assertJsonPath('data.clinic_id', $clinic->id);
    }

    public function test_a_user_with_no_clinic_is_refused(): void
    {
        $user = User::factory()->secretary()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/_test/clinic')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CLINIC_NOT_ASSIGNED');
    }

    public function test_an_inactive_clinic_is_refused(): void
    {
        $clinic = Clinic::factory()->inactive()->create();
        $user = User::factory()->secretary()->inClinic($clinic)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/_test/clinic')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CLINIC_INACTIVE');
    }

    public function test_a_deactivated_user_is_refused_even_with_a_valid_token(): void
    {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->secretary()->inactive()->inClinic($clinic)->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/_test/clinic')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_a_super_admin_cannot_reach_clinic_scoped_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->superAdmin()->create());

        $this->getJson('/api/v1/_test/clinic')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_ROLE');
    }

    public function test_unknown_api_routes_return_the_standard_envelope_not_html(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }
}
