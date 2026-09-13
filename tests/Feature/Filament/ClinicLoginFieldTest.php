<?php

namespace Tests\Feature\Filament;

use App\Actions\Clinic\ProvisionClinicAction;
use App\Filament\Admin\Resources\Clinics\Pages\EditClinic;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Clinic;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The clinic's login email lives on its user account, not on the clinic, so
 * the clinic page shows it read-only — and saving the page leaves it alone.
 */
class ClinicLoginFieldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_edit_page_shows_the_login_email_linked_to_its_user(): void
    {
        $clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($clinic, 'secret-password');

        $owner = $clinic->staff()->first();
        $owner->update(['email' => 'drseham@gmail.com']);

        Livewire::test(EditClinic::class, ['record' => $clinic->getRouteKey()])
            ->assertSee('drseham@gmail.com')
            ->assertSee(UserResource::getUrl('edit', ['record' => $owner]), escape: false);
    }

    public function test_saving_the_clinic_page_keeps_the_login_email(): void
    {
        $clinic = Clinic::factory()->create(['phone' => '+201001234567', 'slug' => 'dr-test']);
        app(ProvisionClinicAction::class)->execute($clinic, 'secret-password');

        $owner = $clinic->staff()->first();
        $owner->update(['email' => 'drseham@gmail.com']);

        Livewire::test(EditClinic::class, ['record' => $clinic->getRouteKey()])
            ->fillForm(['phone' => '+201067854267'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('+201067854267', $clinic->fresh()->phone);
        $this->assertSame('drseham@gmail.com', $owner->fresh()->email);
    }
}
