<?php

namespace Tests\Feature\QrPoster;

use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Models\ClinicSlugRedirect;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A printed poster never stops working: a clinic's old address keeps leading
 * to it after the slug changes.
 */
class ClinicSlugRedirectTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-old', 'self_booking_enabled' => true]);
    }

    public function test_changing_the_slug_keeps_the_old_one(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);

        $this->assertDatabaseHas('clinic_slug_redirects', ['slug' => 'dr-old', 'clinic_id' => $this->clinic->id]);
    }

    public function test_saving_without_changing_the_slug_keeps_nothing(): void
    {
        $this->clinic->update(['address' => 'شارع جديد']);

        $this->assertDatabaseCount('clinic_slug_redirects', 0);
    }

    public function test_the_old_address_redirects_with_its_query_string(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);

        $this->get('/dr-old?src=qr')
            ->assertStatus(302)
            ->assertRedirect(url('/dr-new').'?src=qr');
    }

    public function test_the_old_booking_address_redirects_too(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);

        $this->get('/dr-old/book')
            ->assertStatus(302)
            ->assertRedirect(url('/dr-new/book'));
    }

    public function test_every_earlier_address_leads_to_the_current_one(): void
    {
        $this->clinic->update(['slug' => 'dr-middle']);
        $this->clinic->update(['slug' => 'dr-new']);

        $this->get('/dr-old')->assertRedirect(url('/dr-new'));
        $this->get('/dr-middle')->assertRedirect(url('/dr-new'));
    }

    public function test_taking_back_an_old_slug_makes_it_live_again(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);
        $this->clinic->update(['slug' => 'dr-old']);

        $this->assertDatabaseMissing('clinic_slug_redirects', ['slug' => 'dr-old']);
        $this->get('/dr-old')->assertOk();
        $this->get('/dr-new')->assertRedirect(url('/dr-old'));
    }

    public function test_a_live_slug_always_wins_over_a_redirect(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);

        // Written past the panel's check, as a seeder or tinker could.
        $other = $this->otherClinic();
        $other->update(['slug' => 'dr-old']);
        $this->clinic->doctor->replicate()->fill(['clinic_id' => $other->id])->save();

        $this->get('/dr-old')->assertOk()->assertSee($other->name);
    }

    public function test_an_unknown_address_is_still_not_found(): void
    {
        $this->get('/dr-nobody')->assertNotFound();
    }

    public function test_the_panel_refuses_an_address_another_clinic_gave_up(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);
        $other = $this->otherClinic();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(EditClinic::class, ['record' => $other->getRouteKey()])
            ->fillForm(['slug' => 'dr-old'])
            ->call('save')
            ->assertHasFormErrors(['slug' => 'unique']);

        $this->assertNotSame('dr-old', $other->fresh()->slug);
    }

    public function test_the_panel_lets_a_clinic_take_back_its_own_old_address(): void
    {
        $this->clinic->update(['slug' => 'dr-new']);

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(EditClinic::class, ['record' => $this->clinic->getRouteKey()])
            ->fillForm(['slug' => 'dr-old'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('dr-old', $this->clinic->fresh()->slug);
        $this->assertSame(['dr-new'], ClinicSlugRedirect::query()->pluck('slug')->all());
    }
}
