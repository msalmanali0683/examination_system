<x-slot name="header">
    <x-page-header
        :title="$type === 'rooms' ? 'Import Rooms from Another Session' : 'Import Teachers from Another Session'"
        :subtitle="$examSession->name.' — you get new copies in this session; the other session is left untouched.'"
        icon="download"
        :back="$indexRoute" />
</x-slot>

<div class="space-y-6">
@if (session('error'))
    <div class="p-4 bg-yellow-50 dark:bg-yellow-900/40 text-yellow-700 dark:text-yellow-300 rounded-lg text-sm">
        {{ session('error') }}
    </div>
@endif

<x-finalized-banner :session="$examSession" />

<x-card :padded="false">
    @if ($sessions->isEmpty())
        <x-empty-state icon="calendar" title="No other sessions yet" description="Create another session first — there's nothing to import from until then." />

    @elseif (! $source)
        {{-- Step 1: every other session, one click to open it --}}
        <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">Choose a session</h3>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Click the session you want to import {{ $label }}s from.</p>
            @error('sourceSessionId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        <div class="divide-y divide-gray-100 dark:divide-gray-700">
            @foreach ($sessions as $option)
                @php $count = $type === 'rooms' ? $option->rooms_count : $option->teachers_count; @endphp
                <button type="button" wire:click="chooseSession({{ $option->id }})" @disabled($count === 0)
                    class="w-full flex items-center justify-between gap-4 px-4 sm:px-6 py-4 text-left hover:bg-gray-50 dark:hover:bg-gray-900/40 disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-transparent">
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-gray-900 dark:text-gray-100 truncate">{{ $option->name }}</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ $option->start_date->format('d M Y') }} &ndash; {{ $option->end_date->format('d M Y') }}</span>
                    </span>
                    <span class="flex items-center gap-3 shrink-0">
                        <span class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $count === 0 ? 'No '.$label.'s' : $count.' '.$label.($count === 1 ? '' : 's') }}
                        </span>
                        @if ($count > 0)
                            <x-icon name="chevron-right" class="h-4 w-4 text-gray-400" />
                        @endif
                    </span>
                </button>
            @endforeach
        </div>

    @else
        {{-- Step 2: that session's rooms/teachers — tick some or all, then import --}}
        <div class="p-4 sm:p-6 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between gap-4 flex-wrap">
            <div>
                <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ $source->name }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Tick the {{ $label }}s to import, or tick the box at the top for all of them.</p>
            </div>
            <button type="button" wire:click="chooseAnother" class="text-sm font-medium text-indigo-600 hover:underline">&larr; Choose a different session</button>
        </div>

        @if ($rows->isEmpty())
            <x-empty-state :icon="$type === 'rooms' ? 'door' : 'cap'" :title="'No '.$label.'s in that session'" description="Choose a different session." />
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                            <th class="py-2.5 pl-4 sm:pl-6 pr-2 w-8">
                                <input type="checkbox" wire:click="toggleSelectAll" @checked($allSelected) @disabled($availableCount === 0) title="Select all" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            </th>
                            <th class="py-2.5 pr-4">Name</th>
                            @if ($type === 'rooms')
                                <th class="py-2.5 pr-4">Type</th>
                                <th class="py-2.5 pr-4">Grid</th>
                                <th class="py-2.5 pr-4">Capacity</th>
                            @else
                                <th class="py-2.5 pr-4">Designation</th>
                                <th class="py-2.5 pr-4">Department</th>
                                <th class="py-2.5 pr-4">Email</th>
                            @endif
                            <th class="py-2.5 pr-4 sm:pr-6"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @foreach ($rows as $row)
                            @php $isThere = $alreadyThere->has($row->id); @endphp
                            <tr @class(['hover:bg-gray-50 dark:hover:bg-gray-900/30', 'opacity-60' => $isThere])>
                                <td class="py-3 pl-4 sm:pl-6 pr-2">
                                    <input type="checkbox" wire:model.live="selected" value="{{ $row->id }}" @disabled($isThere) class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                </td>
                                <td class="py-3 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $row->name }}</td>
                                @if ($type === 'rooms')
                                    <td class="py-3 pr-4 text-gray-500 dark:text-gray-400 capitalize">{{ $row->room_type }}</td>
                                    <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $row->rows }} &times; {{ $row->columns }}</td>
                                    <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $row->capacity }}</td>
                                @else
                                    <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $row->designation }}</td>
                                    <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $row->department }}</td>
                                    <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $row->email }}</td>
                                @endif
                                <td class="py-3 pr-4 sm:pr-6 text-right">
                                    @if ($isThere)
                                        <x-badge color="gray">Already in this session</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="p-4 sm:p-6 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between gap-4 flex-wrap">
                <div class="flex items-center gap-3 flex-wrap">
                    <x-btn wire:click="importSelected" wire:loading.attr="disabled" wire:target="importSelected" icon="download" :disabled="count($selected) === 0 || $examSession->isFinalized()">
                        Import {{ count($selected) }} {{ $label }}(s)
                    </x-btn>
                    <a href="{{ $indexRoute }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Cancel</a>
                </div>

                @if ($type === 'teachers')
                    <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="withConstraints" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                        Also bring their duty limits, unavailable days and exclusions
                    </label>
                @endif
            </div>
        @endif
    @endif
</x-card>
</div>
