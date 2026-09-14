<x-slot name="header">
    <x-page-header title="Teachers" subtitle="Faculty available for invigilation duty." icon="cap">
        <x-slot name="actions">
            <a href="{{ route('teachers.import') }}" wire:navigate>
                <x-btn variant="secondary" icon="upload">Import</x-btn>
            </a>
            @if (! $showForm)
                <x-btn wire:click="addTeacher" icon="plus">Add Teacher</x-btn>
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
        <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-6 mb-6 border-b border-gray-100 dark:border-gray-700">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Designation</label>
                <input type="text" wire:model="designation" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Department</label>
                <input type="text" wire:model="department" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                <input type="email" wire:model="email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Phone</label>
                <input type="text" wire:model="phone" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
            </div>
            <div class="sm:col-span-2 flex items-center gap-3">
                <x-btn type="submit" icon="check">Save Teacher</x-btn>
                <x-btn type="button" variant="ghost" wire:click="cancel">Cancel</x-btn>
            </div>
        </form>
    @endif

    <div class="flex items-center justify-between mb-2 gap-3">
        <div class="relative w-full max-w-xs">
            <x-icon name="search" class="h-4 w-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2" />
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search name or email"
                class="pl-9 w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm">
        </div>
        <x-per-page-selector />
    </div>

    @if ($teachers->isEmpty())
        <x-empty-state icon="cap" title="No teachers yet" description="Add teachers manually or import them from a spreadsheet." />
    @else
        <div class="overflow-x-auto -mx-4 sm:-mx-6">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead>
                    <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                        <th class="py-2.5 pl-4 sm:pl-6 pr-4">Name</th>
                        <th class="py-2.5 pr-4">Designation</th>
                        <th class="py-2.5 pr-4">Department</th>
                        <th class="py-2.5 pr-4">Email</th>
                        <th class="py-2.5 pr-4">Status</th>
                        <th class="py-2.5 pr-4 sm:pr-6"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($teachers as $teacher)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/30">
                            <td class="py-3 pl-4 sm:pl-6 pr-4 font-medium text-gray-900 dark:text-gray-100">{{ $teacher->name }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $teacher->designation }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $teacher->department }}</td>
                            <td class="py-3 pr-4 text-gray-500 dark:text-gray-400">{{ $teacher->email }}</td>
                            <td class="py-3 pr-4">
                                <button type="button" wire:click="toggleActive({{ $teacher->id }})">
                                    <x-badge :color="$teacher->is_active ? 'green' : 'gray'">{{ $teacher->is_active ? 'Active' : 'Inactive' }}</x-badge>
                                </button>
                            </td>
                            <td class="py-3 pr-4 sm:pr-6 text-right space-x-3 whitespace-nowrap">
                                <button type="button" wire:click="editTeacher({{ $teacher->id }})" class="text-sm font-medium text-indigo-600 hover:underline">Edit</button>
                                <button type="button" wire:click="deleteTeacher({{ $teacher->id }})" wire:confirm="Delete {{ $teacher->name }}?" class="text-sm font-medium text-red-600 hover:underline">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">
            {{ $teachers->links() }}
        </div>
    @endif
</x-card>
</div>
