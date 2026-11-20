{{-- Expense category chip: uploaded image, else its icon (or a colour dot), then the name. $category may be null (Uncategorised). --}}
@props(['category' => null])
@php $c = preg_match('/^#[0-9a-fA-F]{6}$/', (string) $category?->color) ? $category->color : '#6b7280'; @endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset ring-slate-200 dark:ring-gray-600']) }} data-category="{{ $category?->name }}">
    @if ($category?->icon_path)
        <img src="{{ Storage::disk('public')->url($category->icon_path) }}" alt="" class="h-4 w-4 rounded object-cover">
    @elseif (filled($category?->icon))
        <span style="color: {{ $c }}" class="inline-flex"><x-ui.glyph :value="$category->icon" class="h-3 w-3" /></span>
    @else
        <span class="h-2 w-2 rounded-full" style="background: {{ $c }}"></span>
    @endif
    {{ $category?->name ?? 'Uncategorised' }}
</span>
