<x-ui.side-section :title="count($participants).' '.Str::plural('participant', count($participants))" name="participants">
    <div class="flex flex-wrap gap-1">
        @forelse ($participants as $u)
            <x-ui.avatar :user="$u" ring="" />
        @empty
            <p class="text-slate-400">None yet</p>
        @endforelse
    </div>
</x-ui.side-section>
