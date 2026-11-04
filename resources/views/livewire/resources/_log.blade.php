{{-- Access log of one resource: who viewed / edited / changed viewers. Never contains the value. $activities --}}
<ol class="space-y-1.5" data-test="access-log">
    @forelse ($activities as $a)
        <li class="flex items-start gap-2 text-xs text-slate-500" data-event="{{ $a->event }}">
            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-300"></span>
            <span><span class="font-semibold text-slate-700 dark:text-gray-200">{{ $a->user?->name ?? 'System' }}</span> {{ $a->summary() }} &middot; <span title="{{ $a->created_at->format('M j, Y g:i a') }}">{{ $a->created_at->diffForHumans() }}</span></span>
        </li>
    @empty
        <li class="text-xs text-slate-400">Nothing logged yet.</li>
    @endforelse
</ol>
