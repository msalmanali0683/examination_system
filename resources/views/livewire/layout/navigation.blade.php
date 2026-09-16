<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }
}; ?>

<aside
    :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    class="fixed inset-y-0 left-0 z-40 w-72 flex flex-col bg-slate-900 text-slate-200 transform transition-transform duration-200 ease-in-out lg:translate-x-0 lg:fixed lg:inset-y-0"
>
    <div class="flex items-center gap-3 px-5 h-16 shrink-0 border-b border-white/10">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-3 min-w-0">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-500/20 text-indigo-300">
                <x-application-logo class="h-5 w-5 fill-current" />
            </span>
            <span class="min-w-0">
                <span class="block text-sm font-semibold text-white truncate">Exam Duties &amp; Seat Plan</span>
                <span class="block text-xs text-slate-400 truncate">Faculty of Information Technology</span>
            </span>
        </a>
        <button @click="sidebarOpen = false" class="ml-auto lg:hidden p-1.5 rounded-md text-slate-400 hover:bg-white/10 hover:text-white">
            <x-icon name="x" class="h-5 w-5" />
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-5 space-y-1">
        <a href="{{ route('dashboard') }}" wire:navigate
           class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ request()->routeIs('dashboard') ? 'bg-indigo-500/15 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
            <x-icon name="home" class="h-5 w-5 shrink-0 {{ request()->routeIs('dashboard') ? 'text-indigo-400' : 'text-slate-400' }}" />
            {{ __('Dashboard') }}
        </a>

        @can('manage_sessions')
            <a href="{{ route('sessions.index') }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ request()->routeIs('sessions.*') ? 'bg-indigo-500/15 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <x-icon name="calendar" class="h-5 w-5 shrink-0 {{ request()->routeIs('sessions.*') ? 'text-indigo-400' : 'text-slate-400' }}" />
                {{ __('Exam Sessions') }}
            </a>
        @endcan

        @can('manage_rooms')
            <a href="{{ route('rooms.index') }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ request()->routeIs('rooms.*') ? 'bg-indigo-500/15 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <x-icon name="door" class="h-5 w-5 shrink-0 {{ request()->routeIs('rooms.*') ? 'text-indigo-400' : 'text-slate-400' }}" />
                {{ __('Rooms') }}
            </a>
        @endcan

        @can('manage_teachers')
            <a href="{{ route('teachers.index') }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ request()->routeIs('teachers.*') ? 'bg-indigo-500/15 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <x-icon name="cap" class="h-5 w-5 shrink-0 {{ request()->routeIs('teachers.*') ? 'text-indigo-400' : 'text-slate-400' }}" />
                {{ __('Teachers') }}
            </a>
        @endcan

        @can('manage_subjects')
            <a href="{{ route('subjects.index') }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ request()->routeIs('subjects.*') ? 'bg-indigo-500/15 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <x-icon name="document" class="h-5 w-5 shrink-0 {{ request()->routeIs('subjects.*') ? 'text-indigo-400' : 'text-slate-400' }}" />
                {{ __('Subjects') }}
            </a>
        @endcan

        @can('manage_users')
            <a href="{{ route('users.index') }}" wire:navigate
               class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition {{ request()->routeIs('users.*') ? 'bg-indigo-500/15 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <x-icon name="users" class="h-5 w-5 shrink-0 {{ request()->routeIs('users.*') ? 'text-indigo-400' : 'text-slate-400' }}" />
                {{ __('Users') }}
            </a>
        @endcan
    </nav>

    <div class="border-t border-white/10 p-3" x-data="{ open: false }" @click.outside="open = false">
        <button @click="open = ! open" type="button" class="w-full flex items-center gap-3 px-2 py-2 rounded-lg hover:bg-white/5 text-left">
            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-500 text-white text-sm font-semibold uppercase">
                {{ Str::substr(auth()->user()->name, 0, 1) }}
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-medium text-white truncate" x-data="{{ json_encode(['name' => auth()->user()->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
                <span class="block text-xs text-slate-400 truncate">{{ auth()->user()->email }}</span>
            </span>
            <x-icon name="chevron-down" class="h-4 w-4 text-slate-400 shrink-0" />
        </button>

        <div x-show="open" x-transition
             class="mt-1 rounded-lg bg-slate-800 ring-1 ring-white/10 overflow-hidden"
             style="display: none;">
            <a href="{{ route('profile') }}" wire:navigate class="flex items-center gap-2 px-3 py-2.5 text-sm text-slate-200 hover:bg-white/5">
                <x-icon name="cog" class="h-4 w-4 text-slate-400" /> {{ __('Profile') }}
            </a>
            <button wire:click="logout" class="w-full flex items-center gap-2 px-3 py-2.5 text-sm text-slate-200 hover:bg-white/5 text-left">
                <x-icon name="logout" class="h-4 w-4 text-slate-400" /> {{ __('Log Out') }}
            </button>
        </div>
    </div>
</aside>
