<div class="space-y-6">
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
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">What If: Combine Subjects Per Room</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Try a number of subjects sharing one room per slot (like the Mixed strategy) and see the resulting rooms/teachers needed &mdash; without changing this session's actual seating strategy.
                </p>
            </div>
        </div>

        <div class="mt-4 flex items-end gap-3 flex-wrap">
            <div class="flex items-center gap-2">
                @foreach ([2, 3, 4] as $n)
                    <button type="button" wire:click="$set('whatIfSubjectsPerRoom', {{ $n }})"
                        @class([
                            'px-3 py-1.5 text-sm font-medium rounded-lg border',
                            'bg-indigo-600 text-white border-indigo-600' => $whatIfSubjectsPerRoom === $n,
                            'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-300 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700' => $whatIfSubjectsPerRoom !== $n,
                        ])>
                        {{ $n }} subjects
                    </button>
                @endforeach
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400">Or any other number</label>
                <input type="number" min="2" max="50" wire:model="whatIfSubjectsPerRoom" class="mt-1 block w-28 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('whatIfSubjectsPerRoom') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
            </div>
            <x-btn wire:click="checkWhatIf" wire:loading.attr="disabled" wire:target="checkWhatIf" icon="search">
                <span wire:loading.remove wire:target="checkWhatIf">Check This Number</span>
                <span wire:loading wire:target="checkWhatIf">Checking&hellip;</span>
            </x-btn>
        </div>

        @if ($showWhatIf)
            <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">Simulating {{ $whatIfSubjectsPerRoom }} subjects per room:</p>
            <x-requirement-table :requirements="$whatIfRequirements" />
        @endif
    </x-card>
</div>
