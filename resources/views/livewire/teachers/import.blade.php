<x-slot name="header">
    <x-page-header title="Import Teachers" subtitle="Upload a spreadsheet, map its columns, then review before committing." icon="upload" :back="route('sessions.teachers.index', $examSession)" />
</x-slot>

<x-card class="max-w-3xl mx-auto">
    @php
        $steps = ['upload' => 'Upload', 'map' => 'Map Columns', 'review' => 'Review', 'done' => 'Done'];
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
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Upload an Excel or CSV file. The first row must be column headers.</p>

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

            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Match your file's columns to the system fields. Name is required; the rest are optional.</p>

            <div class="mt-4 space-y-3">
                @foreach ($this->targetFields() as $field => $label)
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                            {{ $label }}
                            @if ($field === 'name') <span class="text-red-500">*</span> @endif
                        </span>
                        <select wire:model="mapping.{{ $field }}" class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm min-w-[12rem]">
                            <option value="">-- Ignore --</option>
                            @foreach ($sourceColumns as $col)
                                <option value="{{ $col['index'] }}">{{ $col['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                @error('mapping.name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>

            <div class="mt-6 flex items-center gap-3">
                <x-btn wire:click="confirmMapping" icon="arrow-right">Continue</x-btn>
                <x-btn variant="ghost" wire:click="startOver">Start Over</x-btn>
            </div>
        </div>
    @endif

    @if ($step === 'review')
        <div>
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Review</h3>

            <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                    <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $report['total'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Rows found</div>
                </div>
                <div class="p-3 bg-green-50 dark:bg-green-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-green-700 dark:text-green-300">{{ $report['valid'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Will be imported</div>
                </div>
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['missingName'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Missing name (skipped)</div>
                </div>
                <div class="p-3 bg-yellow-50 dark:bg-yellow-900/30 rounded-lg">
                    <div class="text-2xl font-semibold text-yellow-700 dark:text-yellow-300">{{ $report['duplicateEmails'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">Duplicate emails in file</div>
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
                <x-btn wire:click="commitImport" icon="check">Import {{ $report['valid'] }} Teacher(s)</x-btn>
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
                {{ $createdCount }} teacher(s) created, {{ $updatedCount }} updated (matched by email).
            </p>
            <div class="mt-6 flex items-center justify-center gap-3">
                <x-btn :href="route('sessions.teachers.index', $examSession)" wire:navigate icon="cap">View Teachers</x-btn>
                <x-btn variant="ghost" wire:click="startOver">Import Another File</x-btn>
            </div>
        </div>
    @endif
</x-card>
