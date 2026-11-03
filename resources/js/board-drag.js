import Sortable from 'sortablejs';

/**
 * Wire up SortableJS on every .board-column element inside a Livewire Board component.
 * On drop, emit a Livewire `taskMoved` event with { taskId, column, position }.
 *
 * ponytail: re-init on Livewire morphdom updates via livewire:navigated / morph hooks.
 */
function initBoard() {
    document.querySelectorAll('.board-column').forEach((el) => {
        if (el._sortable) {
            el._sortable.destroy();
        }

        el._sortable = Sortable.create(el, {
            group: 'board',
            animation: 150,
            onEnd(evt) {
                const taskId  = parseInt(evt.item.dataset.taskId, 10);
                const column  = evt.to.dataset.column;
                const position = evt.newIndex + 1; // 1-based

                // Find the nearest Livewire component and dispatch
                const component = Livewire.find(
                    evt.to.closest('[wire\\:id]')?.getAttribute('wire:id')
                );
                if (component) {
                    component.call('taskMoved', taskId, column, position);
                }
            },
        });
    });
}

document.addEventListener('livewire:initialized', initBoard);
document.addEventListener('livewire:navigated', initBoard);
// Re-init after each Livewire render to handle column re-renders
Livewire.hook('commit', ({ succeed }) => succeed(() => setTimeout(initBoard, 50)));
