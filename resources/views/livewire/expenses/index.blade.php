<div>
    <x-ui.page-header title="Expenses" :subtitle="'Money going out: servers, domains, fees, rent. Only you see this. Local currency: '.$local">
        <x-slot:actions>
            <a href="{{ route('expenses.create') }}" wire:navigate class="btn btn-primary"><x-icon name="plus" class="h-4 w-4" />Add expense</a>
        </x-slot:actions>
    </x-ui.page-header>

    @php
        $money = fn ($c, $cur) => \App\Support\Money::format($c, $cur);
        $statusColor = ['paid' => 'green', 'partly' => 'amber', 'unpaid' => 'rose'];
        $statuses = ['due' => 'Due', 'paid' => 'Paid'];
        $onPayments = $tab === 'payments';
        $category = $categories->firstWhere('id', (int) $typeFilter);
        $dated = $from !== '' || $to !== '';
        $day = fn ($d) => rescue(fn () => \Carbon\Carbon::parse($d)->format('j M Y'), $d, false);
        $dateChip = $period ? ['month' => 'This month', 'year' => 'This year', 'fy' => 'Financial year'][$period]
            : ($year ?: ($from !== '' && $to !== '' ? $day($from).' – '.$day($to) : ($from !== '' ? 'from '.$day($from) : 'until '.$day($to))));
        $statusOn = ! $onPayments && isset($statuses[$status]);
        $filtering = $search !== '' || $category || $statusOn || $dated;
        $rows = $onPayments ? $payments : $expenses;
    @endphp
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Total paid" :value="$money($paidTotal, $local)" color="rose" icon="credit-card"
            :hint="'In '.$local.' · '.$paidCount.' '.\Illuminate\Support\Str::plural('payment', $paidCount)" />
        @if ($dueTotal > 0)
            <x-ui.stat label="Due" :value="$money($dueTotal, $local)" color="yellow" icon="currency" :hint="'Still to pay, in '.$local" />
        @endif
        <x-ui.stat label="Expenses" :value="$count" color="blue" icon="document" hint="Incurred in view" />
    </div>

    <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex gap-8 overflow-x-auto" aria-label="Tabs" role="tablist">
            @foreach (['expenses' => 'Expenses', 'payments' => 'Payments'] as $key => $label)
                <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $key }}')"
                    @class(['whitespace-nowrap border-b-2 px-1 py-3 text-sm font-medium transition-colors', 'border-blue-500 text-blue-600 dark:text-blue-400' => $tab === $key, 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300' => $tab !== $key])>{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    <x-ui.filter-panel :active="(bool) $category + $statusOn + $dated">
        <x-slot:bar>
            <label for="search" class="sr-only">Search expenses</label>
            <input wire:model.live.debounce.300ms="search" id="search" type="search" maxlength="100" placeholder="Search by description or payee..." class="input" />
        </x-slot:bar>
        <x-slot:chips>
            @if ($category)
                <x-ui.filter-chip :label="'Category: '.$category->name" wire:click="$set('typeFilter', '')" />
            @endif
            @if ($statusOn)
                <x-ui.filter-chip :label="'Status: '.$statuses[$status]" wire:click="$set('status', '')" />
            @endif
            @if ($dated)
                <x-ui.filter-chip :label="($onPayments ? 'Paid: ' : 'Incurred: ').$dateChip" wire:click="setPeriod('')" />
            @endif
        </x-slot:chips>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <label for="typeFilter" class="sr-only">Category</label>
                <select wire:model.live="typeFilter" id="typeFilter" class="select">
                    <option value="">All categories</option>
                    @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            </div>
            @unless ($onPayments)
                <div>
                    <label for="status" class="sr-only">Status</label>
                    <select wire:model.live="status" id="status" class="select">
                        <option value="">All</option>
                        @foreach ($statuses as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                    </select>
                </div>
            @endunless
        </div>
        <x-ui.date-filter :label="$onPayments ? 'Paid' : 'Incurred'" :from="$from" :to="$to" :period="$period" :years="$years" :year="$year" />
    </x-ui.filter-panel>

    @if ($rows->isEmpty())
        @if ($filtering)
            <x-ui.empty-state title="No matches" :message="'No '.($onPayments ? 'payments' : 'expenses').' match your filters.'" name="credit-card">
                <x-slot name="action"><button type="button" wire:click="clearFilters" class="btn btn-secondary">Clear filters</button></x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.empty-state :title="$onPayments ? 'No payments yet' : 'No expenses yet'" message="Record money going out to see what you really earn." name="credit-card">
                <x-slot name="action"><a href="{{ route('expenses.create') }}" wire:navigate class="btn btn-primary">Add expense</a></x-slot>
            </x-ui.empty-state>
        @endif
    @elseif ($onPayments)
        <x-ui.table-card sticky>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="payments-table">
                <thead>
                    <tr>
                        <th class="table-th">Paid on</th>
                        <th class="table-th">Expense</th>
                        <th class="table-th hidden md:table-cell">Category</th>
                        <th class="table-th hidden md:table-cell">Payee</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th text-right">In {{ $local }}</th>
                        <th class="table-th hidden lg:table-cell">Method</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($payments as $p)
                        <tr wire:key="epay-{{ $p->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap text-slate-600 dark:text-gray-300">{{ $p->paid_on->format('M j, Y') }}</td>
                            <td class="table-td max-w-xs"><a href="{{ route('expenses.show', $p->expense_id) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-white">{{ $p->expense->description }}</a></td>
                            <td class="table-td hidden whitespace-nowrap md:table-cell"><x-ui.expense-category :category="$p->expense->category" /></td>
                            <td class="table-td hidden whitespace-nowrap text-sm text-slate-600 md:table-cell dark:text-gray-300">{{ $p->expense->payee ?? '—' }}</td>
                            <td class="table-td whitespace-nowrap text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($p->amount_cents, $p->expense->currency) }}</td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums text-slate-700 dark:text-gray-200">{{ $p->local_amount_cents !== null ? $money($p->local_amount_cents, $p->local_currency) : '—' }}</td>
                            <td class="table-td hidden whitespace-nowrap text-sm text-slate-600 lg:table-cell dark:text-gray-300">{{ $p->method ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $payments->links() }}</x-slot>
        </x-ui.table-card>
    @else
        <x-ui.table-card sticky>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Date</th>
                        <th class="table-th">Category</th>
                        <th class="table-th">Description</th>
                        <th class="table-th hidden md:table-cell">Payee</th>
                        <th class="table-th hidden lg:table-cell">Client / project</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th">Status</th>
                        <th class="table-th"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($expenses as $e)
                        @php $st = $e->status(); @endphp
                        <tr wire:key="exp-{{ $e->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap text-slate-600 dark:text-gray-300">{{ $e->incurred_on->format('M j, Y') }}</td>
                            <td class="table-td whitespace-nowrap"><x-ui.expense-category :category="$e->category" /></td>
                            <td class="table-td max-w-xs">
                                <a href="{{ route('expenses.show', $e) }}" wire:navigate class="block truncate font-medium text-gray-900 hover:underline dark:text-white">{{ $e->description }}</a>
                                @if ($e->frequency)<div class="text-xs text-slate-500 dark:text-gray-400">{{ $e->frequency->label() }}</div>@endif
                            </td>
                            <td class="table-td hidden whitespace-nowrap text-sm text-slate-600 md:table-cell dark:text-gray-300">{{ $e->payee ?? '—' }}</td>
                            <td class="table-td hidden max-w-xs lg:table-cell">
                                <div class="truncate text-sm text-slate-600 dark:text-gray-300">{{ $e->client?->name ?? '—' }}</div>
                                @if ($e->project)<div class="truncate text-xs text-slate-500 dark:text-gray-400">{{ $e->project->name }}</div>@endif
                            </td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $money($e->amount_cents, $e->currency) }}</span>
                                @if (strtoupper((string) $e->currency) !== $local && ($l = $e->toLocal($e->amount_cents)) !== null)
                                    <span class="block text-xs text-slate-500 dark:text-gray-400">{{ $money($l, $local) }}</span>
                                @endif
                            </td>
                            <td class="table-td whitespace-nowrap"><x-ui.badge :color="$statusColor[$st]">{{ ucfirst($st) }}</x-ui.badge></td>
                            <td class="table-td whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-0.5">
                                    @if ($st !== 'paid')
                                        <button type="button" wire:click="openPay({{ $e->id }})" x-on:click="$dispatch('open-modal', 'pay-expense')" class="btn btn-secondary px-2 py-1 text-xs" data-test="pay">Pay</button>
                                    @endif
                                    <a href="{{ route('expenses.show', $e) }}" wire:navigate class="btn btn-ghost px-2" title="View" aria-label="View expense"><x-icon name="eye" class="h-4 w-4" /></a>
                                    <a href="{{ route('expenses.edit', $e) }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit expense"><x-icon name="pencil" class="h-4 w-4" /></a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <x-slot name="footer">{{ $expenses->links() }}</x-slot>
        </x-ui.table-card>
    @endif

    <x-modal name="pay-expense" maxWidth="md" focusable>
        <form wire:submit="pay" class="space-y-4 p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Pay {{ $payExpense?->description }}</h2>
            @if ($payExpense)
                <p class="text-sm text-slate-500 dark:text-gray-400">{{ $money($payExpense->dueCents(), $payExpense->currency) }} still to pay.</p>
            @endif
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="pay_paid_on" class="label">Paid on *</label>
                    <input wire:model="paid_on" id="pay_paid_on" type="date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]" />
                    @error('paid_on') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="pay_amount" class="label">Amount{{ $payExpense ? ' ('.$payExpense->currency.')' : '' }} *</label>
                    <input wire:model="amount" id="pay_amount" type="text" inputmode="decimal" placeholder="0.00" class="input tabular-nums" />
                    @error('amount') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                @if ($payExpense && strtoupper((string) $payExpense->currency) !== $local)
                    <div>
                        <label for="pay_rate" class="label">Rate to {{ $local }}</label>
                        <input wire:model="exchange_rate" id="pay_rate" type="text" inputmode="decimal" autocomplete="off" class="input tabular-nums" />
                        @error('exchange_rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                @endif
                <div>
                    <label for="pay_method" class="label">Method (optional)</label>
                    <input wire:model="method" id="pay_method" type="text" maxlength="100" class="input" />
                    @error('method') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="pay_notes" class="label">Notes (optional)</label>
                <textarea wire:model="notes" id="pay_notes" rows="2" maxlength="500" class="textarea"></textarea>
                @error('notes') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                @include('livewire.expenses._files', ['field' => 'files', 'attachments' => collect(), 'remove' => ''])
            </div>
            <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
                <button type="button" x-on:click="$dispatch('close-modal', 'pay-expense')" class="btn btn-secondary justify-center">Cancel</button>
                <button type="submit" class="btn btn-primary justify-center" wire:loading.attr="disabled" @disabled(! $payExpense)>Record payment</button>
            </div>
        </form>
    </x-modal>
</div>
