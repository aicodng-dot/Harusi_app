// Working Flow 04 — Account Login (reference UI/03_Login.png).
// The server remains authoritative; these checks only spare a round trip for empty fields.
const DRAFT_KEY = 'pangaevents.login.identifier';
const form = document.querySelector('[data-lg-form]');
const messages = JSON.parse(document.getElementById('lg-messages')?.textContent || '{}');

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', reveal ? button.dataset.hideLabel : button.dataset.showLabel);
    });
});

if (form) {
    const submit = form.querySelector('[data-lg-submit]');
    const label = submit.querySelector('.lg-submit-label');
    const idleLabel = label.textContent;
    const identifier = form.elements.namedItem('identifier');
    const password = form.elements.namedItem('password');
    let submitting = false;

    const setError = (input, message) => {
        const error = document.getElementById(`lg-error-${input.name}`);
        input.closest('.lg-field').classList.toggle('has-error', Boolean(message));
        if (message) {
            error.textContent = message;
            error.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            input.setAttribute('aria-describedby', error.id);
        } else {
            error.textContent = '';
            error.hidden = true;
            input.removeAttribute('aria-invalid');
            input.removeAttribute('aria-describedby');
        }
    };

    form.addEventListener('input', (event) => {
        if (event.target === identifier || event.target === password) setError(event.target, '');
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        const missing = [
            [identifier, identifier.value.trim() ? '' : messages.identifier],
            [password, password.value ? '' : messages.password],
        ];
        missing.forEach(([input, message]) => setError(input, message));
        const first = missing.find(([, message]) => message);
        if (first) {
            event.preventDefault();
            first[0].focus();
            return;
        }
        submitting = true;
        try { sessionStorage.removeItem(DRAFT_KEY); } catch { /* storage unavailable */ }
        // Disable after dispatch so the single request still goes out.
        requestAnimationFrame(() => {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            label.textContent = submit.dataset.submittingLabel;
        });
    });

    // Back/forward cache: make the button usable again.
    window.addEventListener('pageshow', () => {
        submitting = false;
        submit.disabled = false;
        submit.removeAttribute('aria-busy');
        label.textContent = idleLabel;
    });

    // Language switch keeps the identifier for this tab only; the password is never stored.
    document.querySelectorAll('[data-lg-language]').forEach((link) => {
        link.addEventListener('click', () => {
            try {
                identifier.value.trim()
                    ? sessionStorage.setItem(DRAFT_KEY, identifier.value)
                    : sessionStorage.removeItem(DRAFT_KEY);
            } catch { /* storage unavailable: switching still works */ }
        });
    });

    try {
        const draft = sessionStorage.getItem(DRAFT_KEY);
        sessionStorage.removeItem(DRAFT_KEY);
        if (draft && !identifier.value) identifier.value = draft;
    } catch { /* storage unavailable */ }
}
