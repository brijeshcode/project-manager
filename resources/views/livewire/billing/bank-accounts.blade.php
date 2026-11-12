<div x-data="{ ask: null }">
    <x-ui.page-header title="Bank accounts" subtitle="Payment details printed on invoices. Numbers are stored encrypted and only the last 4 digits are shown here.">
        <x-slot:actions>
            <a href="{{ route('billing.recurring') }}" wire:navigate class="btn btn-ghost">Recurring charges</a>
            @unless ($showForm)
                <button type="button" wire:click="create" class="btn btn-primary" data-test="add-bank-account">Add account</button>
            @endunless
        </x-slot:actions>
    </x-ui.page-header>

    @if ($showForm)
        @php $editing = $editingId ? $accounts->firstWhere('id', $editingId) : null; @endphp
        <form wire:submit="save" class="card mb-6 max-w-3xl p-6" data-test="bank-account-form">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $editing ? 'Edit '.$editing->label : 'New bank account' }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Invoices keep a copy of these details, so changes here don't alter invoices already issued.</p>

            <div class="mt-5 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="label" class="label">Label</label>
                    <input wire:model="label" id="label" type="text" maxlength="100" placeholder="e.g. Main USD" autocomplete="off" class="input" />
                    <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Only you see this, when picking an account for an invoice.</p>
                    @error('label') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="account_name" class="label">Account holder name</label>
                    <input wire:model="account_name" id="account_name" type="text" maxlength="255" autocomplete="off" class="input" />
                    @error('account_name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bank_name" class="label">Bank name</label>
                    <input wire:model="bank_name" id="bank_name" type="text" maxlength="255" autocomplete="off" class="input" />
                    @error('bank_name') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="account_number" class="label">Account number</label>
                    <input wire:model="account_number" id="account_number" type="text" maxlength="50" inputmode="numeric" autocomplete="off" placeholder="{{ $editing?->masked }}" class="input font-mono" />
                    @if ($editing) <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Leave empty to keep the current number.</p> @endif
                    @error('account_number') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="ifsc_swift" class="label">IFSC / SWIFT</label>
                    <input wire:model="ifsc_swift" id="ifsc_swift" type="text" maxlength="50" autocomplete="off" placeholder="{{ $editing ? '••••' : '' }}" class="input font-mono uppercase" />
                    @if ($editing) <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Leave empty to keep the current code.</p> @endif
                    @error('ifsc_swift') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="email" class="label">Payment email (optional)</label>
                    <input wire:model="email" id="email" type="email" maxlength="255" autocomplete="off" placeholder="e.g. payments@example.com" class="input" />
                    @error('email') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">{{ $editing ? 'Save changes' : 'Add account' }}</button>
                <button type="button" wire:click="cancel" class="btn btn-secondary">Cancel</button>
            </div>
        </form>
    @endif

    @if ($accounts->isEmpty())
        @unless ($showForm)
            <x-ui.empty-state name="bank" title="No bank accounts yet" message="Add the account clients should pay into. It can then be picked on each invoice.">
                <x-slot:action>
                    <button type="button" wire:click="create" class="btn btn-primary">Add account</button>
                </x-slot:action>
            </x-ui.empty-state>
        @endunless
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                <thead>
                    <tr><th class="table-th">Label</th><th class="table-th">Account holder</th><th class="table-th">Bank</th><th class="table-th">Number</th><th class="table-th"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($accounts as $a)
                        <tr wire:key="ba-{{ $a->id }}" @class(['bg-brand-50/50 dark:bg-gray-700/30' => $editingId === $a->id])>
                            <td class="table-td font-medium">{{ $a->label }}</td>
                            <td class="table-td">
                                {{ $a->account_name }}
                                @if ($a->email) <span class="block text-xs text-slate-500 dark:text-gray-400">{{ $a->email }}</span> @endif
                            </td>
                            <td class="table-td">{{ $a->bank_name }}</td>
                            <td class="table-td whitespace-nowrap font-mono">{{ $a->masked }}</td>
                            <td class="table-td whitespace-nowrap text-right">
                                <button type="button" wire:click="edit({{ $a->id }})" class="text-sm font-medium link">Edit</button>
                                <button type="button" x-on:click="ask = { method: 'delete', id: {{ $a->id }}, title: @js('Delete '.$a->label.'?'), text: 'Invoices already issued keep their copy of the details. This cannot be undone.' }" class="ml-3 text-sm font-medium text-rose-600 hover:underline dark:text-rose-400">Delete</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif

    <x-ui.confirm-modal />
</div>
