{{-- Comments this client may see, with replies. $subject, $kind ('task'|'module'), $threads closure. --}}
@php($list = $threads($subject))
<div class="space-y-3" data-test="threads-{{ $kind }}-{{ $subject->id }}">
    @foreach ($list as $c)
        <div class="rounded-lg bg-slate-50 p-3">
            <div class="mb-1 text-xs text-slate-500"><span class="font-semibold text-slate-800">{{ $c->author?->name }}</span> &middot; {{ $c->created_at->diffForHumans() }}</div>
            <x-ui.markdown :text="$c->body" />
            @foreach ($c->replies as $r)
                <div class="mt-2 border-l-2 border-slate-200 pl-3">
                    <div class="mb-1 text-xs text-slate-500"><span class="font-semibold text-slate-800">{{ $r->author?->name }}</span> &middot; {{ $r->created_at->diffForHumans() }}</div>
                    <x-ui.markdown :text="$r->body" />
                </div>
            @endforeach
            <div x-data="{ replying: false }" class="mt-2">
                <button type="button" @click="replying = !replying" class="text-xs font-medium text-brand-600 hover:text-brand-700">Reply</button>
                <form x-show="replying" style="display:none" wire:submit="reply('{{ $kind }}', {{ $subject->id }}, {{ $c->id }})" @submit="replying = false" class="mt-1 space-y-2">
                    <x-ui.markdown-editor model="replyBody.{{ $c->id }}" :rows="2" :uploads="false" placeholder="Your reply…" />
                    <button type="submit" class="btn btn-secondary h-8 px-3 py-1 text-xs">Send</button>
                </form>
                @error('replyBody.'.$c->id) <p class="field-error text-xs">{{ $message }}</p> @enderror
            </div>
        </div>
    @endforeach
</div>
