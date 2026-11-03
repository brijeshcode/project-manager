<x-ui.side-section :title="count($participants).' '.Str::plural('participant', count($participants))" name="participants">
    <div class="flex flex-wrap gap-1">
        @forelse ($participants as $u)
            <span title="{{ $u->name }}" class="flex h-6 w-6 items-center justify-center rounded-full bg-brand-50 text-[10px] font-semibold text-brand-700">{{ collect(explode(' ', $u->name))->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('') }}</span>
        @empty
            <p class="text-slate-400">None yet</p>
        @endforelse
    </div>
</x-ui.side-section>
