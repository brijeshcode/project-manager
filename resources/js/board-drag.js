import Sortable from 'sortablejs';

/**
 * `x-board-drag` on a column <ul>: Alpine runs it whenever the element is initialised
 * (first render, tab switch, new column, group-by-module swimlanes) and tears it down on removal.
 * On drop it calls Board::taskMoved(taskId, columnId, position) on the nearest Livewire component.
 * Cards only move between columns of the same lane (data-lane), never across modules.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.directive('board-drag', (el, _, { cleanup }) => {
        const sortable = Sortable.create(el, {
            group: 'board-' + el.dataset.lane,
            animation: 150,
            onEnd(evt) {
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
 *   data-sort-handle  CSS selector of the grip, default '.drag-handle'
 * Alpine re-runs it on every initialised element, so lists survive Livewire morphs like x-board-drag does.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.directive('sortable', (el, _, { cleanup }) => {
        const sortable = Sortable.create(el, {
            handle: el.dataset.sortHandle || '.drag-handle',
            draggable: '[data-id]',
            animation: 150,
            onEnd() {
                const ids = [...el.querySelectorAll(':scope > [data-id]')].map((c) => parseInt(c.dataset.id, 10));
                const args = el.dataset.sortScope !== undefined ? [parseInt(el.dataset.sortScope, 10), ids] : [ids];
                window.Livewire.find(el.closest('[wire\\:id]')?.getAttribute('wire:id'))?.call(el.dataset.sortMethod, ...args);
            },
        });
        cleanup(() => sortable.destroy());
    });
});
