<div>
    <x-ui.page-header title="Edit payment" :subtitle="'Local currency: '.$local">
        <x-slot:actions>
            <a href="{{ route('payments.show', $paymentId) }}" class="btn btn-ghost">Cancel</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="card grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="client_id" class="label">Client</label>
            <select wire:model.live="client_id" id="client_id" class="select">
                <option value="">Select...</option>
                @foreach ($clients as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
            </select>
            @error('client_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="project_id" class="label">Project (optional)</label>
            <select wire:model="project_id" id="project_id" class="select">
                <option value="">None</option>
                @foreach ($projects as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            @error('project_id') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="currency" class="label">Currency</label>
            <select wire:model.live="currency" id="currency" class="select">
                @foreach ($currencies as $cur)<option value="{{ $cur }}">{{ $cur }}</option>@endforeach
            </select>
            @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="amount" class="label">Amount{{ $formCurrency ? ' ('.$formCurrency.')' : '' }}</label>
            <input wire:model.live.debounce.300ms="amount" id="amount" type="text" inputmode="decimal" placeholder="0.00" class="input" />
            @error('amount') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            @if ($preview)<p class="mt-1 text-xs text-gray-500 dark:text-gray-400">≈ {{ $preview }}</p>@endif
        </div>
        @if ($needsRate)
            <div>
                <label for="exchange_rate" class="label">Rate to {{ $local }}</label>
                <input wire:model.live.debounce.300ms="exchange_rate" id="exchange_rate" type="text" inputmode="decimal" autocomplete="off" class="input" />
                @error('exchange_rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            </div>
        @endif
        <div>
            <label for="paid_on" class="label">Paid on</label>
            <input wire:model="paid_on" id="paid_on" type="date" max="{{ today()->toDateString() }}" class="input" />
            @error('paid_on') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="type" class="label">Type</label>
            <select wire:model="type" id="type" class="select">
                @foreach ($types as $t)<option value="{{ $t->value }}">{{ ucfirst(str_replace('_', ' ', $t->value)) }}</option>@endforeach
            </select>
            @error('type') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="method" class="label">Method (optional)</label>
            <input wire:model="method" id="method" type="text" class="input" />
            @error('method') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="lg:col-span-2">
            <label for="notes" class="label">Notes (optional)</label>
            <input wire:model="notes" id="notes" type="text" class="input" />
            @error('notes') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-3"><button type="submit" class="btn btn-primary">Save changes</button></div>
    </form>
</div>
