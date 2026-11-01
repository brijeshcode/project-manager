@php $flash = session('toast') ?? session('status'); @endphp
<div x-data="{ show: false, type: 'success', message: '', t: null,
        open(type, message) { if (!message) return; this.type = type; this.message = message; this.show = true; clearTimeout(this.t); this.t = setTimeout(() => this.show = false, 4000); } }"
     x-init="@if ($flash) open('success', @js($flash)) @endif"
     x-on:toast.window="open($event.detail.type || 'success', $event.detail.message)"
     role="status" aria-live="polite"
     class="pointer-events-none fixed right-4 top-4 z-50">
    <div x-show="show" x-cloak x-transition
         :class="type === 'error' ? 'border-red-200 bg-red-50 text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300' : 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300'"
         class="pointer-events-auto rounded-lg border px-4 py-3 text-sm font-medium shadow-lg">
        <span x-text="message">{{ $flash }}</span>
    </div>
</div>
