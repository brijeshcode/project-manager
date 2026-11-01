<div>
    <x-ui.page-header title="Settings" subtitle="Local currency and exchange rates used to convert client payments." />

    <form wire:submit="saveLocalCurrency" class="card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="localCurrency" class="label">Local currency</label>
            <select wire:model="localCurrency" id="localCurrency" class="select" @disabled($locked)>
                @foreach ($codes as $c)
                    <option value="{{ $c }}" @selected($c === $local)>{{ $c }}</option>
                @endforeach
            </select>
            @error('localCurrency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
            @if ($locked)
                <p class="mt-1 text-sm text-slate-500 dark:text-gray-400">Locked — money has already been recorded in {{ $local }}</p>
            @endif
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <button type="submit" class="btn btn-primary" @disabled($locked)>Save</button>
        </div>
    </form>

    <h2 class="mb-2 text-sm font-semibold text-slate-700 dark:text-gray-200">Exchange rates</h2>
    <form wire:submit="addRate" class="card mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div>
            <label for="currency" class="label">Currency</label>
            <input wire:model="currency" id="currency" type="text" list="rate-codes" maxlength="3" autocomplete="off" class="input uppercase" />
            <datalist id="rate-codes">
                @foreach ($rateCodes as $c)<option value="{{ $c }}"></option>@endforeach
            </datalist>
            @error('currency') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="rate" class="label">Rate (1 unit = ? {{ $local }})</label>
            <input wire:model="rate" id="rate" type="text" inputmode="decimal" autocomplete="off" class="input" />
            @error('rate') <p class="mt-1 field-error">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-end"><button type="submit" class="btn btn-primary">Add rate</button></div>
    </form>

    @if ($active->isEmpty())
        <x-ui.empty-state title="No exchange rates" message="Add a rate above." />
    @else
        <x-ui.table-card>
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead>
                    <tr><th class="table-th">Currency</th><th class="table-th">Rate</th><th class="table-th">Since</th><th class="table-th">By</th></tr>
                </thead>
                @foreach ($active as $r)
                    <tbody wire:key="rate-{{ $r->currency }}" x-data="{ open: false }" class="divide-y divide-slate-100">
                        <tr>
                            <td class="table-td"><button type="button" @click="open = !open" class="link">{{ $r->currency }}</button></td>
                            <td class="table-td font-mono">{{ $r->rate }}</td>
                            <td class="table-td">{{ $r->created_at->format('d M Y H:i') }}</td>
                            <td class="table-td">{{ $r->creator?->name }}</td>
                        </tr>
                        <tr x-show="open" style="display:none">
                            <td colspan="4" class="table-td">
                                <p class="label">History</p>
                                <ul class="space-y-1">
                                    @foreach ($histories[$r->currency] as $h)
                                        <li wire:key="h-{{ $h->id }}" class="flex flex-wrap items-center gap-3">
                                            <span class="font-mono">{{ $h->rate }}</span>
                                            @if ($h->is_active)<x-ui.badge color="green">Active</x-ui.badge>@endif
                                            <span>{{ $h->created_at->format('d M Y H:i') }}</span>
                                            <span class="text-slate-500 dark:text-gray-400">{{ $h->creator?->name }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                @endforeach
            </table>
        </x-ui.table-card>
    @endif
</div>
