{{-- Client-visibility eye for a task row: a toggle for editors (toggleTaskVisibility), static otherwise, nothing for clients. $task. --}}
@unless ($client ?? false)
@php $cv = $task->client_visible; $cvTitle = $cv ? 'Visible to client' : 'Hidden from client'; @endphp
@if ($canManage ?? false)
    <button type="button" wire:click="toggleTaskVisibility({{ $task->id }})" wire:loading.class="animate-pulse opacity-50" wire:loading.attr="disabled" wire:target="toggleTaskVisibility({{ $task->id }})" @class(['shrink-0', 'text-brand-600 hover:text-brand-700' => $cv, 'text-slate-400 hover:text-slate-600' => ! $cv]) title="{{ $cvTitle }}. Click to toggle" aria-label="{{ $cvTitle }}" data-test="task-visibility"><x-icon :name="$cv ? 'eye' : 'eye-off'" /></button>
@else
    <span @class(['shrink-0', 'text-brand-600' => $cv, 'text-slate-400' => ! $cv]) title="{{ $cvTitle }}" data-test="task-visibility"><x-icon :name="$cv ? 'eye' : 'eye-off'" /></span>
@endif
@endunless
