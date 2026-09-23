<x-slot name="header">
    <x-page-header title="Missing Teachers" :subtitle="$examSession->name" icon="user-group" :back="route('sessions.generate', $examSession)" />
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

@if ($ignoredMissingTeacherCount > 0)
    <p class="text-sm text-gray-500 dark:text-gray-400 flex items-center gap-1.5">
        <x-icon name="info" class="h-3.5 w-3.5 shrink-0" />
        {{ $ignoredMissingTeacherCount }} missing-teacher pair{{ $ignoredMissingTeacherCount === 1 ? '' : 's' }} dismissed.
        <a href="{{ route('sessions.missing-teachers.ignored', $examSession) }}" wire:navigate class="text-indigo-600 dark:text-indigo-400 hover:underline">Work through them</a>
        &middot;
        <button type="button" wire:click="unignoreMissingTeachers" class="text-indigo-600 dark:text-indigo-400 hover:underline">Show all again</button>
    </p>
@endif

@if ($missingTeacherSections->isEmpty())
    <x-card>
        <x-empty-state icon="user-group" title="Nothing missing" description="Every subject/section pair with enrollments has a teacher on record." />
    </x-card>
@else
    <x-card :padded="false">
        <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700 flex items-start justify-between gap-4">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Pending Pairs</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">These subject/section combinations had no teacher on the enrollment sheet. Assign one so the exclusion and duty-matches-sections rules can account for them.</p>
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
</div>
