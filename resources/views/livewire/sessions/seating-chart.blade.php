<x-slot name="header">
    <x-page-header title="Seating" :subtitle="$examSession->name" icon="grid" :back="route('sessions.generate', $examSession)" />
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

@if ($slots->isEmpty())
    <x-empty-state icon="grid" title="No seating generated yet" description="Run seat generation from the Generation Constraints page first." />
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
            <x-btn wire:click="regenerate" wire:loading.attr="disabled" wire:confirm="Regenerate seating for this whole session? Locked seats are left untouched." variant="dark" icon="refresh">
                <span wire:loading.remove wire:target="regenerate">Regenerate Seating</span>
                <span wire:loading wire:target="regenerate">Regenerating&hellip;</span>
            </x-btn>
        </div>
        <p class="mt-3 text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
            <x-icon name="info" class="h-3.5 w-3.5 shrink-0" />
            Drag a student card to an empty seat to move them &mdash; the seat locks automatically so regeneration won't undo it. Click the lock icon to lock or unlock a seat by hand.
        </p>
    </x-card>

    @if ($activeSlotId)
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
            @foreach ($rooms as $entry)
                @php $room = $entry['room']; @endphp
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-200 dark:ring-gray-700/60 overflow-hidden" wire:key="room-{{ $room->id }}">
                    <div class="bg-gray-50 dark:bg-gray-900/50 px-4 py-2.5 text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
                        <span class="flex items-center gap-1.5"><x-icon name="door" class="h-4 w-4 text-gray-400" /> {{ $room->name }}</span>
                        <span class="text-xs text-gray-400">{{ $entry['seatedCount'] }} / {{ $room->capacity }} seated</span>
                    </div>
                    <div class="p-3 overflow-x-auto">
                        <div
                            x-data
                            x-init="initSeatGrid($el, {{ $activeSlotId }})"
                            wire:key="grid-{{ $room->id }}"
                            class="inline-grid gap-1"
                            style="grid-template-columns: repeat({{ $room->columns }}, minmax(3.75rem, 1fr));"
                        >
                            @for ($row = 1; $row <= $room->rows; $row++)
                                @for ($col = 1; $col <= $room->columns; $col++)
                                    @php $seat = $entry['grid'][$row][$col] ?? null; @endphp
                                    <div
                                        class="seat-cell min-h-[3rem] rounded border border-dashed border-gray-200 dark:border-gray-700"
                                        wire:key="cell-{{ $room->id }}-{{ $row }}-{{ $col }}"
                                        data-room-id="{{ $room->id }}"
                                        data-row="{{ $row }}"
                                        data-column="{{ $col }}"
                                    >
                                        @if ($seat)
                                            <div
                                                @class([
                                                    'seat-card' => ! $seat->is_locked,
                                                    'seat-card-locked' => $seat->is_locked,
                                                    'h-full rounded p-1 text-[11px] leading-tight cursor-move select-none border',
                                                    'bg-indigo-50 dark:bg-indigo-900/40 border-indigo-200 dark:border-indigo-800' => ! $seat->is_locked,
                                                    'bg-amber-50 dark:bg-amber-900/30 border-amber-300 dark:border-amber-700 cursor-default' => $seat->is_locked,
                                                ])
                                                data-enrollment-id="{{ $seat->enrollment_id }}"
                                                title="{{ $seat->enrollment->student->name }} &mdash; {{ $seat->enrollment->subject->code }}"
                                            >
                                                <div class="flex items-center justify-between gap-1">
                                                    <span class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $seat->enrollment->student->roll_no }}</span>
                                                    <button type="button" wire:click="toggleLock({{ $seat->id }})" class="seat-lock-btn shrink-0 text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="{{ $seat->is_locked ? 'Unlock' : 'Lock' }} this seat">
                                                        {!! $seat->is_locked ? '&#128274;' : '&#128275;' !!}
                                                    </button>
                                                </div>
                                                <div class="text-gray-500 dark:text-gray-400 truncate">{{ $seat->enrollment->subject->code }}</div>
                                            </div>
                                        @endif
                                    </div>
                                @endfor
                            @endfor
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endif
</div>
