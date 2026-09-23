<x-slot name="header">
    <x-page-header
        :title="$scopeIgnored ? 'Import Ignored Missing Teachers' : 'Import Missing Teachers'"
        :subtitle="$scopeIgnored ? 'Upload a spreadsheet to fill in pairs dismissed on the Missing Teachers list.' : 'Upload a spreadsheet of teacher/course/section rows to fill in un-taught pairs.'"
        icon="upload"
        :back="route($scopeIgnored ? 'sessions.missing-teachers.ignored' : 'sessions.missing-teachers', $examSession)"
    />
</x-slot>

<x-card class="max-w-3xl mx-auto">
    @php
        $steps = ['upload' => 'Upload', 'map' => 'Map Columns', 'resolve' => 'Resolve', 'review' => 'Review', 'done' => 'Done'];
        $stepKeys = array_keys($steps);
        $currentIndex = array_search($step, $stepKeys);
    @endphp

    <div class="flex items-center mb-8">
        @foreach ($steps as $key => $label)
            @php $idx = array_search($key, $stepKeys); @endphp
            <div class="flex items-center {{ ! $loop->last ? 'flex-1' : '' }}">
                <div class="flex items-center gap-2">
                    <span @class([
                        'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                        'bg-indigo-600 text-white' => $idx <= $currentIndex,
                        'bg-gray-100 dark:bg-gray-700 text-gray-400' => $idx > $currentIndex,
                    ])>
                        @if ($idx < $currentIndex)
                            <x-icon name="check" class="h-3.5 w-3.5" />
                        @else
                            {{ $idx + 1 }}
                        @endif
                    </span>
                    <span class="text-xs font-medium {{ $idx <= $currentIndex ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400' }} hidden sm:inline">{{ $label }}</span>
                </div>
                @if (! $loop->last)
                    <div class="flex-1 h-px mx-3 {{ $idx < $currentIndex ? 'bg-indigo-600' : 'bg-gray-200 dark:bg-gray-700' }}"></div>
                @endif
            </div>
        @endforeach
    </div>

    @if ($step === 'upload')
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Upload File</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Upload an Excel or CSV file with a teacher name, course and section per row. The first row must be column headers.</p>

            <div class="mt-4 p-3 bg-indigo-50 dark:bg-indigo-900/20 rounded-lg flex items-center justify-between gap-4">
                <p class="text-sm text-indigo-700 dark:text-indigo-300">Not sure of the format? Download a template pre-filled with this session's {{ $scopeIgnored ? 'ignored' : 'pending' }} pairs — just fill in Teacher Name.</p>
                <a href="{{ route('sessions.missing-teachers.template', ['examSession' => $examSession, 'ignored' => $scopeIgnored ? 1 : null]) }}">
                    <x-btn variant="secondary" size="sm" icon="download">Download Template</x-btn>
                </a>
            </div>

            <div class="mt-4 border-2 border-dashed border-gray-200 dark:border-gray-700 rounded-xl p-6 text-center">
                <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-gray-700 dark:text-gray-300">
                <div wire:loading wire:target="file" class="mt-2 text-sm text-gray-500">Uploading&hellip;</div>
                @error('file') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
        </div>
    @endif

    @if ($step === 'map')
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Map Columns</h3>

            @if (! empty($availableSheets))
                <div class="mt-3 p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg flex items-center justify-between gap-4">
                    <label class="text-sm text-gray-700 dark:text-gray-300">
                        This file has multiple sheets &mdash; using the one that looks like the real data:
                    </label>
                    <select wire:change="selectSheet($event.target.value)" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                        @foreach ($availableSheets as $index => $label)
                            <option value="{{ $index }}" @selected($index === $selectedSheetIndex)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Match your file's columns to the system fields. All three are required.</p>

            <div class="mt-4 space-y-3">
                @foreach ($this->targetFields() as $field => $label)
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ $label }} <span class="text-red-500">*</span>
                        </span>
                        <select wire:model="mapping.{{ $field }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm min-w-[12rem]">
                            <option value="">-- Ignore --</option>
                            @foreach ($sourceColumns as $col)
                                <option value="{{ $col['index'] }}">{{ $col['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                @error('mapping.teacher') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
                @error('mapping.course') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
                @error('mapping.section') <span class="text-sm text-red-600 block">{{ $message }}</span> @enderror
            </div>

            <div class="mt-6 flex items-center gap-3">
                <x-btn wire:click="confirmMapping" icon="arrow-right">Continue</x-btn>
                <x-btn variant="ghost" wire:click="startOver">Start Over</x-btn>
            </div>
        </div>
    @endif

    @if ($step === 'resolve')
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Resolve Unmatched Names</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                These values in your file didn't exactly match an existing course or teacher. Pick the real one, or leave it as Skip and those rows won't be imported.
            </p>

            @if (! empty($unresolvedSubjects))
                <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-300">Courses</h4>
                <div class="mt-2 space-y-2">
                    @foreach ($unresolvedSubjects as $i => $row)
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-gray-700 dark:text-gray-300 truncate" title="{{ $row['raw'] }}">{{ $row['raw'] }}</span>
                            <select wire:model="subjectResolutions.{{ $i }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm min-w-[14rem]">
                                <option value="">-- Skip --</option>
                                @foreach (\App\Models\Subject::orderBy('code')->get(['id', 'code', 'title']) as $subject)
                                    <option value="{{ $subject->id }}">{{ $subject->code }} &mdash; {{ $subject->title }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (! empty($unresolvedTeachers))
                <h4 class="mt-5 text-sm font-semibold text-gray-700 dark:text-gray-300">Teachers</h4>
                <div class="mt-2 space-y-2">
                    @foreach ($unresolvedTeachers as $i => $row)
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-gray-700 dark:text-gray-300 truncate" title="{{ $row['raw'] }}">{{ $row['raw'] }}</span>
                            <select wire:model="teacherResolutions.{{ $i }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm min-w-[14rem]">
                                <option value="">-- Skip --</option>
                                @foreach (\App\Models\Teacher::where('is_active', true)->orderBy('name')->get(['id', 'name']) as $teacher)
                                    <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-6 flex items-center gap-3">
                <x-btn wire:click="confirmResolutions" icon="arrow-right">Continue</x-btn>
                <x-btn variant="ghost" wire:click="startOver">Start Over</x-btn>
            </div>
        </div>
    @endif

    @if ($step === 'review')
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Review</h3>

            <div class="mt-4 grid grid-cols-2 sm:grid-cols-5 gap-4 text-center">
                <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $report['total'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rows found</div>
                </div>
                <div class="p-3 bg-green-50 dark:bg-green-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-green-700 dark:text-green-300">{{ $report['valid'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Will be assigned</div>
                </div>
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['unresolvedCourse'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Course not matched</div>
                </div>
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['unresolvedTeacher'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Teacher not matched</div>
                </div>
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['noSuchPair'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">No pending pair found</div>
                </div>
            </div>

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
                <x-btn wire:click="commitImport" icon="check">Assign {{ $report['valid'] }} Row(s)</x-btn>
                <x-btn variant="ghost" wire:click="startOver">Start Over</x-btn>
            </div>
        </div>
    @endif

    @if ($step === 'done')
        <div class="text-center py-6">
            <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100 dark:bg-green-900/40 text-green-600 dark:text-green-300">
                <x-icon name="check" class="h-6 w-6" />
            </span>
            <h3 class="mt-3 text-base font-semibold text-gray-900 dark:text-gray-100">Import Complete</h3>
            <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                {{ $pairsResolved }} subject/section pair(s) resolved, covering {{ $enrollmentsUpdated }} enrollment(s).
            </p>
            <div class="mt-6 flex items-center justify-center gap-3">
                <x-btn :href="route($scopeIgnored ? 'sessions.missing-teachers.ignored' : 'sessions.missing-teachers', $examSession)" wire:navigate icon="check">
                    {{ $scopeIgnored ? 'Back to Ignored List' : 'Back to Missing Teachers' }}
                </x-btn>
                <x-btn variant="ghost" wire:click="startOver">Import Another File</x-btn>
            </div>
        </div>
    @endif
</x-card>
