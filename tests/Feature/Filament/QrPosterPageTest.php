<?php

namespace Tests\Feature\Filament;

use App\Filament\Admin\Resources\Clinics\ClinicResource;
use App\Filament\Admin\Resources\Clinics\Pages\ManageQrPoster;
use App\Models\ClinicQrPoster;
use App\Models\ClinicQrScan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The QR poster page in the admin panel — super admin only.
 */
class QrPosterPageTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-seham']);
        $this->clinic->doctor->update(['name' => 'د. سهام عبدالعزيز', 'title' => 'استشارى أمراض النسا والتوليد']);

        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
    }

    private function url(): string
    {
        return ClinicResource::getUrl('qr-poster', ['record' => $this->clinic]);
    }

    public function test_every_clinic_has_a_poster_from_the_start(): void
    {
        $this->actingAs($this->admin)
            ->get($this->url())
            ->assertOk()
            ->assertSee('data-qr-poster', escape: false)
            ->assertSee('د. سهام عبدالعزيز')
            ->assertSee('استشارى أمراض النسا والتوليد')
            ->assertSee(url('/dr-seham').'?src=qr')
            ->assertSee(__('filament.qr_poster.download'));
    }

    public function test_clinic_staff_cannot_open_it(): void
    {
        $this->actingAs($this->owner)->get($this->url())->assertForbidden();
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get($this->url())->assertRedirect();
    }

    public function test_the_form_opens_with_the_current_settings(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageQrPoster::class, ['record' => $this->clinic->getRouteKey()])
            ->assertSet('data.name', 'د. سهام عبدالعزيز')
            ->assertSet('data.qr_size', config('clinic.qr_poster.sizes.qr.default'));
    }

    public function test_the_preview_follows_the_form_before_saving(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageQrPoster::class, ['record' => $this->clinic->getRouteKey()])
            ->set('data.name', 'د. سهام')
            // On the poster itself; the form still offers the doctor's name
            // as the placeholder.
            ->assertSeeHtml('>د. سهام</text>')
            ->assertDontSeeHtml('>د. سهام عبدالعزيز</text>');

        $this->assertDatabaseCount('clinic_qr_posters', 0);
    }

    public function test_adjustments_are_saved(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ManageQrPoster::class, ['record' => $this->clinic->getRouteKey()])
            ->set('data.name', 'د. سهام')
            ->set('data.qr_size', 1200)
            ->call('save')
            ->assertHasNoErrors()
            ->assertNotified(__('filament.qr_poster.saved'));

        $row = ClinicQrPoster::firstOrFail();
        $this->assertSame('د. سهام', $row->name);
        $this->assertSame(1200, $row->qr_size);
        $this->assertNull($row->title);
    }

    public function test_reset_goes_back_to_the_doctors_details(): void
    {
        ClinicQrPoster::query()->create(['clinic_id' => $this->clinic->id, 'name' => 'د. سهام']);

        $this->actingAs($this->admin);

        Livewire::test(ManageQrPoster::class, ['record' => $this->clinic->getRouteKey()])
            ->assertSet('data.name', 'د. سهام')
            ->call('resetToDefaults')
            ->assertSet('data.name', 'د. سهام عبدالعزيز')
            ->assertNotified(__('filament.qr_poster.reset_done'));

        $this->assertDatabaseCount('clinic_qr_posters', 0);
    }

    public function test_it_shows_the_poster_numbers(): void
    {
        ClinicQrScan::query()->create(['clinic_id' => $this->clinic->id, 'scan_date' => now()->toDateString(), 'scans' => 17]);

        $this->actingAs($this->admin)
            ->get($this->url())
            ->assertOk()
            ->assertSee('data-stat="scans_30_days">17<', escape: false);
    }

    public function test_the_clinic_page_links_to_it(): void
    {
        $this->actingAs($this->admin)
            ->get(ClinicResource::getUrl('edit', ['record' => $this->clinic]))
            ->assertOk()
            ->assertSee($this->url(), escape: false);
    }
}
