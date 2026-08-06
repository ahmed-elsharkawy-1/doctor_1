<?php

namespace Tests\Feature\Patient;

use App\Actions\Patient\GeneratePatientCodeAction;
use App\Models\Clinic;
use App\Models\Patient;
use App\Support\ArabicTransliterator;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PatientCodeTest extends TestCase
{
    use RefreshDatabase;

    private Clinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clinic = Clinic::factory()->create();
    }

    private function code(string $name, string $phone): string
    {
        return app(GeneratePatientCodeAction::class)->execute(
            $this->clinic,
            $name,
            PhoneNumber::parse($phone),
        );
    }

    public function test_it_builds_the_code_from_the_name_and_the_last_four_digits(): void
    {
        $this->assertSame('SAAH5521', $this->code('سارة أحمد', '01012225521'));
    }

    public function test_it_handles_a_latin_name(): void
    {
        $this->assertSame('SAAH5521', $this->code('Sara Ahmed', '01012225521'));
    }

    public function test_it_ignores_words_beyond_the_first_two(): void
    {
        $this->assertSame(
            $this->code('سارة أحمد', '01012225521'),
            $this->code('سارة أحمد محمد علي', '01012225521'),
        );
    }

    public function test_a_single_word_name_is_padded(): void
    {
        $code = $this->code('سارة', '01012225521');

        $this->assertSame(8, strlen($code));
        $this->assertStringEndsWith('5521', $code);
    }

    public function test_a_name_with_no_transliterable_letters_still_gets_a_code(): void
    {
        $code = $this->code('...', '01012225521');

        $this->assertNotSame('', $code);
        $this->assertStringEndsWith('5521', $code);
    }

    /**
     * Two patients can legitimately produce the same base.
     */
    public function test_a_colliding_code_gets_a_suffix(): void
    {
        $first = $this->code('سارة أحمد', '01012225521');

        Patient::factory()->create([
            'clinic_id' => $this->clinic->id,
            'code' => $first,
            'phone' => '+201012225521',
        ]);

        $second = $this->code('سارة أحمد', '01099995521');

        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith($first, $second);
    }

    public function test_codes_only_collide_within_a_clinic(): void
    {
        $code = $this->code('سارة أحمد', '01012225521');

        Patient::factory()->create([
            'clinic_id' => Clinic::factory()->create()->id,
            'code' => $code,
        ]);

        $this->assertSame($code, $this->code('سارة أحمد', '01012225521'));
    }

    public function test_the_same_input_always_produces_the_same_code(): void
    {
        $this->assertSame(
            $this->code('منى عبد الله', '01012345678'),
            $this->code('منى عبد الله', '01012345678'),
        );
    }

    public function test_transliteration_drops_anything_that_is_not_a_letter(): void
    {
        $this->assertSame('SARA', ArabicTransliterator::toLatin('سارة'));
        $this->assertSame('AHMD', ArabicTransliterator::toLatin('أحمد'));
        $this->assertSame('SARA', ArabicTransliterator::toLatin('سارة ١٢٣!'));
        $this->assertSame('', ArabicTransliterator::toLatin('١٢٣ !!'));
    }
}
