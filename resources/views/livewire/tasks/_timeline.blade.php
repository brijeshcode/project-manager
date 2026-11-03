{{-- Composer + merged comments/activity. $items, $scope ('task'|'subtask'), $bodyModel (wire property for the composer). --}}
<x-ui.card class="mb-4 space-y-2">
    <x-ui.markdown-editor :model="$bodyModel" :rows="3" :uploads="false" placeholder="Leave a comment…" />
    @error($bodyModel) <p class="field-error">{{ $message }}</p> @enderror
    <button wire:click="postComment('{{ $scope }}')" class="btn btn-primary h-8 px-3 py-1 text-xs" data-test="comment-{{ $scope }}">Comment</button>
</x-ui.card>

<ol class="space-y-3" data-test="timeline-{{ $scope }}">
    @forelse ($items as $item)
        @php($m = $item['m'])
        @if ($item['kind'] === 'comment')
            <li class="card p-4" data-kind="comment">
                <div class="mb-1 text-xs text-slate-500"><span class="font-semibold text-slate-800">{{ $m->author?->name }}</span> commented &middot; {{ $m->created_at->diffForHumans() }}</div>
                <x-ui.markdown :text="$m->body" />
                @foreach ($m->replies as $r)
                    <div class="mt-3 border-l-2 border-slate-200 pl-3">
                        <div class="mb-1 text-xs text-slate-500"><span class="font-semibold text-slate-800">{{ $r->author?->name }}</span> replied &middot; {{ $r->created_at->diffForHumans() }}</div>
                        <x-ui.markdown :text="$r->body" />
                    </div>
                @endforeach
                @if ($replyTo === $m->id && $replyScope === $scope)
                    <div class="mt-3 space-y-2">
                        <x-ui.markdown-editor model="replyBody" :rows="3" :uploads="false" placeholder="Write a reply…" />
                        @error('replyBody') <p class="field-error">{{ $message }}</p> @enderror
                        <div class="flex gap-2">
                            <button wire:click="postReply" class="btn btn-primary h-7 px-2.5 py-1 text-xs">Reply</button>
                            <button wire:click="$set('replyTo', null)" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
                        </div>
                    </div>
                @else
                    <button wire:click="startReply('{{ $scope }}', {{ $m->id }})" class="mt-2 text-xs font-medium text-brand-600 hover:text-brand-700">Reply</button>
                @endif
            </li>
        @else
            <li class="px-1 text-xs text-slate-500" data-kind="activity">
                <span class="font-semibold text-slate-700">{{ $m->user?->name ?? 'System' }}</span> {{ $m->summary() }}@if ($m->reason) <span class="italic">&mdash; {{ $m->reason }}</span>@endif &middot; {{ $m->created_at->diffForHumans() }}
            </li>
        @endif
    @empty
        <li class="text-sm text-slate-400">Nothing yet.</li>
    @endforelse
</ol>
