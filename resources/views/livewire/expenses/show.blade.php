<div>
    @php
        $money = fn ($c, $cur) => \App\Support\Money::format($c, $cur);
        $st = $expense->status();
        $remaining = $expense->dueCents();
        $loc = fn ($c) => $foreign && ($l = $expense->toLocal($c)) !== null ? $money($l, $local) : null;
        $dt = 'text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-gray-400';
        $dd = 'mt-1 text-sm font-medium text-gray-900 dark:text-white';
    @endphp
    <x-ui.breadcrumb :items="[['Expenses', route('expenses.index')], ['Expense #'.$expense->id, null]]" />
    <x-ui.page-header :title="$expense->description" :subtitle="$expense->incurred_on->format('M j, Y')">
        <x-slot:actions>
            <a href="{{ route('expenses.edit', $expense) }}" wire:navigate class="btn btn-secondary"><x-icon name="pencil" class="h-4 w-4" />Edit</a>
            <button type="button" wire:click="delete" wire:confirm="Delete this expense and its payments?" class="btn btn-ghost text-rose-600 dark:text-rose-400"><x-icon name="trash-2" class="h-4 w-4" />Delete</button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <div class="card p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-gray-400">Amount</p>
                        <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900 dark:text-white" data-test="amount">{{ $money($expense->amount_cents, $expense->currency) }}</p>
                        @if ($loc($expense->amount_cents))<p class="text-sm tabular-nums text-slate-500 dark:text-gray-400">≈ {{ $loc($expense->amount_cents) }} at {{ rtrim(rtrim((string) $expense->exchange_rate, '0'), '.') }}</p>@endif
                        <p class="mt-1 text-sm tabular-nums text-slate-500 dark:text-gray-400">Paid {{ $money($expense->paidCents(), $expense->currency) }}@if ($loc($expense->paidCents())) ({{ $loc($expense->paidCents()) }})@endif</p>
                        @if ($remaining > 0)<p class="text-sm tabular-nums text-slate-500 dark:text-gray-400" data-test="due">{{ $money($remaining, $expense->currency) }}@if ($loc($remaining)) ({{ $loc($remaining) }})@endif still to pay</p>@endif
                    </div>
                    <div class="flex flex-wrap items-center justify-end gap-2">
                        <x-ui.expense-category :category="$expense->category" />
                        <x-ui.badge :color="['paid' => 'green', 'partly' => 'amber', 'unpaid' => 'rose'][$st]">{{ ucfirst($st) }}</x-ui.badge>
                    </div>
                </div>
                <dl class="mt-6 grid grid-cols-2 gap-6 border-t border-slate-100 pt-6 sm:grid-cols-4 dark:border-gray-700">
                    <div><dt class="{{ $dt }}">Payee</dt><dd class="{{ $dd }}">{{ $expense->payee ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Repeats</dt><dd class="{{ $dd }}">{{ $expense->frequency?->label() ?? 'One-off' }}<span class="block text-xs font-normal text-slate-500 dark:text-gray-400">Remind {{ $expense->remind_days }} {{ \Illuminate\Support\Str::plural('day', $expense->remind_days) }} before</span></dd></div>
                    <div class="min-w-0"><dt class="{{ $dt }}">Client</dt><dd class="{{ $dd }} truncate">
                        @if ($expense->client)<a href="{{ route('clients.show', $expense->client) }}" wire:navigate class="link">{{ $expense->client->name }}</a>@else — @endif
                    </dd></div>
                    <div class="min-w-0"><dt class="{{ $dt }}">Project</dt><dd class="{{ $dd }} truncate">
                        @if ($expense->project)<a href="{{ route('projects.show', $expense->project) }}" wire:navigate class="link">{{ $expense->project->name }}</a>@else — @endif
                    </dd></div>
                </dl>
                @if ($expense->notes)
                    <p class="mt-6 whitespace-pre-line border-t border-slate-100 pt-6 text-sm text-slate-700 dark:border-gray-700 dark:text-gray-300">{{ $expense->notes }}</p>
                @endif
            </div>

            <div class="card p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Payments <span class="font-normal text-slate-400">({{ $expense->payments->count() }})</span></h2>
                @if ($expense->payments->isEmpty())
                    <p class="mt-2 text-sm text-slate-500 dark:text-gray-400">Nothing paid yet.</p>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200 dark:divide-gray-700 dark:border-gray-700">
                        @foreach ($expense->payments as $p)
                            <li wire:key="ep-{{ $p->id }}" class="px-4 py-2.5 text-sm">
                                <div class="flex items-center gap-3">
                                    <span class="whitespace-nowrap text-slate-600 dark:text-gray-300">{{ $p->paid_on->format('M j, Y') }}</span>
                                    <span class="min-w-0 flex-1 truncate text-slate-500 dark:text-gray-400">{{ $p->method }}{{ $p->method && $p->notes ? ' · ' : '' }}{{ $p->notes }}</span>
                                    <span class="text-right tabular-nums">
                                        <span class="font-medium text-gray-900 dark:text-white">{{ $money($p->amount_cents, $expense->currency) }}</span>
                                        @if ($foreign && $p->local_amount_cents !== null)<span class="block text-xs text-slate-500 dark:text-gray-400">{{ $money($p->local_amount_cents, $p->local_currency) }} at {{ rtrim(rtrim((string) $p->exchange_rate, '0'), '.') }}</span>@endif
                                    </span>
                                    <button type="button" wire:click="deletePayment({{ $p->id }})" wire:confirm="Delete this payment and its files?" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete payment"><x-icon name="trash-2" class="h-4 w-4" /></button>
                                </div>
                                @foreach ($p->attachments as $a)
                                    <div wire:key="patt-{{ $a->id }}" class="mt-1 flex items-center gap-2 pl-4 text-xs">
                                        <x-icon name="file-text" class="h-3.5 w-3.5 text-slate-400" />
                                        <a href="{{ $a->url() }}" class="link min-w-0 flex-1 truncate">{{ $a->name }}</a>
                                        <button type="button" wire:click="deleteAttachment({{ $a->id }})" wire:confirm="Delete this file?" class="text-rose-600 hover:underline dark:text-rose-400" aria-label="Delete {{ $a->name }}">Delete</button>
                                    </div>
                                @endforeach
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <form wire:submit="uploadFiles" class="card p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Files <span class="font-normal text-slate-400">({{ $expense->attachments->count() }})</span></h2>
                @include('livewire.expenses._files', ['field' => 'expenseFiles', 'attachments' => $expense->attachments, 'remove' => 'deleteAttachment'])
                <button type="submit" class="btn btn-secondary mt-4" wire:loading.attr="disabled"><x-icon name="plus" class="h-4 w-4" />Upload</button>
            </form>
        </div>

        <aside class="space-y-6">
            <form wire:submit="addPayment" class="card space-y-4 p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">Add payment</h2>
                <div>
                    <label for="paid_on" class="label">Paid on *</label>
                    <input wire:model="paid_on" id="paid_on" type="date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]" />
                    @error('paid_on') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="amount" class="label">Amount ({{ $expense->currency }}) *</label>
                    <input wire:model="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="input tabular-nums" />
                    @error('amount') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                @if ($foreign)
                    <div>
                        <label for="exchange_rate" class="label">Rate to {{ $local }}</label>
                        <input wire:model="exchange_rate" id="exchange_rate" type="text" inputmode="decimal" autocomplete="off" class="input tabular-nums" />
                        @error('exchange_rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="method" class="label">Method (optional)</label>
                    <input wire:model="method" id="method" type="text" maxlength="100" class="input" />
                    @error('method') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="notes" class="label">Notes (optional)</label>
                    <textarea wire:model="notes" id="notes" rows="2" maxlength="500" class="textarea"></textarea>
                    @error('notes') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    @include('livewire.expenses._files', ['field' => 'files', 'attachments' => collect(), 'remove' => ''])
                </div>
                <button type="submit" class="btn btn-primary w-full justify-center" wire:loading.attr="disabled"><x-icon name="plus" class="h-4 w-4" />Add payment</button>
            </form>
        </aside>
    </div>
</div>
