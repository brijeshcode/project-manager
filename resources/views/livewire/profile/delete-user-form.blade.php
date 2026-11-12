<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), app(Logout::class)(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<section x-data="{ ask: null }" class="grid gap-4 lg:grid-cols-3" data-test="section-delete-account">
    <div>
        <h2 class="text-base font-semibold text-rose-700 dark:text-rose-400">Delete account</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Permanently removes your account and all of its data. Download anything you want to keep first.</p>
    </div>
    <form x-on:submit.prevent="ask = { method: 'deleteUser', title: 'Delete your account?', text: 'Your account and all of its data will be permanently deleted. This cannot be undone.', button: 'Delete account' }" class="card space-y-4 border-rose-200 p-5 lg:col-span-2 dark:border-rose-900/60">
        <div class="sm:max-w-sm">
            <label for="delete_password" class="label">Password</label>
            <input wire:model="password" id="delete_password" type="password" required autocomplete="current-password" placeholder="Confirm with your password" class="input" />
            @error('password') <p class="field-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-gray-700">
            <button type="submit" class="btn btn-danger" wire:loading.attr="disabled" wire:target="deleteUser" data-test="delete-account">Delete account</button>
        </div>
    </form>

    <x-ui.confirm-modal />
</section>
