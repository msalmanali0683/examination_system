<x-slot name="header">
    <x-page-header title="Duties" :subtitle="$examSession->name" icon="clipboard" :back="route('sessions.generate', $examSession)" />
</x-slot>

<div class="space-y-6">
<x-modal name="teacher-duty-details" :show="$teacherDutyDetails !== null" focusable max-width="lg">
    @if ($teacherDutyDetails)
        <div class="p-6">
            <div class="flex items-start gap-3">
                <x-icon name="clipboard" class="h-6 w-6 text-indigo-600 shrink-0" />
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $teacherDutyDetails['teacherName'] }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ count($teacherDutyDetails['duties']) }} duty/duties this session:</p>
                </div>
            </div>
            <div class="mt-4 max-h-96 overflow-y-auto">
                <ul class="text-sm text-gray-700 dark:text-gray-300 divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-lg overflow-hidden">
                    @foreach ($teacherDutyDetails['duties'] as $duty)
                        <li class="px-3 py-2 flex items-center justify-between gap-2">
                            <span>{{ $duty['date'] }}, {{ $duty['time'] }}</span>
                            <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                                {{ $duty['room'] }}
                                @if ($duty['locked'])
                                    <span title="Locked">&#128274;</span>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="mt-6 flex justify-end">
                <x-btn variant="secondary" wire:click="$set('teacherDutyDetails', null)" x-on:click="$dispatch('close')">Close</x-btn>
            </div>
        </div>
    @endif
</x-modal>

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
    <x-card>
        <x-empty-state icon="clipboard" title="No duties generated yet" description="Generate seating first — duties are assigned to the rooms actually in use each slot." />
        <div class="mt-4 flex justify-center gap-3">
            <x-btn :href="route('sessions.seating', $examSession)" wire:navigate variant="secondary" icon="grid">Seating</x-btn>
            <x-btn wire:click="regenerate" wire:loading.attr="disabled" icon="refresh">
                <span wire:loading.remove wire:target="regenerate">Generate Duties</span>
                <span wire:loading wire:target="regenerate">Generating&hellip;</span>
            </x-btn>
        </div>
    </x-card>
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

    @if ($dutyFairness->isNotEmpty())
        <x-card>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Duty Fairness</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">How duties are spread across active teachers this session.</p>
            <div class="mt-4 max-h-96 overflow-y-auto overflow-x-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <th class="py-2 px-3">Teacher</th>
                            <th class="py-2 px-3">Duties</th>
                            <th class="py-2 px-3">Min / Max</th>
                            <th class="py-2 px-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($dutyFairness as $row)
                            <tr>
                                <td class="py-2 px-3">
                                    @if ($row->count > 0)
                                        <button type="button" wire:click="showTeacherDuties({{ $row->teacher->id }})" class="text-indigo-600 dark:text-indigo-400 hover:underline">{{ $row->teacher->name }}</button>
                                    @else
                                        <span class="text-gray-900 dark:text-gray-100">{{ $row->teacher->name }}</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-500 dark:text-gray-400">{{ $row->count }}</td>
                                <td class="py-2 px-3 text-gray-500 dark:text-gray-400">{{ $row->min }} / {{ $row->max }}</td>
                                <td class="py-2 px-3">
                                    @if ($row->excluded)
                                        <x-badge color="gray">Excluded</x-badge>
                                    @elseif ($row->met)
                                        <x-badge color="green">OK</x-badge>
                                    @else
                                        <x-badge color="red">Below Min</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
@endif
</div>
