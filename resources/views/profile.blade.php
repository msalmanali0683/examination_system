<x-app-layout>
    <x-slot name="header">
        <x-page-header title="Profile" subtitle="Manage your account details, password and data." icon="cog" />
    </x-slot>

    <x-card>
        <div class="max-w-xl">
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Appearance</h3>
            <p class="mt-1 mb-5 text-sm text-gray-600 dark:text-gray-400">Pick a color theme and choose light, dark, or follow your device. It's saved to your account, so it follows you to any device you sign in on.</p>
            <x-theme-picker />
        </div>
    </x-card>

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
