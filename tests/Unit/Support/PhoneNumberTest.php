<?php

namespace Tests\Unit\Support;

use App\Support\PhoneNumber;
use InvalidArgumentException;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * The same number typed five different ways must resolve to one patient.
     */
    public function test_it_normalises_every_common_input_shape_to_the_same_e164(): void
    {
        $inputs = [
            '01012345678',
            '0101 234 5678',
            '0101-234-5678',
            '+201012345678',
            '00201012345678',
            '201012345678',
        ];

        foreach ($inputs as $input) {
            $this->assertSame(
                '+201012345678',
                PhoneNumber::parse($input)->e164,
                "Failed normalising [{$input}]",
            );
        }
    }

    public function test_it_renders_the_national_form_for_display(): void
    {
        $this->assertSame('01012345678', PhoneNumber::parse('+201012345678')->national());
    }

    public function test_it_masks_the_middle_for_list_views(): void
    {
        $this->assertSame('0101***5678', PhoneNumber::parse('01012345678')->masked());
    }

    public function test_it_rejects_a_number_of_the_wrong_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::parse('0101234');
    }

    public function test_it_rejects_input_with_no_digits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::parse('not a phone');
    }

    public function test_try_parse_returns_null_instead_of_throwing(): void
    {
        $this->assertNull(PhoneNumber::tryParse('0101234'));
        $this->assertNotNull(PhoneNumber::tryParse('01012345678'));
    }

    public function test_it_honours_a_non_default_country(): void
    {
        $this->assertSame('+971501234567', PhoneNumber::parse('0501234567', 'AE')->e164);
    }

    public function test_an_unknown_country_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PhoneNumber::parse('01012345678', 'ZZ');
    }
}
