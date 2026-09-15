/**
 * Workaround for a real bug in this Livewire/Alpine build: an early
 * exception during Alpine's initial directive walk can silently abort the
 * rest of that walk, leaving arbitrarily many wire:click elements with no
 * event listener attached at all (no console error, no failed request —
 * the click just does nothing). It's timing-sensitive and doesn't
 * reliably self-heal, so rather than detect and retry it, "click" is
 * registered as a custom Livewire directive — this disables Livewire's
 * own per-element binding for wire:click entirely (see the "click" entry
 * in customDirectiveNames inside wire-wildcard.js) — and a single
 * document-level delegated listener becomes the one real handler, which
 * doesn't depend on any element ever having been walked successfully.
 */
window.Livewire.directive('click', () => {});

function findWireClickTarget(startEl) {
    let el = startEl;

    while (el && el.nodeType === 1) {
        for (const attr of el.attributes) {
            if (attr.name === 'wire:click' || attr.name.startsWith('wire:click.')) {
                return { el, attrName: attr.name, expression: attr.value };
            }
        }
        el = el.parentElement;
    }

    return null;
}

/**
 * <x-slot name="header"> content (e.g. an index page's "Add X" button)
 * renders into the layout's <header>, a sibling of <main> — not a
 * descendant of the page's own wire:id root, which only wraps the main
 * slot content. Such a button has no ancestor Livewire component at all,
 * so $wire can never resolve from it directly. Fall back to the page's
 * one non-sidebar component and evaluate from its root element instead.
 */
function resolveEvaluationElement(clickedEl) {
    if (clickedEl.closest('[wire\\:id]')) {
        return clickedEl;
    }

    const pageComponent = window.Livewire.all().find((c) => c.name !== 'layout.navigation');

    return pageComponent ? pageComponent.el : clickedEl;
}

document.addEventListener('click', (event) => {
    const target = findWireClickTarget(event.target);

    if (!target || !target.expression) {
        return;
    }

    const modifiers = target.attrName.replace('wire:click', '').split('.').filter(Boolean);

    if (modifiers.includes('prevent')) {
        event.preventDefault();
    }

    if (modifiers.includes('stop')) {
        event.stopPropagation();
    }

    // A bare method reference like "addRoom" (no arguments, so no parens in
    // the attribute) must still be called, not just evaluated to a function
    // reference — Alpine only auto-calls bare references inside its own
    // x-on directive machinery, not for a plain evaluate() like this.
    const call = target.expression.includes('(') ? target.expression : target.expression + '()';

    const evalEl = resolveEvaluationElement(target.el);

    const execute = () => window.Alpine.evaluate(evalEl, '$wire.' + call, { scope: { $event: event } });

    // wire:confirm (e.g. every "Delete" button) sets up its own dialog via
    // a separate Livewire directive, independent of this fix — a delegated
    // click handler must still honor it so deletes stay gated behind it.
    if (typeof target.el.__livewire_confirm === 'function') {
        target.el.__livewire_confirm(execute, () => event.stopImmediatePropagation());
    } else if (target.el.hasAttribute('wire:confirm')) {
        if (window.confirm(target.el.getAttribute('wire:confirm').replaceAll('\\n', '\n') || 'Are you sure?')) {
            execute();
        }
    } else {
        execute();
    }
});
