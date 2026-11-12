<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    public string $name = '';
    public string $email = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->fill($validated);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
        $this->dispatch('toast', type: 'success', message: 'Profile saved.');
    }

    /**
     * Send an email verification notification to the current user.
     */
    public function sendVerification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }
}; ?>

<section class="grid gap-4 lg:grid-cols-3" data-test="section-profile">
    <div>
        <h2 class="text-base font-semibold text-slate-900 dark:text-white">Profile information</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Your name and the email address you sign in with.</p>
    </div>
    <form wire:submit="updateProfileInformation" class="card space-y-4 p-5 lg:col-span-2">
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="name" class="label">Name</label>
                <input wire:model="name" id="name" type="text" maxlength="255" required autocomplete="name" placeholder="Your full name" class="input" />
                @error('name') <p class="field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="email" class="label">Email</label>
                <input wire:model="email" id="email" type="email" maxlength="255" required autocomplete="username" placeholder="you@example.com" class="input" />
                @error('email') <p class="field-error">{{ $message }}</p> @enderror
            </div>
        </div>

        @if (auth()->user() instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! auth()->user()->hasVerifiedEmail())
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                Your email address is unverified.
                <button type="button" wire:click="sendVerification" wire:loading.attr="disabled" wire:target="sendVerification" class="link">Re-send the verification email</button>
                @if (session('status') === 'verification-link-sent')
                    <p class="mt-1 font-medium text-emerald-700 dark:text-emerald-400">A new verification link has been sent to your email address.</p>
                @endif
            </div>
        @endif

        <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-gray-700">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="updateProfileInformation">Save</button>
        </div>
    </form>
</section>
