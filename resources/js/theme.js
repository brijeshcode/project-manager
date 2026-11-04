const LAYOUTS = ['full', 'half', 'mini'];
const root = document.documentElement;

document.addEventListener('alpine:init', () => {
    window.Alpine.store('theme', {
        dark: root.classList.contains('dark'),
        toggle() {
            this.dark = !this.dark;
            root.classList.toggle('dark', this.dark);
            try { localStorage.setItem('theme', this.dark ? 'dark' : 'light'); } catch (e) {}
        },
    });

    // Sidebar layout: the head script already put the stored choice (or the system default) on <html data-sidebar>.
    window.Alpine.store('sidebar', {
        mode: root.dataset.sidebar || 'full',
        chosen: false, // true once picked in this page session (covers blocked localStorage)
        label: { full: 'Full', half: 'Half', mini: 'Mini' },
        next() { return LAYOUTS[(LAYOUTS.indexOf(this.mode) + 1) % LAYOUTS.length]; },
        cycle() {
            this.mode = this.next();
            this.chosen = true;
            root.dataset.sidebar = this.mode;
            try { localStorage.setItem('sidebar', this.mode); } catch (e) {}
        },
    });
});

// Livewire navigate swaps <html> attributes (dropping `dark` and data-sidebar); reapply right after the swap, before paint.
document.addEventListener('livewire:navigating', (e) => {
    e.detail.onSwap(() => {
        const theme = window.Alpine && window.Alpine.store('theme');
        if (theme) root.classList.toggle('dark', theme.dark);
        const sidebar = window.Alpine && window.Alpine.store('sidebar');
        if (sidebar) {
            // the swapped-in page carries the system default; a stored or just-made choice wins over it
            let m = null;
            try { m = localStorage.getItem('sidebar'); } catch (err) {}
            if (sidebar.chosen || LAYOUTS.includes(m)) root.dataset.sidebar = sidebar.chosen ? sidebar.mode : m;
            sidebar.mode = root.dataset.sidebar || sidebar.mode;
        }
    });
});
