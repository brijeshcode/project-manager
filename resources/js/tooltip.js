/**
 * Instant tooltip for any element with data-tip="text" (mouse hover and keyboard focus). One fixed element on <body>,
 * so overflow containers never clip it. Native `title` is slow/unreliable, so use data-tip + aria-label instead.
 */
let tip;
const get = () => {
    if (!tip) {
        tip = document.createElement('div');
        tip.setAttribute('role', 'tooltip');
        tip.dataset.test = 'tooltip';
        tip.className = 'ui-tip';
        document.body.appendChild(tip);
    }
    return tip;
};
const hide = () => tip && tip.classList.remove('ui-tip-on');
const show = (el) => {
    const t = get();
    t.textContent = el.dataset.tip;
    t.classList.add('ui-tip-on');
    const r = el.getBoundingClientRect();
    const w = t.offsetWidth, h = t.offsetHeight;
    let top = r.top - h - 6;
    if (top < 4) top = r.bottom + 6;
    t.style.top = `${top}px`;
    t.style.left = `${Math.max(4, Math.min(window.innerWidth - w - 4, r.left + r.width / 2 - w / 2))}px`;
};
const target = (e) => { const t = e.target instanceof Element ? e.target.closest('[data-tip]') : null; return t && !t.closest('aside') ? t : null; }; // the sidebar has its own flyout
document.addEventListener('mouseover', (e) => { const el = target(e); el ? show(el) : hide(); });
document.addEventListener('focusin', (e) => { const el = target(e); el && el.matches(':focus-visible') ? show(el) : hide(); }); // keyboard focus only: a click focuses too
document.addEventListener('focusout', hide);
document.addEventListener('mousedown', hide, true);
document.addEventListener('scroll', hide, true);
document.addEventListener('keydown', (e) => e.key === 'Escape' && hide());
document.addEventListener('livewire:navigating', hide);
