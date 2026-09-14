<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Rooms') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if (session('status'))
            <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Rooms</h3>
                @if (! $showForm)
                    <button type="button" wire:click="addRoom" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                        Add Room
                    </button>
                @endif
            </div>

            @if ($showForm)
                <form wire:submit="save" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room Name</label>
                        <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room Type</label>
                        <select wire:model="room_type" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                            <option value="regular">Regular</option>
                            <option value="lab">Lab</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Rows</label>
                        <input type="number" min="1" wire:model="rows" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('rows') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Columns</label>
                        <input type="number" min="1" wire:model="columns" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('columns') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Capacity
                            @if ($rows && $columns)
                                <span class="text-xs text-gray-400">(max {{ $rows * $columns }})</span>
                            @endif
                        </label>
                        <input type="number" min="1" wire:model="capacity" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('capacity') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div class="sm:col-span-3 flex items-center gap-3">
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Save Room
                        </button>
                        <button type="button" wire:click="cancel" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            Cancel
                        </button>
                    </div>
                </form>
            @endif

            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Type</th>
                            <th class="py-2 pr-4">Grid</th>
                            <th class="py-2 pr-4">Capacity</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($rooms as $room)
                            <tr>
                                <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $room->name }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400 capitalize">{{ $room->room_type }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $room->rows }} &times; {{ $room->columns }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $room->capacity }}</td>
                                <td class="py-2 pr-4">
                                    <button type="button" wire:click="toggleActive({{ $room->id }})" class="text-xs px-2 py-1 rounded-full {{ $room->is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                                        {{ $room->is_active ? 'Active' : 'Inactive' }}
                                    </button>
                                </td>
                                <td class="py-2 pr-4 text-right space-x-3">
                                    <button type="button" wire:click="editRoom({{ $room->id }})" class="text-sm text-indigo-600 hover:underline">Edit</button>
                                    <button type="button" wire:click="deleteRoom({{ $room->id }})" wire:confirm="Delete room {{ $room->name }}?" class="text-sm text-red-600 hover:underline">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No rooms yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
