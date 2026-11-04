@php
    $user = auth()->user();
    $isClient = $user->hasRole('client');
    $canAdd = ! $isClient;
@endphp
<div class="space-y-4" data-test="resources-panel">
    <div class="flex flex-wrap items-center gap-3">
        <h2 class="font-display text-sm font-semibold text-slate-900">Resources</h2>
        <div class="relative min-w-[12rem] flex-1 sm:max-w-xs">
            <x-icon name="search" class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search resources…" aria-label="Search resources" class="input h-9 pl-8 text-sm" data-test="resource-search">
        </div>
        <select wire:model.live="labelFilter" aria-label="Filter by label" class="select h-9 w-auto text-sm" data-test="resource-label-filter">
            <option value="">All labels</option>
            @foreach ($this->filterLabels as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
        </select>
        @if ($canAdd)
            <button type="button" wire:click="openAdd" class="btn btn-primary ml-auto h-9 px-3 py-1 text-sm" data-test="add-resource"><x-icon name="plus" class="h-4 w-4" /> Add resource</button>
        @endif
    </div>

    @forelse ($this->resources as $r)
        @php $canEdit = $r->canEdit($user); @endphp
        <x-ui.card padding="p-0" wire:key="res-{{ $r->id }}" data-test="resource-row" data-id="{{ $r->id }}" @resources-changed.window="open && load(false)" x-data="{
                open: false, busy: false, html: '', raw: '', log: '', note: '',
                async load(spin = true) {
                    this.busy = spin;
                    const r = await $wire.reveal({{ $r->id }});
                    this.html = r.html; this.raw = r.raw; this.log = r.log; this.busy = false;
                },
                async toggle() {
                    if (this.open) { this.open = false; this.html = this.raw = this.log = ''; return; }
                    this.open = true;
                    await this.load();
                },
                async copy() {
                    try { await navigator.clipboard.writeText(this.raw); } catch (e) {
                        const t = document.createElement('textarea'); t.value = this.raw; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
                    }
                    this.note = 'Copied'; setTimeout(() => this.note = '', 1500);
                },
            }">
            <div class="flex cursor-pointer flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5" @click="if (!$event.target.closest('[data-no-drag], [data-stop]')) toggle()">
                <x-icon name="lock" class="h-4 w-4 shrink-0 text-slate-400" />
                <button type="button" class="min-w-0 flex-1 basis-40 truncate text-left font-display text-sm font-semibold text-slate-900 hover:text-brand-600" :aria-expanded="open" data-test="resource-toggle">{{ $r->title }}</button>
                <span class="flex flex-wrap gap-1">
                    @foreach ($r->labels as $l)<x-ui.label-chip :label="$l" />@endforeach
                </span>
                @if ($r->creator)<x-ui.avatar :user="$r->creator" data-stop data-test="resource-creator" />@endif
                <span class="w-24 text-xs text-slate-500" data-test="resource-date">{{ $r->created_at->format('M j, Y') }}</span>
                <x-ui.assignees :users="$r->viewers" :candidates="$canEdit ? $this->candidates : null" method="toggleViewer" :id="$r->id" data-test="resource-viewers" />
                <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-slate-400 transition" ::class="open && 'rotate-90'" />
            </div>
            <div x-show="open" x-collapse style="display:none" class="border-t border-slate-100 dark:border-gray-700">
                <div class="space-y-3 px-5 py-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="mr-auto flex items-center gap-1 text-xs text-slate-500"><x-icon name="shield" class="h-3.5 w-3.5" /> Access to this value is logged</p>
                        <span x-show="note" x-text="note" style="display:none" class="text-xs font-medium text-emerald-600" role="status"></span>
                        <button type="button" @click="copy()" :disabled="busy" class="btn btn-secondary h-7 px-2.5 py-1 text-xs" data-test="resource-copy"><x-icon name="copy" class="h-3.5 w-3.5" /> Copy</button>
                        @if ($canEdit)
                            <button type="button" wire:click="startEdit({{ $r->id }})" class="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-gray-700" title="Edit" aria-label="Edit resource" data-test="resource-edit"><x-icon name="pencil" class="h-4 w-4" /></button>
                            <div class="relative" x-data="{ menu: false }" @click.outside="menu = false" @keydown.escape="menu = false">
                                <button type="button" @click="menu = !menu" class="rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-gray-700" title="More actions" aria-label="More actions" aria-haspopup="menu" data-test="resource-menu"><x-icon name="ellipsis" class="h-4 w-4" /></button>
                                <div x-show="menu" style="display:none" role="menu" class="absolute right-0 z-20 mt-1 w-40 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-gray-600 dark:bg-gray-800">
                                    <button type="button" role="menuitem" wire:click="deleteResource({{ $r->id }})" wire:confirm="Delete this resource and its files?" class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-rose-600 hover:bg-slate-50 dark:text-rose-400 dark:hover:bg-gray-700" data-test="resource-delete"><x-icon name="trash-2" class="h-4 w-4" /> Delete</button>
                                </div>
                            </div>
                        @endif
                    </div>
                    <p x-show="busy" class="text-xs text-slate-400">Loading…</p>
                    <div wire:ignore class="md break-words rounded-lg bg-slate-50 px-4 py-3 text-sm dark:bg-gray-900/40" x-show="!busy" x-html="html" data-test="resource-value"></div>
                    @if ($canEdit)
                        <div class="border-t border-slate-100 pt-3 dark:border-gray-700">
                            <p class="mb-2 flex items-center gap-1 text-xs font-semibold text-slate-700 dark:text-gray-200"><x-icon name="history" class="h-3.5 w-3.5" /> Access log</p>
                            <div wire:ignore x-html="log"></div>
                        </div>
                    @endif
                </div>
            </div>
        </x-ui.card>
    @empty
        <x-ui.empty-state title="{{ $search !== '' || $labelFilter !== '' ? 'No matching resources' : 'No resources yet' }}"
                          message="{{ $canAdd ? 'Store credentials, links and notes here. Values are encrypted and every reveal is logged.' : 'Nothing has been shared with you yet.' }}" />
    @endforelse

    @if ($showForm)
        <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" role="dialog" aria-modal="true" aria-label="{{ $editingId ? 'Edit resource' : 'Add resource' }}" data-test="resource-form"
             x-data @keydown.escape.window="$wire.cancelForm()">
            <form wire:submit="save" class="card w-full max-w-2xl space-y-4 p-5">
                <div class="flex items-center justify-between">
                    <h3 class="font-display text-base font-semibold text-slate-900">{{ $editingId ? 'Edit resource' : 'Add resource' }}</h3>
                    <button type="button" wire:click="cancelForm" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Close"><x-icon name="x" class="h-4 w-4" /></button>
                </div>
                <div>
                    <label for="res-title" class="label">Title</label>
                    <input id="res-title" type="text" wire:model="form.title" class="input" placeholder="e.g. Production database" autofocus data-test="form-title">
                    @error('form.title') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="label">Value <span class="font-normal text-slate-400">(encrypted at rest; attach images or PDFs)</span></p>
                    <x-ui.markdown-editor model="form.value" :rows="8" placeholder="Username, password, links, notes…" />
                    @error('form.value') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="label">Labels</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->labels as $l)
                            <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs"><input type="checkbox" wire:model="form.labels" value="{{ $l->id }}" class="rounded border-slate-300 text-brand-600" data-test="form-label-{{ $l->id }}"><x-ui.label-chip :label="$l" /></label>
                        @endforeach
                    </div>
                </div>
                <div>
                    <p class="label">Who can view <span class="font-normal text-slate-400">(you, the creator, can always edit)</span></p>
                    <div class="grid max-h-40 gap-1 overflow-y-auto sm:grid-cols-2">
                        @foreach ($this->candidates as $c)
                            @continue($c->id === auth()->id() && ! $editingId)
                            <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 text-sm text-slate-700 hover:bg-slate-100 dark:text-gray-200 dark:hover:bg-gray-700"><input type="checkbox" wire:model="form.viewers" value="{{ $c->id }}" class="rounded border-slate-300 text-brand-600" data-test="form-viewer-{{ $c->id }}"> {{ $c->name }}</label>
                        @endforeach
                    </div>
                    @error('form.viewers.*') <p class="field-error">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2 border-t border-slate-100 pt-3 dark:border-gray-700">
                    <button type="button" wire:click="cancelForm" class="btn btn-ghost h-9 px-3 py-1 text-sm">Cancel</button>
                    <button type="submit" class="btn btn-primary h-9 px-3 py-1 text-sm" data-test="form-save">{{ $editingId ? 'Save changes' : 'Add resource' }}</button>
                </div>
            </form>
        </div>
    @endif
</div>
