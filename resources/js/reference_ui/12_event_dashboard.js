// Working Flow 12 — Event Dashboard. Read-only; actions whose screens belong to later
// working flows show an honest note instead of navigating anywhere.
const note = document.getElementById('db-note');
document.querySelectorAll('[data-db-pending]').forEach((control) => {
    control.addEventListener('click', () => {
        if (!note) return;
        note.textContent = control.dataset.dbPending;
        note.hidden = false;
    });
});

const navNote = document.getElementById('db-nav-note');
let timer;
document.querySelectorAll('[data-db-nav-pending]').forEach((control) => {
    control.addEventListener('click', () => {
        if (!navNote) return;
        navNote.hidden = false;
        clearTimeout(timer);
        timer = setTimeout(() => { navNote.hidden = true; }, 3200);
    });
});
