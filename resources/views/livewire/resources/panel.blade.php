@php
    $isAdmin = auth()->user()->hasRole('super_admin');
    $isClient = auth()->user()->hasRole('client');
@endphp
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
                        @elseif ($item->type === 'image')
                            <img src="{{ route('resource-items.download', $item->id) }}" alt="{{ $item->original_name }}" class="mt-1 max-h-40 rounded">
                        @elseif ($item->type === 'file')
                            <a href="{{ route('resource-items.download', $item->id) }}" class="mt-1 inline-block text-indigo-600 underline">{{ $item->original_name }}</a>
                        @endif
                        <p class="mt-1 text-xs text-slate-500">{{ $item->created_at?->format('M j, Y') }}@if (! $isClient || $item->created_by === auth()->id()) · {{ $isClient ? 'You' : $item->creator?->name }}@endif</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($item->assignees as $a)
                                @if (! $isClient)
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">{{ $a->name }}</span>
                                @elseif ($a->id === auth()->id())
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs">You</span>
                                @endif
                            @endforeach
                        </div>
                        <div class="mt-2 space-y-1 border-t border-slate-100 pt-2">
                            @foreach ($item->comments as $comment)
                                <p wire:key="c-{{ $comment->id }}" class="text-xs text-slate-700">
                                    <span class="font-medium">{{ $isClient ? ($comment->author_id === auth()->id() ? 'You' : 'Team') : $comment->author?->name }}</span>
                                    <span class="text-slate-400">{{ $comment->created_at?->format('M j, g:i a') }}</span>
                                    <span class="block whitespace-pre-wrap break-words">{{ $comment->body }}</span>
                                </p>
                            @endforeach
                            <form wire:submit="addComment({{ $item->id }})" class="flex gap-2">
                                <input type="text" wire:model="commentBody.{{ $item->id }}" placeholder="Add a comment…" class="input flex-1 text-sm h-8 py-1">
                                <button type="submit" class="btn btn-secondary text-xs">Comment</button>
                            </form>
                            @error("commentBody.{$item->id}") <p class="field-error text-xs">{{ $message }}</p> @enderror
                        </div>
                        @if ($isAdmin)
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

            <div class="mt-3 space-y-2">
                <input type="file" wire:model="upload.{{ $resource->id }}" class="text-sm">
                @error("upload.{$resource->id}") <p class="field-error text-xs">{{ $message }}</p> @enderror
                <button type="button" wire:click="addFileItem({{ $resource->id }}, 'image')" class="btn btn-secondary text-sm">+ Image</button>
                <button type="button" wire:click="addFileItem({{ $resource->id }}, 'file')" class="btn btn-secondary text-sm">+ File</button>
            </div>
        </x-ui.card>
    @empty
        <p class="text-sm text-slate-500">No resources yet.</p>
    @endforelse

    @if ($isAdmin)
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
