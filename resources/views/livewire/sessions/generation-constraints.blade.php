<x-slot name="header">
    <x-page-header title="Generation Constraints" :subtitle="$examSession->name" icon="lightning" :back="route('sessions.show', $examSession)" />
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

<x-card>
    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Generation Settings</h3>
    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">These apply to seating and duty generation (later steps).</p>

    <form wire:submit="saveSettings" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Seating Strategy</label>
            <select wire:model.live="seating_strategy" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                <optgroup label="Basic">
                    <option value="strict">Strict (one room per subject+section)</option>
                    <option value="combine_sections">Combine sections of the same subject</option>
                    <option value="mixed">Mix different subjects (whole columns alternate)</option>
                </optgroup>
                <optgroup label="Fill leftover seats instead of wasting them">
                    <option value="strict_overflow_section">Strict, then fill leftover seats with another section</option>
                    <option value="strict_overflow_subject">Strict, then fill leftover seats with a different subject</option>
                    <option value="combine_sections_overflow_subject">Combine sections, then fill leftover seats with a different subject</option>
                </optgroup>
            </select>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">The "fill leftover seats" options avoid needing an extra room for a small group by seating it alongside another group in the same room, once the primary group is placed.</p>
        </div>
        @if ($seating_strategy === 'mixed')
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Subjects per Room</label>
                <input type="number" min="2" max="10" wire:model="mixed_subjects_per_room" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Columns alternate between this many subjects/sections, e.g. 2 means columns 1,3,5.. are one subject and 2,4,6.. are another.</p>
            </div>
        @endif
        <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Invigilators per Room</label>
            <input type="number" min="1" max="10" wire:model="invigilators_per_room" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 mb-2">
                <input type="checkbox" wire:model="teacher_subject_exclusion" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Avoid assigning a teacher to invigilate their own subject
            </label>
        </div>
        <div class="flex items-end">
            <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 mb-2">
                <input type="checkbox" wire:model="respect_room_capacity" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                Keep each slot within active room capacity when generating the timetable
            </label>
        </div>
        <div class="sm:col-span-3">
            <x-btn type="submit" variant="dark" icon="check">Save Settings</x-btn>
        </div>
    </form>
</x-card>

@if ($missingTeacherSections->isNotEmpty())
    <x-card :padded="false">
        <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Missing Teachers</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">These subject/section combinations had no teacher on the enrollment sheet. Assign one so the exclusion and duty-matches-sections rules above can account for them.</p>
        </div>

        <div class="p-4 sm:p-6">
            <div class="overflow-x-auto -mx-4 sm:-mx-6">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <th class="py-2 pl-4 sm:pl-6 pr-4">Subject</th>
                            <th class="py-2 pr-4">Section</th>
                            <th class="py-2 pr-4">Missing</th>
                            <th class="py-2 pr-4 sm:pr-6">Assign Teacher</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($missingTeacherSections as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                <td class="py-2.5 pl-4 sm:pl-6 pr-4 text-gray-900 dark:text-gray-100">{{ $row->code }} &mdash; {{ $row->title }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $row->section }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $row->missing_count }} student{{ $row->missing_count === 1 ? '' : 's' }}</td>
                                <td class="py-2.5 pr-4 sm:pr-6">
                                    <div class="flex items-center gap-2">
                                        <select wire:model="missingTeacherSelection.{{ $row->subject_id }}.{{ $row->section }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                                            <option value="">Select teacher&hellip;</option>
                                            @foreach ($activeTeachers as $teacher)
                                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                            @endforeach
                                        </select>
                                        <x-btn wire:click="assignMissingTeacher({{ $row->subject_id }}, {{ Illuminate\Support\Js::from($row->section) }})" wire:loading.attr="disabled" variant="secondary">Assign</x-btn>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </x-card>
@endif

<x-card :padded="false">
    <div class="p-4 sm:p-6 flex items-center justify-between gap-4 flex-wrap border-b border-gray-100 dark:border-gray-700">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Pin Subjects to Slots</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Optional. Everything else is placed automatically, clash-free where possible.</p>
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
                            <th class="py-2 pl-4 sm:pl-6 pr-4">Subject</th>
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
                                <td class="py-2.5 pl-4 sm:pl-6 pr-4 text-gray-900 dark:text-gray-100">
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
                                            <span class="text-yellow-600 dark:text-yellow-400" title="{{ $assignment->conflict_note }}">&#9888;</span>
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
                                                @endphp
                                                <option value="{{ $slot->id }}" @selected($assignment?->is_pinned && $assignment->time_slot_id === $slot->id) @style(['color: #dc2626' => $projected > $seatsAvailableTotal])>
                                                    {{ $slot->date->format('d M') }} {{ substr($slot->start_time, 0, 5) }} {{ $slot->label ? "({$slot->label})" : '' }} &mdash; {{ $projected }}/{{ $seatsAvailableTotal }} seats
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
        @endif
    </div>
</x-card>

<x-card>
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Capacity Check</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                For each slot: students needing seats, rooms needed (from real room capacities) vs. active, and teachers needed (rooms &times; invigilators/room) vs. available (excluding excluded/unavailable teachers).
            </p>
        </div>
        <x-btn wire:click="checkRequirements" wire:loading.attr="disabled" wire:target="checkRequirements" variant="secondary" icon="search">
            <span wire:loading.remove wire:target="checkRequirements">Check Capacity</span>
            <span wire:loading wire:target="checkRequirements">Checking&hellip;</span>
        </x-btn>
    </div>

    @if ($showRequirements)
        <x-requirement-table :requirements="$requirements" />
    @endif
</x-card>

<x-card>
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Seating</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Seats every enrolled student into a room, using the strategy selected above. Run this after generating the timetable.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-btn :href="route('sessions.seating', $examSession)" wire:navigate variant="secondary" icon="grid">View Seating</x-btn>
            <x-btn wire:click="generateSeating" wire:loading.attr="disabled" icon="refresh">
                <span wire:loading.remove wire:target="generateSeating">Generate Seating</span>
                <span wire:loading wire:target="generateSeating">Generating&hellip;</span>
            </x-btn>
        </div>
    </div>
</x-card>

<x-card>
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Duties</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Assigns invigilators to every room in use each slot, balancing load across teachers. Run this after generating seating.</p>
        </div>
        <div class="flex items-center gap-2">
            <x-btn :href="route('sessions.duties', $examSession)" wire:navigate variant="secondary" icon="clipboard">View Duties</x-btn>
            <x-btn wire:click="generateDuties" wire:loading.attr="disabled" wire:confirm="Regenerate duties? Locked duties are left untouched; everything else will be recomputed." icon="refresh">
                <span wire:loading.remove wire:target="generateDuties">Generate Duties</span>
                <span wire:loading wire:target="generateDuties">Generating&hellip;</span>
            </x-btn>
        </div>
    </div>

    @if ($dutyFairness->isNotEmpty())
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
                            <td class="py-2 px-3 text-gray-900 dark:text-gray-100">{{ $row->teacher->name }}</td>
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
    @endif
</x-card>

@can('view_reports')
    <x-card>
        <div class="flex items-center justify-between gap-4 flex-wrap mb-1">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Reports &amp; Downloads</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Final, print-ready versions in the department's usual layout.</p>
            </div>
        </div>
        <livewire:sessions.report-downloads :exam-session="$examSession" :key="'reports-gen-'.$examSession->id" />
    </x-card>
@endcan

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
                    {{ $assignment->conflict_note }}
                </li>
            @endforeach
        </ul>
    </x-card>
@endif
</div>
