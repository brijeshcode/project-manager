{{-- Stacked avatars (instant tooltip with the full name) and, when $candidates is given, a "+" opening a checkbox popover
     that applies each tick at once through the Livewire method `$method($id, $userId)` on the nearest component.
     $users assigned, $candidates active members (null = read only), $method, $id, $max avatars shown, $size, $ring. --}}
@props(['users', 'candidates' => null, 'method' => null, 'id' => null, 'max' => 4, 'size' => 'sm', 'ring' => 'ring-2 ring-white dark:ring-gray-800'])
@php $users = collect($users); $edit = $candidates !== null && $method; @endphp
<span {{ $attributes->merge(['class' => 'relative inline-flex shrink-0 items-center', 'data-test' => 'assignees']) }} data-no-drag
      @if ($edit) x-data="{ pop: false, top: 0, left: 0, place(el) { const r = el.getBoundingClientRect(); this.left = Math.max(8, Math.min(window.innerWidth - 224, r.left)); this.top = r.bottom + 4 > window.innerHeight - 260 ? Math.max(8, r.top - 252) : r.bottom + 4; } }"
      @click.outside="pop = false" @keydown.escape.window="pop = false" @scroll.window.capture="if (!$event.target.closest?.('[data-test=assign-pop]')) pop = false" @endif>
    <span class="flex -space-x-1.5" data-test="avatars">
        @foreach ($users->take($max) as $u)<x-ui.avatar :user="$u" :size="$size" :ring="$ring" />@endforeach
        @if ($users->count() > $max)
            <span class="inline-flex {{ $size === 'xs' ? 'h-5 w-5 text-[9px]' : 'h-6 w-6 text-[10px]' }} items-center justify-center rounded-full bg-slate-200 font-semibold text-slate-600 {{ $ring }} dark:bg-gray-600 dark:text-gray-200" data-tip="{{ $users->skip($max)->pluck('name')->implode(', ') }}" tabindex="0">+{{ $users->count() - $max }}</span>
        @endif
    </span>
    @if ($edit)
        <button type="button" @click="place($el); pop = !pop" data-tip="Assign people" aria-label="Assign people" aria-haspopup="true" data-test="assign-btn"
                class="{{ $users->isEmpty() ? '' : 'ml-1' }} inline-flex {{ $size === 'xs' ? 'h-5 w-5' : 'h-6 w-6' }} items-center justify-center rounded-full border border-dashed border-slate-300 text-slate-400 transition hover:border-brand-500 hover:text-brand-600 dark:border-gray-500">
            <x-icon name="plus" class="h-3 w-3" />
        </button>
        <div x-show="pop" style="display:none" :style="{ top: top + 'px', left: left + 'px' }" role="dialog" aria-label="Assign people" data-test="assign-pop"
             class="fixed z-50 max-h-60 w-52 overflow-y-auto rounded-lg border border-slate-200 bg-white p-2 shadow-lg dark:border-gray-700 dark:bg-gray-800">
            @forelse ($candidates as $c)
                <label class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-xs text-slate-700 hover:bg-slate-100 dark:hover:bg-gray-700">
                    <input type="checkbox" class="rounded border-slate-300 text-brand-600" @checked($users->contains('id', $c->id)) wire:change="{{ $method }}({{ (int) $id }}, {{ $c->id }})" data-test="assign-{{ $c->id }}">
                    {{ $c->name }}
                </label>
            @empty
                <p class="text-xs text-slate-400">No active members.</p>
            @endforelse
        </div>
    @endif
</span>
