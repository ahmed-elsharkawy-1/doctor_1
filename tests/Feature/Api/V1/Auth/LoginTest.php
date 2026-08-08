<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_sign_in_with_the_clinic_phone_and_receives_a_token(): void
    {
        $clinic = Clinic::factory()->create(['phone' => '+201001234567']);
        $user = User::factory()->owner()->inClinic($clinic)->create([
            'phone' => '+201001234567',
        ]);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'phone' => '01001234567',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.role.value', 'owner')
            ->assertJsonPath('data.clinic.id', $clinic->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['user', 'abilities', 'clinic', 'token'],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_phone_is_matched_after_normalisation(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->owner()->inClinic($clinic)->create(['phone' => '+201001234567']);

        foreach (['01001234567', '+20 100 123 4567', '00201001234567'] as $phone) {
            $this->postJson(route('api.v1.auth.login'), [
                'phone' => $phone,
                'password' => 'password',
            ])->assertOk();
        }
    }

    public function test_wrong_password_returns_the_standard_error_envelope(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->owner()->inClinic($clinic)->create(['phone' => '+201001234567']);

        $this->postJson(route('api.v1.auth.login'), [
            'phone' => '01001234567',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.fields', null)
            ->assertJsonMissingPath('data');
    }

    /**
     * A short guess is a wrong password, not invalid input — answering with
     * VALIDATION_FAILED would tell the caller it was too short to be anyone's.
     */
    public function test_a_short_wrong_password_is_still_a_credentials_failure(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->owner()->inClinic($clinic)->create(['phone' => '+201001234567']);

        $this->postJson(route('api.v1.auth.login'), [
            'phone' => '01001234567',
            'password' => 'x',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_an_unknown_phone_reports_the_same_code_as_a_wrong_password(): void
    {
        $this->postJson(route('api.v1.auth.login'), [
            'phone' => '01009999999',
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->secretary()->inactive()->inClinic($clinic)->create([
            'phone' => '+201001234567',
        ]);

        $this->postJson(route('api.v1.auth.login'), [
            'phone' => '01001234567',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_a_super_admin_cannot_sign_in_to_the_mobile_app(): void
    {
        User::factory()->superAdmin()->create(['phone' => '+201001234567']);

        $this->postJson(route('api.v1.auth.login'), [
            'phone' => '01001234567',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_ROLE');
    }

    public function test_validation_failures_use_the_fields_envelope(): void
    {
        $this->postJson(route('api.v1.auth.login'), [
            'phone' => '123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['code', 'details', 'fields' => ['phone', 'password']],
            ]);
    }
}
