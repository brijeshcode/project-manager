<div>
    <x-ui.page-header title="Subscriptions" subtitle="Recurring services we provide for you." />

    @if ($charges->isEmpty())
        <x-ui.empty-state title="No subscriptions" message="Recurring services like hosting or maintenance will appear here." />
    @else
        @php($awaiting = $charges->where('client_state', \App\Models\RecurringCharge::AWAITING)->count())
        @if ($awaiting)
            <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/10 dark:text-amber-200" data-test="awaiting-banner">
                {{ $awaiting }} new {{ \Illuminate\Support\Str::plural('subscription', $awaiting) }} {{ $awaiting === 1 ? 'is' : 'are' }} waiting for your approval. Open it to review and approve.
            </div>
        @endif
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm" data-test="portal-subscriptions">
                <thead><tr>
                    <th class="table-th">Service</th><th class="table-th">Project</th><th class="table-th">Billed</th>
                    <th class="table-th text-right">Amount</th><th class="table-th">Next due</th><th class="table-th text-right">Invoiced</th><th class="table-th">Status</th><th class="table-th"></th>
                </tr></thead>
                <tbody>
                @foreach ($charges as $s)
                    @php([$badge, $color] = $s->statusBadge())
                    @php($cur = $s->project?->currency ?? $client->currency)
                    <tr wire:key="sub-{{ $s->id }}" class="border-t border-slate-100 dark:border-gray-700">
                        <td class="table-td font-medium"><a href="{{ route('portal.subscription', $s->id) }}" wire:navigate class="link">{{ $s->description }}</a></td>
                        <td class="table-td">{{ $s->project?->name ?? 'All projects' }}</td>
                        <td class="table-td">{{ ucfirst($s->frequency->value) }}</td>
                        <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($s->amount_cents, $cur) }}</td>
                        <td class="table-td whitespace-nowrap">{{ $s->active ? $s->next_due_on?->format('d M Y') : '—' }}</td>
                        <td class="table-td text-right tabular-nums" data-test="sub-usage">
                            {{ \App\Support\Money::format((int) $s->invoiced_cents, $cur) }}
                            <div class="text-xs text-slate-500 dark:text-gray-400">{{ $s->invoiced_count }} {{ \Illuminate\Support\Str::plural('time', $s->invoiced_count) }}</div>
                        </td>
                        <td class="table-td"><x-ui.badge :color="$color">{{ $badge }}</x-ui.badge></td>
                        <td class="table-td text-right"><a href="{{ route('portal.subscription', $s->id) }}" wire:navigate class="link whitespace-nowrap text-sm">View details</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
