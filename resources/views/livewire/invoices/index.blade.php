<div>
    <x-ui.page-header title="Invoices">
        <x-slot:actions>
            <a href="{{ route('invoices.create') }}" class="btn btn-primary">New invoice</a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-4">
        <label for="clientFilter" class="sr-only">Client</label>
        <select wire:model.live="clientFilter" id="clientFilter" class="rounded-md border-slate-300 text-sm">
            <option value="">All clients</option>
            @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
    </div>

    @if ($invoices->isEmpty())
        <x-ui.empty-state title="No invoices" message="Create one from a client's pending work." />
    @else
        <div class="card overflow-hidden p-0">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                    <tr><th class="px-5 py-3">Number</th><th class="px-5 py-3">Client</th><th class="px-5 py-3">Issued</th><th class="px-5 py-3">Total</th><th class="px-5 py-3">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($invoices as $i)
                        @php
                            $paid = $i->status !== 'void' && $i->total_cents > 0 && (int) $i->payments_sum_amount_cents >= $i->total_cents;
                            [$label, $color] = $i->status === 'void' ? ['void', 'red'] : ($paid ? ['paid', 'green'] : ['sent', 'slate']);
                        @endphp
                        <tr wire:key="inv-{{ $i->id }}">
                            <td class="px-5 py-3"><a href="{{ route('invoices.show', $i) }}" class="text-indigo-600 hover:underline">{{ $i->number }}</a></td>
                            <td class="px-5 py-3">{{ $i->client->name }}</td>
                            <td class="px-5 py-3">{{ $i->issued_on->toFormattedDateString() }}</td>
                            <td class="px-5 py-3">{{ \App\Support\Money::format($i->total_cents, $i->currency) }}</td>
                            <td class="px-5 py-3"><x-ui.badge :color="$color">{{ $label }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
