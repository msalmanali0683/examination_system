<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Exam Sessions') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Sessions</h3>
                @if (! $showForm)
                    <button type="button" wire:click="addSession" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                        Create Session
                    </button>
                @endif
            </div>

            @if ($showForm)
                <form wire:submit="save" class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <div class="sm:col-span-3">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Name</label>
                        <input type="text" wire:model="name" placeholder="e.g. Midterm Spring 2026" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
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
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Create &amp; Continue
                        </button>
                        <button type="button" wire:click="cancel" class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300 hover:underline">
                            Cancel
                        </button>
                    </div>
                </form>
            @endif

            <div class="mt-6 divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($sessions as $session)
                    <a href="{{ route('sessions.show', $session) }}" wire:navigate class="flex items-center justify-between py-3 hover:bg-gray-50 dark:hover:bg-gray-900/40 -mx-2 px-2 rounded">
                        <div>
                            <div class="font-medium text-gray-900 dark:text-gray-100">{{ $session->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $session->start_date->format('d M Y') }} &ndash; {{ $session->end_date->format('d M Y') }}
                            </div>
                        </div>
                        <span @class([
                            'text-xs px-2 py-1 rounded-full',
                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' => $session->status === 'draft',
                            'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300' => $session->status === 'generated',
                            'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $session->status === 'finalized',
                        ])>
                            {{ ucfirst($session->status) }}
                        </span>
                    </a>
                @empty
                    <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No exam sessions yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
