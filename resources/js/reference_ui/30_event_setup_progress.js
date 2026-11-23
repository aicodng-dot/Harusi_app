// Working Flow 11 — Event Setup Progress. The page is read-only; steps whose screens
// belong to later working flows show an honest note instead of navigating anywhere.
const note = document.getElementById('sp-note');

document.querySelectorAll('[data-sp-pending]').forEach((control) => {
    control.addEventListener('click', () => {
        if (!note) return;
        note.textContent = control.dataset.spPending;
        note.hidden = false;
    });
});
