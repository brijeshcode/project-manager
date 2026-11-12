<a href="{{ route('invoices.pdf', $i) }}" target="_blank" class="btn btn-ghost px-2" title="Download PDF" aria-label="Download PDF for {{ $i->number }}"><x-icon name="file-text" class="h-4 w-4" /></a>
@if ($i->status !== 'void')
    <a href="{{ route('invoices.edit', $i) }}" class="btn btn-ghost px-2" title="Edit" aria-label="Edit {{ $i->number }}"><x-icon name="pencil" class="h-4 w-4" /></a>
@endif
<a href="{{ route('invoices.show', $i) }}" class="btn btn-ghost px-2">View</a>
