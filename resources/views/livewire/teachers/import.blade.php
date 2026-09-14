<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Import Teachers') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">

            <a href="{{ route('teachers.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">&larr; Back to Teachers</a>

            @if ($step === 'upload')
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">1. Upload File</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Upload an Excel or CSV file. The first row must be column headers.</p>

                    <div class="mt-4">
                        <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-gray-700 dark:text-gray-300">
                        <div wire:loading wire:target="file" class="mt-2 text-sm text-gray-500">Uploading&hellip;</div>
                        @error('file') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                </div>
            @endif

            @if ($step === 'map')
                <div class="mt-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">2. Map Columns</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Match your file's columns to the system fields. Name is required; the rest are optional.</p>

                    <div class="mt-4 space-y-3">
                        @foreach ($this->targetFields() as $field => $label)
                            <div class="flex items-center justify-between gap-4">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ $label }}
                                    @if ($field === 'name') <span class="text-red-500">*</span> @endif
                                </span>
                                <select wire:model="mapping.{{ $field }}" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm min-w-[12rem]">
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
                        <button type="button" wire:click="commitImport" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Import {{ $report['valid'] }} Teacher(s)
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
                        {{ $createdCount }} teacher(s) created, {{ $updatedCount }} updated (matched by email).
                    </p>
                    <div class="mt-6 flex items-center gap-3">
                        <a href="{{ route('teachers.index') }}" wire:navigate class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            View Teachers
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
