<div>
    <div class="flex items-center justify-between mb-4 gap-4 flex-wrap">
        <p class="text-sm text-gray-500 dark:text-gray-400">Every exam in this session will be assigned to one of these slots.</p>
        @if (! $showForm)
            <x-btn wire:click="addSlot" size="sm" icon="plus">Add Time Slot</x-btn>
        @endif
    </div>

    @if ($showForm)
        <form wire:submit="save" class="mb-4 grid grid-cols-1 sm:grid-cols-4 gap-4 border-b border-gray-100 dark:border-gray-700 pb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date</label>
                <input type="date" wire:model="date" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Time</label>
                <input type="time" wire:model="start_time" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('start_time') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">End Time</label>
                <input type="time" wire:model="end_time" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('end_time') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Label (optional)</label>
                <input type="text" wire:model="label" placeholder="Morning" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
            </div>
            <div class="sm:col-span-4 flex items-center gap-3">
                <x-btn type="submit" icon="check">Save Slot</x-btn>
                <x-btn type="button" variant="ghost" wire:click="cancel">Cancel</x-btn>
            </div>
        </form>
    @endif

    @if ($slots->isEmpty())
        <x-empty-state icon="calendar" title="No time slots yet" description="Add the slots exams in this session can be scheduled into." />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <th class="py-2 pr-4">Date</th>
                        <th class="py-2 pr-4">Day</th>
                        <th class="py-2 pr-4">Time</th>
                        <th class="py-2 pr-4">Label</th>
                        <th class="py-2 pr-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($slots as $slot)
                        <tr>
                            <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $slot->date->format('d M Y') }}</td>
                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $slot->day_name }}</td>
                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ substr($slot->start_time, 0, 5) }} &ndash; {{ substr($slot->end_time, 0, 5) }}</td>
                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $slot->label }}</td>
                            <td class="py-2 pr-4 text-right space-x-3 whitespace-nowrap">
                                <button type="button" wire:click="editSlot({{ $slot->id }})" class="text-sm font-medium text-indigo-600 hover:underline">Edit</button>
                                <button type="button" wire:click="deleteSlot({{ $slot->id }})" wire:confirm="Delete this time slot?" class="text-sm font-medium text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
