{{-- GitHub-style "..." menu of a description or comment card: Copy link, Copy Markdown, Quote reply, Edit.
     $anchor = element id to link to, $target = 'description' | 'comment:ID' (raw markdown is fetched from the host's rawMarkdown() on click), $quote shows Quote reply (inserts into the composer), $edit = wire:click expression. --}}
@props(['anchor', 'target', 'quote' => false, 'edit' => null])
<div class="relative" x-data="{
        open: false, note: '',
        async copy(text, note) {
            try { await navigator.clipboard.writeText(text); } catch (e) {
                const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
            }
            this.open = false; this.note = note; setTimeout(() => this.note = '', 1500);
        },
        async quoteReply() {
            const md = await $wire.rawMarkdown(@js($target));
            const q = md.split('\n').map(l => '> ' + l).join('\n') + '\n\n';
            this.open = false;
            $wire.set('commentBody', ($wire.commentBody ? $wire.commentBody + '\n\n' : '') + q);
            document.getElementById('composer')?.scrollIntoView({ block: 'center' });
            setTimeout(() => document.querySelector('#composer textarea')?.focus(), 80);
        },
    }" @keydown.escape.window="open = false">
    <div class="flex items-center gap-2">
        <span x-show="note" x-text="note" style="display:none" class="text-xs font-medium text-emerald-600" role="status"></span>
        <button type="button" @click="open = !open" title="More actions" aria-label="More actions" aria-haspopup="menu" :aria-expanded="open" class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" data-test="item-menu">
            <x-icon name="ellipsis" class="h-4 w-4" />
        </button>
    </div>
    <div x-show="open" style="display:none" @click.outside="open = false" role="menu" class="absolute right-0 z-20 mt-1 w-48 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg dark:border-gray-600 dark:bg-gray-800">
        @php($item = 'flex w-full items-center gap-2 px-3 py-1.5 text-left text-slate-700 hover:bg-slate-50 dark:text-gray-200 dark:hover:bg-gray-700')
        <button type="button" role="menuitem" @click="copy(location.origin + location.pathname + location.search + '#{{ $anchor }}', 'Link copied')" class="{{ $item }}" data-test="menu-copy-link"><x-icon name="link" /> Copy link</button>
        <button type="button" role="menuitem" @click="copy(await $wire.rawMarkdown(@js($target)), 'Markdown copied')" class="{{ $item }}" data-test="menu-copy-md"><x-icon name="file-text" /> Copy Markdown</button>
        @if ($quote)
            <button type="button" role="menuitem" @click="quoteReply()" class="{{ $item }}" data-test="menu-quote"><x-icon name="quote" /> Quote reply</button>
        @endif
        @if ($edit)
            <button type="button" role="menuitem" @click="open = false" wire:click="{{ $edit }}" class="{{ $item }}" data-test="menu-edit"><x-icon name="pencil" /> Edit</button>
        @endif
    </div>
</div>
