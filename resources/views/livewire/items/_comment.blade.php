{{-- One comment or reply: header with author, "..." menu (Copy link / Copy Markdown / Quote reply / Edit), body or in-place editor,
     "Last edited by". $c, $label, $top, $authorId (optional). --}}
@php
    $mine = $c->author_id === auth()->id() || auth()->user()->hasRole('super_admin');
@endphp
<div class="flex items-center gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-500 {{ $top ? 'rounded-t-xl' : '' }}">
    <span><span class="font-semibold text-slate-800">{{ $c->author?->name }}</span> {{ $label }} &middot; {{ $c->created_at->diffForHumans() }}
        @if ($c->edited_by)
            <span class="text-slate-400" data-test="last-edited">&middot; Last edited by {{ $c->editor?->name ?? 'a former user' }} {{ $c->updated_at->diffForHumans() }}</span>
        @endif
    </span>
    @if (($authorId ?? null) && $c->author_id === $authorId) <x-ui.badge color="slate" class="ml-auto" data-test="author-badge">Author</x-ui.badge> @endif
    <div class="{{ ($authorId ?? null) && $c->author_id === $authorId ? '' : 'ml-auto' }}">
        <x-ui.card-menu :anchor="'comment-'.$c->id" :target="'comment:'.$c->id" :quote="true" :edit="$mine ? 'editComment('.$c->id.')' : null" />
    </div>
</div>
<div class="px-4 py-3">
    @if ($editingComment === $c->id)
        <div class="space-y-2" data-test="comment-editor">
            <x-ui.markdown-editor model="commentEditBody" :rows="4" :uploads="false" />
            @error('commentEditBody') <p class="field-error">{{ $message }}</p> @enderror
            <div class="flex gap-2">
                <button type="button" wire:click="saveComment" class="btn btn-primary h-7 px-2.5 py-1 text-xs" data-test="save-comment">Save</button>
                <button type="button" wire:click="cancelCommentEdit" class="btn btn-ghost h-7 px-2.5 py-1 text-xs">Cancel</button>
            </div>
        </div>
    @else
        <x-ui.markdown :text="$c->body" :toggle="$mine ? 'comment:'.$c->id : null" />
    @endif
</div>
