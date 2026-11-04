/**
 * "Card options" on the board: which fields cards show. Entirely client-side: every field is always rendered with
 * data-f="<field>"; the chosen hidden fields live on <html data-board-hide="code deadline"> and CSS does the hiding
 * (app.css), so it is instant, survives Livewire morphs, and the saved choice (localStorage) is re-applied after
 * wire:navigate. Without localStorage everything still renders, just not remembered.
 */
const KEY = 'boardCardHide';
const read = () => {
    try { return (localStorage.getItem(KEY) || '').split(' ').filter(Boolean); } catch (e) { return []; }
};
const apply = (hidden) => { document.documentElement.dataset.boardHide = hidden.join(' '); };

document.addEventListener('alpine:init', () => {
    window.Alpine.data('cardOpts', () => ({
        o: false,
        hidden: read(),
        init() { apply(this.hidden); },
        shown(f) { return !this.hidden.includes(f); },
        toggle(f) {
            this.hidden = this.shown(f) ? [...this.hidden, f] : this.hidden.filter((x) => x !== f);
            this.save();
        },
        reset() { this.hidden = []; this.save(); },
        save() {
            apply(this.hidden);
            try { this.hidden.length ? localStorage.setItem(KEY, this.hidden.join(' ')) : localStorage.removeItem(KEY); } catch (e) {}
        },
    }));
});

// wire:navigate swaps <html>'s attributes; put the saved choice back before paint
document.addEventListener('livewire:navigating', (e) => e.detail.onSwap(() => apply(read())));
