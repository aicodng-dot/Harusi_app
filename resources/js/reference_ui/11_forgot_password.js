// Working Flow 05 — Forgot Password (reference UI/11_Forgot_Password.png).
// The server stays authoritative; this only spares a round trip for an empty field.
const DRAFT_KEY = 'pangaevents.recovery.identifier';
const form = document.querySelector('[data-fp-form]');
const messages = JSON.parse(document.getElementById('fp-messages')?.textContent || '{}');

if (form) {
    const submit = form.querySelector('[data-fp-submit]');
    const label = submit.querySelector('.fp-submit-label');
    const idleLabel = label.textContent;
    const identifier = form.elements.namedItem('identifier');
    const error = document.getElementById('fp-error-identifier');
    let submitting = false;

    const setError = (message) => {
        identifier.closest('.fp-field').classList.toggle('has-error', Boolean(message));
        error.textContent = message || '';
        error.hidden = !message;
        message ? identifier.setAttribute('aria-invalid', 'true') : identifier.removeAttribute('aria-invalid');
        identifier.setAttribute('aria-describedby', message ? 'fp-delivery-hint fp-error-identifier' : 'fp-delivery-hint');
    };

    identifier.addEventListener('input', () => { if (!error.hidden) setError(''); });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        if (!identifier.value.trim()) {
            event.preventDefault();
            setError(messages.identifier);
            identifier.focus();
            return;
        }
        submitting = true;
        try { sessionStorage.removeItem(DRAFT_KEY); } catch { /* storage unavailable */ }
        requestAnimationFrame(() => {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            label.textContent = submit.dataset.submittingLabel;
        });
    });

    window.addEventListener('pageshow', () => {
        submitting = false;
        submit.disabled = false;
        submit.removeAttribute('aria-busy');
        label.textContent = idleLabel;
    });

    // Language switch keeps the identifier for this tab only.
    document.querySelectorAll('[data-fp-language]').forEach((link) => {
        link.addEventListener('click', () => {
            try {
                identifier.value.trim() ? sessionStorage.setItem(DRAFT_KEY, identifier.value) : sessionStorage.removeItem(DRAFT_KEY);
            } catch { /* storage unavailable */ }
        });
    });

    try {
        const draft = sessionStorage.getItem(DRAFT_KEY);
        sessionStorage.removeItem(DRAFT_KEY);
        if (draft && !identifier.value) identifier.value = draft;
    } catch { /* storage unavailable */ }
}
