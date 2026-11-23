// Working Flow 10 — Event Details. The server validates and creates the event (with
// its own idempotency key); these checks only spare a round trip and guard repeated
// taps. Nothing is written to browser storage.
const form = document.querySelector('[data-ed-form]');

if (form) {
    const messages = JSON.parse(document.getElementById('ed-messages')?.textContent || '{}');
    const alertBox = document.getElementById('ed-alert');
    const submit = form.querySelector('[data-ed-submit]');
    const label = submit.querySelector('.ed-submit-label');
    const idleLabel = label.textContent;
    const fields = ['event_name', 'event_datetime', 'venue_name'].map((name) => form.elements.namedItem(name));
    let submitting = false;

    const setError = (input, message) => {
        const error = document.getElementById(`ed-error-${input.name}`);
        input.closest('.ed-field').classList.toggle('has-error', Boolean(message));
        error.textContent = message || '';
        error.hidden = !message;
        message ? input.setAttribute('aria-invalid', 'true') : input.removeAttribute('aria-invalid');
        const base = input.name === 'event_datetime' ? ['ed-datetime-hint'] : [];
        input.setAttribute('aria-describedby', [...base, message ? error.id : ''].filter(Boolean).join(' '));
    };

    form.addEventListener('input', (event) => {
        if (fields.includes(event.target)) setError(event.target, '');
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        const today = new Date();
        const todayValue = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const problems = fields.map((input) => {
            const value = input.value.trim();
            if (!value) return [input, messages[input.name]];
            if (input.name === 'event_datetime' && value.slice(0, 10) < todayValue) return [input, messages.event_datetime_past];
            return [input, ''];
        });
        problems.forEach(([input, message]) => setError(input, message));
        const first = problems.find(([, message]) => message);
        if (first) {
            event.preventDefault();
            alertBox.textContent = messages.check;
            alertBox.hidden = false;
            first[0].focus();
            return;
        }
        submitting = true;
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
}
