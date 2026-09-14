<x-slot name="header">
    <x-page-header title="Rooms" subtitle="Exam venues, their seating grid and capacity." icon="door">
        <x-slot name="actions">
            @if (! $showForm)
                <x-btn wire:click="addRoom" icon="plus">Add Room</x-btn>
            @endif
        </x-slot>
    </x-page-header>
</x-slot>

<div class="space-y-6">
@if (session('status'))
    <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg text-sm">
        {{ session('status') }}
    </div>
@endif

<x-card>
    @if ($showForm)
        <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-3 gap-4 pb-6 mb-6 border-b border-gray-100 dark:border-gray-700">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room Name</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room Type</label>
                <select wire:model="room_type" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    <option value="regular">Regular</option>
                    <option value="lab">Lab</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Rows</label>
                <input type="number" min="1" wire:model="rows" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('rows') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Columns</label>
                <input type="number" min="1" wire:model="columns" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('columns') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                    Capacity
                    @if ($rows && $columns)
                        <span class="text-xs text-gray-400">(max {{ $rows * $columns }})</span>
                    @endif
                </label>
                <input type="number" min="1" wire:model="capacity" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('capacity') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="sm:col-span-3 flex items-center gap-3">
                <x-btn type="submit" icon="check">Save Room</x-btn>
                <x-btn type="button" variant="ghost" wire:click="cancel">Cancel</x-btn>
            </div>
        </form>
    @endif

    <div class="flex justify-end mb-2">
        <x-per-page-selector />
    </div>

    @if ($rooms->isEmpty())
        <x-empty-state icon="door" title="No rooms yet" description="Add exam rooms to start planning seating." />
    @else
        <div class="overflow-x-auto -mx-4 sm:-mx-6">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <th class="py-2.5 pl-4 sm:pl-6 pr-4">Name</th>
                        <th class="py-2.5 pr-4">Type</th>
                        <th class="py-2.5 pr-4">Grid</th>
                        <th class="py-2.5 pr-4">Capacity</th>
                        <th class="py-2.5 pr-4">Status</th>
                        <th class="py-2.5 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($rooms as $room)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                            <td class="py-3 pl-4 sm:pl-6 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $room->name }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400 capitalize">{{ $room->room_type }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $room->rows }} &times; {{ $room->columns }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $room->capacity }}</td>
                            <td class="py-3 pr-4">
                                <button type="button" wire:click="toggleActive({{ $room->id }})">
                                    <x-badge :color="$room->is_active ? 'green' : 'gray'">{{ $room->is_active ? 'Active' : 'Inactive' }}</x-badge>
                                </button>
                            </td>
                            <td class="py-3 pr-4 sm:pr-6 text-right space-x-3 whitespace-nowrap">
                                <button type="button" wire:click="editRoom({{ $room->id }})" class="text-sm font-medium text-indigo-600 hover:underline">Edit</button>
                                <button type="button" wire:click="deleteRoom({{ $room->id }})" wire:confirm="Delete room {{ $room->name }}?" class="text-sm font-medium text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $rooms->links() }}
        </div>
    @endif
</x-card>
</div>
