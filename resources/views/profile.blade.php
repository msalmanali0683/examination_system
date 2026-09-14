<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Profile" subtitle="Manage your account details, password and data." icon="cog" />
    </x-slot>

    <x-card>
        <div class="max-w-xl">
            <livewire:profile.update-profile-information-form />
        </div>
    </x-card>

    <x-card>
        <div class="max-w-xl">
            <livewire:profile.update-password-form />
        </div>
    </x-card>

    <x-card>
        <div class="max-w-xl">
            <livewire:profile.delete-user-form />
        </div>
    </x-card>
</x-app-layout>
