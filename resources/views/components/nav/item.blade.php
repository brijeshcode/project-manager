@props(['route', 'pattern' => null, 'icon'])
{{-- In the half (icon-only) sidebar the label is hidden and shown by the sidebar's flyout (data-tip). --}}
<a href="{{ route($route) }}" wire:navigate @click="open = false" data-tip="{{ trim($slot) }}" aria-label="{{ trim($slot) }}"
   class="nav-item {{ request()->routeIs($pattern ?? $route) ? 'nav-item-active' : '' }}">
    @if (\App\Support\Icon::exists($icon)) <x-icon :name="$icon" class="h-5 w-5" /> @else <x-nav.icon :name="$icon" /> @endif
    <span class="sb-label">{{ $slot }}</span>
</a>
