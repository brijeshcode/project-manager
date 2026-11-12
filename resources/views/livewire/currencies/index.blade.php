<div x-data="{ ask: null }">
    <x-ui.page-header title="Currencies" :subtitle="'Every currency the system can use. Amounts are converted to the local currency, '.$local.'. Open a currency to see its old rates.'">
        <x-slot:actions>
            <button type="button" x-on:click.prevent="$dispatch('open-modal', 'local-currency')" class="btn btn-secondary">Change local currency</button>
            <a href="{{ route('currencies.create') }}" wire:navigate class="btn btn-primary" data-test="add-currency">Add currency</a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-modal name="local-currency" maxWidth="md" focusable>
        <div class="p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Local currency</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">The currency every amount is converted into. Currently {{ $local }}.</p>

            <div class="mt-5">
                <label for="localCurrency" class="label">Select local currency</label>
                <select wire:model="localCurrency" id="localCurrency" class="select" @disabled($locked)>
                    @foreach ($localOptions as $code)
                        <option value="{{ $code }}">{{ $code }}</option>
                    @endforeach
                </select>
                @error('localCurrency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                @if ($locked)
                    <p class="mt-2 text-xs text-slate-500 dark:text-gray-400">Locked: money has already been recorded in {{ $local }}.</p>
                @else
                    <p class="mt-2 text-xs text-slate-500 dark:text-gray-400">Changing it clears the active rates, so set them again afterwards. Old rates stay in the history.</p>
                @endif
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'local-currency')" class="btn btn-secondary justify-center">Cancel</button>
                <button type="button" wire:click="saveLocalCurrency" wire:loading.attr="disabled" wire:target="saveLocalCurrency" class="btn btn-primary justify-center" @disabled($locked)>Save</button>
            </div>
        </div>
    </x-modal>

    <x-ui.table-card>
        <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
            <thead>
                <tr><th class="table-th">Code</th><th class="table-th">Name</th><th class="table-th">Rate to {{ $local }}</th><th class="table-th">Status</th><th class="table-th">In use</th><th class="table-th"></th></tr>
            </thead>
            @foreach ($currencies as $c)
                <tbody wire:key="cur-{{ $c->id }}" x-data="{ open: false }" class="divide-y divide-slate-100 dark:divide-gray-700">
                    <tr :class="open && 'bg-brand-50/50 dark:bg-gray-700/30'">
                        <td class="table-td whitespace-nowrap font-medium">
                            <button type="button" x-on:click="open = !open" :aria-expanded="open" aria-controls="hist-{{ $c->id }}" class="link font-mono">{{ $c->code }}</button>
                            @if ($c->is_local) <x-ui.badge color="brand">local</x-ui.badge> @endif
                        </td>
                        <td class="table-td">{{ $c->name }}</td>
                        <td class="table-td whitespace-nowrap font-mono tabular-nums">{{ $c->active_rate ? rtrim(rtrim($c->active_rate, '0'), '.') : '—' }}</td>
                        <td class="table-td"><x-ui.badge :color="$c->is_active ? 'green' : 'slate'">{{ $c->is_active ? 'active' : 'inactive' }}</x-ui.badge></td>
                        <td class="table-td tabular-nums">{{ $c->used ?: '—' }}</td>
                        <td class="table-td whitespace-nowrap text-right">
                            <button type="button" x-on:click="open = !open" class="text-sm font-medium link" x-text="open ? 'Hide rates' : 'Rates'">Rates</button>
                            <a href="{{ route('currencies.edit', $c) }}" wire:navigate class="ml-3 text-sm font-medium link">Edit</a>
                            @unless ($c->is_local)
                                <button type="button" wire:click="toggle({{ $c->id }})" wire:loading.attr="disabled" wire:target="toggle({{ $c->id }})" class="ml-3 text-sm font-medium link disabled:opacity-50">{{ $c->is_active ? 'Deactivate' : 'Activate' }}</button>
                                @if ($c->used)
                                    <span class="ml-3 cursor-not-allowed text-sm font-medium text-slate-400 dark:text-gray-500" title="In use, so it can't be deleted. Deactivate it instead.">Delete</span>
                                @else
                                    <button type="button" x-on:click="ask = { method: 'delete', id: {{ $c->id }}, title: @js('Delete '.$c->code.'?'), text: 'It is not used anywhere yet. This cannot be undone.' }" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400">Delete</button>
                                @endif
                            @endunless
                        </td>
                    </tr>
                    <tr x-show="open" x-cloak id="hist-{{ $c->id }}">
                        <td colspan="6" class="table-td bg-slate-50/60 dark:bg-gray-800/60">
                            <p class="label">Rate history</p>
                            @if ($c->is_local)
                                <p class="text-slate-500 dark:text-gray-400">{{ $c->code }} is the local currency. Its rate is always 1.</p>
                            @elseif ($c->history->isEmpty())
                                <p class="text-slate-500 dark:text-gray-400">No rate has been set yet. <a href="{{ route('currencies.edit', $c) }}" wire:navigate class="link">Set one</a>.</p>
                            @else
                                <ul class="divide-y divide-slate-100 dark:divide-gray-700">
                                    @foreach ($c->history as $h)
                                        <li wire:key="h-{{ $h->id }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5">
                                            <span class="font-mono tabular-nums">{{ $h->rate }}</span>
                                            @if ($h->is_active)<x-ui.badge color="green">Active</x-ui.badge>@endif
                                            <span class="text-slate-600 dark:text-gray-300">{{ $h->created_at->format('d M Y H:i') }}</span>
                                            <span class="text-slate-500 dark:text-gray-400">{{ $h->creator?->name }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </td>
                    </tr>
                </tbody>
            @endforeach
        </table>
    </x-ui.table-card>

    <x-ui.confirm-modal />
</div>
