<div {{ $attributes->merge(['class' => 'card mb-6 p-4']) }}>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {{ $slot }}
    </div>
</div>
