<div>
    <x-ui.page-header :title="$currencyId ? 'Edit currency' : 'New currency'" :subtitle="'Local currency: '.$local">
        <x-slot:actions>
            <a href="{{ route('currencies.index') }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="card grid max-w-2xl gap-4 p-6 sm:grid-cols-2">
        <div>
            <label for="code" class="label">Code</label>
            <input wire:model="code" id="code" type="text" maxlength="3" placeholder="USD" @disabled($currencyId) class="input uppercase" />
            @error('code') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="name" class="label">Name</label>
            <input wire:model="name" id="name" type="text" maxlength="100" placeholder="US Dollar" class="input" />
            @error('name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        @unless ($isLocal)
            <div>
                <label for="rate" class="label">Rate to {{ $local }} (optional)</label>
                <input wire:model="rate" id="rate" type="text" inputmode="decimal" placeholder="{{ $activeRate ?? 'e.g. 83.5' }}" class="input" />
                <p class="mt-1 text-xs text-slate-500">Leave empty to keep the current rate. A new rate is added to the history.</p>
                @error('rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div class="flex items-end">
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="is_active" class="rounded border-slate-300 text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                    Active (shown in payment, invoice and project forms)
                </label>
            </div>
        @endunless
        <div class="sm:col-span-2"><button type="submit" class="btn btn-primary">{{ $currencyId ? 'Save changes' : 'Add currency' }}</button></div>
    </form>
</div>
