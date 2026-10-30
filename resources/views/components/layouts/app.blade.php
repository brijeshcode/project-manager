{{-- Default layout for full-page Livewire components. Delegates to Breeze's
     <x-app-layout> (app/View/Components/AppLayout.php → layouts.app) so routed
     Livewire pages get the same chrome/nav as the Breeze Blade pages. --}}
<x-app-layout>
    {{ $slot }}
</x-app-layout>
