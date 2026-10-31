<div>
    <x-ui.page-header title="Bank accounts" subtitle="Payment details shown on invoices. Numbers are encrypted and masked here." />
    <p class="mb-4 text-sm"><a href="{{ route('billing.recurring') }}" class="text-indigo-600 hover:underline">&larr; Recurring charges</a></p>

    <form wire:submit="save" class="card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        @foreach ([['label', 'Label'], ['account_name', 'Account name'], ['bank_name', 'Bank name'], ['account_number', 'Account number'], ['ifsc_swift', 'IFSC / SWIFT'], ['email', 'Email (optional)']] as [$f, $l])
            <div>
                <label for="{{ $f }}" class="block text-sm font-medium text-slate-700">{{ $l }}</label>
                <input wire:model="{{ $f }}" id="{{ $f }}" type="text" autocomplete="off" class="mt-1 block w-full rounded-md border-slate-300 text-sm" />
                @error($f) <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>
        @endforeach
        <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">Add account</button></div>
    </form>

    @if ($accounts->isEmpty())
        <x-ui.empty-state title="No bank accounts" message="Add one above." />
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr><th class="px-5 py-3">Label</th><th class="px-5 py-3">Account</th><th class="px-5 py-3">Number</th><th class="px-5 py-3"></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($accounts as $a)
                        <tr wire:key="ba-{{ $a->id }}">
                            <td class="px-5 py-3">{{ $a->label }}</td>
                            <td class="px-5 py-3">{{ $a->account_name }} <span class="text-slate-500">/ {{ $a->bank_name }}@if ($a->email) / {{ $a->email }}@endif</span></td>
                            <td class="px-5 py-3 font-mono">{{ $a->masked }}</td>
                            <td class="px-5 py-3 text-right"><button wire:click="delete({{ $a->id }})" wire:confirm="Delete this bank account?" class="btn btn-ghost">Delete</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
