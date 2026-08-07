<?php

namespace App\Services\V1\Patients;

use App\Enums\ApiErrorCode;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Exceptions\ApiException;
use App\Models\Booking;
use App\Models\Clinic;
use App\Models\Patient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Patient search and visit history — SPEC §4.4.
 */
class PatientSearchService
{
    /**
     * Matches the way the secretary actually types: a name fragment, an ID
     * code, or the tail of a phone number.
     *
     * @return LengthAwarePaginator<int, Patient>
     */
    public function search(Clinic $clinic, ?string $term, int $perPage): LengthAwarePaginator
    {
        $term = trim((string) $term);

        return $clinic->patients()
            ->withCount([
                'bookings as visits_count' => fn (Builder $query) => $query
                    ->whereIn('status', BookingStatus::occupyingSlot()),
            ])
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $this->applyTerm($inner, $term),
            ))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function find(Clinic $clinic, int $patientId): Patient
    {
        $patient = $clinic->patients()->whereKey($patientId)->first();

        if ($patient === null) {
            throw ApiException::make(
                ApiErrorCode::PATIENT_NOT_FOUND,
                __('patient.not_found'),
                http: 404,
            );
        }

        return $patient;
    }

    /**
     * The whole history, newest first — cancellations and no-shows included,
     * because a pattern of them is exactly what the secretary wants to see.
     *
     * @return Collection<int, Booking>
     */
    public function history(Patient $patient): Collection
    {
        return $patient->bookings()
            ->with('visitType')
            ->orderByDesc('start_at')
            ->get();
    }

    /**
     * @param  Collection<int, Booking>  $history
     * @return array{visits_count: int, no_show_count: int, cancelled_count: int, first_visit: ?Booking, last_visit: ?Booking}
     */
    public function summary(Collection $history): array
    {
        $visits = $history->filter(
            fn (Booking $booking) => in_array($booking->status, BookingStatus::occupyingSlot(), true),
        );

        return [
            'visits_count' => $visits->count(),
            'no_show_count' => $history->where('cancel_reason', CancelReason::NO_SHOW)->count(),
            'cancelled_count' => $history->where('status', BookingStatus::CANCELLED)->count(),
            // Ordered newest first, so the earliest visit is at the end.
            'first_visit' => $visits->last(),
            'last_visit' => $visits->first(),
        ];
    }

    /**
     * @param  Builder<Patient>  $query
     */
    private function applyTerm(Builder $query, string $term): void
    {
        $query->where('name', 'like', '%'.$term.'%')
            ->orWhere('code', 'like', $term.'%');

        // A run of digits is almost always a phone, not a name. Matching the
        // tail lets her type the last four digits she has on screen.
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        if (strlen($digits) >= 4) {
            $query->orWhere('phone', 'like', '%'.$digits);
        }
    }
}
