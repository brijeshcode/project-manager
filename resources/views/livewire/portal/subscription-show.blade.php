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

    @if ($charge->skipped_periods || ($charge->client_approved_at && ! $charge->client_note))
        <div class="mb-6 space-y-1 text-sm text-slate-500 dark:text-gray-400">
            @if ($charge->client_approved_at && ! $charge->client_note)
                <p data-test="team-approved">Approved by our team on {{ $charge->client_approved_at->toFormattedDateString() }}.</p>
            @endif
            @if ($charge->skipped_periods)
                <p data-test="skipped">Not billed (skipped): {{ collect($charge->skipped_periods)->map($periodLabel)->implode(', ') }}</p>
            @endif
        </div>
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

    <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Invoice history</h2>
    @if ($usages->isEmpty())
        <x-ui.empty-state title="Not invoiced yet" message="Each billing period of this service will appear here once it is on an invoice." name="document" />
    @else
        <div class="card overflow-x-auto">
            <table class="min-w-full text-sm" data-test="subscription-usages">
                <thead><tr><th class="table-th">Invoice</th><th class="table-th">Issued</th><th class="table-th">Period</th><th class="table-th text-right">Amount</th></tr></thead>
                <tbody>
                    @foreach ($usages as $l)
                        <tr wire:key="use-{{ $l->id }}" class="border-t border-slate-100 dark:border-gray-700">
                            <td class="table-td"><a href="{{ route('portal.invoice', $l->invoice_id) }}" wire:navigate class="link font-medium">{{ $l->invoice->number }}</a></td>
                            <td class="table-td">{{ $l->invoice->issued_on->format('d M Y') }}</td>
                            <td class="table-td">{{ $periodLabel($l->period) }}</td>
                            <td class="table-td text-right tabular-nums">{{ $money($l->amount_cents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
