{{-- Write/Preview markdown textarea. Host component must use HandlesMarkdownUploads (uploads + preview). --}}
@props(['model', 'rows' => 6, 'placeholder' => 'Write in Markdown…', 'uploads' => true])
<div x-data="{
        tab: 'write', html: '', err: '', busy: false,
        put(text) {
            const t = this.$refs.ta;
            t.setRangeText(text, t.selectionStart, t.selectionEnd, 'end');
            t.dispatchEvent(new Event('input', { bubbles: true }));
            t.focus();
        },
        sel(ph) {
            const t = this.$refs.ta;
            return t.value.substring(t.selectionStart, t.selectionEnd) || ph;
        },
        wrap(a, b, ph) { this.put(a + this.sel(ph) + b); },
        line(prefix) { this.put(this.sel('item').split('\n').map(l => prefix + l).join('\n')); },
        async preview() {
            this.tab = 'preview';
            this.html = await this.$wire.previewMarkdown(this.$refs.ta.value);
        },
        send(file) {
            if (file.size > 10 * 1024 * 1024) { this.err = file.name + ' is larger than 10MB.'; return Promise.resolve(); }
            this.busy = true; this.err = '';
            return new Promise(res => this.$wire.upload('upload', file, async () => {
                const r = await this.$wire.storeUpload();
                this.busy = false;
                r.error ? this.err = r.error : this.put((r.image ? '!' : '') + '[' + r.name + '](' + r.url + ')\n');
                res();
            }, () => { this.busy = false; this.err = 'Upload failed.'; res(); }));
        },
        async files(list) { for (const f of [...list]) await this.send(f); },
    }" class="rounded-lg border border-slate-300 bg-white dark:border-gray-600">
    <div class="flex flex-wrap items-center gap-1 border-b border-slate-200 px-2 py-1.5 text-xs">
        <button type="button" @click="tab = 'write'" :class="tab === 'write' ? 'bg-slate-100 font-semibold' : ''" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Write</button>
        <button type="button" @click="preview()" :class="tab === 'preview' ? 'bg-slate-100 font-semibold' : ''" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Preview</button>
        <span class="mx-1 h-4 w-px bg-slate-200"></span>
        <div x-show="tab === 'write'" class="flex flex-wrap items-center gap-1">
            <button type="button" title="Bold" @click="wrap('**', '**', 'bold')" class="rounded px-2 py-1 font-bold text-slate-600 hover:bg-slate-100">B</button>
            <button type="button" title="Italic" @click="wrap('_', '_', 'italic')" class="rounded px-2 py-1 italic text-slate-600 hover:bg-slate-100">I</button>
            <button type="button" title="List" @click="line('- ')" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">List</button>
            <button type="button" title="Task list" @click="line('- [ ] ')" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Tasks</button>
            <button type="button" title="Link" @click="wrap('[', '](https://)', 'link text')" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Link</button>
            <button type="button" title="Image" @click="wrap('![', '](https://)', 'alt text')" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Image</button>
            <button type="button" title="Code" @click="wrap('`', '`', 'code')" class="rounded px-2 py-1 font-mono text-slate-600 hover:bg-slate-100">&lt;/&gt;</button>
            @if ($uploads)
                <button type="button" title="Attach a file (or paste / drop one)" @click="$refs.file.click()" class="rounded px-2 py-1 text-slate-600 hover:bg-slate-100">Attach</button>
                <input type="file" x-ref="file" class="hidden" multiple @change="files($event.target.files); $event.target.value = ''">
            @endif
        </div>
        <span x-show="busy" class="ml-auto text-slate-400">Uploading…</span>
    </div>
    <textarea x-ref="ta" x-show="tab === 'write'" wire:model="{{ $model }}" rows="{{ $rows }}" placeholder="{{ $placeholder }}"
              @if ($uploads)
              @paste="if ($event.clipboardData.files.length) { $event.preventDefault(); files($event.clipboardData.files) }"
              @drop.prevent="files($event.dataTransfer.files)" @dragover.prevent
              @endif
              class="textarea rounded-none border-0 text-sm shadow-none focus:ring-0"></textarea>
    <div x-show="tab === 'preview'" style="display:none" class="min-h-[6rem] px-3 py-2"><div class="md" x-html="html"></div></div>
    <p x-show="err" x-text="err" class="field-error px-3 pb-2"></p>
    @error('upload') <p class="field-error px-3 pb-2">{{ $message }}</p> @enderror
</div>
