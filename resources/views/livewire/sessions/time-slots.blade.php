<div>
    <div class="flex items-center justify-between mb-4 gap-4 flex-wrap">
        <p class="text-sm text-gray-500 dark:text-gray-400">Every exam in this session will be assigned to one of these slots.</p>
        @if (! $examSession->isFinalized() && ! $showForm && ! $showBulkForm)
            <div class="flex items-center gap-2">
                <x-btn wire:click="startBulkGenerate" size="sm" variant="secondary" icon="lightning">Bulk Generate</x-btn>
                <x-btn wire:click="addSlot" size="sm" icon="plus">Add Time Slot</x-btn>
            </div>
        @endif
    </div>

    @if ($showBulkForm)
        <form wire:submit="generateBulkSlots" class="mb-6 space-y-5 border-b border-gray-100 dark:border-gray-700 pb-6">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Slots per day</label>
                    <input type="number" min="1" max="10" wire:model.live="bulkSlotsPerDay" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('bulkSlotsPerDay') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start date</label>
                    <input type="date" wire:model="bulkStartDate" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('bulkStartDate') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Number of exam dates</label>
                    <input type="number" min="1" max="60" wire:model="bulkDateCount" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    @error('bulkDateCount') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Slot timings <span class="text-xs text-gray-400 font-normal">(applied to every generated date)</span></label>
                <div class="space-y-2">
                    @for ($i = 0; $i < $bulkSlotsPerDay; $i++)
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-gray-400 w-14 shrink-0">Slot {{ $i + 1 }}</span>
                            <input type="time" wire:model="bulkSlotTimes.{{ $i }}.start" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                            <span class="text-gray-400 text-sm">&ndash;</span>
                            <input type="time" wire:model="bulkSlotTimes.{{ $i }}.end" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        </div>
                        @error("bulkSlotTimes.{$i}.start") <p class="text-sm text-red-600 ml-[4.5rem]">{{ $message }}</p> @enderror
                        @error("bulkSlotTimes.{$i}.end") <p class="text-sm text-red-600 ml-[4.5rem]">{{ $message }}</p> @enderror
                    @endfor
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Skip these days of the week <span class="text-xs text-gray-400 font-normal">(optional)</span></label>
                <div class="flex items-center gap-3 flex-wrap">
                    @foreach ($this->days() as $iso => $label)
                        <label class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                            <input type="checkbox" wire:click="toggleBulkSkipDay({{ $iso }})" @checked(in_array($iso, $bulkSkipDays)) class="rounded border-gray-300 h-3.5 w-3.5 text-indigo-600 focus:ring-indigo-500">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
                @error('bulkSkipDays') <p class="text-sm text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center gap-3">
                @if ($slots->isNotEmpty())
                    <x-btn type="submit" icon="lightning" wire:confirm="This replaces all {{ $slots->count() }} existing time slot(s) for this session — any seating and duties already generated from them will be deleted too, and the session drops back to draft. Continue?">
                        Generate Time Slots
                    </x-btn>
                @else
                    <x-btn type="submit" icon="lightning">Generate Time Slots</x-btn>
                @endif
                <x-btn type="button" variant="ghost" wire:click="cancelBulkGenerate">Cancel</x-btn>
            </div>
        </form>
    @endif

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
        <x-empty-state icon="calendar" title="No time slots yet" description="Add slots one at a time, or use Bulk Generate to lay out a whole exam schedule at once." />
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
                                @unless ($examSession->isFinalized())
                                    <button type="button" wire:click="editSlot({{ $slot->id }})" class="text-sm font-medium text-indigo-600 hover:underline">Edit</button>
                                    <button type="button" wire:click="deleteSlot({{ $slot->id }})" wire:confirm="Delete this time slot?" class="text-sm font-medium text-red-600 hover:underline">Delete</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
