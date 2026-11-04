<?php

namespace Tests\Feature\Web;

use App\Enums\BookingSource;
use App\Enums\DayOfWeek;
use App\Enums\WaitingListStatus;
use App\Livewire\App\NewBooking;
use App\Livewire\App\WaitingList;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\VisitType;
use App\Models\WaitingListEntry;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\WaitingList\WaitingListService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * The secretary's waiting-list screen, and booking a patient from it.
 */
class ClinicAppWaitingListTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['waiting_list_enabled' => true]);

        $schedule = $this->clinic->scheduleFor(DayOfWeek::THURSDAY);
        $schedule->update(['is_open' => true]);
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->visitType = $this->clinic->visitTypes()->active()->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function onTheList(): WaitingListEntry
    {
        $patient = Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => 'منى السيد',
            'phone' => '+201012345678',
            'whatsapp_opt_in_at' => now(),
        ]);

        return app(WaitingListService::class)->join(
            $this->clinic, $patient, $this->visitType, self::DAY, BookingSource::PATIENT_WEB,
        );
    }

    public function test_the_secretary_sees_who_joined_and_removes_them_but_cannot_add(): void
    {
        $entry = $this->onTheList();

        $page = Livewire::actingAs($this->secretary)->test(WaitingList::class)
            ->assertSee('منى السيد')
            ->assertSee(route('app.bookings.new', ['waiting_list' => $entry->id]))
            // Patients join themselves, on the public page.
            ->assertDontSeeHtml('wire:submit="add"');

        $this->assertFalse(method_exists($page->instance(), 'add'));

        $page->call('remove', $entry->id)->assertSee(__('waiting_list.app.empty'));

        $this->assertSame(WaitingListStatus::REMOVED, $entry->fresh()->status);
    }

    public function test_booking_from_the_list_prefills_and_takes_them_off(): void
    {
        $entry = $this->onTheList();

        $page = Livewire::actingAs($this->owner)
            ->withQueryParams(['waiting_list' => $entry->id])
            ->test(NewBooking::class)
            ->assertSet('patientId', $entry->patient_id)
            ->assertSet('date', self::DAY)
            ->assertSet('visitTypeId', $this->visitType->id)
            ->assertSee(__('waiting_list.app.booking_from_list', ['name' => 'منى السيد']));

        $page->call('selectSlot', '09:00')->call('save')->assertSet('failed', false);

        $entry->refresh();
        $this->assertSame(WaitingListStatus::BOOKED, $entry->status);
        $this->assertSame(
            [WhatsAppMessagingService::WAITING_LIST_SLOT_BOOKED_KEY],
            OutboundMessage::query()->where('booking_id', $entry->booking_id)->pluck('template_key')->all(),
        );
    }

    public function test_booking_from_the_list_keeps_the_day_they_wait_for(): void
    {
        $entry = $this->onTheList();
        $friday = Carbon::parse(self::DAY)->addDay()->toDateString();

        Livewire::actingAs($this->owner)
            ->withQueryParams(['waiting_list' => $entry->id])
            ->test(NewBooking::class)
            ->call('selectDay', $friday)
            ->assertSet('date', self::DAY)
            ->call('selectKind', 'emergency')
            ->assertSet('kind', 'emergency');
    }

    public function test_an_entry_for_a_day_gone_by_is_dropped(): void
    {
        $entry = $this->onTheList();
        Carbon::setTestNow(Carbon::parse(self::DAY)->addDay()->setTime(10, 0)->shiftTimezone('Africa/Cairo'));

        Livewire::actingAs($this->owner)
            ->withQueryParams(['waiting_list' => $entry->id])
            ->test(NewBooking::class)
            ->assertSet('waitingListEntry', null)
            ->assertSet('patientId', null)
            ->assertSet('notice', __('waiting_list.closed'));
    }

    public function test_an_entry_already_off_the_list_books_normally(): void
    {
        $entry = $this->onTheList();
        app(WaitingListService::class)->remove($entry);

        Livewire::actingAs($this->owner)
            ->withQueryParams(['waiting_list' => $entry->id])
            ->test(NewBooking::class)
            ->assertSet('waitingListEntry', null)
            ->assertSet('patientId', null)
            ->assertSet('notice', __('waiting_list.not_found'));
    }

    public function test_without_the_switch_the_screen_says_so_and_the_nav_hides_it(): void
    {
        $this->actingAs($this->secretary)
            ->get(route('app.queue'))
            ->assertSee(__('waiting_list.app.nav'));

        $this->clinic->update(['waiting_list_enabled' => false]);

        $this->actingAs($this->secretary)
            ->get(route('app.queue'))
            ->assertDontSee(route('app.waiting-list'));

        Livewire::actingAs($this->secretary)->test(WaitingList::class)
            ->assertSee(__('waiting_list.app.disabled'));
    }
}
