<x-slot name="header">
    <x-page-header
        :title="$item->name.' — availability'"
        :subtitle="$examSession->name.' — choose the time slots this '.$label.' can be used in.'"
        icon="calendar"
        :back="$backRoute" />
</x-slot>

<div class="space-y-6">
@if (session('error'))
    <div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg text-sm">
        {{ session('error') }}
    </div>
@endif

<x-finalized-banner :session="$examSession" />

@if ($excluded)
    <div class="p-4 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-300 rounded-lg text-sm">
        This teacher is excluded from the whole session (see the Teachers tab), so these slots don't matter until that's switched off.
    </div>
@endif

<x-card :padded="false">
    <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Available slots</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Ticked means this {{ $label }} can be used in that slot. Untick a slot to switch it off &mdash; for example, free on Monday except the 2nd slot. Changes save as you click.
            </p>
            <p class="mt-2 text-sm">
                <span class="font-medium {{ count($off) > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-gray-700 dark:text-gray-300' }}">{{ count($off) }} of {{ $slotCount }} slot(s) unavailable</span>
            </p>
        </div>
        @if (count($off) > 0)
            <x-btn wire:click="makeAllAvailable" wire:confirm="Make this {{ $label }} available for every slot again?" variant="secondary" size="sm">Make all available</x-btn>
        @endif
    </div>

    @if ($days->isEmpty())
        <x-empty-state icon="calendar" title="No time slots yet" description="Add the session's time slots first — then you can switch this {{ $label }} off for individual ones." />
    @else
        @foreach ($days as $date => $slots)
            @php
                $carbon = \Illuminate\Support\Carbon::parse($date);
                $dayOff = in_array($carbon->dayOfWeekIso, $weekdaysOff, true);
                $allOff = $slots->every(fn ($slot) => in_array($slot->id, $off, true));
            @endphp
            <div class="border-b border-gray-100 dark:border-gray-700 last:border-b-0">
                <div class="px-4 sm:px-6 py-3 bg-gray-50 dark:bg-gray-900/40 flex items-center justify-between gap-3 flex-wrap">
                    <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-200">{{ $carbon->format('l, d M Y') }}</h4>
                    @if ($dayOff)
                        <span class="text-xs text-gray-500 dark:text-gray-400">Off every {{ $carbon->format('l') }} &mdash; set on the Teachers tab</span>
                    @else
                        <button type="button" wire:click="toggleDay('{{ $date }}')" class="text-xs font-medium text-indigo-600 hover:underline">
                            {{ $allOff ? 'Make the whole day available' : 'Mark the whole day unavailable' }}
                        </button>
                    @endif
                </div>

                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($slots as $slot)
                        @php $isOff = $dayOff || in_array($slot->id, $off, true); @endphp
                        <label @class([
                            'px-4 sm:px-6 py-3 flex items-center gap-3',
                            'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-900/30' => ! $dayOff,
                            'opacity-60 cursor-not-allowed' => $dayOff,
                        ])>
                            <input type="checkbox" wire:click="toggleSlot({{ $slot->id }})" @checked(! $isOff) @disabled($dayOff) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            <span class="text-sm font-medium text-gray-900 dark:text-gray-100">Slot {{ $loop->iteration }}</span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">{{ substr($slot->start_time, 0, 5) }} &ndash; {{ substr($slot->end_time, 0, 5) }}{{ $slot->label ? ' ('.$slot->label.')' : '' }}</span>
                            @if ($isOff)
                                <x-badge color="yellow">Unavailable</x-badge>
                            @endif
                        </label>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif
</x-card>
</div>
