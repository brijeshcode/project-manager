@php
    [$badge, $color] = $charge->statusBadge();
    $money = fn ($c) => \App\Support\Money::format((int) $c, $cur);
    $periodLabel = fn ($p) => $p === null ? '—' : (strlen($p) === 7 ? \Carbon\Carbon::createFromFormat('!Y-m', $p)->format('M Y') : $p);
    $awaiting = $charge->client_state === \App\Models\RecurringCharge::AWAITING;
    $canCancel = $charge->active && $charge->client_state === null;
@endphp
<div>
    <x-ui.breadcrumb :items="[['Subscriptions', route('portal.subscriptions')], [$charge->description, null]]" />

    <x-ui.page-header :title="$charge->description" :subtitle="$project?->name ?? 'All projects'">
        <x-slot:actions><x-ui.badge :color="$color">{{ $badge }}</x-ui.badge></x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Amount" :value="$money($charge->amount_cents)" icon="currency" color="blue" :hint="'Billed '.$charge->frequency->value" />
        <x-ui.stat label="Next due" :value="$charge->active ? $charge->next_due_on->toFormattedDateString() : '—'" icon="calendar" color="yellow" :hint="$charge->active ? $charge->next_due_on->diffForHumans() : $badge" />
        <x-ui.stat label="Times invoiced" :value="$charge->invoiced_count" icon="check" color="green" hint="On invoices sent to you" />
        <x-ui.stat label="Invoiced to date" :value="$money($charge->invoiced_cents)" icon="refresh" color="purple" />
    </div>

    @php
        $starts = $charge->starts_on ?? $charge->next_due_on ?? $charge->created_at;
        $ends = $charge->ends_on ?? $charge->ended_at;
    @endphp
    <div class="mb-6 space-y-2 text-sm text-slate-500 dark:text-gray-400">
        <p class="flex items-center gap-1.5" data-test="service-dates">
            <x-icon name="calendar" class="h-4 w-4" />
            {{ $starts->isFuture() ? 'Starts' : 'Started' }} {{ $starts->toFormattedDateString() }}
            · {{ $ends ? ($ends->isFuture() ? 'Ends ' : 'Ended ').$ends->toFormattedDateString() : 'No end date' }}
            @if (! $charge->active && $charge->resume_on) · Resumes {{ $charge->resume_on->toFormattedDateString() }}@endif
        </p>
        @if ($charge->client_approved_at && ! $charge->client_note)
            <p class="flex items-center gap-1.5" data-test="team-approved"><x-icon name="circle-check-big" class="h-4 w-4 text-emerald-600" />Approved by our team on {{ $charge->client_approved_at->toFormattedDateString() }}.</p>
        @endif
    </div>

    @php
        // price changes as billed to the client: runs of the same amount over sent-invoice periods only
        $prices = $usages->whereNotNull('period')->sortBy('period')->reduce(function ($runs, $l) {
            if ($runs && end($runs)[2] === (int) $l->amount_cents) {
                $runs[array_key_last($runs)][1] = $l->period;
            } else {
                $runs[] = [$l->period, $l->period, (int) $l->amount_cents];
            }

            return $runs;
        }, []);
    @endphp
    @if (count($prices) > 1)
        <x-ui.card class="mb-6" data-test="price-history">
            <details class="group">
                <summary class="flex cursor-pointer list-none items-center justify-between text-sm font-semibold text-gray-900 dark:text-white">
                    Price history <span class="font-normal text-slate-500 dark:text-gray-400">{{ count($prices) }} prices <x-icon name="chevron-down" class="inline h-4 w-4 transition group-open:rotate-180" /></span>
                </summary>
                <ol class="mt-3 space-y-1.5 text-sm">
                    @foreach (array_reverse($prices) as $i => [$from, $to, $cents])
                        <li class="flex justify-between gap-4">
                            <span class="text-slate-500 dark:text-gray-400">{{ $periodLabel($from) }} – {{ $i === 0 ? 'now' : $periodLabel($to) }}</span>
                            <span class="font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($cents) }}</span>
                        </li>
                    @endforeach
                </ol>
            </details>
        </x-ui.card>
    @endif

    @if ($awaiting || $canCancel)
        <x-ui.card class="mb-6" data-test="subscription-action">
            <form wire:submit="{{ $awaiting ? 'approve' : 'requestCancel' }}" class="space-y-3">
                <h2 class="text-sm font-semibold text-slate-900 dark:text-white">{{ $awaiting ? 'Approve this subscription' : 'Unsubscribe' }}</h2>
                <p class="text-sm text-slate-500 dark:text-gray-400">
                    {{ $awaiting ? 'We have added this service for you. Billing starts once you approve it.' : 'Tell us why you want to stop. The service keeps running until we confirm the cancellation.' }}
                </p>
                <div>
                    <label class="label" for="sub-note">Note</label>
                    <textarea id="sub-note" wire:model="note" rows="3" class="input" placeholder="{{ $awaiting ? 'Anything we should know before we start?' : 'Reason for cancelling' }}"></textarea>
                    @error('note') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <button type="submit" @class(['btn', 'btn-primary' => $awaiting, 'btn-danger' => ! $awaiting]) data-test="{{ $awaiting ? 'approve-subscription' : 'request-cancel' }}">{{ $awaiting ? 'Approve and start' : 'Request to unsubscribe' }}</button>
            </form>
        </x-ui.card>
    @elseif ($charge->client_state === \App\Models\RecurringCharge::CANCEL_REQUESTED)
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900 dark:border-rose-900/50 dark:bg-rose-900/10 dark:text-rose-200">
            You asked to unsubscribe on {{ $charge->cancel_requested_at?->toFormattedDateString() }}. We will confirm it shortly; until then the service continues.
        </div>
    @endif

    @if ($charge->client_note || $charge->cancel_note)
        <x-ui.card class="mb-6 space-y-3 text-sm">
            @if ($charge->client_note)
                <div><p class="font-medium text-slate-900 dark:text-white">Your note when subscribing <span class="font-normal text-slate-500">· {{ $charge->client_approved_at?->toFormattedDateString() }}</span></p><p class="mt-1 whitespace-pre-line text-slate-700 dark:text-gray-300">{{ $charge->client_note }}</p></div>
            @endif
            @if ($charge->cancel_note)
                <div><p class="font-medium text-slate-900 dark:text-white">Your note when unsubscribing <span class="font-normal text-slate-500">· {{ $charge->cancel_requested_at?->toFormattedDateString() }}</span></p><p class="mt-1 whitespace-pre-line text-slate-700 dark:text-gray-300">{{ $charge->cancel_note }}</p></div>
            @endif
        </x-ui.card>
    @endif

    @php
        $gaps = collect($charge->skipped_periods ?? [])->map(fn ($p) => ['period' => $p, 'kind' => 'Skipped'])
            ->merge(collect($charge->paused_periods ?? [])->map(fn ($p) => ['period' => $p, 'kind' => 'Paused']));
        $rows = $usages->toBase()->map(fn ($l) => ['period' => $l->period, 'line' => $l])->merge($gaps)->sortByDesc('period')->values();
    @endphp
    <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Invoice history</h2>
    @if ($rows->isEmpty())
        <x-ui.empty-state title="Not invoiced yet" message="Each billing period of this service will appear here once it is on an invoice." name="document" />
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700" data-test="subscription-usages">
                <thead>
                    <tr>
                        <th class="table-th">Invoice</th>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Billing period</th>
                        <th class="table-th text-right">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($rows as $row)
                        @unless ($l = $row['line'] ?? null)
                            <tr wire:key="gap-{{ $row['period'] }}" class="text-slate-500 dark:text-gray-400" data-test="gap-row">
                                <td class="table-td">—</td>
                                <td class="table-td">—</td>
                                <td class="table-td whitespace-nowrap">{{ $periodLabel($row['period']) }}</td>
                                <td class="table-td whitespace-nowrap text-right">Not billed <x-ui.badge :color="$row['kind'] === 'Paused' ? 'slate' : 'amber'" class="ml-1">{{ $row['kind'] }}</x-ui.badge></td>
                            </tr>
                            @continue
                        @endunless
                        <tr wire:key="use-{{ $l->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40">
                            <td class="table-td whitespace-nowrap"><a href="{{ route('portal.invoice', $l->invoice_id) }}" wire:navigate class="link font-medium">{{ $l->invoice->number }}</a></td>
                            <td class="table-td whitespace-nowrap">{{ $l->invoice->issued_on->toFormattedDateString() }}</td>
                            <td class="table-td whitespace-nowrap">{{ $periodLabel($l->period) }}</td>
                            <td class="table-td whitespace-nowrap text-right font-medium tabular-nums text-gray-900 dark:text-white">{{ $money($l->amount_cents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif
</div>
