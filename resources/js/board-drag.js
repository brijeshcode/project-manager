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
                );
            },
        });
        cleanup(() => sortable.destroy());
    });
});
