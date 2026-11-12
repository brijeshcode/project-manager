@php
    $cur = $charge->currency();
    $money = fn ($c, $code = null) => \App\Support\Money::format((int) $c, $code ?? $cur);
    $periodLabel = fn ($p) => $p === null ? '—' : (strlen($p) === 7 ? \Carbon\Carbon::createFromFormat('!Y-m', $p)->format('M Y') : $p);
    $other = strtoupper($cur) !== $local;
@endphp
<div>
    <x-ui.breadcrumb :items="[['Recurring services', route('billing.recurring')], [$charge->description, null]]" />

    <x-ui.page-header :title="$charge->description" :subtitle="$charge->client->name.' · '.($charge->project?->name ?? 'Client-wide')">
        <x-slot:actions>
            @php [$badge, $badgeColor] = $charge->statusBadge(); @endphp
            <x-ui.badge :color="$badgeColor">{{ $badge }}</x-ui.badge>
            @if ($charge->pass_through)<x-ui.badge title="A cost passed on to the client: billed as usual, not counted as income" data-test="pass-through-badge">Pass-through</x-ui.badge>@endif
            <button type="button" wire:click="toggle" class="btn btn-secondary">{{ $charge->active ? 'Pause' : ($charge->client_state === \App\Models\RecurringCharge::AWAITING ? 'Start without approval' : 'Resume') }}</button>
            <a href="{{ route('billing.recurring.edit', $charge) }}" wire:navigate class="btn btn-primary"><x-icon name="pencil" class="h-4 w-4" /> Edit</a>
            @if ($usages->isEmpty())
                <button type="button" wire:click="delete" wire:confirm="Delete this service? Any lines it added that are not on an invoice are removed too." class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete service"><x-icon name="trash-2" class="h-4 w-4" /></button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Amount" :value="$money($charge->amount_cents)" icon="currency" color="blue" :hint="'Billed '.$charge->frequency->value.($other && $charge->local_amount_cents !== null ? ' · ≈ '.$money($charge->local_amount_cents, $local) : '')" />
        <x-ui.stat label="Next due" :value="$charge->active ? $charge->next_due_on->toFormattedDateString() : '—'" icon="calendar" color="yellow" :hint="$charge->active ? $charge->next_due_on->diffForHumans() : ($charge->resume_on ? 'Resumes '.$charge->resume_on->toFormattedDateString() : 'Paused')" />
        <x-ui.stat label="Times invoiced" :value="$charge->invoiced_count" icon="check" color="green" :hint="$charge->draft_count ? $charge->draft_count.' more on draft invoices' : 'On sent invoices'" />
        <x-ui.stat label="Invoiced to date" :value="$money($charge->invoiced_cents)" icon="refresh" color="purple" :hint="$other && $charge->invoiced_count ? $money($charge->invoiced_local_cents, $local) : 'Sent invoices only'" />
    </div>

    @if ($charge->client_state === \App\Models\RecurringCharge::CANCEL_REQUESTED)
        <div class="mb-6 rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-900/50 dark:bg-rose-900/10" data-test="cancel-request">
            <p class="font-semibold text-rose-900 dark:text-rose-200">The client asked to unsubscribe on {{ $charge->cancel_requested_at?->toFormattedDateString() }}.</p>
            <p class="mt-1 whitespace-pre-line text-rose-800 dark:text-rose-300">{{ $charge->cancel_note }}</p>
            <div class="mt-3 flex gap-2">
                <button type="button" wire:click="decideCancel(true)" wire:confirm="End this subscription? Nothing more will be billed." class="btn btn-danger" data-test="approve-cancel">Approve and end</button>
                <button type="button" wire:click="decideCancel(false)" class="btn btn-secondary" data-test="reject-cancel">Keep it running</button>
            </div>
        </div>
    @elseif ($charge->client_state === \App\Models\RecurringCharge::AWAITING)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-900/10 dark:text-amber-200">
            Waiting for the client to approve this subscription in their portal. Nothing is billed until then.
        </div>
    @endif

    @if (! in_array($charge->client_state, [\App\Models\RecurringCharge::AWAITING, \App\Models\RecurringCharge::ENDED], true))
        <x-ui.card class="mb-6" data-test="schedule">
            <h2 class="mb-3 text-sm font-semibold text-slate-900 dark:text-white">Schedule</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                @if ($charge->active)
                    <form wire:submit="skip" wire:confirm="Skip these periods? They will not be billed." class="flex flex-wrap items-end gap-2" data-test="skip-form">
                        <div>
                            <label for="skip-count" class="label">Skip periods</label>
                            <select id="skip-count" wire:model="skipCount" class="select w-auto">
                                @for ($i = 1; $i <= 12; $i++)<option value="{{ $i }}">{{ $i }} {{ $charge->frequency->value === 'yearly' ? Str::plural('year', $i) : Str::plural('month', $i) }}</option>@endfor
                            </select>
                        </div>
                        <button type="submit" class="btn btn-secondary" data-test="skip-submit">Skip</button>
                        <p class="w-full text-xs text-slate-500 dark:text-gray-400">Moves the next due date on without billing; billing continues after.</p>
                        @error('skipCount') <p class="field-error w-full">{{ $message }}</p> @enderror
                    </form>
                @endif
                <form wire:submit="pauseUntil" class="flex flex-wrap items-end gap-2" data-test="pause-until-form">
                    <div>
                        <label for="resume-on" class="label">{{ $charge->resume_on ? 'Change resume date' : 'Pause until' }}</label>
                        <input id="resume-on" type="date" wire:model="resumeOn" min="{{ today()->addDay()->toDateString() }}" class="input w-auto dark:[color-scheme:dark]">
                    </div>
                    <button type="submit" class="btn btn-secondary" data-test="pause-until-submit">{{ $charge->resume_on ? 'Update' : 'Pause' }}</button>
                    <p class="w-full text-xs text-slate-500 dark:text-gray-400">Paused periods are not billed; it resumes on that date by itself.</p>
                    @error('resumeOn') <p class="field-error w-full">{{ $message }}</p> @enderror
                </form>
            </div>
            @if ($charge->skipped_periods)
                <p class="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-gray-700 dark:text-gray-400" data-test="skipped">
                    Skipped: {{ collect($charge->skipped_periods)->map($periodLabel)->implode(', ') }}
                </p>
            @endif
        </x-ui.card>
    @endif

    @if ($charge->client_approved_at && ! $charge->client_note)
        <p class="mb-6 flex items-center gap-1.5 text-sm text-slate-500 dark:text-gray-400" data-test="team-approved"><x-icon name="circle-check-big" class="h-4 w-4 text-emerald-600" />Approved by your team on {{ $charge->client_approved_at->toFormattedDateString() }}.</p>
    @endif

    @if ($charge->client_note || ($charge->cancel_note && $charge->client_state !== \App\Models\RecurringCharge::CANCEL_REQUESTED))
        <x-ui.card class="mb-6 space-y-3 text-sm" data-test="client-notes">
            @if ($charge->client_note)
                <div><p class="font-medium text-slate-900 dark:text-white">Client's note when subscribing <span class="font-normal text-slate-500">· {{ $charge->client_approved_at?->toFormattedDateString() }}</span></p><p class="mt-1 whitespace-pre-line text-slate-700 dark:text-gray-300">{{ $charge->client_note }}</p></div>
            @endif
            @if ($charge->cancel_note && $charge->client_state !== \App\Models\RecurringCharge::CANCEL_REQUESTED)
                <div><p class="font-medium text-slate-900 dark:text-white">Client's note when unsubscribing <span class="font-normal text-slate-500">· {{ $charge->cancel_requested_at?->toFormattedDateString() }}</span></p><p class="mt-1 whitespace-pre-line text-slate-700 dark:text-gray-300">{{ $charge->cancel_note }}</p></div>
            @endif
        </x-ui.card>
    @endif

    @if ($pending->isNotEmpty())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-800 dark:bg-amber-900/20 dark:text-amber-300" data-test="pending">
            {{ $pending->count() }} {{ \Illuminate\Support\Str::plural('period', $pending->count()) }} waiting to be invoiced
            ({{ $pending->map(fn ($l) => $periodLabel($l->period))->implode(', ') }}) · {{ $money($pending->sum('amount_cents')) }}.
            <a href="{{ route('invoices.create') }}" class="link font-medium">Create invoice</a>
        </div>
    @endif

    <h2 class="mb-3 text-lg font-semibold text-gray-900 dark:text-white">Invoice history</h2>
    @if ($usages->isEmpty())
        <x-ui.empty-state title="Not invoiced yet" message="Each time this service falls due it adds a line you can include on the client's next invoice." name="document" />
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                <thead>
                    <tr>
                        <th class="table-th">Invoice</th>
                        <th class="table-th">Issued</th>
                        <th class="table-th">Billing period</th>
                        <th class="table-th text-right">Amount</th>
                        <th class="table-th">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    @foreach ($usages as $l)
                        <tr wire:key="use-{{ $l->id }}" class="hover:bg-slate-50 dark:hover:bg-gray-700/40" data-test="usage-row">
                            <td class="table-td whitespace-nowrap"><a href="{{ route('invoices.show', $l->invoice_id) }}" class="link font-medium">{{ $l->invoice->number }}</a></td>
                            <td class="table-td whitespace-nowrap">{{ $l->invoice->issued_on->toFormattedDateString() }}</td>
                            <td class="table-td whitespace-nowrap">{{ $periodLabel($l->period) }}</td>
                            <td class="table-td whitespace-nowrap text-right tabular-nums">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $money($l->amount_cents) }}</span>
                                @if ($other && $l->local_amount_cents !== null)
                                    <div class="text-xs text-slate-500 dark:text-gray-400">{{ $money($l->local_amount_cents, $local) }}</div>
                                @endif
                            </td>
                            <td class="table-td"><x-ui.badge :color="$l->invoice->status === 'sent' ? 'brand' : 'amber'">{{ $l->invoice->status === 'sent' ? 'Sent' : 'Not sent' }}</x-ui.badge></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.table-card>
    @endif
</div>
