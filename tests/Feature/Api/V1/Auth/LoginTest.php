<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_clinic_user_can_sign_in_with_email_and_receives_a_token(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->owner()->inClinic($clinic)->create([
            'email' => 'doctor@doctor1.test',
        ]);

        $response = $this->postJson(route('api.v1.auth.login'), [
            'email' => 'doctor@doctor1.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.title', $clinic->name)
            ->assertJsonMissingPath('data.user')
            ->assertJsonMissingPath('data.abilities')
            ->assertJsonMissingPath('data.clinic')
            ->assertJsonMissingPath('data.token_type')
            ->assertJsonStructure([
                'status',
                'message',
                'data' => ['title', 'token'],
            ]);

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_email_is_matched_case_insensitively(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->owner()->inClinic($clinic)->create(['email' => 'doctor@doctor1.test']);

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'DOCTOR@doctor1.test',
            'password' => 'password',
        ])->assertOk();
    }

    public function test_wrong_password_returns_the_standard_error_envelope(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->owner()->inClinic($clinic)->create(['email' => 'doctor@doctor1.test']);

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'doctor@doctor1.test',
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
        User::factory()->owner()->inClinic($clinic)->create(['email' => 'doctor@doctor1.test']);

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'doctor@doctor1.test',
            'password' => 'x',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_an_unknown_email_reports_the_same_code_as_a_wrong_password(): void
    {
        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'unknown@example.test',
            'password' => 'password',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $clinic = Clinic::factory()->create();
        User::factory()->secretary()->inactive()->inClinic($clinic)->create([
            'email' => 'doctor@doctor1.test',
        ]);

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'doctor@doctor1.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }

    public function test_a_super_admin_cannot_sign_in_to_the_mobile_app(): void
    {
        User::factory()->superAdmin()->create(['email' => 'admin@example.test']);

        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'admin@example.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN_ROLE');
    }

    public function test_validation_failures_use_the_fields_envelope(): void
    {
        $this->postJson(route('api.v1.auth.login'), [
            'email' => 'not-an-email',
        ])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['code', 'details', 'fields' => ['email', 'password']],
            ]);
    }
}
