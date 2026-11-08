<?php

namespace Tests\Feature\WaitingList;

use App\DTOs\V1\Booking\BookingData;
use App\Enums\ApiErrorCode;
use App\Enums\BookingKind;
use App\Enums\BookingSource;
use App\Enums\DayOfWeek;
use App\Enums\PatientLocation;
use App\Enums\WaitingListStatus;
use App\Exceptions\ApiException;
use App\Livewire\Patient\BookVisit;
use App\Models\Booking;
use App\Models\OutboundMessage;
use App\Models\Patient;
use App\Models\VisitType;
use App\Models\WaitingListEntry;
use App\Services\Messaging\OtpSender;
use App\Services\V1\Booking\BookingService;
use App\Services\V1\Booking\SlotAvailabilityService;
use App\Services\V1\Messaging\WhatsAppMessagingService;
use App\Services\V1\Patients\PatientService;
use App\Services\V1\WaitingList\WaitingListService;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithClinic;
use Tests\Support\RecordingOtpSender;
use Tests\TestCase;

/**
 * The waiting list for a full day: joining it from the public page or the
 * clinic, the cap, being booked off it, and the end-of-day close.
 *
 * The day is one slot long and that slot is taken, so it is full.
 */
class WaitingListTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private const DAY = '2026-09-03';

    private const PHONE = '01012225521';

    private const E164 = '+201012225521';

    private RecordingOtpSender $sender;

    private VisitType $visitType;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::DAY.' 08:00:00', 'Africa/Cairo'));

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);

        $this->clinic->update([
            'slug' => 'dr-sara',
            'phone' => '01001234500',
            'self_booking_enabled' => true,
            'waiting_list_enabled' => true,
            'booking_window_days' => 7,
            'patient_booking_window_days' => 3,
        ]);

        $this->visitType = $this->clinic->visitTypes()->selfBookable()->firstOrFail();

        // One slot long: 09:00 to the end of a single visit.
        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate(Carbon::parse(self::DAY)));
        $schedule->update(['is_open' => true]);
        $schedule->periods()->delete();
        $schedule->periods()->create([
            'start_time' => '09:00',
            'end_time' => Carbon::parse('09:00')->addMinutes($this->visitType->duration_minutes)->format('H:i'),
        ]);

        $this->bookTheOnlySlot();

        $this->sender = new RecordingOtpSender;
        $this->app->instance(OtpSender::class, $this->sender);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function bookTheOnlySlot(): Booking
    {
        return app(BookingService::class)->create($this->clinic, new BookingData(
            patientId: null,
            patientName: 'سارة محمود',
            phone: '01099990000',
            age: null,
            whatsappOptIn: true,
            visitTypeId: $this->visitType->id,
            date: self::DAY,
            startTime: '09:00',
        ));
    }

    private function patient(string $phone = self::E164, string $name = 'فاطمة عبد الرحمن'): Patient
    {
        return Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'name' => $name,
            'phone' => $phone,
            'whatsapp_opt_in_at' => now(),
        ]);
    }

    private function join(Patient $patient): WaitingListEntry
    {
        return app(WaitingListService::class)->join(
            $this->clinic, $patient, $this->visitType, self::DAY, BookingSource::PATIENT_WEB,
        );
    }

    private function verified(): Testable
    {
        return Livewire::test(BookVisit::class, ['slug' => $this->clinic->slug])
            ->call('start')
            ->set('name', 'فاطمة عبد الرحمن')
            ->set('phone', self::PHONE)
            ->call('sendCode')
            ->set('code', $this->sender->lastCode())
            ->call('verifyCode')
            ->set('visitTypeId', $this->visitType->id)
            ->call('selectDay', self::DAY);
    }

    private function assertRefused(ApiErrorCode $code, callable $action): void
    {
        try {
            $action();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);

            return;
        }

        $this->fail("Expected {$code->value}.");
    }

    /*
    |--------------------------------------------------------------------------
    | The public page
    |--------------------------------------------------------------------------
    */

    public function test_a_full_day_offers_its_waiting_list(): void
    {
        $this->verified()
            ->assertViewHas('stage', 'appointment')
            ->assertSee(__('waiting_list.patient.join'));
    }

    public function test_a_patient_joins_and_is_shown_no_place_number(): void
    {
        $this->join($this->patient('+201011112222', 'أول المنتظرين'));

        $page = $this->verified()
            ->call('openWaitingSheet')
            ->assertSee(__('waiting_list.patient.sheet_title'))
            ->call('joinWaitingList')
            ->assertViewHas('stage', 'waitlisted')
            ->assertSee(__('waiting_list.patient.not_confirmed'))
            // Nothing is accepted yet, so no "you are number 2".
            ->assertDontSee('ترتيبك في القائمة')
            ->assertDontSee('Your place on the list');

        $entry = WaitingListEntry::query()->whereHas('patient', fn ($q) => $q->where('phone', self::E164))->firstOrFail();

        $this->assertSame(BookingSource::PATIENT_WEB, $entry->source);
        $this->assertSame($this->visitType->id, $entry->visit_type_id);

        $message = OutboundMessage::query()
            ->where('waiting_list_entry_id', $entry->id)
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_JOINED_KEY)
            ->sole();

        $this->assertNull($message->booking_id);
        $this->assertSame('فاطمة عبد الرحمن', $message->variables[0]);
        $this->assertSame('الخميس 3 سبتمبر', $message->variables[1]);
        $this->assertNull($message->button_suffix);

        $page->call('leaveWaitingList')->assertViewHas('stage', 'appointment');

        $this->assertSame(WaitingListStatus::LEFT, $entry->fresh()->status);
    }

    public function test_leaving_a_days_list_twice_shuts_it_for_that_patient(): void
    {
        $patient = $this->patient();
        $service = app(WaitingListService::class);

        // Once: they may come back.
        $service->leave($this->join($patient));
        $service->leave($this->join($patient));

        $this->assertRefused(ApiErrorCode::WAITING_LIST_LEFT_TOO_OFTEN, fn () => $this->join($patient));
        $this->assertSame(0, WaitingListEntry::query()->waiting()->count());
    }

    public function test_being_removed_by_the_clinic_does_not_count_as_leaving(): void
    {
        $patient = $this->patient();
        $service = app(WaitingListService::class);

        $service->remove($this->join($patient));
        $service->leave($this->join($patient));

        $this->assertSame(WaitingListStatus::WAITING, $this->join($patient)->status);
    }

    public function test_the_page_says_so_instead_of_offering_the_list_again(): void
    {
        $page = $this->verified();

        for ($i = 0; $i < 2; $i++) {
            $page->call('openWaitingSheet')->call('joinWaitingList')->assertViewHas('stage', 'waitlisted')
                ->call('leaveWaitingList')->assertViewHas('stage', 'appointment');
        }

        $page->call('selectDay', self::DAY)
            ->assertSee(__('waiting_list.patient.left_too_often'))
            ->assertDontSee(__('waiting_list.patient.join'))
            // A request that skips the page is refused too.
            ->call('joinWaitingList');

        $this->assertSame(0, WaitingListEntry::query()->waiting()->count());
        $this->assertSame(2, OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_JOINED_KEY)->count());
    }

    public function test_a_patient_back_on_the_day_sees_their_place_and_joins_once(): void
    {
        $this->verified()->call('joinWaitingList');

        $page = $this->verified()->assertSee(__('waiting_list.patient.already_on'));
        $entry = WaitingListEntry::query()->sole();

        $page->call('showWaitingEntry', $entry->id)->assertViewHas('stage', 'waitlisted');

        $this->assertSame(1, OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_JOINED_KEY)->count());
    }

    public function test_a_full_list_is_said_and_refused(): void
    {
        $this->clinic->update(['waiting_list_max_per_day' => 1]);
        $this->join($this->patient('+201011112222'));

        $this->verified()
            ->assertSee(__('waiting_list.patient.list_full'))
            ->assertDontSee(__('waiting_list.patient.join'))
            ->call('joinWaitingList')
            ->assertSet('failed', true)
            ->assertSet('notice', __('waiting_list.full'));

        $this->assertSame(1, WaitingListEntry::query()->count());
    }

    public function test_a_day_with_a_free_slot_has_no_list(): void
    {
        Booking::query()->delete();

        $this->verified()
            ->assertDontSee(__('waiting_list.patient.join'))
            ->call('joinWaitingList')
            ->assertSet('notice', __('waiting_list.day_not_full'));

        $this->assertSame(0, WaitingListEntry::query()->count());
    }

    public function test_without_the_switch_a_full_day_stays_shut(): void
    {
        $this->clinic->update(['waiting_list_enabled' => false]);

        $this->verified()->assertDontSee(__('waiting_list.patient.join'));

        $this->assertRefused(ApiErrorCode::WAITING_LIST_DISABLED, fn () => $this->join($this->patient()));
    }

    /*
    |--------------------------------------------------------------------------
    | The rules
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_booked_that_day_cannot_join(): void
    {
        $booked = Booking::query()->sole()->patient;

        $this->assertRefused(ApiErrorCode::WAITING_LIST_ALREADY_BOOKED, fn () => $this->join($booked));
    }

    public function test_a_day_that_has_ended_cannot_be_joined(): void
    {
        Carbon::setTestNow(Carbon::parse(self::DAY.' 11:00:00', 'Africa/Cairo'));

        $this->assertRefused(ApiErrorCode::WAITING_LIST_CLOSED, fn () => $this->join($this->patient()));
    }

    public function test_positions_move_up_as_people_leave(): void
    {
        $service = app(WaitingListService::class);
        $first = $this->join($this->patient('+201011112222'));
        $second = $this->join($this->patient('+201011113333'));

        $this->assertSame(2, $service->position($second));

        $service->remove($first);

        $this->assertSame(1, $service->position($second));
    }

    /*
    |--------------------------------------------------------------------------
    | Booked off the list
    |--------------------------------------------------------------------------
    */

    private function freeTheSlot(): void
    {
        Booking::query()->delete();
    }

    public function test_booking_from_the_list_sends_slot_booked_instead_of_the_confirmation(): void
    {
        $patient = $this->patient();
        $entry = $this->join($patient);
        $this->freeTheSlot();

        $booking = app(BookingService::class)->create($this->clinic, new BookingData(
            patientId: $patient->id,
            patientName: null,
            phone: null,
            age: null,
            whatsappOptIn: true,
            visitTypeId: $this->visitType->id,
            date: self::DAY,
            startTime: '09:00',
            waitingListEntryId: $entry->id,
        ));

        $entry->refresh();
        $this->assertSame(WaitingListStatus::BOOKED, $entry->status);
        $this->assertSame($booking->id, $entry->booking_id);

        $keys = OutboundMessage::query()->where('booking_id', $booking->id)->pluck('template_key')->all();
        $this->assertSame([WhatsAppMessagingService::WAITING_LIST_SLOT_BOOKED_KEY], $keys);

        $message = OutboundMessage::query()->where('booking_id', $booking->id)->sole();
        $this->assertCount(5, $message->variables);
        $this->assertSame('booking/'.$booking->tracking_token, $message->button_suffix);
    }

    public function test_any_booking_that_day_takes_the_patient_off_the_list(): void
    {
        $patient = $this->patient();
        $entry = $this->join($patient);
        $this->freeTheSlot();

        $booking = app(BookingService::class)->create($this->clinic, new BookingData(
            patientId: $patient->id,
            patientName: null,
            phone: null,
            age: null,
            whatsappOptIn: true,
            visitTypeId: $this->visitType->id,
            date: self::DAY,
            startTime: '09:00',
        ));

        $this->assertSame(WaitingListStatus::BOOKED, $entry->fresh()->status);
        $this->assertSame(
            [WhatsAppMessagingService::CONFIRMATION_KEY, WhatsAppMessagingService::VISIT_GUIDELINES_KEY],
            OutboundMessage::query()->where('booking_id', $booking->id)->orderBy('id')->pluck('template_key')->all(),
        );
    }

    public function test_another_patients_entry_is_refused(): void
    {
        $entry = $this->join($this->patient());
        $this->freeTheSlot();

        $this->assertRefused(ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND, fn () => app(BookingService::class)->create(
            $this->clinic,
            new BookingData(
                patientId: null,
                patientName: 'شخص آخر',
                phone: '01055556666',
                age: null,
                whatsappOptIn: true,
                visitTypeId: $this->visitType->id,
                date: self::DAY,
                startTime: '09:00',
                waitingListEntryId: $entry->id,
            ),
        ));

        $this->assertSame(WaitingListStatus::WAITING, $entry->fresh()->status);
        $this->assertSame(0, Booking::query()->count());
    }

    /** The day after DAY, open 09:00–13:00. */
    private function openTheNextDay(): string
    {
        $next = Carbon::parse(self::DAY)->addDay();

        $schedule = $this->clinic->scheduleFor(DayOfWeek::fromDate($next));
        $schedule->update(['is_open' => true]);
        $schedule->periods()->delete();
        $schedule->periods()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        return $next->toDateString();
    }

    private function bookingFor(Patient $patient, string $date, ?int $entryId = null): BookingData
    {
        return new BookingData(
            patientId: $patient->id,
            patientName: null,
            phone: null,
            age: null,
            whatsappOptIn: true,
            visitTypeId: $this->visitType->id,
            date: $date,
            startTime: '09:00',
            waitingListEntryId: $entryId,
        );
    }

    public function test_booking_from_the_list_on_another_day_is_refused(): void
    {
        $nextDay = $this->openTheNextDay();
        $patient = $this->patient();
        $entry = $this->join($patient);

        $this->assertRefused(ApiErrorCode::WAITING_LIST_WRONG_DAY, fn () => app(BookingService::class)
            ->create($this->clinic, $this->bookingFor($patient, $nextDay, $entry->id)));

        $this->assertSame(WaitingListStatus::WAITING, $entry->fresh()->status);
        $this->assertFalse(Booking::query()->where('patient_id', $patient->id)->exists());
    }

    public function test_booking_from_the_list_leaves_a_booking_on_another_day_alone(): void
    {
        $nextDay = $this->openTheNextDay();
        $patient = $this->patient();
        $other = app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, $nextDay));
        $entry = $this->join($patient);
        Booking::query()->whereKeyNot($other->id)->delete();

        $booking = app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, self::DAY, $entry->id));

        $this->assertSame(WaitingListStatus::BOOKED, $entry->fresh()->status);
        $this->assertSame(self::DAY, $booking->visit_date->toDateString());
        $this->assertSame($nextDay, $other->fresh()->visit_date->toDateString());
    }

    public function test_a_patient_already_booked_that_day_cannot_be_booked_from_the_list(): void
    {
        $patient = $this->patient();
        $entry = $this->join($patient);
        $this->freeTheSlot();
        app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, self::DAY));

        // Left waiting by data from before a moved booking settled the list.
        $entry->refresh()->update(['status' => WaitingListStatus::WAITING, 'booking_id' => null, 'left_at' => null]);

        $this->assertRefused(ApiErrorCode::WAITING_LIST_ALREADY_BOOKED, fn () => app(BookingService::class)->create(
            $this->clinic,
            new BookingData(
                patientId: $patient->id,
                patientName: null,
                phone: null,
                age: null,
                whatsappOptIn: true,
                visitTypeId: $this->visitType->id,
                date: self::DAY,
                startTime: null,
                bookingKind: BookingKind::EMERGENCY,
                patientLocation: PatientLocation::INSIDE_CLINIC,
                waitingListEntryId: $entry->id,
            ),
        ));

        $this->assertSame(1, Booking::query()->where('patient_id', $patient->id)->count());
    }

    public function test_an_entry_already_booked_from_is_refused_as_a_duplicate(): void
    {
        $patient = $this->patient();
        $entry = $this->join($patient);
        $this->freeTheSlot();
        app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, self::DAY, $entry->id));

        // The same call saved twice — a double tap, or two secretaries.
        $this->assertRefused(ApiErrorCode::WAITING_LIST_ALREADY_BOOKED, fn () => app(BookingService::class)->create(
            $this->clinic,
            new BookingData(
                patientId: $patient->id,
                patientName: null,
                phone: null,
                age: null,
                whatsappOptIn: true,
                visitTypeId: $this->visitType->id,
                date: self::DAY,
                startTime: null,
                bookingKind: BookingKind::EMERGENCY,
                patientLocation: PatientLocation::INSIDE_CLINIC,
                waitingListEntryId: $entry->id,
            ),
        ));

        $this->assertSame(1, Booking::query()->where('patient_id', $patient->id)->count());
    }

    public function test_an_entry_that_stopped_waiting_still_books_with_the_usual_confirmation(): void
    {
        $patient = $this->patient();
        $entry = $this->join($patient);
        app(WaitingListService::class)->leave($entry);
        $this->freeTheSlot();

        $booking = app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, self::DAY, $entry->id));

        $this->assertSame(WaitingListStatus::LEFT, $entry->fresh()->status);
        $this->assertSame(
            [WhatsAppMessagingService::CONFIRMATION_KEY, WhatsAppMessagingService::VISIT_GUIDELINES_KEY],
            OutboundMessage::query()->where('booking_id', $booking->id)->orderBy('id')->pluck('template_key')->all(),
        );
    }

    public function test_a_patient_with_an_upcoming_visit_cannot_join_online(): void
    {
        $nextDay = $this->openTheNextDay();
        $patient = $this->patient();
        app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, $nextDay));

        // The page shows the upcoming visit instead; this is the call made
        // without it.
        $this->verified()->call('joinWaitingList');

        $this->assertFalse(WaitingListEntry::query()->where('patient_id', $patient->id)->exists());
        $this->assertSame(0, OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_JOINED_KEY)->count());
    }

    public function test_moving_a_booking_onto_the_waited_day_takes_them_off_the_list(): void
    {
        $nextDay = $this->openTheNextDay();
        $patient = $this->patient();
        $booking = app(BookingService::class)->create($this->clinic, $this->bookingFor($patient, $nextDay));
        $entry = $this->join($patient);
        Booking::query()->whereKeyNot($booking->id)->delete();

        app(BookingService::class)->update($this->clinic, $booking->id, $this->bookingFor($patient, self::DAY));

        $entry->refresh();
        $this->assertSame(WaitingListStatus::BOOKED, $entry->status);
        $this->assertSame($booking->id, $entry->booking_id);

        // The day closes with nobody left to tell.
        Carbon::setTestNow(Carbon::parse(self::DAY.' 23:00:00', 'Africa/Cairo'));
        $this->assertSame(['closed' => 0, 'messaged' => 0], app(WaitingListService::class)->closeEndedDays($this->clinic));
    }

    /*
    |--------------------------------------------------------------------------
    | The end of the day
    |--------------------------------------------------------------------------
    */

    public function test_the_list_closes_when_the_last_period_ends_and_tells_who_is_left(): void
    {
        $entry = $this->join($this->patient());

        $this->artisan('clinic:close-waiting-lists')->assertSuccessful();
        $this->assertSame(WaitingListStatus::WAITING, $entry->fresh()->status);

        Carbon::setTestNow(Carbon::parse(self::DAY.' 10:00:00', 'Africa/Cairo'));
        $this->artisan('clinic:close-waiting-lists')->assertSuccessful();
        $this->artisan('clinic:close-waiting-lists')->assertSuccessful();

        $entry->refresh();
        $this->assertSame(WaitingListStatus::CLOSED, $entry->status);
        $this->assertNotNull($entry->messaged_at);

        $message = OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_NO_SLOT_KEY)
            ->sole();

        $this->assertSame($entry->id, $message->waiting_list_entry_id);
        $this->assertSame('dr-sara/book', $message->button_suffix);
    }

    public function test_a_close_long_after_the_day_sends_nothing(): void
    {
        $entry = $this->join($this->patient());

        Carbon::setTestNow(Carbon::parse(self::DAY, 'Africa/Cairo')->addDays(2)->setTime(12, 0));
        $this->artisan('clinic:close-waiting-lists')->assertSuccessful();

        $this->assertSame(WaitingListStatus::CLOSED, $entry->fresh()->status);
        $this->assertSame(0, OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_NO_SLOT_KEY)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | The mobile API
    |--------------------------------------------------------------------------
    */

    public function test_the_secretary_lists_and_removes(): void
    {
        $this->join($this->patient());

        Sanctum::actingAs($this->secretary);

        $this->getJson('/api/v1/waiting-list?date='.self::DAY)
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.items.0.patient.name', 'فاطمة عبد الرحمن');

        $id = WaitingListEntry::query()->sole()->id;

        $this->deleteJson('/api/v1/waiting-list/'.$id)->assertOk();
        $this->deleteJson('/api/v1/waiting-list/'.$id)
            ->assertNotFound()
            ->assertJsonPath('error.code', ApiErrorCode::WAITING_LIST_ENTRY_NOT_FOUND->value);

        $this->assertSame(WaitingListStatus::REMOVED, WaitingListEntry::query()->sole()->status);
    }

    public function test_the_secretary_adds_a_patient_who_phoned_in(): void
    {
        $this->join($this->patient('+201011112222', 'أول المنتظرين'));

        Sanctum::actingAs($this->secretary);

        $this->postJson('/api/v1/waiting-list', [
            'date' => self::DAY,
            'patient_name' => 'مريض بالتليفون',
            'phone' => '01077778888',
            'visit_type_id' => $this->visitType->id,
        ])
            ->assertCreated()
            ->assertJsonPath('message', __('waiting_list.added_ok', [], 'ar'))
            ->assertJsonPath('data.position', 2)
            ->assertJsonPath('data.patient.name', 'مريض بالتليفون')
            ->assertJsonPath('data.visit_type.id', $this->visitType->id)
            ->assertJsonPath('data.source.value', BookingSource::CLINIC->value);

        $entry = WaitingListEntry::query()->whereHas('patient', fn ($q) => $q->where('phone', '+201077778888'))->sole();

        $this->assertSame(BookingSource::CLINIC, $entry->source);
        $this->assertSame($this->secretary->id, $entry->created_by);
        $this->assertSame(WaitingListStatus::WAITING, $entry->status);

        // Told they are on the list, as when they join online.
        $this->assertSame(1, OutboundMessage::query()
            ->where('waiting_list_entry_id', $entry->id)
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_JOINED_KEY)
            ->count());
    }

    public function test_adding_a_known_patient_twice_hands_back_their_place(): void
    {
        $patient = $this->patient();

        Sanctum::actingAs($this->secretary);

        $body = ['date' => self::DAY, 'patient_id' => $patient->id, 'visit_type_id' => $this->visitType->id];

        $first = $this->postJson('/api/v1/waiting-list', $body)->assertCreated()->json('data.id');

        $this->postJson('/api/v1/waiting-list', $body)
            ->assertOk()
            ->assertJsonPath('data.id', $first)
            ->assertJsonPath('data.position', 1);

        $this->assertSame(1, WaitingListEntry::query()->count());
        $this->assertSame(1, OutboundMessage::query()
            ->where('template_key', WhatsAppMessagingService::WAITING_LIST_JOINED_KEY)->count());
    }

    public function test_adding_follows_the_lists_rules(): void
    {
        Sanctum::actingAs($this->secretary);

        // Already booked that day: the patient who holds the only slot.
        $this->postJson('/api/v1/waiting-list', [
            'date' => self::DAY,
            'patient_id' => Booking::query()->sole()->patient_id,
            'visit_type_id' => $this->visitType->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::WAITING_LIST_ALREADY_BOOKED->value);

        $this->clinic->update(['waiting_list_max_per_day' => 1]);
        $this->join($this->patient('+201011112222'));

        $this->postJson('/api/v1/waiting-list', ['date' => self::DAY, 'patient_id' => $this->patient()->id, 'visit_type_id' => $this->visitType->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::WAITING_LIST_FULL->value);

        $this->clinic->update(['waiting_list_enabled' => false]);

        $this->postJson('/api/v1/waiting-list', ['date' => self::DAY, 'patient_name' => 'جديد', 'phone' => '01066665555', 'visit_type_id' => $this->visitType->id])
            ->assertForbidden()
            ->assertJsonPath('error.code', ApiErrorCode::WAITING_LIST_DISABLED->value);

        $this->assertSame(1, WaitingListEntry::query()->count());
    }

    public function test_adding_needs_a_patient_and_a_day(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->postJson('/api/v1/waiting-list', [])
            ->assertUnprocessable();

        $this->postJson('/api/v1/waiting-list', ['date' => self::DAY, 'patient_id' => 999_999, 'visit_type_id' => $this->visitType->id])
            ->assertNotFound()
            ->assertJsonPath('error.code', ApiErrorCode::PATIENT_NOT_FOUND->value);

        $this->assertSame(0, WaitingListEntry::query()->count());
    }

    public function test_another_clinics_patient_cannot_be_added(): void
    {
        $other = $this->otherClinic();
        $stranger = app(PatientService::class)->findOrCreate(
            $other, 'غريب', app(PatientService::class)->parsePhone($other, '01044443333'),
        );

        Sanctum::actingAs($this->secretary);

        $this->postJson('/api/v1/waiting-list', ['date' => self::DAY, 'patient_id' => $stranger->id, 'visit_type_id' => $this->visitType->id])
            ->assertNotFound();

        $this->assertSame(0, WaitingListEntry::query()->count());
    }

    public function test_another_clinics_entry_is_not_found(): void
    {
        $other = $this->otherClinic();
        $patient = app(PatientService::class)->findOrCreate(
            $other, 'غريب', app(PatientService::class)->parsePhone($other, '01044443333'),
        );
        $entry = $other->waitingListEntries()->create([
            'patient_id' => $patient->id,
            'visit_date' => self::DAY,
            'status' => WaitingListStatus::WAITING,
            'source' => BookingSource::PATIENT_WEB,
        ]);

        Sanctum::actingAs($this->secretary);

        $this->deleteJson('/api/v1/waiting-list/'.$entry->id)->assertNotFound();
        $this->assertSame(WaitingListStatus::WAITING, $entry->fresh()->status);
    }

    public function test_the_availability_service_still_sees_the_day_as_full(): void
    {
        // A list entry holds nothing: the day is exactly as full as before.
        $this->join($this->patient());

        $availability = app(SlotAvailabilityService::class)->for(
            $this->clinic,
            Carbon::parse(self::DAY, $this->clinic->timezone),
            $this->visitType,
        );

        $this->assertFalse($availability->hasAvailableSlot());
    }

    public function test_adding_needs_the_day_to_be_full_for_that_visit_type(): void
    {
        $next = $this->openTheNextDay();
        $patients = Patient::query()->count();

        Sanctum::actingAs($this->secretary);

        $this->postJson('/api/v1/waiting-list', [
            'date' => $next,
            'patient_name' => 'مريض بالتليفون',
            'phone' => '01077776666',
            'visit_type_id' => $this->visitType->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::WAITING_LIST_DAY_NOT_FULL->value)
            ->assertJsonPath('message', __('waiting_list.day_not_full_staff', ['type' => $this->visitType->name], 'ar'));

        // Refused before anyone was created.
        $this->assertSame(0, WaitingListEntry::query()->count());
        $this->assertSame($patients, Patient::query()->count());
    }

    public function test_adding_needs_a_visit_type(): void
    {
        Sanctum::actingAs($this->secretary);

        $this->postJson('/api/v1/waiting-list', ['date' => self::DAY, 'patient_name' => 'جديد', 'phone' => '01066665555'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['fields' => ['visit_type_id']]]);

        $this->assertSame(0, WaitingListEntry::query()->count());
    }

    public function test_a_patient_who_left_too_often_is_refused_in_the_secretarys_words(): void
    {
        $patient = $this->patient('+201011112222');
        $service = app(WaitingListService::class);

        foreach ([1, 2] as $time) {
            $service->leave($this->join($patient));
        }

        Sanctum::actingAs($this->secretary);

        $this->postJson('/api/v1/waiting-list', ['date' => self::DAY, 'patient_id' => $patient->id, 'visit_type_id' => $this->visitType->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', ApiErrorCode::WAITING_LIST_LEFT_TOO_OFTEN->value)
            ->assertJsonPath('message', __('waiting_list.left_too_often_staff', [], 'ar'));
    }
}
