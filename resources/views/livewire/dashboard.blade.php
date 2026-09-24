<x-slot name="header">
    <x-page-header title="Dashboard" subtitle="Overview of your exam sessions — each one keeps its own rooms, teachers and students." icon="chart-bar" />
</x-slot>

<div class="space-y-6">
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
    <x-stat-card label="Exam Sessions" :value="$sessionCount" icon="calendar" color="indigo" />
    <x-stat-card label="In Progress" :value="$activeSessionCount" icon="clipboard" color="blue" />
    <x-stat-card label="Finalized" :value="$finalizedSessionCount" icon="lock" color="green" />
    <x-stat-card label="Students (all sessions)" :value="$studentCount" icon="users" color="amber" />
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <x-card class="lg:col-span-2" :padded="false">
        <div class="p-4 sm:p-6 flex items-center justify-between border-b border-gray-100 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Recent Sessions</h3>
            @can('manage_sessions')
                <a href="{{ route('sessions.index') }}" wire:navigate class="text-xs font-medium text-indigo-600 hover:underline">View all</a>
            @endcan
        </div>

        @if ($recentSessions->isEmpty())
            <x-empty-state icon="calendar" title="No exam sessions yet" description="Create your first exam session to get started.">
                @can('manage_sessions')
                    <x-slot name="actions">
                        <x-btn :href="route('sessions.index')" wire:navigate icon="plus" size="sm">Create Session</x-btn>
                    </x-slot>
                @endcan
            </x-empty-state>
        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($recentSessions as $session)
                    <a href="{{ route('sessions.show', $session) }}" wire:navigate class="flex items-center justify-between gap-4 px-4 sm:px-6 py-3.5 hover:bg-gray-50 dark:hover:bg-gray-900/40">
                        <div class="min-w-0">
                            <div class="font-medium text-sm text-gray-900 dark:text-gray-100 truncate">{{ $session->name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                                {{ $session->start_date->format('d M Y') }} &ndash; {{ $session->end_date->format('d M Y') }}
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

    <x-card>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-4">Quick Actions</h3>
        <div class="space-y-2">
            @can('manage_sessions')
                <x-btn :href="route('sessions.index')" wire:navigate variant="secondary" icon="calendar" class="w-full justify-start">Manage Sessions</x-btn>
            @endcan
            @can('manage_users')
                <x-btn :href="route('users.index')" wire:navigate variant="secondary" icon="users" class="w-full justify-start">Manage Users</x-btn>
            @endcan
        </div>

        <p class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">Rooms, teachers, subjects and students belong to a single session &mdash; open a session to manage them.</p>
    </x-card>
</div>
</div>
