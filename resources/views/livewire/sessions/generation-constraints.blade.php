<x-slot name="header">
    <x-page-header title="Generation" :subtitle="$examSession->name" icon="lightning" :back="route('sessions.show', $examSession)">
        <x-slot name="actions">
            <a href="{{ route('sessions.section-teachers', $examSession) }}" wire:navigate>
                <x-btn variant="secondary" icon="user-group">Section Teachers</x-btn>
            </a>
        </x-slot>
    </x-page-header>
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

<div>
    <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-3">Generation Steps</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="{{ route('sessions.missing-teachers', $examSession) }}" wire:navigate class="block">
            <x-card class="h-full hover:ring-2 hover:ring-indigo-500 transition">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                        <x-icon name="user-group" class="h-5 w-5" />
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Missing Teachers</h4>
                        @if ($missingTeacherCount > 0)
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $missingTeacherCount }} pending pair{{ $missingTeacherCount === 1 ? '' : 's' }}</p>
                        @else
                            <p class="mt-1 text-sm text-green-600 dark:text-green-400">All accounted for</p>
                        @endif
                        @if ($ignoredMissingTeacherCount > 0)
                            <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $ignoredMissingTeacherCount }} dismissed</p>
                        @endif
                    </div>
                </div>
            </x-card>
        </a>

        <a href="{{ route('sessions.timetable', $examSession) }}" wire:navigate class="block">
            <x-card class="h-full hover:ring-2 hover:ring-indigo-500 transition">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                        <x-icon name="calendar" class="h-5 w-5" />
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Timetable</h4>
                        @if ($enrolledSubjectCount === 0)
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">No enrollments yet</p>
                        @elseif ($unplacedSubjectCount === 0)
                            <p class="mt-1 text-sm text-green-600 dark:text-green-400">All {{ $placedSubjectCount }} subject{{ $placedSubjectCount === 1 ? '' : 's' }} placed</p>
                        @else
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $placedSubjectCount }}/{{ $placedSubjectCount + $unplacedSubjectCount }} subjects placed</p>
                        @endif
                    </div>
                </div>
            </x-card>
        </a>

        <a href="{{ route('sessions.show', ['examSession' => $examSession, 'tab' => 'capacity']) }}" wire:navigate class="block">
            <x-card class="h-full hover:ring-2 hover:ring-indigo-500 transition">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                        <x-icon name="search" class="h-5 w-5" />
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Capacity Check</h4>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Rooms and teachers, per slot</p>
                    </div>
                </div>
            </x-card>
        </a>

        <a href="{{ route('sessions.seating', $examSession) }}" wire:navigate class="block">
            <x-card class="h-full hover:ring-2 hover:ring-indigo-500 transition">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                        <x-icon name="grid" class="h-5 w-5" />
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Seating</h4>
                        @if ($hasSeating)
                            <p class="mt-1 text-sm text-green-600 dark:text-green-400">Generated</p>
                        @else
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Not yet generated</p>
                        @endif
                    </div>
                </div>
            </x-card>
        </a>

        <a href="{{ route('sessions.duties', $examSession) }}" wire:navigate class="block">
            <x-card class="h-full hover:ring-2 hover:ring-indigo-500 transition">
                <div class="flex items-start gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                        <x-icon name="clipboard" class="h-5 w-5" />
                    </span>
                    <div>
                        <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Duties</h4>
                        @if ($hasDuties)
                            <p class="mt-1 text-sm text-green-600 dark:text-green-400">Generated</p>
                        @else
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Not yet generated</p>
                        @endif
                    </div>
                </div>
            </x-card>
        </a>
    </div>
</div>

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
</div>
