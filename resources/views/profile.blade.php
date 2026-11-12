<x-app-layout>
    <x-ui.page-header title="Profile" subtitle="Manage your account information and security settings." />

    <div class="space-y-8">
        <livewire:profile.update-profile-information-form />
        <livewire:profile.update-password-form />
        <livewire:profile.delete-user-form />
    </div>
</x-app-layout>
