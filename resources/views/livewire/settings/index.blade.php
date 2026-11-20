<div>
    <x-ui.page-header title="Settings" subtitle="Money settings: the local currency and the exchange rates used to convert client payments." />

    @php
        $u = auth()->user();
        $links = collect([
            ['Currencies', 'currencies.index', 'coins', 'All currencies and their rate history.', true],
            ['Bank accounts', 'billing.bank-accounts', 'landmark', 'Accounts printed on invoices.', true],
            ['Expense categories', 'settings.expense-categories', 'tags', 'Groups for expenses.', true],
            ['Task types', 'settings.task-types', 'sliders-horizontal', 'Kinds of work items.', $u->can('manage-taxonomy')],
            ['Labels', 'settings.labels', 'tags', 'Tags for work items.', $u->can('manage-taxonomy')],
            ['System', 'settings.system', 'settings', 'Page size and sidebar defaults.', $u->can('manage-system')],
        ])->filter(fn ($l) => $l[4]);
    @endphp

    <div class="space-y-8">
        <section class="grid gap-4 lg:grid-cols-3" data-test="section-local-currency">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">Local currency</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Every amount is converted into this currency for totals and reports.</p>
            </div>
            <form wire:submit="saveLocalCurrency" class="card space-y-4 p-5 lg:col-span-2">
                <div class="max-w-xs">
                    <label for="localCurrency" class="label">Local currency</label>
                    <select wire:model="localCurrency" id="localCurrency" class="select" @disabled($locked)>
                        @foreach ($codes as $c)
                            <option value="{{ $c }}" @selected($c === $local)>{{ $c }}</option>
                        @endforeach
                    </select>
                    @error('localCurrency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                @if ($locked)
                    <p class="text-sm text-slate-500 dark:text-gray-400">Locked — money has already been recorded in {{ $local }}</p>
                @else
                    <p class="text-sm text-slate-500 dark:text-gray-400">Changing it clears the active exchange rates, so set them again afterwards. Old rates stay in the history. It locks once money is recorded.</p>
                    <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-gray-700">
                        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="saveLocalCurrency">Save</button>
                    </div>
                @endif
            </form>
        </section>

        <section class="grid gap-4 lg:grid-cols-3" data-test="section-rates">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">Exchange rates</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">How many {{ $local }} one unit of a foreign currency is worth. Adding a rate for a currency replaces its active rate; the old one stays in its history.</p>
            </div>
            <div class="space-y-4 lg:col-span-2">
                <form wire:submit="addRate" class="card grid gap-4 p-5 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
                    <div>
                        <label for="currency" class="label">Currency</label>
                        <input wire:model="currency" id="currency" type="text" list="rate-codes" maxlength="3" autocomplete="off" placeholder="USD" class="input uppercase" />
                        <datalist id="rate-codes">
                            @foreach ($rateCodes as $c)<option value="{{ $c }}"></option>@endforeach
                        </datalist>
                        @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="rate" class="label">Rate (1 unit = ? {{ $local }})</label>
                        <input wire:model="rate" id="rate" type="text" inputmode="decimal" autocomplete="off" placeholder="83.50" class="input" />
                        @error('rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:pt-6">
                        <button type="submit" class="btn btn-primary w-full sm:w-auto" wire:loading.attr="disabled" wire:target="addRate">Add rate</button>
                    </div>
                </form>

                @if ($active->isEmpty())
                    <x-ui.empty-state title="No exchange rates" message="Add a rate above." />
                @else
                    <x-ui.table-card>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-sm">
                                <thead>
                                    <tr><th class="table-th">Currency</th><th class="table-th">Rate</th><th class="table-th">Since</th><th class="table-th">By</th></tr>
                                </thead>
                                @foreach ($active as $r)
                                    <tbody wire:key="rate-{{ $r->currency }}" x-data="{ open: false }" class="divide-y divide-slate-100">
                                        <tr>
                                            <td class="table-td">
                                                <button type="button" @click="open = !open" :aria-expanded="open" class="link inline-flex items-center gap-1" title="Show history">
                                                    {{ $r->currency }}
                                                    <svg class="h-3.5 w-3.5 transition-transform" :class="open && 'rotate-90'" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7"/></svg>
                                                </button>
                                            </td>
                                            <td class="table-td font-mono">{{ $r->rate }}</td>
                                            <td class="table-td whitespace-nowrap">{{ $r->created_at->format('d M Y H:i') }}</td>
                                            <td class="table-td">{{ $r->creator?->name }}</td>
                                        </tr>
                                        <tr x-show="open" style="display:none">
                                            <td colspan="4" class="table-td bg-slate-50 dark:bg-gray-900/40">
                                                <p class="label">History</p>
                                                <ul class="space-y-1">
                                                    @foreach ($histories[$r->currency] as $h)
                                                        <li wire:key="h-{{ $h->id }}" class="flex flex-wrap items-center gap-3">
                                                            <span class="font-mono">{{ $h->rate }}</span>
                                                            @if ($h->is_active)<x-ui.badge color="green">Active</x-ui.badge>@endif
                                                            <span>{{ $h->created_at->format('d M Y H:i') }}</span>
                                                            <span class="text-slate-500 dark:text-gray-400">{{ $h->creator?->name }}</span>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            </td>
                                        </tr>
                                    </tbody>
                                @endforeach
                            </table>
                        </div>
                    </x-ui.table-card>
                @endif
            </div>
        </section>

        <section class="grid gap-4 lg:grid-cols-3" data-test="section-more">
            <div>
                <h2 class="text-base font-semibold text-slate-900 dark:text-white">More settings</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Other setup pages, also in the sidebar.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 lg:col-span-2">
                @foreach ($links as [$label, $route, $icon, $hint])
                    <a href="{{ route($route) }}" wire:navigate class="card flex items-start gap-3 p-4 transition hover:border-brand-300 hover:shadow-md dark:hover:border-brand-500" data-test="link-{{ Str::slug($label) }}">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-600 dark:bg-brand-500/10 dark:text-brand-400">
                            <x-icon :name="$icon" class="h-5 w-5" />
                        </span>
                        <span>
                            <span class="block text-sm font-semibold text-slate-900 dark:text-white">{{ $label }}</span>
                            <span class="block text-sm text-slate-500 dark:text-gray-400">{{ $hint }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</div>
