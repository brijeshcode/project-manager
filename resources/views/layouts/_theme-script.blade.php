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
            // a choice made under an older system default is dropped once the admin changes the default
            var d = document.documentElement.dataset.sidebar, s = localStorage.getItem('sidebar');
            if (s && localStorage.getItem('sidebarBase') !== d) { localStorage.removeItem('sidebar'); s = null; }
            if (s === 'full' || s === 'half' || s === 'mini') document.documentElement.dataset.sidebar = s;
            document.documentElement.dataset.sidebarDefault = d;
        } catch (e) {}
        try {
            // board "Card options": fields to hide on cards (see board-options.js)
            document.documentElement.dataset.boardHide = (localStorage.getItem('boardCardHide') || '').replace(/[^a-z ]/g, '');
        } catch (e) {}
    })();
</script>
