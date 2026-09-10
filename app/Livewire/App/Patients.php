<?php

namespace App\Livewire\App;

use App\Models\Patient;
use App\Services\V1\Patients\PatientSearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * Looking a patient up — the thing the secretary does most.
 *
 * The matching rules are PatientSearchService's, the same ones the mobile
 * app's search runs through: a name fragment, an ID code, or the tail of a
 * phone number.
 */
class Patients extends ClinicComponent
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $term = '';

    public function mount(): void
    {
        $this->requireAbility('patients.view');
    }

    public function render(): View
    {
        return view('livewire.app.patients', [
            'patients' => $this->results(),
        ])->title(__('app.patients.title'));
    }

    /**
     * Typing restarts the list, or page two of the previous term shows.
     */
    public function updatedTerm(): void
    {
        $this->resetPage();
    }

    public function clear(): void
    {
        $this->term = '';
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Patient>
     */
    private function results(): LengthAwarePaginator
    {
        $this->requireAbility('patients.view');

        return app(PatientSearchService::class)->search(
            $this->clinic(),
            $this->term,
            config('clinic.api.pagination.per_page'),
        );
    }
}
