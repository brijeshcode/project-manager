document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try { localStorage.setItem('theme', this.dark ? 'dark' : 'light'); } catch (e) {}
        },
    });
});

// Livewire navigate swaps <html> attributes (dropping `dark`); reapply right after the swap, before paint.
document.addEventListener('livewire:navigating', (e) => {
    e.detail.onSwap(() => {
        const store = window.Alpine && window.Alpine.store('theme');
        if (store) document.documentElement.classList.toggle('dark', store.dark);
    });
});
