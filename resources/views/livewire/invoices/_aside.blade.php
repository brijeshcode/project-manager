{{-- Rows removed from the invoice, kept on the page only. Shared by create and edit. --}}
                @if ($parkedGroups || $parkedCustom)
                    <div class="card border-dashed p-6">
                        <h2 class="{{ $h2 }}">Set aside</h2>
                        <p class="{{ $hint }}">Removed from this invoice and not saved anywhere. Put a row back, or drop it: its work leaves this invoice and becomes billable again.</p>
                        <ul class="mt-4 divide-y divide-slate-100 rounded-lg bg-slate-50 dark:divide-gray-700 dark:bg-gray-700/30">
                            @foreach ($parkedGroups as $g => $group)
                                <li wire:key="aside-{{ $g }}-{{ $group['lines'][0] ?? $g }}" class="flex items-center gap-3 px-4 py-3 text-sm text-slate-500 dark:text-gray-400">
                                    <span class="min-w-0 flex-1">{{ $group['label'] }}@isset($group['amount']) <x-ui.badge class="ml-1 inline-flex items-center gap-1"><x-icon name="repeat" class="h-3 w-3" />Recurring</x-ui.badge>@endisset</span>
                                    <span class="tabular-nums">{{ $fmt($summary['parked'][$g] ?? 0) }}</span>
                                    <button type="button" wire:click="restoreGroup({{ $g }})" class="btn btn-ghost px-2 text-brand-600 dark:text-blue-300">Put back</button>
                                    <button type="button" wire:click="forgetGroup({{ $g }})" class="btn btn-ghost px-2" title="Leave it off this invoice">Drop</button>
                                </li>
                            @endforeach
                            @foreach ($parkedCustom as $j => $c)
                                <li wire:key="aside-custom-{{ $j }}" class="flex items-center gap-3 px-4 py-3 text-sm text-slate-500 dark:text-gray-400">
                                    <span class="min-w-0 flex-1">{{ $c['description'] !== '' ? $c['description'] : 'Untitled item' }} <span class="text-xs">(custom)</span></span>
                                    <button type="button" wire:click="restoreCustom({{ $j }})" class="btn btn-ghost px-2 text-brand-600 dark:text-blue-300">Put back</button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

