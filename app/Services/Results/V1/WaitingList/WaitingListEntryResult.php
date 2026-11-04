<?php

namespace App\Services\Results\V1\WaitingList;

use App\Models\WaitingListEntry;
use App\Services\Results\ServiceResult;
use App\Support\PhoneNumber;
use App\Support\Wire;

/**
 * One patient on a day's waiting list. Phones are unmasked: the whole point
 * of the row is to call them when a slot opens.
 */
final class WaitingListEntryResult extends ServiceResult
{
    public function __construct(
        private readonly WaitingListEntry $entry,
        private readonly int $position,
    ) {}

    public function toArray(): array
    {
        $patient = $this->entry->patient;
        $phone = $patient?->phone ? PhoneNumber::tryParse($patient->phone) : null;

        return [
            'id' => $this->entry->id,
            'position' => $this->position,
            'date' => Wire::date($this->entry->visit_date),
            'patient' => $patient === null ? null : [
                'id' => $patient->id,
                'code' => $patient->code,
                'name' => $patient->name,
                'phone' => Wire::phone($phone),
            ],
            'visit_type' => $this->entry->visitType === null ? null : [
                'id' => $this->entry->visitType->id,
                'name' => $this->entry->visitType->name,
            ],
            'source' => Wire::enum($this->entry->source, __('waiting_list.source.'.$this->entry->source->value)),
            'status' => Wire::enum($this->entry->status, $this->entry->status->label()),
            'joined_at' => $this->entry->created_at?->toAtomString(),
        ];
    }
}
