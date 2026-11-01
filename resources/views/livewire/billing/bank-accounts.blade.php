<div>
    <x-ui.page-header title="Bank accounts" subtitle="Payment details shown on invoices. Numbers are encrypted and masked here." />
    <p class="mb-4 text-sm"><a href="{{ route('billing.recurring') }}" class="link">&larr; Recurring charges</a></p>

    <form wire:submit="save" class="card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([['label', 'Label'], ['account_name', 'Account name'], ['bank_name', 'Bank name'], ['account_number', 'Account number'], ['ifsc_swift', 'IFSC / SWIFT'], ['email', 'Email (optional)']] as [$f, $l])
            <div>
                <label for="{{ $f }}" class="label">{{ $l }}</label>
                <input wire:model="{{ $f }}" id="{{ $f }}" type="text" autocomplete="off" class="input" />
                @error($f) <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">Add account</button></div>
    </form>

    @if ($accounts->isEmpty())
        <x-ui.empty-state title="No bank accounts" message="Add one above." />
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr><th class="table-th">Label</th><th class="table-th">Account</th><th class="table-th">Number</th><th class="table-th"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($accounts as $a)
                        <tr wire:key="ba-{{ $a->id }}">
                            <td class="table-td">{{ $a->label }}</td>
                            <td class="table-td">{{ $a->account_name }} <span class="text-slate-500">/ {{ $a->bank_name }}@if ($a->email) / {{ $a->email }}@endif</span></td>
                            <td class="table-td font-mono">{{ $a->masked }}</td>
                            <td class="table-td text-right"><button wire:click="delete({{ $a->id }})" wire:confirm="Delete this bank account?" class="btn btn-ghost">Delete</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
