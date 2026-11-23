// Working Flow 06 — Reset Password. The server and password broker stay authoritative;
// these checks only spare a round trip. Nothing is written to browser storage.
const form = document.querySelector('[data-rs-form]');

document.querySelectorAll('[data-rs-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.getElementById(button.getAttribute('aria-controls'));
        const reveal = input.type === 'password';
        input.type = reveal ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(reveal));
        button.setAttribute('aria-label', reveal ? button.dataset.hideLabel : button.dataset.showLabel);
    });
});

if (form) {
    const messages = JSON.parse(document.getElementById('rs-messages')?.textContent || '{}');
    const submit = form.querySelector('[data-rs-submit]');
    const label = submit.querySelector('.rs-submit-label');
    const idleLabel = label.textContent;
    const alertBox = document.getElementById('rs-alert');
    const password = form.elements.namedItem('password');
    const confirmation = form.elements.namedItem('password_confirmation');
    let submitting = false;

    const setError = (input, message) => {
        const error = document.getElementById(`rs-error-${input.name}`);
        input.closest('.fp-field').classList.toggle('has-error', Boolean(message));
        error.textContent = message || '';
        error.hidden = !message;
        message ? input.setAttribute('aria-invalid', 'true') : input.removeAttribute('aria-invalid');
        const base = input === password ? 'rs-password-hint' : '';
        input.setAttribute('aria-describedby', [base, message ? error.id : ''].filter(Boolean).join(' '));
    };

    form.addEventListener('input', (event) => {
        if (event.target === password || event.target === confirmation) setError(event.target, '');
        if (event.target === password) setError(confirmation, '');
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        const problems = [
            [password, !password.value ? messages.password : (password.value.length < 8 ? messages.password_min : '')],
            [confirmation, !confirmation.value ? messages.password_confirmation : (confirmation.value !== password.value ? messages.mismatch : '')],
        ];
        problems.forEach(([input, message]) => setError(input, message));
        const first = problems.find(([, message]) => message);
        if (first) {
            event.preventDefault();
            alertBox.textContent = messages.check_fields;
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
