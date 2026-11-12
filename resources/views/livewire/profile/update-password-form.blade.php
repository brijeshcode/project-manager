<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    /**
     * Update the password for the currently authenticated user.
     */
    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');
        $this->dispatch('toast', type: 'success', message: 'Password updated.');
    }
}; ?>

<section class="grid gap-4 lg:grid-cols-3" data-test="section-password">
    <div>
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Password</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Use a long, random password to keep your account secure.</p>
    </div>
    <form wire:submit="updatePassword" class="card space-y-4 p-5 lg:col-span-2">
        <div class="sm:max-w-sm">
            <label for="update_password_current_password" class="label">Current password</label>
            <input wire:model="current_password" id="update_password_current_password" type="password" autocomplete="current-password" class="input" />
            @error('current_password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="update_password_password" class="label">New password</label>
                <input wire:model="password" id="update_password_password" type="password" autocomplete="new-password" class="input" />
                @error('password') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="update_password_password_confirmation" class="label">Confirm new password</label>
                <input wire:model="password_confirmation" id="update_password_password_confirmation" type="password" autocomplete="new-password" class="input" />
                @error('password_confirmation') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>
        <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-gray-700">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="updatePassword">Update password</button>
        </div>
    </form>
</section>
