// Customer shell: the "More" bottom sheet (phones/tablets). Opens from the ⋯ button,
// traps keyboard focus while open, closes on Esc, the scrim, × or when the screen widens.
const sheet = document.querySelector('[data-cs-sheet]');
const openButton = document.querySelector('[data-cs-open]');
const scrim = document.querySelector('.cs-scrim');
const desktop = window.matchMedia('(min-width: 1024px)');

if (sheet && openButton && scrim) {
    let lastFocus = null;
    const focusables = () => [...sheet.querySelectorAll('a[href], button:not([disabled])')];
    const open = () => {
        lastFocus = document.activeElement;
        sheet.hidden = false;
        scrim.hidden = false;
        openButton.setAttribute('aria-expanded', 'true');
        document.documentElement.style.overflow = 'hidden';
        sheet.focus();
    };
    const close = () => {
        if (sheet.hidden) return;
        sheet.hidden = true;
        scrim.hidden = true;
        openButton.setAttribute('aria-expanded', 'false');
        document.documentElement.style.overflow = '';
        if (lastFocus instanceof HTMLElement) lastFocus.focus();
    };
    openButton.addEventListener('click', open);
    document.querySelectorAll('[data-cs-close]').forEach((el) => el.addEventListener('click', close));
    document.addEventListener('keydown', (event) => {
        if (sheet.hidden) return;
        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'Tab') {
            const items = focusables();
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    // Swipe the sheet down to close it.
    let startY = null;
    sheet.addEventListener('touchstart', (event) => { startY = sheet.scrollTop === 0 ? event.touches[0].clientY : null; }, { passive: true });
    sheet.addEventListener('touchend', (event) => {
        if (startY !== null && event.changedTouches[0].clientY - startY > 70) close();
        startY = null;
    }, { passive: true });
    desktop.addEventListener('change', () => { if (desktop.matches) close(); });
}
