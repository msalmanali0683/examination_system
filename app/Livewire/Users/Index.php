<?php

namespace App\Livewire\Users;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

class Index extends Component
{
    public bool $showCreateForm = false;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $role = 'staff';

    public ?int $expandedUserId = null;

    /** @var array<string,string> permission => 'default'|'granted'|'revoked' for the expanded user */
    public array $overrides = [];

    public function mount(): void
    {
        $this->authorize('manage_users');
    }

    public function createUser(): void
    {
        $this->authorize('manage_users');

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in(['head', 'staff'])],
        ]);

        User::create($validated);

        $this->reset(['name', 'email', 'password', 'role', 'showCreateForm']);
        $this->role = 'staff';

        session()->flash('status', 'User created.');
    }

    public function updateRole(int $userId, string $role): void
    {
        $this->authorize('manage_users');

        if (! in_array($role, ['head', 'staff'], true)) {
            return;
        }

        $user = User::findOrFail($userId);

        if ($user->role === 'head' && $role !== 'head' && User::where('role', 'head')->count() <= 1) {
            session()->flash('error', 'Cannot demote the only remaining Head.');

            return;
        }

        $user->update(['role' => $role]);

        session()->flash('status', "Updated {$user->name}'s role.");
    }

    public function deleteUser(int $userId): void
    {
        $this->authorize('manage_users');

        $user = User::findOrFail($userId);

        if ($user->id === Auth::id()) {
            session()->flash('error', 'You cannot delete your own account.');

            return;
        }

        if ($user->role === 'head' && User::where('role', 'head')->count() <= 1) {
            session()->flash('error', 'Cannot delete the only remaining Head.');

            return;
        }

        $user->delete();

        if ($this->expandedUserId === $userId) {
            $this->expandedUserId = null;
        }

        session()->flash('status', "Deleted {$user->name}.");
    }

    public function toggleExpand(int $userId): void
    {
        $this->authorize('manage_users');

        if ($this->expandedUserId === $userId) {
            $this->expandedUserId = null;
            $this->overrides = [];

            return;
        }

        $user = User::findOrFail($userId);
        $existing = $user->permissionOverrides->pluck('granted', 'permission');

        $this->overrides = collect(config('permissions.catalog'))
            ->keys()
            ->mapWithKeys(function (string $permission) use ($existing) {
                if (! $existing->has($permission)) {
                    return [$permission => 'default'];
                }

                return [$permission => $existing->get($permission) ? 'granted' : 'revoked'];
            })
            ->all();

        $this->expandedUserId = $userId;
    }

    public function setOverride(string $permission, string $value): void
    {
        $this->authorize('manage_users');

        if (! $this->expandedUserId || ! array_key_exists($permission, config('permissions.catalog'))) {
            return;
        }

        if ($value === 'default') {
            UserPermission::where('user_id', $this->expandedUserId)
                ->where('permission', $permission)
                ->delete();
        } else {
            UserPermission::updateOrCreate(
                ['user_id' => $this->expandedUserId, 'permission' => $permission],
                ['granted' => $value === 'granted']
            );
        }

        $this->overrides[$permission] = $value;
    }

    #[Layout('layouts.app')]
    public function render()
    {
        return view('livewire.users.index', [
            'users' => User::with('permissionOverrides')->orderBy('name')->get(),
            'catalog' => config('permissions.catalog'),
        ]);
    }
}
