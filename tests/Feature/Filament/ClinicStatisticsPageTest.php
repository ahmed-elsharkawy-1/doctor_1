<?php

namespace Tests\Feature\Filament;

use App\Enums\BookingStatus;
use App\Filament\Admin\Pages\ClinicStatisticsPage;
use App\Models\Booking;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

class ClinicStatisticsPageTest extends TestCase
{
    use InteractsWithClinic;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        Filament::setCurrentPanel('admin');
        $this->admin = User::factory()->superAdmin()->create();
    }

    public function test_only_an_admin_can_open_the_page(): void
    {
        $this->actingAs($this->owner)
            ->get(ClinicStatisticsPage::getUrl())
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(ClinicStatisticsPage::getUrl())
            ->assertOk();
    }

    public function test_filters_render_the_metric_cards_and_export_current_data(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(ClinicStatisticsPage::class)
            ->fillForm([
                'clinic_id' => $this->clinic->id,
                'from' => '2026-11-01',
                'to' => '2026-11-30',
            ])
            ->assertSee(__('statistics.cards.no_show.title'))
            ->assertSee(__('statistics.cards.satisfaction.title'))
            ->assertSee(__('statistics.comments.title'))
            ->callAction('export')
            ->assertFileDownloaded(
                "clinic-{$this->clinic->id}-statistics-2026-11-01-to-2026-11-30.csv",
                contentType: 'text/csv; charset=UTF-8',
            );
    }

    public function test_changing_the_clinic_or_date_range_recomputes_the_cards(): void
    {
        config(['clinic.statistics.min_sample' => 1]);
        Booking::factory()
            ->forClinic($this->clinic)
            ->at(Carbon::parse('2026-11-10 10:00', 'Africa/Cairo'))
            ->create(['status' => BookingStatus::NO_SHOW]);
        $otherClinic = $this->otherClinic();

        $this->actingAs($this->admin);

        Livewire::test(ClinicStatisticsPage::class)
            ->fillForm([
                'clinic_id' => $this->clinic->id,
                'from' => '2026-11-10',
                'to' => '2026-11-10',
            ])
            ->assertSee('data-card="no_show" data-state="ok"', false)
            ->fillForm([
                'from' => '2026-11-11',
                'to' => '2026-11-11',
            ])
            ->assertSee('data-card="no_show" data-state="no_data"', false)
            ->fillForm(['clinic_id' => $otherClinic->id])
            ->assertSee('data-card="no_show" data-state="no_data"', false);
    }
}
