import Sortable from 'sortablejs';

/**
 * Each grid cell is its own single-item Sortable list (capped via `put`
 * checking child count), all sharing one group per time slot so a card can
 * move between any room's grid for that slot but never jump to a different
 * slot's board. Locked seats render without the `.seat-card` class, so
 * they're excluded from `draggable` and can't be picked up in the first
 * place; `put` blocking on non-empty cells means you also can't drop onto
 * one (locked or not) without first freeing it.
 */
function livewireComponentFor(el) {
    const root = el.closest('[wire\\:id]');

    // Livewire.find() already returns the $wire proxy, not the raw
    // component — call methods on it directly, never `.find(...).$wire`.
    return root ? window.Livewire.find(root.getAttribute('wire:id')) : null;
}

export function initSeatGrid(gridEl, slotId) {
    gridEl.querySelectorAll('.seat-cell').forEach((cell) => {
        if (cell.dataset.sortableInit) {
            return;
        }

        cell.dataset.sortableInit = '1';

        Sortable.create(cell, {
            group: {
                name: 'seat-slot-' + slotId,
                pull: true,
                put: (to) => to.el.children.length === 0,
            },
            animation: 150,
            draggable: '.seat-card',
            filter: '.seat-lock-btn',
            preventOnFilter: false,
            ghostClass: 'opacity-40',
            forceFallback: true,
            onAdd(evt) {
                const card = evt.item;
                const targetCell = evt.to;
                const component = livewireComponentFor(targetCell);

                if (! component) {
                    return;
                }

                component.moveSeat(
                    parseInt(card.dataset.enrollmentId, 10),
                    parseInt(targetCell.dataset.roomId, 10),
                    parseInt(targetCell.dataset.row, 10),
                    parseInt(targetCell.dataset.column, 10)
                );
            },
        });
    });
}

window.initSeatGrid = initSeatGrid;
