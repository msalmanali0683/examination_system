<x-slot name="header">
    <x-page-header title="Ignored Missing Teachers" subtitle="Subject/section pairs dismissed from the Missing Teachers card — work through them here." icon="user-group" :back="route('sessions.generate', $examSession)" />
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

<x-card :padded="false">
    <div class="p-4 sm:p-6 flex items-start justify-between gap-4 flex-wrap border-b border-gray-100 dark:border-gray-700">
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Ignored Pairs</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Assign a teacher directly, move a pair back to the main card, or upload a spreadsheet for all of these at once.</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="{{ route('sessions.missing-teachers.import', ['examSession' => $examSession, 'ignored' => 1]) }}" wire:navigate>
                <x-btn variant="secondary" size="sm" icon="upload">Import from Excel</x-btn>
            </a>
            @if ($ignoredSections->isNotEmpty())
                <x-btn wire:click="restoreAll" wire:confirm="Move all {{ $ignoredSections->count() }} ignored pair(s) back to the Missing Teachers card?" wire:loading.attr="disabled" variant="ghost" size="sm">Restore All</x-btn>
            @endif
        </div>
    </div>

    @if ($ignoredSections->isEmpty())
        <x-empty-state icon="user-group" title="Nothing ignored" description="Pairs dismissed via 'Ignore All' on the Missing Teachers card show up here." />
    @else
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
                        @foreach ($ignoredSections as $row)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                                <td class="py-2.5 pl-4 sm:pl-6 pr-4 text-gray-900 dark:text-gray-100">{{ $row->code }} &mdash; {{ $row->title }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $row->section }}</td>
                                <td class="py-2.5 pr-4 text-gray-500 dark:text-gray-400">{{ $row->missing_count }} student{{ $row->missing_count === 1 ? '' : 's' }}</td>
                                <td class="py-2.5 pr-4 sm:pr-6">
                                    @php $suggested = $suggestedTeachersBySubject->get($row->subject_id, collect()); @endphp
                                    <div class="flex items-center gap-2">
                                        <select wire:model="selection.{{ $row->subject_id }}.{{ $row->section }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
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
                                        <x-btn wire:click="assignTeacher({{ $row->subject_id }}, {{ Illuminate\Support\Js::from($row->section) }})" wire:loading.attr="disabled" variant="secondary">Assign</x-btn>
                                        <button type="button" wire:click="restoreToMissingList({{ $row->subject_id }}, {{ Illuminate\Support\Js::from($row->section) }})" class="text-sm text-gray-500 dark:text-gray-400 hover:underline whitespace-nowrap">Restore</button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</x-card>
</div>
