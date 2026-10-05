<?php

namespace Tests\Feature\Web;

use App\Livewire\Patient\BookVisit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * A test clinic on production is public like any other — so it says what it
 * is, and keeps out of search results, without behaving any differently.
 */
class TestClinicLabelTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpClinic();
        $this->clinic->update(['slug' => 'dr-test', 'self_booking_enabled' => true]);
    }

    public function test_a_test_clinics_page_is_labelled_and_not_indexed(): void
    {
        $this->clinic->update(['is_test' => true]);

        $this->get('/dr-test')
            ->assertOk()
            ->assertSee(__('landing.test_clinic'))
            ->assertSee('<meta name="robots" content="noindex, nofollow">', escape: false);
    }

    public function test_its_booking_page_is_labelled(): void
    {
        $this->clinic->update(['is_test' => true]);

        Livewire::test(BookVisit::class, ['slug' => 'dr-test'])->assertSee(__('landing.test_clinic'));
    }

    /** A real clinic's page must stay findable and unlabelled. */
    public function test_a_real_clinic_is_neither_labelled_nor_hidden(): void
    {
        $this->get('/dr-test')
            ->assertOk()
            ->assertDontSee(__('landing.test_clinic'))
            ->assertDontSee('<meta name="robots" content="noindex', escape: false);

        Livewire::test(BookVisit::class, ['slug' => 'dr-test'])->assertDontSee(__('landing.test_clinic'));
    }
}
