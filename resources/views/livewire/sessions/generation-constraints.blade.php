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
                    <option value="strict_overflow_section_then_subject">Strict, then fill leftover seats with another section &mdash; or a different subject if none left</option>
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
        <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700 flex items-start justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Missing Teachers</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">These subject/section combinations had no teacher on the enrollment sheet. Assign one so the exclusion and duty-matches-sections rules above can account for them.</p>
            </div>
            <a href="{{ route('sessions.missing-teachers.import', $examSession) }}" wire:navigate class="shrink-0">
                <x-btn variant="secondary" size="sm" icon="upload">Import from Excel</x-btn>
            </a>
        </div>

        <div class="px-4 sm:px-6 py-3 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30 flex flex-wrap items-center gap-2">
            <span class="text-sm text-gray-600 dark:text-gray-300">All {{ $missingTeacherSections->count() }} row{{ $missingTeacherSections->count() === 1 ? '' : 's' }} below:</span>
            <select wire:model="bulkMissingTeacherId" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                <option value="">Select teacher&hellip;</option>
                @foreach ($activeTeachers as $teacher)
                    <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                @endforeach
            </select>
            <x-btn wire:click="assignMissingTeacherToAll" wire:confirm="Assign the selected teacher to all {{ $missingTeacherSections->count() }} pending subject/section pair(s)?" wire:loading.attr="disabled" variant="secondary">Assign to All</x-btn>
            <x-btn wire:click="ignoreAllMissingTeachers" wire:confirm="Ignore all {{ $missingTeacherSections->count() }} pending subject/section pair(s)? They'll stay without a teacher and won't be listed here again." wire:loading.attr="disabled" variant="ghost">Ignore All</x-btn>
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
                                    @php $suggested = $suggestedTeachersBySubject->get($row->subject_id, collect()); @endphp
                                    <div class="flex items-center gap-2">
                                        <select wire:model="missingTeacherSelection.{{ $row->subject_id }}.{{ $row->section }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                                            <option value="">Select teacher&hellip;</option>
                                            @if ($suggested->isNotEmpty())
                                                <optgroup label="Already teaches {{ $row->code }}">
                                                    @foreach ($suggested as $teacher)
                                                        <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                                    @endforeach
                                                </optgroup>
                                                <optgroup label="All Teachers">
                                                    @foreach ($activeTeachers as $teacher)
                                                        <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                                    @endforeach
                                                </optgroup>
                                            @else
                                                @foreach ($activeTeachers as $teacher)
                                                    <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                                @endforeach
                                            @endif
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

@if ($ignoredMissingTeacherCount > 0)
    <p class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
        <x-icon name="info" class="h-3.5 w-3.5 shrink-0" />
        {{ $ignoredMissingTeacherCount }} missing-teacher pair{{ $ignoredMissingTeacherCount === 1 ? '' : 's' }} dismissed.
        <a href="{{ route('sessions.missing-teachers.ignored', $examSession) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:underline">Work through them</a>
        &middot;
        <button type="button" wire:click="unignoreMissingTeachers" class="text-indigo-600 dark:text-indigo-400 hover:underline">Show all again</button>
    </p>
@endif

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
