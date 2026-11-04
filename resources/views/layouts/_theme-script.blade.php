<script>
    (function () {
        try {
            var t = localStorage.getItem('theme');
            if (t === 'dark' || (!t && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        } catch (e) {}
        try {
            // per-user sidebar layout overrides the system default already rendered in data-sidebar
            var s = localStorage.getItem('sidebar');
            if (s === 'full' || s === 'half' || s === 'mini') document.documentElement.dataset.sidebar = s;
        } catch (e) {}
    })();
</script>
