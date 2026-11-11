@if ($subject->request_status)
    @php $rk = $kind === 'module' ? 'Module' : 'Task'; $pending = $subject->request_status === 'pending'; @endphp
    <span class="inline-flex flex-wrap items-center gap-1.5" data-test="{{ $kind }}-request" x-data="{ rejecting: false }">
        @unless ($client)<x-ui.badge color="brand">Client request</x-ui.badge>@endunless
        @if ($pending)
            <x-ui.badge color="amber" data-test="request-pending">Awaiting approval</x-ui.badge>
        @else
            <x-ui.badge color="rose" data-test="request-rejected" title="{{ $subject->request_note }}">Rejected: {{ Str::limit($subject->request_note, 60) }}</x-ui.badge>
        @endif
        @if ($client && ($kind === 'module' || $subject->creator_id === auth()->id()))
            <button type="button" wire:click="open{{ $rk }}Request({{ $subject->id }})" class="text-xs font-medium text-brand-600 hover:text-brand-700" data-test="edit-request">Edit</button>
            <button type="button" wire:click="delete{{ $rk }}Request({{ $subject->id }})" wire:confirm="Delete this request?" class="text-xs font-medium text-rose-600 hover:text-rose-700" data-test="delete-request">Delete</button>
        @elseif (! $client && $canManage)
            <button type="button" wire:click="approveRequest('{{ $kind }}', {{ $subject->id }})" class="btn btn-primary h-7 px-2.5 py-1 text-xs" data-test="approve-request">Approve</button>
            @if ($pending)
                <button type="button" x-show="!rejecting" @click="rejecting = true" class="btn btn-ghost h-7 px-2.5 py-1 text-xs" data-test="reject-request">Reject</button>
                <form x-show="rejecting" style="display:none" wire:submit.prevent="rejectRequest('{{ $kind }}', {{ $subject->id }})" class="flex gap-1.5">
                    <input type="text" wire:model="rejectNote.{{ $kind }}.{{ $subject->id }}" placeholder="Reason" class="input h-7 w-48 py-1 text-xs" aria-label="Reason">
                    <button type="submit" class="btn btn-secondary h-7 px-2.5 py-1 text-xs">Reject</button>
                    <button type="button" @click="rejecting = false" class="btn btn-ghost h-7 px-2" title="Cancel" aria-label="Cancel"><x-icon name="x" /></button>
                </form>
            @endif
        @endif
        @error("rejectNote.$kind.{$subject->id}") <span class="field-error text-xs">{{ $message }}</span> @enderror
    </span>
@endif
