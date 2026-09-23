<x-slot name="header">
    <x-page-header title="Timetable" :subtitle="$examSession->name" icon="calendar" :back="route('sessions.generate', $examSession)" />
</x-slot>

<div class="space-y-6">
<x-modal name="generation-error" :show="session('error') !== null" focusable>
    <div class="p-6">
        <div class="flex items-start gap-3">
            <x-icon name="warning" class="h-6 w-6 text-red-600 shrink-0" />
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Error</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ session('error') }}</p>
            </div>
        </div>
        <div class="mt-6 flex justify-end">
            <x-btn variant="secondary" x-on:click="$dispatch('close')">Close</x-btn>
        </div>
    </div>
</x-modal>

<x-modal name="clash-details" :show="$clashDetails !== null" focusable max-width="lg">
    @if ($clashDetails)
        <div class="p-6">
            <div class="flex items-start gap-3">
                <x-icon name="warning" class="h-6 w-6 text-yellow-600 shrink-0" />
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $clashDetails['subjectLabel'] }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Shares students with the following on {{ $clashDetails['day'] }}:</p>
                </div>
            </div>
            <div class="mt-4 max-h-96 overflow-y-auto space-y-4">
                @foreach ($clashDetails['pairs'] as $pair)
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $pair['subjectLabel'] }}</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ count($pair['students']) }} shared student{{ count($pair['students']) === 1 ? '' : 's' }}</p>
                        <ul class="text-sm text-gray-700 dark:text-gray-300 divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-lg overflow-hidden">
                            @foreach ($pair['students'] as $student)
                                <li class="px-3 py-1.5 flex justify-between gap-2">
                                    <span>{{ $student['name'] }}</span>
                                    <span class="text-gray-400 dark:text-gray-500">{{ $student['rollNo'] }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            <div class="mt-6 flex justify-end">
                <x-btn variant="secondary" wire:click="$set('clashDetails', null)" x-on:click="$dispatch('close')">Close</x-btn>
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

<x-card :padded="false">
    <div class="p-4 sm:p-6 flex items-center justify-between gap-4 flex-wrap border-b border-gray-100 dark:border-gray-700">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Pin Subjects to Slots</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Optional. Everything else is placed automatically, clash-free where possible.</p>
            <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">A day another subject already shares students with, or that already has another subject from the same semester, shows a &#9888; clash warning &mdash; every day stays pickable, since two papers on one day is sometimes unavoidable.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-btn wire:click="removeAllSlots" wire:loading.attr="disabled" wire:confirm="Remove every subject's assigned slot? Pins and slot assignments will be cleared for the whole session." variant="secondary" icon="trash">
                <span wire:loading.remove wire:target="removeAllSlots">Remove All Slots</span>
                <span wire:loading wire:target="removeAllSlots">Removing&hellip;</span>
            </x-btn>
            <x-btn wire:click="generateTimetable" wire:loading.attr="disabled" wire:confirm="Regenerate the timetable? Pinned subjects are left untouched; everything else will be recomputed." icon="refresh">
                <span wire:loading.remove wire:target="generateTimetable">Generate Timetable</span>
                <span wire:loading wire:target="generateTimetable">Generating&hellip;</span>
            </x-btn>
        </div>
    </div>

    <div class="p-4 sm:p-6">
        @if ($timeSlots->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No time slots yet &mdash; add some on the session's Time Slots tab first.</p>
        @elseif ($subjects->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No enrollments yet &mdash; import enrollments first.</p>
        @else
            <div class="overflow-x-auto -mx-4 sm:-mx-6">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            @can('manage_subjects')
                                <th class="py-2 pl-4 sm:pl-6 pr-2 w-8" title="Select subjects to merge"></th>
                            @endcan
                            <th class="py-2 pr-4 {{ auth()->user()->can('manage_subjects') ? '' : 'pl-4 sm:pl-6' }}">Subject</th>
                            <th class="py-2 pr-4">Semester</th>
                            <th class="py-2 pr-4">Students</th>
                            <th class="py-2 pr-4">Assigned Slot</th>
                            <th class="py-2 pr-4">Pin</th>
                            <th class="py-2 pr-4">Duty = Sections Taught</th>
                            <th class="py-2 pr-4 sm:pr-6">Exclude</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($subjects as $subject)
                            @php
                                $assignment = $assignments->get($subject->id);
                                $sections = $sectionBreakdown->get($subject->id, collect());
                                $semesters = $semesterBySubject->get($subject->id, collect());
                                $excluded = (bool) $assignment?->is_excluded;
                                $semesterColors = ['blue', 'green', 'indigo', 'yellow', 'gray'];
                            @endphp
                            <tr @class(['hover:bg-gray-50 dark:hover:bg-gray-900/30', 'opacity-50' => $excluded])>
                                @can('manage_subjects')
                                    <td class="py-2.5 pl-4 sm:pl-6 pr-2">
                                        <input type="checkbox" wire:model.live="mergeSelected" value="{{ $subject->id }}" title="Select to merge with another subject below" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                    </td>
                                @endcan
                                <td class="py-2.5 pr-4 text-gray-900 dark:text-gray-100 {{ auth()->user()->can('manage_subjects') ? '' : 'pl-4 sm:pl-6' }}">
                                    {{ $subject->code }} &mdash; {{ $subject->title }}
                                    @if ($sections->count() > 1)
                                        <div class="text-xs text-gray-400 font-normal mt-0.5">
                                            {{ $sections->map(fn ($c, $section) => "{$section}: {$c}")->implode(', ') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    @forelse ($semesters as $semester)
                                        <x-badge :color="$semesterColors[((int) $semester - 1) % count($semesterColors)]">{{ $semester }}</x-badge>
                                    @empty
                                        <span class="text-gray-400">&mdash;</span>
                                    @endforelse
                                </td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $subject->enrollments_count }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">
                                    @if ($excluded)
                                        <x-badge color="red">Excluded</x-badge>
                                    @elseif ($assignment?->timeSlot)
                                        {{ $assignment->timeSlot->date->format('d M') }} {{ substr($assignment->timeSlot->start_time, 0, 5) }}
                                        @if ($assignment->conflict_note)
                                            @php $isBlockingClash = \App\Services\Generation\ConflictNoteClassifier::isBlockingClash($assignment->conflict_note); @endphp
                                            <button type="button" wire:click="showClashDetails({{ $subject->id }})" title="Click to see the students causing this {{ $isBlockingClash ? 'clash' : 'alert' }}" @class(['underline decoration-dotted', 'text-yellow-600 dark:text-yellow-400 hover:text-yellow-800 dark:hover:text-yellow-300' => $isBlockingClash, 'text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300' => ! $isBlockingClash])>&#9888;</button>
                                        @endif
                                    @else
                                        <span class="text-gray-400">Not yet generated</span>
                                    @endif
                                </td>
                                <td class="py-2.5 pr-4">
                                    <div class="flex items-center gap-1.5">
                                        <select wire:change="updatePin({{ $subject->id }}, $event.target.value)" @disabled($excluded) class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm disabled:opacity-50">
                                            <option value="">Auto</option>
                                            @foreach ($timeSlots as $slot)
                                                @php
                                                    $alreadyThere = $assignment?->time_slot_id === $slot->id;
                                                    $used = $seatsUsedPerSlot->get($slot->id, 0);
                                                    $projected = $alreadyThere ? $used : $used + $subject->enrollments_count;
                                                    $wouldClashExactSlot = $clashingSlotsBySubject->get($subject->id, collect())->contains($slot->id);
                                                    $wouldAlertSameDay = ! $wouldClashExactSlot && $clashingDaysBySubject->get($subject->id, collect())->contains($slot->id);
                                                    $clashLabel = $wouldClashExactSlot ? ' &mdash; &#9888; clash (same slot)' : ($wouldAlertSameDay ? ' &mdash; &#9888; alert (same day)' : '');
                                                @endphp
                                                <option value="{{ $slot->id }}" @selected($assignment?->is_pinned && $assignment->time_slot_id === $slot->id) @style(['color: #dc2626' => $projected > $seatsAvailableTotal || $wouldClashExactSlot, 'color: #b45309' => $wouldAlertSameDay && ! ($projected > $seatsAvailableTotal)])>
                                                    {{ $slot->date->format('d M') }} {{ substr($slot->start_time, 0, 5) }} {{ $slot->label ? "({$slot->label})" : '' }} &mdash; {{ $projected }}/{{ $seatsAvailableTotal }} seats{!! $clashLabel !!}
                                                </option>
                                            @endforeach
                                        </select>
                                        @if ($assignment?->time_slot_id && ! $excluded)
                                            <button type="button" wire:click="removeSlot({{ $subject->id }})" wire:loading.attr="disabled" title="Remove this subject's assigned slot" class="p-1 text-gray-400 hover:text-red-600 dark:hover:text-red-400 disabled:opacity-50">
                                                <x-icon name="trash" class="h-4 w-4" />
                                            </button>
                                        @endif
                                    </div>
                                </td>
                                <td class="py-2.5 pr-4">
                                    <input type="checkbox" wire:click="toggleDutyMatchesSections({{ $subject->id }})" @checked($assignment?->duty_matches_sections) @disabled($excluded) title="A teacher who teaches N sections of this subject gets exactly N duties this session." class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 disabled:opacity-50">
                                </td>
                                <td class="py-2.5 pr-4 sm:pr-6">
                                    <input type="checkbox" wire:click="toggleSubjectExcluded({{ $subject->id }})" @checked($excluded)
                                        @if (! $excluded) wire:confirm="Exclude {{ $subject->code }} from this session? It will be left out of the timetable, seating and duty generation entirely, and any existing slot/pin for it will be cleared." @endif
                                        title="Leave this subject out of generation entirely." class="rounded border-red-300 text-red-600 focus:ring-red-500">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @can('manage_subjects')
                @if (count($mergeSelected) > 0)
                    <div class="mt-3 flex items-center gap-3 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg">
                        <span class="text-sm text-indigo-700 dark:text-indigo-300">{{ count($mergeSelected) }} selected</span>
                        <x-btn wire:click="openSubjectMergeModal" variant="dark" size="sm" icon="document">Merge Selected</x-btn>
                        <button type="button" wire:click="$set('mergeSelected', [])" class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Clear selection</button>
                    </div>
                @else
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1.5">
                        <x-icon name="info" class="h-3.5 w-3.5 shrink-0" />
                        Two codes that turn out to be the same real course? Check them above and merge them into one.
                    </p>
                @endif
            @endcan
        @endif
    </div>
</x-card>

<x-modal name="merge-subjects" :show="$showSubjectMergeModal" focusable max-width="lg">
    <div class="p-6">
        <div class="flex items-start gap-3">
            <x-icon name="document" class="h-6 w-6 text-indigo-600 shrink-0" />
            <div>
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100">Merge Subjects</h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Pick which subject survives &mdash; every other selected subject is merged into it, catalog-wide. Enrollments and pinned slots in sessions that aren't finalized move onto the survivor; finalized sessions keep their original historical record untouched.</p>
            </div>
        </div>
        <div class="mt-4 space-y-2">
            @foreach (\App\Models\Subject::whereIn('id', $mergeSelected)->orderBy('code')->get() as $subject)
                <label class="flex items-center gap-2 text-sm text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 cursor-pointer">
                    <input type="radio" wire:model="mergeSurvivorId" value="{{ $subject->id }}" class="text-indigo-600 focus:ring-indigo-500">
                    <span class="font-medium">{{ $subject->code }}</span>
                    <span class="text-gray-500 dark:text-gray-400">&mdash; {{ $subject->title }}</span>
                </label>
            @endforeach
        </div>
        <div class="mt-6 flex justify-end gap-3">
            <x-btn variant="secondary" wire:click="closeSubjectMergeModal" x-on:click="$dispatch('close')">Cancel</x-btn>
            <x-btn wire:click="confirmSubjectMerge" wire:confirm="Merge these subjects? This cannot be undone." wire:loading.attr="disabled" variant="dark">
                <span wire:loading.remove wire:target="confirmSubjectMerge">Merge</span>
                <span wire:loading wire:target="confirmSubjectMerge">Merging&hellip;</span>
            </x-btn>
        </div>
    </div>
</x-modal>

@if ($conflicted->isNotEmpty())
    <x-card>
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Unavoidable Clashes</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            The algorithm couldn't find a fully clash-free slot for these — resolve manually by adding a time slot or pinning one of the subjects elsewhere.
        </p>
        <ul class="mt-4 space-y-2">
            @foreach ($conflicted as $assignment)
                <li class="text-sm text-yellow-700 dark:text-yellow-400 flex items-start gap-2">
                    <x-icon name="warning" class="h-4 w-4 mt-0.5 shrink-0" />
                    <button type="button" wire:click="showClashDetails({{ $assignment->subject_id }})" class="text-left hover:underline decoration-dotted">{{ $assignment->conflict_note }}</button>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif

@if ($alerts->isNotEmpty())
    <x-card>
        <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Same-Day Alerts</h3>
        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
            These subjects share a calendar day with another paper from the same semester, just never the same time slot — informational only, and never blocks Generate Seating.
        </p>
        <ul class="mt-4 space-y-2">
            @foreach ($alerts as $assignment)
                <li class="text-sm text-blue-700 dark:text-blue-400 flex items-start gap-2">
                    <x-icon name="warning" class="h-4 w-4 mt-0.5 shrink-0" />
                    <button type="button" wire:click="showClashDetails({{ $assignment->subject_id }})" class="text-left hover:underline decoration-dotted">{{ $assignment->conflict_note }}</button>
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
</div>
