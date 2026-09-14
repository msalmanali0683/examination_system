<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Teachers') }}
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
            <div class="flex items-center justify-between gap-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Teachers</h3>
                <div class="flex items-center gap-3">
                    <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name or email"
                        class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
                    <a href="{{ route('teachers.import') }}" wire:navigate class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-sm rounded-md hover:bg-gray-200 dark:hover:bg-gray-600">
                        Import
                    </a>
                    @if (! $showForm)
                        <button type="button" wire:click="addTeacher" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                            Add Teacher
                        </button>
                    @endif
                </div>
            </div>

            @if ($showForm)
                <form wire:submit="save" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                        <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Designation</label>
                        <input type="text" wire:model="designation" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                        <input type="text" wire:model="department" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                        <input type="text" wire:model="phone" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-3">
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Save Teacher
                        </button>
                        <button type="button" wire:click="cancel" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            Cancel
                        </button>
                    </div>
                </form>
            @endif

            <div class="mt-6 flex justify-end">
                <x-per-page-selector />
            </div>

            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead>
                        <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">
                            <th class="py-2 pr-4">Name</th>
                            <th class="py-2 pr-4">Designation</th>
                            <th class="py-2 pr-4">Department</th>
                            <th class="py-2 pr-4">Email</th>
                            <th class="py-2 pr-4">Status</th>
                            <th class="py-2 pr-4"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        @forelse ($teachers as $teacher)
                            <tr>
                                <td class="py-2 pr-4 text-gray-900 dark:text-gray-100">{{ $teacher->name }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $teacher->designation }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $teacher->department }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $teacher->email }}</td>
                                <td class="py-2 pr-4">
                                    <button type="button" wire:click="toggleActive({{ $teacher->id }})" class="text-xs px-2 py-1 rounded-full {{ $teacher->is_active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                                        {{ $teacher->is_active ? 'Active' : 'Inactive' }}
                                    </button>
                                </td>
                                <td class="py-2 pr-4 text-right space-x-3">
                                    <button type="button" wire:click="editTeacher({{ $teacher->id }})" class="text-sm text-indigo-600 hover:underline">Edit</button>
                                    <button type="button" wire:click="deleteTeacher({{ $teacher->id }})" wire:confirm="Delete {{ $teacher->name }}?" class="text-sm text-red-600 hover:underline">Delete</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No teachers yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="mt-4">
                    {{ $teachers->links() }}
                </div>
            </div>
        </div>
    </div>
</div>
