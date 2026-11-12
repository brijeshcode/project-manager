<div>
    <x-ui.page-header :title="$currencyId ? 'Edit '.$code : 'New currency'" subtitle="Currencies can be picked on clients, projects, invoices and payments.">
        <x-slot:actions>
            <a href="{{ route('currencies.index') }}" wire:navigate class="btn btn-ghost">Back to currencies</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="card max-w-3xl p-6" data-test="currency-form">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $currencyId ? $code.' details' : 'Currency details' }}</h2>
        <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">
            @if ($isLocal)
                {{ $code }} is the local currency: its rate is always 1 and it stays active.
            @else
                Amounts in this currency are converted to {{ $local }} (the local currency) using its active rate.
            @endif
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <label for="code" class="label">Code</label>
                <input wire:model="code" id="code" type="text" maxlength="3" placeholder="e.g. USD" autocomplete="off" @disabled($currencyId) class="input font-mono uppercase disabled:opacity-60" />
                <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">{{ $currencyId ? 'The code can\'t be changed.' : '3-letter ISO code. It can\'t be changed later.' }}</p>
                @error('code') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="name" class="label">Name</label>
                <input wire:model="name" id="name" type="text" maxlength="100" placeholder="e.g. US Dollar" autocomplete="off" class="input" />
                @error('name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
            @unless ($isLocal)
                <div>
                    <label for="rate" class="label">Rate to {{ $local }} (optional)</label>
                    <input wire:model="rate" id="rate" type="text" inputmode="decimal" placeholder="{{ $activeRate ?? 'e.g. 83.5' }}" autocomplete="off" class="input font-mono" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">
                        1 {{ $code ?: 'unit' }} = how many {{ $local }}.
                        {{ $activeRate ? 'Current: '.$activeRate.'. Leave empty to keep it;' : 'Leave empty to set it later;' }} a new rate is added to the history.
                    </p>
                    @error('rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:pt-7">
                    <label class="flex items-start gap-2 text-sm text-slate-700 dark:text-gray-300">
                        <input type="checkbox" wire:model="is_active" class="mt-0.5 h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span>Active<span class="block text-xs text-slate-500 dark:text-gray-400">Shown in payment, invoice and project forms.</span></span>
                    </label>
                </div>
            @endunless
        </div>

        <div class="mt-6 flex flex-wrap gap-3">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $currencyId ? 'Save changes' : 'Add currency' }}</button>
            <a href="{{ route('currencies.index') }}" wire:navigate class="btn btn-secondary">Cancel</a>
        </div>
    </form>
</div>
