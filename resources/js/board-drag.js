import Sortable from 'sortablejs';

/**
 * `x-board-drag` on a column <ul>: Alpine runs it whenever the element is initialised
 * (first render, tab switch, new column, group-by-module swimlanes) and tears it down on removal.
 * On drop it calls Board::taskMoved(taskId, columnId, position) on the nearest Livewire component.
 * Cards only move between columns of the same lane (data-lane), never across modules.
 */
const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

const fmtHours = (h) => String(Math.round(h * 10) / 10) + 'h';
const fmtMoney = (sym, cents) => sym + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: cents % 100 ? 2 : 0, maximumFractionDigits: 2 });

/** Recompute a column's count (with its limit state) and totals from its cards; mirrors the Blade header. */
function refreshColumn(ul) {
    const col = ul.closest('[data-col]');
    if (!col) return;
    const cards = [...ul.querySelectorAll(':scope > li[data-task-id]')];
    const limit = parseInt(col.dataset.limit, 10) || 0;
    const n = cards.length;
    const count = col.querySelector('[data-count]');
    if (count) {
        count.textContent = limit ? n + ' / ' + limit : n;
        count.dataset.state = !limit ? 'none' : n > limit ? 'over' : n === limit ? 'at' : 'under';
    }
    const totals = col.querySelector('[data-totals]');
    if (totals) {
        const sum = (k) => cards.reduce((t, c) => t + (parseFloat(c.dataset[k]) || 0), 0);
        const cents = sum('value'), hours = sum('hours'); // data-value only exists for manage-money users, so their totals stay server-side only
        totals.textContent = [cents > 0 ? fmtMoney(col.dataset.sym, cents) : '', hours > 0 ? fmtHours(hours) : ''].filter(Boolean).join(' · ');
    }
}

document.addEventListener('alpine:init', () => {
    window.Alpine.directive('board-drag', (el, _, { cleanup }) => {
        const sortable = Sortable.create(el, {
            group: 'board-' + el.dataset.lane,
            animation: reduce ? 0 : 200,
            easing: 'cubic-bezier(0.2, 0, 0, 1)',
            ghostClass: 'board-ghost',
            chosenClass: 'board-chosen',
            fallbackClass: 'board-drag',
            forceFallback: true, // a real DOM clone follows the pointer: styleable and never clipped by the scrolling columns
            fallbackOnBody: true,
            filter: '[data-no-drag]', // assignee picker etc.: clicks work, no drag starts
            preventOnFilter: false,
            onEnd(evt) {
                window.__boardDropAt = Date.now(); // the click ending a drag must not open the task panel
                refreshColumn(evt.from); refreshColumn(evt.to); // counts and totals update at once, the server does not need to re-render a same-stage move
                if (!reduce) { evt.item.classList.add('board-dropped'); setTimeout(() => evt.item.classList.remove('board-dropped'), 250); }
                const id = evt.to.closest('[wire\\:id]')?.getAttribute('wire:id');
                window.Livewire.find(id)?.call(
                    'taskMoved',
                    parseInt(evt.item.dataset.taskId, 10),
                    parseInt(evt.to.dataset.column, 10),
                    evt.newIndex + 1,
                    // the column's cards after the drop: the server derives the slot among the same module's cards
                    [...evt.to.children].map((c) => parseInt(c.dataset.taskId, 10)),
                );
            },
        });
        cleanup(() => sortable.destroy());
    });
});

/**
 * `x-sortable` on a list: drag-to-reorder its children (each needs data-id) by their grip handle.
 *   data-sort-method  Livewire method on the nearest component, called with (ids[]) or (scope, ids[])
 *   data-sort-scope   optional leading argument (e.g. a module id)
 *   data-sort-group   optional SortableJS group: lists sharing it accept each other's rows
 *   data-sort-move    Livewire method called on the RECEIVING list when a row arrives from another list, with
 *                     (rowId, data-sort-target as int, ids[] of this list after the drop); the sending list stays quiet
 *   data-sort-handle  CSS selector of the grip, default '.drag-handle'
 * Alpine re-runs it on every initialised element, so lists survive Livewire morphs like x-board-drag does.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.directive('sortable', (el, _, { cleanup }) => {
        const sortable = Sortable.create(el, {
            handle: el.dataset.sortHandle || '.drag-handle',
            draggable: '[data-id]',
            group: el.dataset.sortGroup,
            animation: 150,
            onAdd(evt) {
                if (!el.dataset.sortMove) return;
                const ids = [...el.querySelectorAll(':scope > [data-id]')].map((c) => parseInt(c.dataset.id, 10));
                window.Livewire.find(el.closest('[wire\\:id]')?.getAttribute('wire:id'))?.call(el.dataset.sortMove, parseInt(evt.item.dataset.id, 10), parseInt(el.dataset.sortTarget, 10), ids);
            },
            onEnd(evt) {
                if (evt.from !== evt.to) return; // moved to another list: the receiving list reports it
                const ids = [...el.querySelectorAll(':scope > [data-id]')].map((c) => parseInt(c.dataset.id, 10));
                const args = el.dataset.sortScope !== undefined ? [parseInt(el.dataset.sortScope, 10), ids] : [ids];
                window.Livewire.find(el.closest('[wire\\:id]')?.getAttribute('wire:id'))?.call(el.dataset.sortMethod, ...args);
            },
        });
        cleanup(() => sortable.destroy());
    });
});
