<x-slot name="header">
    <x-page-header title="Duties" :subtitle="$examSession->name" icon="clipboard" :back="route('sessions.generate', $examSession)" />
</x-slot>

<div class="space-y-6">
@if (session('status'))
    <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg text-sm">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg text-sm">
        {{ session('error') }}
    </div>
@endif

<x-finalized-banner :session="$examSession" />

@if ($slots->isEmpty())
    <x-empty-state icon="clipboard" title="No duties generated yet" description="Run duty generation from the Generation Constraints page first." />
@else
    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-2 flex-wrap">
                @foreach ($slots as $slot)
                    <button type="button" wire:click="selectSlot({{ $slot->id }})"
                        @class([
                            'px-3 py-1.5 text-sm font-medium rounded-lg whitespace-nowrap transition',
                            'bg-indigo-600 text-white shadow-sm' => $activeSlotId === $slot->id,
                            'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' => $activeSlotId !== $slot->id,
                        ])>
                        {{ $slot->date->format('d M') }} {{ substr($slot->start_time, 0, 5) }}
                    </button>
                @endforeach
            </div>
            <x-btn wire:click="regenerate" wire:loading.attr="disabled" wire:confirm="Regenerate duties for this whole session? Locked duties are left untouched." variant="dark" icon="refresh">
                <span wire:loading.remove wire:target="regenerate">Regenerate Duties</span>
                <span wire:loading wire:target="regenerate">Regenerating&hellip;</span>
            </x-btn>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
            <x-icon name="info" class="h-3.5 w-3.5 shrink-0" />
            Reassign a room's invigilator with the dropdown &mdash; it locks automatically so regeneration won't change it back. Click the lock icon to lock or unlock a duty by hand.
        </p>
    </x-card>

    @if ($activeSlotId)
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            @foreach ($rooms as $entry)
                @php $room = $entry['room']; @endphp
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-200 dark:ring-gray-700/60 overflow-hidden">
                    <div class="bg-gray-50 dark:bg-gray-900/50 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center gap-1.5 border-b border-gray-100 dark:border-gray-700">
                        <x-icon name="door" class="h-4 w-4 text-gray-400" /> {{ $room->name }}
                    </div>
                    <div class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($entry['rows'] as $row)
                            @php $duty = $row['duty']; @endphp
                            <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                                @if ($duty->is_locked)
                                    <span class="flex-1 text-sm text-gray-900 dark:text-gray-100">{{ $duty->teacher->name }}</span>
                                @else
                                    <select wire:change="reassignDuty({{ $duty->id }}, $event.target.value)" class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
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
