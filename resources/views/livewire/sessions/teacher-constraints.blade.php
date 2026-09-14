<div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Exclude a teacher from duty this session, or override their min/max duty count. Leave blank to use the default
        (min {{ config('exam.default_min_duties') }}, max {{ config('exam.default_max_duties') }}).
    </p>

    @if (session('status'))
        <div class="mb-4 p-3 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 text-sm rounded-lg">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 text-sm rounded-lg">
            {{ session('error') }}
        </div>
    @endif

    @if ($teachers->isNotEmpty())
        <div class="mb-4 p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg flex items-center gap-4 flex-wrap">
            <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Set for every teacher:</span>
            <div class="flex items-center gap-1">
                <label class="text-xs text-gray-500 dark:text-gray-400">Min</label>
                <input type="number" min="0" wire:model="bulkMinDuties" placeholder="{{ config('exam.default_min_duties') }}" class="w-16 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
            </div>
            <div class="flex items-center gap-1">
                <label class="text-xs text-gray-500 dark:text-gray-400">Max</label>
                <input type="number" min="0" wire:model="bulkMaxDuties" placeholder="{{ config('exam.default_max_duties') }}" class="w-16 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
            </div>
            <button type="button" wire:click="applyBulkDuties" wire:confirm="Apply these min/max duties to every teacher in this session, overwriting their current values?" class="px-3 py-1.5 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                Apply to All
            </button>
        </div>
    @endif

    @if ($teachers->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No active teachers yet &mdash; add some from the <a href="{{ route('teachers.index') }}" wire:navigate class="text-indigo-600 hover:underline">Teachers</a> page.</p>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($teachers as $teacher)
                @php
                    $constraint = $constraints->get($teacher->id);
                    $unavailableDays = $constraint?->unavailable_days ?? [];
                @endphp
                <div class="py-3 space-y-2">
                    <div class="flex items-center justify-between gap-4 flex-wrap">
                        <div>
                            <span class="font-medium text-gray-900 dark:text-gray-100">{{ $teacher->name }}</span>
                            @if ($teacher->department)
                                <span class="text-xs text-gray-400 ml-1">({{ $teacher->department }})</span>
                            @endif
                        </div>

                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                                <input type="checkbox" wire:click="toggleExcluded({{ $teacher->id }})" @checked($constraint?->is_excluded) class="rounded border-gray-300">
                                Exclude
                            </label>

                            <div class="flex items-center gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">Min</label>
                                <input type="number" min="0" value="{{ $constraint?->min_duties }}"
                                    placeholder="{{ config('exam.default_min_duties') }}"
                                    wire:change="updateMinDuties({{ $teacher->id }}, $event.target.value)"
                                    class="w-16 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            </div>

                            <div class="flex items-center gap-1">
                                <label class="text-xs text-gray-500 dark:text-gray-400">Max</label>
                                <input type="number" min="0" value="{{ $constraint?->max_duties }}"
                                    placeholder="{{ config('exam.default_max_duties') }}"
                                    wire:change="updateMaxDuties({{ $teacher->id }}, $event.target.value)"
                                    class="w-16 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="text-xs text-gray-400">Available:</span>
                        @foreach ($this->days() as $iso => $label)
                            <label class="flex items-center gap-1 text-xs text-gray-600 dark:text-gray-300">
                                <input type="checkbox"
                                    wire:click="toggleDayAvailable({{ $teacher->id }}, {{ $iso }})"
                                    @checked(! in_array($iso, $unavailableDays))
                                    class="rounded border-gray-300 h-3.5 w-3.5">
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
