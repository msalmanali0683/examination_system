<x-slot name="header">
    <x-page-header title="Exam Sessions" subtitle="Self-contained exam periods with their own rooms, teachers and enrollments." icon="calendar">
        <x-slot name="actions">
            @if (! $showForm)
                <x-btn wire:click="addSession" icon="plus">Create Session</x-btn>
            @endif
        </x-slot>
    </x-page-header>
</x-slot>

<x-card>
    @if ($showForm)
        <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-3 gap-4 pb-6 mb-6 border-b border-gray-100 dark:border-gray-700">
            <div class="sm:col-span-3">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Name</label>
                <input type="text" wire:model="name" placeholder="e.g. Midterm Spring 2026" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Start Date</label>
                <input type="date" wire:model="start_date" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('start_date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">End Date</label>
                <input type="date" wire:model="end_date" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('end_date') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div class="sm:col-span-3 flex items-center gap-3">
                <x-btn type="submit" icon="check">Create &amp; Continue</x-btn>
                <x-btn type="button" variant="ghost" wire:click="cancel">Cancel</x-btn>
            </div>
        </form>
    @endif

    @if ($sessions->isEmpty() && ! $showForm)
        <x-empty-state icon="calendar" title="No exam sessions yet" description="Create your first exam session to start scheduling." />
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-700 -mx-4 sm:-mx-6">
            @foreach ($sessions as $session)
                <a href="{{ route('sessions.show', $session) }}" wire:navigate class="flex items-center justify-between gap-4 px-4 sm:px-6 py-4 hover:bg-gray-50 dark:hover:bg-gray-900/40 transition">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="hidden sm:flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-50 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300">
                            <x-icon name="calendar" class="h-4 w-4" />
                        </span>
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 dark:text-gray-100 truncate">{{ $session->name }}</div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                {{ $session->start_date->format('d M Y') }} &ndash; {{ $session->end_date->format('d M Y') }}
                            </div>
                        </div>
                    </div>
                    <x-badge :color="match($session->status) { 'generated' => 'blue', 'finalized' => 'green', default => 'gray' }">
                        {{ ucfirst($session->status) }}
                    </x-badge>
                </a>
            @endforeach
        </div>
    @endif
</x-card>
