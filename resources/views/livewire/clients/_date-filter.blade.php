{{-- A tab's date range: period shortcuts and from/to, applied with the filter panel. $label, $id, $method (period setter, used by the chip), $fromModel/$toModel, $from/$to values, $period (active key or false).
     With $chip set, renders the matching filter chip instead. --}}
@php $periodNames = ['month' => 'This month', 'year' => 'This year', 'fy' => 'Financial year']; @endphp
@if ($chip ?? false)
    @if ($from !== '' || $to !== '')
        <x-ui.filter-chip :label="$label.': '.($period ? $periodNames[$period] : trim(($from !== '' ? 'from '.$from : '').' '.($to !== '' ? 'to '.$to : '')))" wire:click="{{ $method }}('')" />
    @endif
@else
    <x-ui.date-filter :label="$label" :from="$from" :to="$to" :period="$period" :from-model="$fromModel" :to-model="$toModel" :id="$id" defer />
@endif
