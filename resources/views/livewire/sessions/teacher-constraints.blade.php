<div>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Exclude a teacher from duty this session, or override their min/max duty count. Leave blank to use the default
        (min {{ config('exam.default_min_duties') }}, max {{ config('exam.default_max_duties') }}).
    </p>

    @if ($teachers->isEmpty())
        <p class="text-sm text-gray-500 dark:text-gray-400">No active teachers yet &mdash; add some from the <a href="{{ route('teachers.index') }}" wire:navigate class="text-indigo-600 hover:underline">Teachers</a> page.</p>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($teachers as $teacher)
                @php $constraint = $constraints->get($teacher->id); @endphp
                <div class="py-3 flex items-center justify-between gap-4 flex-wrap">
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
            @endforeach
        </div>
    @endif
</div>
