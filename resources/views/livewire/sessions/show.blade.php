<x-slot name="header">
    <div class="flex items-center justify-between">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ $examSession->name }}
        </h2>
        <a href="{{ route('sessions.index') }}" wire:navigate class="text-sm text-indigo-600 hover:underline">&larr; All Sessions</a>
    </div>
</x-slot>

<div class="py-12" x-data="{ tab: 'rooms' }">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="flex items-center justify-between">
                <div>
                    <span @class([
                        'text-xs px-2 py-1 rounded-full',
                        'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $examSession->status === 'draft',
                        'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' => $examSession->status === 'generated',
                        'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $examSession->status === 'finalized',
                    ])>
                        {{ ucfirst($examSession->status) }}
                    </span>
                    <span class="text-sm text-gray-500 dark:text-gray-400 ml-2">
                        {{ $examSession->start_date->format('d M Y') }} &ndash; {{ $examSession->end_date->format('d M Y') }}
                    </span>
                </div>
                @if (! $editingDetails)
                    <button type="button" wire:click="editDetails" class="text-sm text-indigo-600 hover:underline">Edit</button>
                @endif
            </div>

            @if ($editingDetails)
                <form wire:submit="saveDetails" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Name</label>
                        <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                        <input type="date" wire:model="start_date" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('start_date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">End Date</label>
                        <input type="date" wire:model="end_date" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('end_date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div class="sm:col-span-3 flex items-center gap-3">
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">Save</button>
                        <button type="button" wire:click="$set('editingDetails', false)" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">Cancel</button>
                    </div>
                </form>
            @endif
        </div>

        <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="border-b border-gray-100 dark:border-gray-700 px-4 sm:px-8 flex gap-6">
                <button type="button" @click="tab = 'rooms'" :class="tab === 'rooms' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 dark:text-gray-400'" class="py-4 border-b-2 text-sm font-medium">
                    Rooms
                </button>
                <button type="button" @click="tab = 'teachers'" :class="tab === 'teachers' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 dark:text-gray-400'" class="py-4 border-b-2 text-sm font-medium">
                    Teacher Constraints
                </button>
                <button type="button" @click="tab = 'slots'" :class="tab === 'slots' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 dark:text-gray-400'" class="py-4 border-b-2 text-sm font-medium">
                    Time Slots
                </button>
                <button type="button" @click="tab = 'enrollments'" :class="tab === 'enrollments' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 dark:text-gray-400'" class="py-4 border-b-2 text-sm font-medium">
                    Enrollments
                </button>
            </div>

            <div class="p-4 sm:p-8">
                <div x-show="tab === 'rooms'">
                    <livewire:sessions.room-selection :exam-session="$examSession" :key="'rooms-'.$examSession->id" />
                </div>
                <div x-show="tab === 'teachers'">
                    <livewire:sessions.teacher-constraints :exam-session="$examSession" :key="'teachers-'.$examSession->id" />
                </div>
                <div x-show="tab === 'slots'">
                    <livewire:sessions.time-slots :exam-session="$examSession" :key="'slots-'.$examSession->id" />
                </div>
                <div x-show="tab === 'enrollments'">
                    <div class="grid grid-cols-3 gap-4 text-center mb-6">
                        <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $enrollmentCount }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Enrollments</div>
                        </div>
                        <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $studentCount }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Students</div>
                        </div>
                        <div class="p-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg">
                            <div class="text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ $subjectCount }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">Subjects</div>
                        </div>
                    </div>
                    <a href="{{ route('sessions.enrollments.import', $examSession) }}" wire:navigate class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                        Import Enrollments
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
