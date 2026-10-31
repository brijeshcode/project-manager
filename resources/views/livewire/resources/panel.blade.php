<div class="space-y-4">
    <h2 class="text-sm font-semibold text-slate-900">Resources</h2>

    @error('assignees') <p class="field-error text-xs">{{ $message }}</p> @enderror

    @forelse ($this->resources as $resource)
        <x-ui.card wire:key="res-{{ $resource->id }}">
            <h3 class="font-semibold text-slate-900">{{ $resource->title }}</h3>
            @if ($resource->description)
                <p class="text-sm text-slate-500">{{ $resource->description }}</p>
            @endif

            <ul class="mt-3 space-y-3">
                @foreach ($resource->items as $item)
                    <li wire:key="item-{{ $item->id }}" class="rounded border border-slate-200 p-3 text-sm">
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $item->type }}</span>
                            <span class="font-medium text-slate-900">{{ $item->label }}</span>
                        </div>
                        @if ($item->type === 'text')
                            <p class="mt-1 whitespace-pre-wrap break-words text-slate-700">{{ $item->value }}</p>
                        @endif
                        <p class="mt-1 text-xs text-slate-500">{{ $item->created_at?->format('M j, Y') }} · {{ $item->creator?->name }}</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($item->assignees as $a)
                                <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $a->name }}</span>
                            @endforeach
                        </div>
                        @if (auth()->user()->hasRole('super_admin'))
                            <div class="mt-2 flex gap-2">
                                <select multiple wire:model="assigneeIds.{{ $item->id }}" class="input text-sm">
                                    @foreach ($this->candidates as $u)
                                        <option value="{{ $u->id }}" @selected($item->assignees->contains('id', $u->id))>{{ $u->name }}</option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-secondary text-xs"
                                        wire:click="setAssignees({{ $item->id }}, $wire.assigneeIds[{{ $item->id }}] ?? {{ $item->assignees->pluck('id')->toJson() }})">Assign</button>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            <form wire:submit="addTextItem({{ $resource->id }})" class="mt-3 space-y-2">
                <input type="text" wire:model="itemLabel.{{ $resource->id }}" placeholder="Label" class="input w-full text-sm">
                @error("itemLabel.{$resource->id}") <p class="field-error text-xs">{{ $message }}</p> @enderror
                <textarea wire:model="itemValue.{{ $resource->id }}" placeholder="Value" rows="2" class="textarea w-full text-sm"></textarea>
                @error("itemValue.{$resource->id}") <p class="field-error text-xs">{{ $message }}</p> @enderror
                <button type="submit" class="btn btn-secondary text-sm">+ Text item</button>
            </form>
        </x-ui.card>
    @empty
        <p class="text-sm text-slate-500">No resources yet.</p>
    @endforelse

    @if (auth()->user()->hasRole('super_admin'))
        <x-ui.card>
            <form wire:submit="addResource" class="space-y-2">
                <input type="text" wire:model="newTitle" placeholder="New resource title" class="input w-full text-sm">
                @error('newTitle') <p class="field-error text-xs">{{ $message }}</p> @enderror
                <textarea wire:model="newDescription" placeholder="Description (optional)" rows="2" class="textarea w-full text-sm"></textarea>
                <button type="submit" class="btn btn-secondary text-sm">+ Resource</button>
            </form>
        </x-ui.card>
    @endif
</div>
