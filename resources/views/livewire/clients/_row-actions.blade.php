{{-- Edit link + Delete button that opens the confirm modal. $edit (url|null), $method, $id, $title, $text --}}
<td class="table-td whitespace-nowrap text-right" data-test="row-actions">
    @if ($edit)
        <a href="{{ $edit }}" wire:navigate class="btn btn-ghost px-2" title="Edit" aria-label="Edit"><x-icon name="pencil" class="h-4 w-4" /></a>
    @endif
    <button type="button" x-on:click="ask = @js(['method' => $method, 'id' => $id, 'title' => $title, 'text' => $text])" class="btn btn-ghost px-2 text-rose-600 dark:text-rose-400" title="Delete" aria-label="Delete" data-test="{{ $method }}-{{ $id }}"><x-icon name="trash-2" class="h-4 w-4" /></button>
</td>
