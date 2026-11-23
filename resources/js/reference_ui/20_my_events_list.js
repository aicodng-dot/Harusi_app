// Working Flow 08 — My Events List. The server decides which events are visible;
// this only toggles the filter panel, shows honest "not yet available" notes and
// a loading state while a search or filter request is in flight. No storage is used.
const filterToggle = document.querySelector('[data-ev-filter-toggle]');
const filters = document.querySelector('[data-ev-filters]');
const list = document.querySelector('[data-ev-list]');

if (filterToggle && filters) {
    filterToggle.addEventListener('click', () => {
        const open = filterToggle.getAttribute('aria-expanded') !== 'true';
        filterToggle.setAttribute('aria-expanded', String(open));
        filters.hidden = !open;
        if (open) filters.querySelector('.is-active, a')?.focus();
    });
}

const busy = () => list?.setAttribute('aria-busy', 'true');
document.querySelector('[data-ev-search]')?.addEventListener('submit', busy);
document.querySelectorAll('[data-ev-filter]').forEach((link) => link.addEventListener('click', busy));

const timers = new Map();
document.querySelectorAll('[data-ev-pending]').forEach((control) => {
    control.addEventListener('click', () => {
        const note = document.getElementById(control.dataset.evPending);
        if (!note) return;
        note.hidden = false;
        clearTimeout(timers.get(note));
        if (note.classList.contains('ev-tab-note')) timers.set(note, setTimeout(() => { note.hidden = true; }, 3200));
    });
});

window.addEventListener('pageshow', () => list?.removeAttribute('aria-busy'));
