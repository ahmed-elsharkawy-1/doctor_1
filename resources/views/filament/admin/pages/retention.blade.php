<x-filament-panels::page>
    <div class="fi-section rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <label for="retention-period" class="block text-sm font-medium text-gray-950 dark:text-white">
            {{ __('reports.period.label') }}
        </label>

        <select
            id="retention-period"
            wire:model.live="period"
            class="mt-2 block w-full max-w-xs rounded-lg border-none bg-white text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm"
        >
            @foreach ($this->periodOptions() as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>
</x-filament-panels::page>
