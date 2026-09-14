<x-slot name="header">
    <x-page-header title="Users & Permissions" subtitle="Staff accounts and their access to each part of the system." icon="users">
        <x-slot name="actions">
            <x-btn wire:click="$toggle('showCreateForm')" :icon="$showCreateForm ? 'x' : 'plus'">
                {{ $showCreateForm ? 'Cancel' : 'Add User' }}
            </x-btn>
        </x-slot>
    </x-page-header>
</x-slot>

<div class="space-y-6">
@if (session('status'))
    <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg text-sm">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div class="p-4 bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300 rounded-lg text-sm">
        {{ session('error') }}
    </div>
@endif

<x-card>
    @if ($showCreateForm)
        <form wire:submit="createUser" class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-6 mb-6 border-b border-gray-100 dark:border-gray-700">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                <input type="text" wire:model="name" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                <input type="email" wire:model="email" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Temporary Password</label>
                <input type="text" wire:model="password" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Role</label>
                <select wire:model="role" class="mt-1 block w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm">
                    <option value="staff">Staff</option>
                    <option value="head">Head</option>
                </select>
            </div>
            <div class="sm:col-span-2">
                <x-btn type="submit" icon="check">Create User</x-btn>
            </div>
        </form>
    @endif

    <div class="divide-y divide-gray-100 dark:divide-gray-700 -mx-4 sm:-mx-6">
        @foreach ($users as $user)
            <div class="px-4 sm:px-6 py-4">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-indigo-100 dark:bg-indigo-900/40 text-indigo-600 dark:text-indigo-300 text-sm font-semibold uppercase">
                            {{ Str::substr($user->name, 0, 1) }}
                        </span>
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 dark:text-gray-100 truncate">
                                {{ $user->name }}
                                @if ($user->id === auth()->id())
                                    <span class="text-xs text-gray-400 font-normal">(you)</span>
                                @endif
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400 truncate">{{ $user->email }}</div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <select
                            class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                            wire:change="updateRole({{ $user->id }}, $event.target.value)"
                        >
                            <option value="staff" @selected($user->role === 'staff')>Staff</option>
                            <option value="head" @selected($user->role === 'head')>Head</option>
                        </select>
                        <button type="button" wire:click="toggleExpand({{ $user->id }})" class="text-sm font-medium text-indigo-600 hover:underline whitespace-nowrap">
                            {{ $expandedUserId === $user->id ? 'Hide permissions' : 'Permissions' }}
                            @if ($user->permissionOverrides->isNotEmpty())
                                <span class="text-xs text-gray-400">({{ $user->permissionOverrides->count() }})</span>
                            @endif
                        </button>
                        <button
                            type="button"
                            wire:click="deleteUser({{ $user->id }})"
                            wire:confirm="Delete {{ $user->name }}? This cannot be undone."
                            class="text-sm font-medium text-red-600 hover:underline"
                        >
                            Delete
                        </button>
                    </div>
                </div>

                @if ($expandedUserId === $user->id)
                    <div class="mt-3 bg-gray-50 dark:bg-gray-900/50 rounded-lg p-4">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">
                            "Default" uses the {{ ucfirst($user->role) }} role's normal permission. Override only to grant or revoke a specific ability for this user.
                        </p>
                        <div class="space-y-2">
                            @foreach ($catalog as $permission => $label)
                                <div class="flex items-center justify-between gap-4">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">{{ $label }}</span>
                                    <select
                                        class="rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                                        wire:change="setOverride('{{ $permission }}', $event.target.value)"
                                    >
                                        <option value="default" @selected(($overrides[$permission] ?? 'default') === 'default')>Default</option>
                                        <option value="granted" @selected(($overrides[$permission] ?? 'default') === 'granted')>Granted</option>
                                        <option value="revoked" @selected(($overrides[$permission] ?? 'default') === 'revoked')>Revoked</option>
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-card>
</div>
