<x-slot name="header">
    <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
        {{ __('Users & Permissions') }}
    </h2>
</x-slot>

<div class="py-12">
    <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

        @if (session('status'))
            <div class="p-4 bg-green-50 dark:bg-green-900/40 text-green-700 dark:text-green-300 rounded-lg">
                {{ session('status') }}
            </div>
        @endif

        @if (session('error'))
            <div class="p-4 bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-300 rounded-lg">
                {{ session('error') }}
            </div>
        @endif

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Users</h3>
                <button type="button" wire:click="$toggle('showCreateForm')" class="px-4 py-2 bg-gray-800 text-white text-sm rounded-md hover:bg-gray-700">
                    {{ $showCreateForm ? 'Cancel' : 'Add User' }}
                </button>
            </div>

            @if ($showCreateForm)
                <form wire:submit="createUser" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-gray-100 dark:border-gray-700 pt-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Name</label>
                        <input type="text" wire:model="name" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('name') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Email</label>
                        <input type="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('email') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Temporary Password</label>
                        <input type="text" wire:model="password" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                        @error('password') <span class="text-sm text-red-600">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Role</label>
                        <select wire:model="role" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm">
                            <option value="staff">Staff</option>
                            <option value="head">Head</option>
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm rounded-md hover:bg-indigo-500">
                            Create User
                        </button>
                    </div>
                </form>
            @endif

            <div class="mt-6 divide-y divide-gray-100 dark:divide-gray-700">
                @foreach ($users as $user)
                    <div class="py-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ $user->name }}
                                    @if ($user->id === auth()->id())
                                        <span class="text-xs text-gray-400">(you)</span>
                                    @endif
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $user->email }}</div>
                            </div>
                            <div class="flex items-center gap-3">
                                <select
                                    class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
                                    wire:change="updateRole({{ $user->id }}, $event.target.value)"
                                >
                                    <option value="staff" @selected($user->role === 'staff')>Staff</option>
                                    <option value="head" @selected($user->role === 'head')>Head</option>
                                </select>
                                <button type="button" wire:click="toggleExpand({{ $user->id }})" class="text-sm text-indigo-600 hover:underline">
                                    {{ $expandedUserId === $user->id ? 'Hide permissions' : 'Permissions' }}
                                    @if ($user->permissionOverrides->isNotEmpty())
                                        <span class="text-xs text-gray-400">({{ $user->permissionOverrides->count() }} override{{ $user->permissionOverrides->count() === 1 ? '' : 's' }})</span>
                                    @endif
                                </button>
                                <button
                                    type="button"
                                    wire:click="deleteUser({{ $user->id }})"
                                    wire:confirm="Delete {{ $user->name }}? This cannot be undone."
                                    class="text-sm text-red-600 hover:underline"
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
                                                class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 text-sm"
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
        </div>
    </div>
</div>
