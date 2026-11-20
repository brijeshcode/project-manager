<div>
    <x-ui.breadcrumb :items="array_filter([['Expenses', route('expenses.index')], $expenseId ? ['Expense #'.$expenseId, route('expenses.show', $expenseId)] : null, [$expenseId ? 'Edit' : 'Add expense', null]])" />
    <x-ui.page-header :title="$expenseId ? 'Edit expense' : 'Add expense'" :subtitle="'Money going out. Only you see this. Local currency: '.$local" />

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Expense</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="flex items-center justify-between">
                            <label for="expense_category_id" class="label">Category *</label>
                            <a href="{{ route('settings.expense-categories') }}" wire:navigate class="link text-xs">Manage categories</a>
                        </div>
                        <select wire:model="expense_category_id" id="expense_category_id" class="select">
                            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                        </select>
                        @error('expense_category_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="incurred_on" class="label">Date / due on *</label>
                        <input wire:model="incurred_on" id="incurred_on" type="date" class="input dark:[color-scheme:dark]" />
                        @error('incurred_on') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="description" class="label">Description *</label>
                        <input wire:model="description" id="description" type="text" maxlength="255" placeholder="VPS hosting, domain renewal..." class="input" />
                        @error('description') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="payee" class="label">Payee (optional)</label>
                        <input wire:model="payee" id="payee" type="text" maxlength="255" class="input" />
                        @error('payee') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="client_id" class="label">Client (optional)</label>
                        <select wire:model.live="client_id" id="client_id" class="select">
                            <option value="">None</option>
                            @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                        </select>
                        @error('client_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="project_id" class="label">Project (optional)</label>
                        <select wire:model="project_id" id="project_id" class="select" @disabled(! $client_id)>
                            <option value="">None</option>
                            @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                        </select>
                        @error('project_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="frequency" class="label">Repeat</label>
                        <select wire:model="frequency" id="frequency" class="select">
                            <option value="">One-off</option>
                            @foreach ($frequencies as $f)<option value="{{ $f->value }}">{{ $f->label() }}</option>@endforeach
                        </select>
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">The next copy is added automatically.</p>
                        @error('frequency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="remind_days" class="label">Remind me N days before</label>
                        <input wire:model="remind_days" id="remind_days" type="number" min="0" max="365" class="input tabular-nums" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Shows on the dashboard until it is paid.</p>
                        @error('remind_days') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Amount</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="currency" class="label">Currency</label>
                        <select wire:model.live="currency" id="currency" class="select">
                            @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
                        </select>
                        @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="amount" class="label">Amount ({{ $currency }}) *</label>
                        <input wire:model.live.debounce.400ms="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="input tabular-nums" />
                        @error('amount') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($foreign)
                        <div>
                            <label for="exchange_rate" class="label">Rate to {{ $local }}</label>
                            <input wire:model.live.debounce.400ms="exchange_rate" id="exchange_rate" type="text" inputmode="decimal" autocomplete="off" class="input tabular-nums" />
                            @if ($localHint !== null)<p class="mt-1 text-xs tabular-nums text-slate-500 dark:text-gray-400" data-test="local-hint">≈ {{ \App\Support\Money::format($localHint, $local) }}</p>@endif
                            @error('exchange_rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                @unless ($expenseId)
                    <label class="mt-4 flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-gray-700">
                        <input type="checkbox" wire:model.live="paid_now" id="paid_now" class="mt-0.5 h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="text-sm">
                            <span class="font-medium text-gray-900 dark:text-white">Paid now</span>
                            <span class="block text-slate-500 dark:text-gray-400">Records one payment for the full amount on the expense date.</span>
                        </span>
                    </label>
                    @if ($paid_now)
                        <div class="mt-4">
                            <label for="method" class="label">Method (optional)</label>
                            <input wire:model="method" id="method" type="text" maxlength="100" placeholder="Card, bank transfer, UPI..." class="input" />
                            @error('method') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endunless
            </section>

            <section class="card p-6">
                <label for="notes" class="label">Notes (optional)</label>
                <textarea wire:model="notes" id="notes" rows="2" maxlength="500" class="textarea"></textarea>
                @error('notes') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                @include('livewire.expenses._files', ['field' => 'files', 'attachments' => $attachments, 'remove' => 'removeAttachment'])
            </section>
        </div>

        <aside class="space-y-6">
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled">{{ $expenseId ? 'Save changes' : 'Add expense' }}</button>
                <a href="{{ $expenseId ? route('expenses.show', $expenseId) : route('expenses.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
            </div>
        </aside>
    </form>
</div>
