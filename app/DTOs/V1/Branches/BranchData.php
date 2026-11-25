<?php

namespace App\DTOs\V1\Branches;

/**
 * A branch as the doctor fills it in (ELA-16): name, address and pin are
 * required, the phone is optional (it falls back to the clinic's number).
 */
final readonly class BranchData
{
    public function __construct(
        public string $name,
        public string $address,
        public float $latitude,
        public float $longitude,
        /** E.164, or null for the clinic's main booking number. */
        public ?string $phone,
    ) {}
}
