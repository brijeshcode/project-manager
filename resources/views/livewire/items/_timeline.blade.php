{{-- GitHub-style timeline, oldest first: comment cards, events as one-liners, composer last. $timeline, $authorId (optional). --}}
<ol class="space-y-3" data-test="timeline">
    @foreach ($timeline as $item)
        @php($m = $item['m'])
        @if ($item['kind'] === 'comment')
            <li class="card overflow-hidden" data-kind="comment">
                <div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500">
                    <span><span class="font-semibold text-slate-800">{{ $m->author?->name }}</span> commented &middot; {{ $m->created_at->diffForHumans() }}</span>
                    @if (($authorId ?? null) && $m->author_id === $authorId) <x-ui.badge color="slate" class="ml-auto" data-test="author-badge">Author</x-ui.badge> @endif
                </div>
                <div class="px-4 py-3"><x-ui.markdown :text="$m->body" /></div>
                @foreach ($m->replies as $r)
                    <div class="border-t border-slate-100 bg-slate-50 px-4 py-3 pl-8" data-kind="reply">
                        <div class="mb-1 flex items-center gap-2 text-xs text-slate-500">
                            <span><span class="font-semibold text-slate-800">{{ $r->author?->name }}</span> replied &middot; {{ $r->created_at->diffForHumans() }}</span>
                            @if (($authorId ?? null) && $r->author_id === $authorId) <x-ui.badge color="slate" class="ml-auto">Author</x-ui.badge> @endif
                        </div>
                        <x-ui.markdown :text="$r->body" />
                    </div>
                @endforeach
                <div class="border-t border-slate-100 px-4 py-2">
                    @if ($replyTo === $m->id)
                        <div class="space-y-2">
                            <x-ui.markdown-editor model="replyBody" :rows="3" :uploads="false" placeholder="Write a reply…" />
                            @error('replyBody') <p class="field-error">{{ $message }}</p> @enderror
                            <div class="flex gap-2">
                                <button wire:click="postReply" class="btn btn-primary h-7 px-2.5 py-1 text-xs">Reply</button>
                                <button wire:click="$set('replyTo', null)" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
                            </div>
                        </div>
                    @else
                        <button wire:click="startReply({{ $m->id }})" class="text-xs font-medium text-brand-600 hover:text-brand-700">Reply</button>
                    @endif
                </div>
            </li>
        @else
            <li class="flex items-start gap-2 px-1 text-xs text-slate-500" data-kind="{{ $item['kind'] }}">
                <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-300"></span>
                <span>
                    <span class="font-semibold text-slate-700">{{ $m->user?->name ?? 'System' }}</span>
                    @if ($item['kind'] === 'budget')
                        changed the budget {{ str_replace('_', ' ', $m->field) }} from {{ $m->old ?? 'none' }} to {{ $m->new ?? 'none' }}
                    @else
                        {{ $m->summary() }}
                    @endif
                    @if ($m->reason) <span class="italic">&mdash; {{ $m->reason }}</span>@endif &middot; {{ $m->created_at->diffForHumans() }}
                </span>
            </li>
        @endif
    @endforeach
</ol>

<x-ui.card class="mt-4 space-y-2">
    <p class="text-sm font-semibold text-slate-700">Add a comment</p>
    <x-ui.markdown-editor model="commentBody" :rows="4" :uploads="false" placeholder="Leave a comment…" />
    @error('commentBody') <p class="field-error">{{ $message }}</p> @enderror
    <button wire:click="postComment" class="btn btn-primary h-8 px-3 py-1 text-xs" data-test="comment">Comment</button>
</x-ui.card>
