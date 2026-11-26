<?php

namespace Tests\Feature\Clinic;

use App\DTOs\V1\Branches\BranchData;
use App\Enums\BookingSource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\MessageTemplate;
use App\Models\Patient;
use App\Models\WaitingListEntry;
use App\Services\V1\Branches\BranchService;
use App\Services\V1\Messaging\TemplatePayloadResolver;
use App\Services\V1\Messaging\WhatsAppMessagingService as M;
use Database\Seeders\MessageTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithClinic;
use Tests\TestCase;

/**
 * ELA-22: every message about a booking names its branch (once Meta has
 * approved the new versions), gives the branch's address and phone, and
 * links back to the same branch.
 */
class BranchMessagesTest extends TestCase
{
    use InteractsWithClinic, RefreshDatabase;

    private Branch $tura;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-12-03 10:00:00', 'Africa/Cairo'));
        config(['clinic.branches.multi_enabled' => true]);

        $this->setUpClinic();
        $this->seed(MessageTemplateSeeder::class);
        $this->clinic->update(['slug' => 'dr-sara', 'phone' => '+201000000001']);
        $this->tura = app(BranchService::class)->create(
            $this->clinic,
            new BranchData('عيادة الترعة', '5 شارع الترعة', 31.1, 31.4, '+201099999999'),
            $this->owner,
        );
        $this->booking = Booking::factory()->forClinic($this->clinic)
            ->at(Carbon::parse('2026-12-05 17:00', 'Africa/Cairo'))
            ->create(['branch_id' => $this->tura->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function template(string $key): MessageTemplate
    {
        return MessageTemplate::query()->where('key', $key)->firstOrFail();
    }

    private function body(string $key): array
    {
        return app(TemplatePayloadResolver::class)->for($this->template($key), $this->booking, $this->clinic)->body;
    }

    private function branchVersions(bool $on): void
    {
        config(['clinic.messaging.branch_templates' => $on]);
    }

    public function test_until_the_new_versions_are_switched_on_nothing_about_the_templates_changes(): void
    {
        $this->assertCount(5, $this->body(M::CONFIRMATION_KEY));
        $this->assertSame('appointment_booking_confirmation_v4', $this->template(M::CONFIRMATION_KEY)->providerName());
        $this->assertNotContains('عيادة الترعة', $this->body(M::CONFIRMATION_KEY));
    }

    public function test_switched_on_the_branch_line_lands_where_each_template_has_it(): void
    {
        $this->branchVersions(true);

        $this->assertSame('appointment_booking_confirmation_v5', $this->template(M::CONFIRMATION_KEY)->providerName());

        // {{5}} branch, {{6}} its address.
        $confirmation = $this->body(M::CONFIRMATION_KEY);
        $this->assertSame(['عيادة الترعة', '5 شارع الترعة'], array_slice($confirmation, 4, 2));

        $this->assertSame('عيادة الترعة', $this->body(M::DAILY_REMINDER_KEY)[4]);
        $this->assertSame('عيادة الترعة', $this->body(M::REMINDER_KEY)[1]);
        $this->assertSame('عيادة الترعة', $this->body(M::WAITING_LIST_SLOT_BOOKED_KEY)[4]);

        // {{4}} branch, {{5}} its phone.
        $cancel = $this->body(M::CANCELS_THE_DAY_KEY);
        $this->assertSame(['عيادة الترعة', '+201099999999'], array_slice($cancel, 3, 2));

        // {{2}} branch.
        $delay = app(TemplatePayloadResolver::class)->forDelay($this->booking, $this->clinic, 30)->body;
        $this->assertSame('عيادة الترعة', $delay[1]);
        $this->assertCount(5, $delay);
    }

    public function test_the_waiting_list_messages_name_the_lists_branch(): void
    {
        $this->branchVersions(true);
        $entry = WaitingListEntry::query()->create([
            'clinic_id' => $this->clinic->id,
            'branch_id' => $this->tura->id,
            'patient_id' => Patient::factory()->create(['clinic_id' => $this->clinic->id])->id,
            'visit_date' => '2026-12-05',
            'status' => 'waiting',
            'source' => BookingSource::CLINIC,
        ]);

        foreach ([M::WAITING_LIST_JOINED_KEY, M::WAITING_LIST_NO_SLOT_KEY] as $key) {
            $payload = app(TemplatePayloadResolver::class)->forWaitingList($this->template($key), $entry, $this->clinic);
            $this->assertSame('عيادة الترعة', $payload->body[2]);
        }

        $noSlot = app(TemplatePayloadResolver::class)->forWaitingList($this->template(M::WAITING_LIST_NO_SLOT_KEY), $entry, $this->clinic);
        $this->assertSame('dr-sara/book?b='.$this->tura->id, $noSlot->buttons[0]);
    }

    public function test_the_phone_is_the_branchs_own_else_the_main_booking_number(): void
    {
        $this->assertSame('+201099999999', $this->body(M::CANCELS_THE_DAY_KEY)[3]);

        $this->tura->update(['phone' => null]);
        $this->booking->refresh();

        $this->assertSame('+201000000001', $this->body(M::CANCELS_THE_DAY_KEY)[3]);
    }

    public function test_book_again_links_open_on_the_same_branch(): void
    {
        $payload = app(TemplatePayloadResolver::class)->for($this->template(M::CANCELS_THE_DAY_KEY), $this->booking, $this->clinic);
        $this->assertSame(['dr-sara/book?b='.$this->tura->id], $payload->buttons);

        $rating = app(TemplatePayloadResolver::class)->for($this->template(M::VISIT_COMPLETED_KEY), $this->booking, $this->clinic);
        $this->assertSame('dr-sara/book?b='.$this->tura->id, $rating->buttons[1]);
    }

    public function test_a_one_branch_clinics_links_are_as_they_were(): void
    {
        $this->tura->delete();
        $single = Booking::factory()->forClinic($this->clinic)->create();

        $payload = app(TemplatePayloadResolver::class)->for($this->template(M::CANCELS_THE_DAY_KEY), $single, $this->clinic);

        $this->assertSame(['dr-sara/book'], $payload->buttons);
    }
}
