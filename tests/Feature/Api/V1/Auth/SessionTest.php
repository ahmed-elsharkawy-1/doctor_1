<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\Clinic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_returns_the_signed_in_user_with_abilities_and_clinic(): void
    {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->secretary()->inClinic($clinic)->create();

        Sanctum::actingAs($user);

        $this->getJson(route('api.v1.auth.me'))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.clinic.id', $clinic->id)
            ->assertJsonPath('data.abilities', $user->role->abilities())
            // No token is minted on /me, only on login.
            ->assertJsonMissingPath('data.token');
    }

    public function test_an_owner_receives_the_price_and_report_abilities_a_secretary_does_not(): void
    {
        $clinic = Clinic::factory()->create();
        $owner = User::factory()->owner()->inClinic($clinic)->create();

        Sanctum::actingAs($owner);

        $abilities = $this->getJson(route('api.v1.auth.me'))->json('data.abilities');

        $this->assertContains('prices.view', $abilities);
        $this->assertContains('reports.view', $abilities);

        $secretary = User::factory()->secretary()->inClinic($clinic)->create();
        Sanctum::actingAs($secretary);

        $secretaryAbilities = $this->getJson(route('api.v1.auth.me'))->json('data.abilities');

        $this->assertNotContains('prices.view', $secretaryAbilities);
        $this->assertNotContains('reports.view', $secretaryAbilities);
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson(route('api.v1.auth.me'))
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'ACCESS_TOKEN_MISSING');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->secretary()->inClinic($clinic)->create();

        $phone = $user->createToken('phone')->plainTextToken;
        $user->createToken('tablet');

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson(route('api.v1.auth.logout'))
            ->assertOk()
            ->assertJsonPath('status', 'success');

        $this->assertSame(1, $user->tokens()->count());
        $this->assertSame('tablet', $user->tokens()->first()->name);
    }

    public function test_a_revoked_token_no_longer_works(): void
    {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->secretary()->inClinic($clinic)->create();
        $token = $user->createToken('phone')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(route('api.v1.auth.logout'))
            ->assertOk();

        // The guard memoises the resolved user for the life of the container;
        // a real second request would get a fresh one.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.v1.auth.me'))
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'ACCESS_TOKEN_MISSING');
    }

    public function test_a_token_that_never_existed_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer 999|totally-made-up-token')
            ->getJson(route('api.v1.auth.me'))
            ->assertStatus(401)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'ACCESS_TOKEN_MISSING');
    }

    public function test_the_locale_header_switches_the_message_language(): void
    {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->secretary()->inClinic($clinic)->create();

        Sanctum::actingAs($user);

        $arabic = $this->withHeader('Accept-Language', 'ar')
            ->getJson(route('api.v1.auth.me'))
            ->json('data.user.role.display');

        $english = $this->withHeader('Accept-Language', 'en')
            ->getJson(route('api.v1.auth.me'))
            ->json('data.user.role.display');

        $this->assertSame('سكرتيرة', $arabic);
        $this->assertSame('Secretary', $english);
    }
}
