<div class="space-y-6">
    <x-modal name="capacity-check-error" :show="$errors->isNotEmpty()" focusable>
        <div class="p-6">
            <div class="flex items-start gap-3">
                <x-icon name="warning" class="h-6 w-6 text-red-600 shrink-0" />
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Error</h2>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-400 space-y-1">
                        @foreach ($errors->all() as $message)
                            <p>{{ $message }}</p>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end">
                <x-btn variant="secondary" x-on:click="$dispatch('close')">Close</x-btn>
            </div>
        </div>
    </x-modal>

    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Current Strategy</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    For each slot, using this session's saved seating strategy: students needing seats, rooms needed (from real room capacities) vs. active, and teachers needed (rooms &times; invigilators/room) vs. available.
                </p>
            </div>
            <x-btn wire:click="checkCurrent" wire:loading.attr="disabled" wire:target="checkCurrent" variant="secondary" icon="search">
                <span wire:loading.remove wire:target="checkCurrent">Check Capacity</span>
                <span wire:loading wire:target="checkCurrent">Checking&hellip;</span>
            </x-btn>
        </div>

        @if ($showCurrent)
            <x-requirement-table :requirements="$currentRequirements" />
        @endif
    </x-card>

    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Slot Capacity Simulator</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Automatically packs every enrolled subject into as few simultaneous slots as possible &mdash; as full as the active rooms allow, and never two subjects that share a student in the same slot. Works straight from the enrollment sheet &mdash; no timetable required yet. Every section of a subject always lands in the same simulated slot, same as the real timetable.
                </p>
            </div>
        </div>

        <div class="mt-4 flex items-end gap-4 flex-wrap">
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Slots per day</label>
                <input type="number" min="1" wire:model="slotsPerDay" class="mt-1 block w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('slotsPerDay') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
            </div>
            <x-btn wire:click="simulateSlots" wire:loading.attr="disabled" wire:target="simulateSlots" icon="search">
                <span wire:loading.remove wire:target="simulateSlots">Simulate</span>
                <span wire:loading wire:target="simulateSlots">Simulating&hellip;</span>
            </x-btn>
        </div>

        @if ($showSlotSimulation)
            @if ($slotRequirements->isEmpty())
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">No enrollments yet &mdash; upload the enrollment sheet first.</p>
            @else
                <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $slotRequirements->count() }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Slots needed in total</div>
                    </div>
                    <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $daysNeeded }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Days needed at {{ $slotsPerDay }}/day</div>
                    </div>
                    <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg">
                        <div class="text-2xl font-semibold text-indigo-700 dark:text-indigo-300">{{ $peakRoomsNeeded }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Peak rooms needed (busiest slot)</div>
                    </div>
                    <div class="p-3 bg-indigo-50 dark:bg-indigo-900/30 rounded-lg">
                        <div class="text-2xl font-semibold text-indigo-700 dark:text-indigo-300">{{ $peakTeachersNeeded }}</div>
                        <div class="text-xs text-gray-500 dark:text-gray-400">Peak teachers needed (busiest slot)</div>
                    </div>
                </div>

                <x-requirement-table :requirements="$slotRequirements" />
            @endif
        @endif
    </x-card>
</div>
