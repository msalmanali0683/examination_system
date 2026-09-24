<x-slot name="header">
    <x-page-header
        :title="$type === 'rooms' ? 'Copy Rooms from Another Session' : 'Copy Teachers from Another Session'"
        :subtitle="$examSession->name.' — pick a session and tick what to bring over. You get new copies; the other session is left untouched.'"
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

<x-card>
    @if ($sessions->isEmpty())
        <x-empty-state icon="calendar" title="No other sessions yet" description="Create another session first — there's nothing to copy from until then." />
    @else
        <div class="max-w-xl">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Copy from session</label>
            <select wire:model.live="sourceSessionId" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                <option value="">Choose a session&hellip;</option>
                @foreach ($sessions as $option)
                    <option value="{{ $option->id }}">
                        {{ $option->name }} ({{ $option->start_date->format('d M Y') }}) &middot; {{ $option->rooms_count }} room(s), {{ $option->teachers_count }} teacher(s)
                    </option>
                @endforeach
            </select>
            @error('sourceSessionId') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
        </div>

        @if ($source)
            @if ($rows->isEmpty())
                <x-empty-state :icon="$type === 'rooms' ? 'door' : 'cap'" :title="'No '.$label.'s in that session'" description="Pick a different session." />
            @else
                <div class="mt-6 flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-3 text-sm">
                        <button type="button" wire:click="selectAll" class="font-medium text-indigo-600 hover:underline">Select all</button>
                        <button type="button" wire:click="selectNone" class="font-medium text-gray-500 dark:text-gray-400 hover:underline">Select none</button>
                        <span class="text-gray-500 dark:text-gray-400">{{ count($selected) }} of {{ $rows->count() - $alreadyThere->count() }} available selected</span>
                    </div>

                    @if ($type === 'teachers')
                        <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                            <input type="checkbox" wire:model="withConstraints" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            Also copy duty limits, unavailable days and exclusions
                        </label>
                    @endif
                </div>

                <div class="mt-3 overflow-x-auto -mx-4 sm:-mx-6">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead>
                            <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                                <th class="py-2.5 pl-4 sm:pl-6 pr-2 w-8"></th>
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

                <div class="mt-6 flex items-center gap-3">
                    <x-btn wire:click="copy" wire:loading.attr="disabled" wire:target="copy" icon="download" :disabled="$examSession->isFinalized()">
                        Copy {{ count($selected) }} {{ $label }}(s)
                    </x-btn>
                    <a href="{{ $indexRoute }}" wire:navigate class="text-sm text-gray-500 dark:text-gray-400 hover:underline">Cancel</a>
                </div>
            @endif
        @endif
    @endif
</x-card>
</div>
