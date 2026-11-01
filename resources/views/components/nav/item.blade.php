@props(['route', 'pattern' => null, 'icon'])
<a href="{{ route($route) }}" wire:navigate @click="open = false"
   class="nav-item {{ request()->routeIs($pattern ?? $route) ? 'nav-item-active' : '' }}">
    <x-nav.icon :name="$icon" />
    {{ $slot }}
</a>
