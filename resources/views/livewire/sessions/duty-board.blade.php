<x-slot name="header">
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Duties') }} &mdash; {{ $examSession->name }}
        </h2>
        <a href="{{ route('sessions.generate', $examSession) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">&larr; Back to Generation</a>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if (session('status'))
            <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        @if ($slots->isEmpty())
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg text-center text-sm text-gray-500 dark:text-gray-400">
                No duties generated yet.
            </div>
        @else
            <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-2 flex-wrap">
                        @foreach ($slots as $slot)
                            <button type="button" wire:click="selectSlot({{ $slot->id }})"
                                @class([
                                    'px-3 py-1.5 text-sm rounded-md whitespace-nowrap',
                                    'bg-indigo-600 text-white' => $activeSlotId === $slot->id,
                                    'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' => $activeSlotId !== $slot->id,
                                ])>
                                {{ $slot->date->format('d M') }} {{ substr($slot->start_time, 0, 5) }}
                            </button>
                        @endforeach
                    </div>
                    <button type="button" wire:click="regenerate" wire:loading.attr="disabled" wire:confirm="Regenerate duties for this whole session? Locked duties are left untouched." class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700 disabled:opacity-50 whitespace-nowrap">
                        <span wire:loading.remove wire:target="regenerate">Regenerate Duties</span>
                        <span wire:loading wire:target="regenerate">Regenerating&hellip;</span>
                    </button>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Reassign a room's invigilator with the dropdown &mdash; it locks automatically so regeneration won't change it back. Click the lock icon to lock or unlock a duty by hand.
                </p>
            </div>

            @if ($activeSlotId)
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    @foreach ($rooms as $entry)
                        @php $room = $entry['room']; @endphp
                        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
                            <div class="bg-gray-50 dark:bg-gray-900/50 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ $room->name }}
                            </div>
                            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                @foreach ($entry['rows'] as $row)
                                    @php $duty = $row['duty']; @endphp
                                    <div class="px-4 py-2 flex items-center justify-between gap-3">
                                        @if ($duty->is_locked)
                                            <span class="flex-1 text-sm text-gray-900 dark:text-gray-100">{{ $duty->teacher->name }}</span>
                                        @else
                                            <select wire:change="reassignDuty({{ $duty->id }}, $event.target.value)" class="flex-1 rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                                                @foreach ($row['options'] as $option)
                                                    <option value="{{ $option->id }}" @selected($option->id === $duty->teacher_id)>{{ $option->name }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        <button type="button" wire:click="toggleDutyLock({{ $duty->id }})" @class(['text-gray-400 hover:text-gray-700 dark:hover:text-gray-200', 'text-amber-600 dark:text-amber-400' => $duty->is_locked]) title="{{ $duty->is_locked ? 'Unlock' : 'Lock' }} this duty">
                                            {!! $duty->is_locked ? '&#128274;' : '&#128275;' !!}
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </div>
</div>
