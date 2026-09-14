<x-slot name="header">
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Import Enrollments') }} &mdash; {{ $examSession->name }}
        </h2>
    </div>
</x-slot>

<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">

            <a href="{{ route('sessions.show', $examSession) }}" wire:navigate class="text-sm text-indigo-600 hover:underline">&larr; Back to {{ $examSession->name }}</a>

            @if ($step === 'upload')
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">1. Upload File</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        One row per student-subject enrollment. The first row must be column headers.
                    </p>

                    <div class="mt-4">
                        <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-gray-700 dark:text-gray-300">
                        <div wire:loading wire:target="file" class="mt-2 text-sm text-gray-500">Uploading &amp; reading file&hellip;</div>
                        @error('file') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif

            @if ($step === 'map')
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">2. Map Columns</h3>

                    @if (! empty($availableSheets))
                        <div class="mt-3 p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg flex items-center justify-between gap-4">
                            <label class="text-sm text-gray-700 dark:text-gray-300">
                                This file has multiple sheets &mdash; using the one that looks like the real data:
                            </label>
                            <select wire:change="selectSheet($event.target.value)" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                                @foreach ($availableSheets as $index => $label)
                                    <option value="{{ $index }}" @selected($index === $selectedSheetIndex)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Fields marked <span class="text-red-500">*</span> are required.
                    </p>

                    <div class="mt-4 space-y-3">
                        @foreach ($this->targetFields() as $field => $label)
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ $label }}
                                    @if (in_array($field, $this->requiredFields()))
                                        <span class="text-red-500">*</span>
                                    @endif
                                </span>
                                <select wire:model="mapping.{{ $field }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm min-w-[12rem]">
                                    <option value="">-- Ignore --</option>
                                    @foreach ($sourceColumns as $col)
                                        <option value="{{ $col['index'] }}">{{ $col['label'] }}</option>
                                    @endforeach
                                </select>
                                @error("mapping.{$field}") <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-6 flex items-center gap-3">
                        <button type="button" wire:click="confirmMapping" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Continue
                        </button>
                        <button type="button" wire:click="startOver" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            Start Over
                        </button>
                    </div>
                </div>
            @endif

            @if ($step === 'review')
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">3. Review</h3>

                    <div class="mt-4 grid grid-cols-2 sm:grid-cols-3 gap-4 text-center">
                        <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $report['total'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Rows found</div>
                        </div>
                        <div class="p-3 bg-green-50 dark:bg-green-900/30 rounded-lg">
                            <div class="text-2xl font-semibold text-green-700 dark:text-green-300">{{ $report['valid'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Will be imported</div>
                        </div>
                        <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                            <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['missingRequired'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Missing required field (skipped)</div>
                        </div>
                        <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                            <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['duplicateInFile'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Duplicate student+subject in file</div>
                        </div>
                        <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $report['blankTeacher'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">No teacher listed (fine, not an error)</div>
                        </div>
                        <div class="p-3 bg-blue-50 dark:bg-blue-900/30 rounded-lg">
                            <div class="text-2xl font-semibold text-blue-700 dark:text-blue-300">{{ $report['newStudentsCount'] }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">New students to create</div>
                        </div>
                    </div>

                    @if (! empty($report['newSubjects']))
                        <div class="mt-4">
                            <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ count($report['newSubjects']) }} new subject code(s) will be created:
                            </p>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400 break-words">
                                {{ implode(', ', array_slice($report['newSubjects'], 0, 30)) }}
                                @if (count($report['newSubjects']) > 30)
                                    &hellip; and {{ count($report['newSubjects']) - 30 }} more
                                @endif
                            </p>
                        </div>
                    @endif

                    @if (! empty($report['errors']))
                        <div class="mt-4 max-h-64 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                            <table class="min-w-full text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-900/50">
                                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                        <th class="py-2 px-3">Row</th>
                                        <th class="py-2 px-3">Issue</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                    @foreach ($report['errors'] as $error)
                                        <tr>
                                            <td class="py-2 px-3 text-gray-700 dark:text-gray-300">{{ $error['row'] }}</td>
                                            <td class="py-2 px-3 text-gray-700 dark:text-gray-300">{{ $error['message'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($report['errorsTruncated'])
                            <p class="mt-2 text-xs text-gray-500">Showing first 50 issues.</p>
                        @endif
                    @endif

                    <div class="mt-6 flex items-center gap-3">
                        <button type="button" wire:click="commitImport" wire:loading.attr="disabled" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500 disabled:opacity-50">
                            <span wire:loading.remove wire:target="commitImport">Import {{ $report['valid'] }} Enrollment(s)</span>
                            <span wire:loading wire:target="commitImport">Importing&hellip;</span>
                        </button>
                        <button type="button" wire:click="startOver" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            Start Over
                        </button>
                    </div>
                </div>
            @endif

            @if ($step === 'done')
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Import Complete</h3>
                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                        {{ $createdEnrollments }} enrollment(s) created, {{ $updatedEnrollments }} updated.
                    </p>

                    @if ($subjectSummary->isNotEmpty())
                        <div class="mt-6">
                            <h4 class="text-sm font-medium text-gray-900 dark:text-gray-100">Subjects in this session</h4>
                            <div class="mt-2 max-h-96 overflow-y-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-900/50 sticky top-0">
                                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                                            <th class="py-2 px-3">Subject</th>
                                            <th class="py-2 px-3">Students</th>
                                            <th class="py-2 px-3">Sections</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                        @foreach ($subjectSummary as $subject)
                                            @php $sections = $sectionBreakdown->get($subject->id, collect()); @endphp
                                            <tr>
                                                <td class="py-2 px-3 text-gray-700 dark:text-gray-300">{{ $subject->code }} &mdash; {{ $subject->title }}</td>
                                                <td class="py-2 px-3 text-gray-700 dark:text-gray-300">{{ $subject->enrollments_count }}</td>
                                                <td class="py-2 px-3 text-gray-500 dark:text-gray-400">
                                                    {{ $sections->map(fn ($c, $section) => "{$section}: {$c}")->implode(', ') }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <div class="mt-6 flex items-center gap-3">
                        <a href="{{ route('sessions.show', $examSession) }}" wire:navigate class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Back to Session
                        </a>
                        <button type="button" wire:click="startOver" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            Import Another File
                        </button>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
