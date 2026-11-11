{{-- Invoice items: work groups, existing manual lines (edit), custom lines, totals. Shared by create and edit. --}}
                <div class="card p-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="{{ $h2 }}">Invoice items</h2>
                            <p class="{{ $hint }}">These rows print on the invoice. Edit any label; remove a row and it moves to <em>Set aside</em>.</p>
                        </div>
                        <button type="button" wire:click="addCustom" class="btn btn-secondary shrink-0"><x-icon name="plus" class="h-4 w-4" />Custom item</button>
                    </div>

                    @if (! $groups && ! $custom && empty($edits))
                        <p class="mt-4 {{ $empty }}">No items yet. Tick work above and press <em>Add to invoice</em>, or add a custom item such as hosting or an advance.</p>
                    @endif

                    @if ($groups || $custom || ! empty($edits))
                        <div class="mt-4 overflow-hidden rounded-lg border border-slate-200 dark:border-gray-700">
                            @include('livewire.invoices._items-head', ['showQty' => $showQuantity])
                            <div class="divide-y divide-slate-100 dark:divide-gray-700">
                                @foreach ($groups as $g => $group)
                                    @php $groupAmount = $fmt($summary['groups'][$g] ?? 0); $n = count($group['lines']); @endphp
                                    <div wire:key="group-{{ $g }}-{{ $group['lines'][0] ?? $g }}" class="flex flex-wrap items-start gap-3 px-4 py-3 sm:flex-nowrap">
                                        <div class="min-w-0 basis-full sm:basis-auto sm:flex-1">
                                            <label for="group-{{ $g }}" class="sr-only">Description on invoice</label>
                                            <input wire:model.live.debounce.500ms="groups.{{ $g }}.label" id="group-{{ $g }}" type="text" maxlength="500" placeholder="e.g. Sprint 1 (Oct 22 – Nov 4): completed FLE-1, FLE-2" class="input" />
                                            <p class="mt-1 text-xs text-slate-400 dark:text-gray-500">{{ $n > 1 ? $n.' tasks · ' : '' }}Edit the text as it should print</p>
                                            @error("groups.$g.label") <p class="field-error">{{ $message }}</p> @enderror
                                        </div>
                                        @if ($showQuantity)
                                            <div class="flex h-10 w-20 shrink-0 items-center justify-end text-sm text-slate-500 dark:text-gray-400">1</div>
                                            <div class="flex h-10 w-32 shrink-0 items-center justify-end text-sm tabular-nums text-slate-500 dark:text-gray-400">{{ $groupAmount }}</div>
                                            <div class="flex h-10 w-32 shrink-0 items-center justify-end text-sm font-medium tabular-nums text-slate-800 dark:text-gray-100">{{ $groupAmount }}</div>
                                        @else
                                            <div class="flex h-10 w-36 shrink-0 items-center justify-end text-sm font-medium tabular-nums text-slate-800 dark:text-gray-100">{{ $groupAmount }}</div>
                                        @endif
                                        <button type="button" wire:click="removeGroup({{ $g }})" class="btn btn-ghost h-10 w-10 shrink-0 justify-center px-0 text-slate-400 hover:text-rose-600" title="Remove" aria-label="Remove from invoice">&times;</button>
                                    </div>
                                @endforeach
                                @foreach ($edits ?? [] as $id => $row)
                                    @include('livewire.invoices._custom-line', ['showQty' => $showQuantity, 'model' => 'edits.'.$id, 'remove' => 'removeEdit('.$id.')', 'amount' => $summary['edits'][$id] ?? 0, 'tag' => 'On this invoice'])
                                @endforeach
                                @foreach ($custom as $i => $row)
                                    @include('livewire.invoices._custom-line', ['showQty' => $showQuantity])
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @error('lines') <p class="field-error">{{ $message }}</p> @enderror

                    <dl class="ml-auto mt-6 w-full max-w-xs space-y-2 text-sm">
                        <div class="flex justify-between text-slate-600 dark:text-gray-300"><dt>Subtotal ({{ $summary['count'] }} {{ \Illuminate\Support\Str::plural('item', $summary['count']) }})</dt><dd class="tabular-nums">{{ $fmt($summary['subtotal']) }}</dd></div>
                        <div class="flex justify-between text-slate-600 dark:text-gray-300"><dt>Tax</dt><dd class="tabular-nums">{{ $fmt($summary['tax']) }}</dd></div>
                        <div class="flex justify-between border-t border-slate-200 pt-2 text-base font-semibold text-gray-900 dark:border-gray-700 dark:text-white"><dt>Total</dt><dd class="tabular-nums" data-test="invoice-total">{{ $fmt($summary['subtotal'] + $summary['tax']) }}</dd></div>
                        @unless ($sameCurrency)
                            <div class="pt-1">
                                <label class="inline-flex cursor-pointer items-center gap-2 text-xs text-slate-500 dark:text-gray-400">
                                    <input type="checkbox" wire:model.live="showLocal" class="h-4 w-4 rounded text-brand-600 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-700" />
                                    Show in {{ $local }}
                                </label>
                                @if ($showLocal)
                                    <div class="mt-1 flex justify-between text-slate-600 dark:text-gray-300" data-test="local-total">
                                        <dt>You receive about</dt>
                                        <dd class="font-medium tabular-nums">{{ $summary['local'] !== null ? \App\Support\Money::format($summary['local'], $local) : 'Set an exchange rate' }}</dd>
                                    </div>
                                    <p class="text-xs text-slate-400 dark:text-gray-500">At {{ $summary['rate'] ?? '—' }} {{ $local }} per {{ $summary['currency'] }}. Only shown here; not saved or printed.</p>
                                @endif
                            </div>
                        @endunless
                    </dl>
                </div>

