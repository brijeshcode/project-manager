<div class="space-y-4">
    <div class="flex items-center justify-between">
        <p class="text-sm text-gray-500 dark:text-gray-400">Recurring services billed to this client.</p>
        <a href="{{ route('billing.recurring.create', ['client' => $client->id]) }}" wire:navigate class="btn btn-primary"><x-nav.icon name="plus" class="h-5 w-5" /> Add subscription</a>
    </div>

    @if ($subscriptions->isEmpty())
        <x-ui.empty-state title="No subscriptions yet" message="Recurring services for this client, like hosting or maintenance, will appear here." name="refresh" />
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="subscriptions">
                <thead>
                    <tr>
                        <th class="table-th">Description</th><th class="table-th">Project</th><th class="table-th">Frequency</th><th class="table-th text-right">Amount</th>
                        <th class="table-th">Next due</th><th class="table-th">Status</th><th class="table-th text-right">Invoiced</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($subscriptions as $s)
                        <tr wire:key="sub-{{ $s->id }}">
                            <td class="table-td"><a href="{{ route('billing.recurring.show', ['charge' => $s->id]) }}" wire:navigate class="link font-medium">{{ $s->description }}</a></td>
                            <td class="table-td">{{ $s->project?->name ?? 'Client-wide' }}</td>
                            <td class="table-td">{{ ucfirst($s->frequency->value) }}</td>
                            <td class="table-td text-right tabular-nums">{{ \App\Support\Money::format($s->amount_cents, $s->display_currency) }}</td>
                            <td class="table-td whitespace-nowrap">{{ $s->active ? $s->next_due_on?->format('M j, Y') : '—' }}</td>
                            <td class="table-td"><x-ui.badge :color="$s->active ? 'green' : 'slate'">{{ $s->active ? 'Active' : 'Paused' }}</x-ui.badge></td>
                            <td class="table-td text-right tabular-nums">
                                {{ \App\Support\Money::format($s->total_invoiced, $s->display_currency) }}
                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $s->times_invoiced }} {{ Str::plural('time', $s->times_invoiced) }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
