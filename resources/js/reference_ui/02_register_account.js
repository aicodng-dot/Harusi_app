// Working Flow 03 — Account Registration (reference UI/02_Register_Account.png).
// The server remains authoritative for every rule; these checks only spare a round trip.
const DRAFT_KEY = 'pangaevents.register.draft';
const form = document.querySelector('[data-reg-form]');
const messages = JSON.parse(document.getElementById('reg-messages')?.textContent || '{}');

// Password visibility toggles.
document.querySelectorAll('[data-register-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', reveal ? button.dataset.hideLabel : button.dataset.showLabel);
    });
});

if (form) {
    const submit = form.querySelector('[data-reg-submit]');
    const label = submit.querySelector('.reg-submit-label');
    const idleLabel = label.textContent;
    const alertBox = document.getElementById('reg-alert');
    let submitting = false;

    const fieldOf = (name) => form.elements.namedItem(name);
    const errorOf = (name) => document.getElementById(`reg-error-${name}`);
    const shellOf = (input) => input.closest('.reg-field, .reg-select, .reg-terms');

    const setError = (name, message) => {
        const input = fieldOf(name);
        const error = errorOf(name);
        if (!input || !error) return;
        const ids = (input.getAttribute('aria-describedby') || '').split(/\s+/).filter((id) => id && id !== error.id);
        if (message) {
            error.textContent = message;
            error.hidden = false;
            input.setAttribute('aria-invalid', 'true');
            ids.push(error.id);
        } else {
            error.textContent = '';
            error.hidden = true;
            input.removeAttribute('aria-invalid');
        }
        ids.length ? input.setAttribute('aria-describedby', ids.join(' ')) : input.removeAttribute('aria-describedby');
        shellOf(input)?.classList.toggle('has-error', Boolean(message));
    };

    const validate = () => {
        const value = (name) => (fieldOf(name)?.value || '').trim();
        const problems = {
            name: value('name') ? '' : messages.name,
            phone_number: value('phone_number') ? '' : messages.phone_number,
            email: !value('email') ? messages.email : (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value('email')) ? '' : messages.email_format),
            password: fieldOf('password').value.length >= 8 ? '' : messages.password,
            password_confirmation: !fieldOf('password_confirmation').value
                ? messages.password_confirmation
                : (fieldOf('password_confirmation').value === fieldOf('password').value ? '' : messages.mismatch),
            terms: fieldOf('terms').checked ? '' : messages.terms,
        };
        Object.entries(problems).forEach(([name, message]) => setError(name, message));
        return Object.keys(problems).filter((name) => problems[name]);
    };

    // Clear a field's error as soon as the visitor corrects it.
    form.addEventListener('input', (event) => {
        const name = event.target.name;
        if (name && errorOf(name) && !errorOf(name).hidden) setError(name, '');
        if (name === 'password' && !errorOf('password_confirmation').hidden) setError('password_confirmation', '');
    });
    form.addEventListener('change', (event) => {
        if (event.target.name === 'terms' && event.target.checked) setError('terms', '');
    });

    const resetSubmit = () => {
        submitting = false;
        submit.disabled = false;
        submit.removeAttribute('aria-busy');
        label.textContent = idleLabel;
    };

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        const invalid = validate();
        if (invalid.length) {
            event.preventDefault();
            alertBox.textContent = messages.check_fields;
            alertBox.hidden = false;
            fieldOf(invalid[0])?.focus();
            return;
        }
        submitting = true;
        try { sessionStorage.removeItem(DRAFT_KEY); } catch { /* storage unavailable */ }
        // Disable after the submit event has been dispatched so the request still goes out once.
        requestAnimationFrame(() => {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
            label.textContent = submit.dataset.submittingLabel;
        });
    });

    // Back/forward cache restores the page as it was left: make the button usable again.
    window.addEventListener('pageshow', resetSubmit);

    // Language switch: keep non-secret input for this tab only. Passwords are never stored.
    document.querySelectorAll('[data-reg-language]').forEach((link) => {
        link.addEventListener('click', () => {
            const draft = {};
            form.querySelectorAll('[data-reg-draft]').forEach((input) => { if (input.value.trim()) draft[input.name] = input.value; });
            try {
                Object.keys(draft).length ? sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft)) : sessionStorage.removeItem(DRAFT_KEY);
            } catch { /* storage unavailable: switching still works */ }
        });
    });

    try {
        const draft = JSON.parse(sessionStorage.getItem(DRAFT_KEY) || 'null');
        sessionStorage.removeItem(DRAFT_KEY);
        if (draft) {
            form.querySelectorAll('[data-reg-draft]').forEach((input) => {
                if (!input.value && typeof draft[input.name] === 'string') input.value = draft[input.name];
            });
        }
    } catch { /* storage unavailable or malformed draft */ }
}
