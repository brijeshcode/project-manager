<div>
    <x-ui.breadcrumb :items="array_filter([['Payments', route('payments.index')], $paymentId ? ['Payment #'.$paymentId, route('payments.show', $paymentId)] : null, [$paymentId ? 'Edit' : 'Record payment', null]])" />
    <x-ui.page-header :title="$paymentId ? 'Edit payment' : 'Record payment'" :subtitle="'Money received from a client settles their balance. Local currency: '.$local" />

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Payment</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="client_id" class="label">Client *</label>
                        <select wire:model.live="client_id" id="client_id" class="select">
                            <option value="">Select...</option>
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
                    <div>
                        <label for="paid_on" class="label">Paid on *</label>
                        <input wire:model="paid_on" id="paid_on" type="date" max="{{ today()->toDateString() }}" class="input dark:[color-scheme:dark]" />
                        @error('paid_on') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="type" class="label">Type</label>
                        <select wire:model="type" id="type" class="select">
                            @foreach ($types as $t)<option value="{{ $t->value }}">{{ ucfirst(str_replace('_', ' ', $t->value)) }}</option>@endforeach
                        </select>
                        @error('type') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="method" class="label">Method (optional)</label>
                        <input wire:model="method" id="method" type="text" maxlength="100" placeholder="Bank transfer, UPI, PayPal..." class="input" />
                        @error('method') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </section>

            <section class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Amount</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="currency" class="label">Currency</label>
                        <select wire:model.live="currency" id="currency" class="select" @disabled(! $client_id)>
                            @unless ($client_id)<option value="">Pick a client</option>@endunless
                            @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
                        </select>
                        @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="amount" class="label">Amount{{ $formCurrency ? ' ('.$formCurrency.')' : '' }} *</label>
                        <input wire:model.live.debounce.300ms="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="input tabular-nums" />
                        @error('amount') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($needsRate)
                        <div>
                            <label for="exchange_rate" class="label">Rate to {{ $local }}</label>
                            <input wire:model.live.debounce.300ms="exchange_rate" id="exchange_rate" type="text" inputmode="decimal" autocomplete="off" class="input tabular-nums" />
                            @error('exchange_rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>
                @if ($preview)
                    <p class="mt-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:bg-gray-700/40 dark:text-gray-300"><span class="font-semibold tabular-nums">≈ {{ $preview }}</span> in {{ $local }}. The rate is frozen on this payment.</p>
                @endif
            </section>

            <section class="card p-6">
                <h2 class="mb-4 text-base font-semibold text-gray-900 dark:text-white">Notes and documents</h2>
                <div class="space-y-4">
                    <div>
                        <label for="notes" class="label">Notes (optional, internal)</label>
                        <textarea wire:model="notes" id="notes" rows="2" maxlength="500" class="textarea"></textarea>
                        @error('notes') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    @if ($documents->isNotEmpty())
                        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm dark:divide-gray-700 dark:border-gray-700">
                            @foreach ($documents as $d)
                                <li wire:key="doc-{{ $d->id }}" class="flex items-center gap-3 px-3 py-2">
                                    <x-icon name="file-text" class="h-4 w-4 text-slate-400" />
                                    <a href="{{ route('payment-documents.download', $d->id) }}" class="link min-w-0 flex-1 truncate">{{ $d->original_name }}</a>
                                    <button type="button" wire:click="removeDocument({{ $d->id }})" wire:confirm="Remove this document?" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Remove" aria-label="Remove {{ $d->original_name }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    <div>
                        <label for="newDocs" class="label">{{ $documents->isNotEmpty() ? 'Add documents' : 'Documents' }} (optional)</label>
                        <input wire:model="newDocs" id="newDocs" type="file" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx,.xls,.xlsx" class="input" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">Up to 5 files: PDF, images, Word or Excel, 10 MB each.</p>
                        <div wire:loading wire:target="newDocs" class="mt-1 text-xs text-slate-500">Uploading...</div>
                        @error('newDocs') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                        @error('newDocs.*') <p class="mt-1 field-error">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-start gap-3 rounded-lg border border-slate-200 p-3 dark:border-gray-700">
                        <input type="checkbox" wire:model="documents_visible_to_client" id="documents_visible_to_client" class="mt-0.5 h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                        <span class="text-sm">
                            <span class="font-medium text-gray-900 dark:text-white">Show documents to client</span>
                            <span class="block text-slate-500 dark:text-gray-400">The client always sees the payment in their portal; documents only when this is on.</span>
                        </span>
                    </label>
                </div>
            </section>
        </div>

        <aside class="space-y-6">
            <section class="card p-6">
                <h2 class="mb-3 text-base font-semibold text-gray-900 dark:text-white">Client balance</h2>
                @if ($client)
                    <p class="text-sm text-slate-500 dark:text-gray-400">{{ $client->name }}, before this {{ $paymentId ? 'change' : 'payment' }}</p>
                    <p @class(['mt-1 text-2xl font-bold tabular-nums', 'text-rose-600 dark:text-rose-400' => $balance < 0, 'text-emerald-700 dark:text-emerald-400' => $balance >= 0])>{{ \App\Support\Money::format($balance, $client->currency) }}</p>
                    @if ($balanceLocal !== null && strtoupper($client->currency) !== $local)
                        <p class="text-sm tabular-nums text-slate-500 dark:text-gray-400">{{ \App\Support\Money::format($balanceLocal, $local) }}</p>
                    @endif
                    <p class="mt-1 text-xs text-slate-500 dark:text-gray-400">{{ $balance < 0 ? 'Owes' : 'In credit' }}</p>
                @else
                    <p class="text-sm text-slate-500 dark:text-gray-400">Pick a client to see what they owe.</p>
                @endif
            </section>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary flex-1" wire:loading.attr="disabled">{{ $paymentId ? 'Save changes' : 'Record payment' }}</button>
                <a href="{{ $paymentId ? route('payments.show', $paymentId) : route('payments.index') }}" wire:navigate class="btn btn-ghost">Cancel</a>
            </div>
        </aside>
    </form>
</div>
