// Working Flow 09 — Choose Event Type. The server validates and stores the choice;
// this keeps the visual selection in sync, carries it across the SW/EN switch via
// the URL, spares a round trip when nothing is chosen and guards double submits.
// Nothing is written to browser storage.
const form = document.querySelector('[data-et-form]');

if (form) {
    const messages = JSON.parse(document.getElementById('et-messages')?.textContent || '{}');
    const alertBox = document.getElementById('et-alert');
    const typeError = document.getElementById('et-error-type');
    const workspaceError = document.getElementById('et-error-workspace');
    const submit = form.querySelector('[data-et-submit]');
    const label = submit.querySelector('.et-continue-label');
    const idleLabel = label.textContent;
    let submitting = false;

    const checked = (selector) => form.querySelector(`${selector}:checked`);
    const show = (element, message) => {
        if (!element) return;
        element.textContent = message || '';
        element.hidden = !message;
    };

    const syncLanguageLinks = () => {
        const type = checked('[data-et-type]')?.value;
        const workspace = checked('[data-et-workspace]')?.value;
        document.querySelectorAll('[data-et-language]').forEach((link) => {
            const url = new URL(link.href);
            type ? url.searchParams.set('type', type) : url.searchParams.delete('type');
            workspace ? url.searchParams.set('workspace', workspace) : url.searchParams.delete('workspace');
            link.href = url.toString();
        });
    };

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-et-type]')) {
            form.querySelectorAll('.et-card').forEach((card) => card.classList.toggle('is-selected', card.contains(event.target)));
            show(typeError, '');
        }
        if (event.target.matches('[data-et-workspace]')) show(workspaceError, '');
        if (!typeError?.textContent && !workspaceError?.textContent) show(alertBox, '');
        syncLanguageLinks();
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        const missingType = !checked('[data-et-type]');
        const missingWorkspace = Boolean(form.querySelector('[data-et-workspace]')) && !checked('[data-et-workspace]');
        if (missingType || missingWorkspace) {
            event.preventDefault();
            show(typeError, missingType ? messages.type : '');
            show(workspaceError, missingWorkspace ? messages.workspace : '');
            show(alertBox, messages.check);
            (missingType ? form.querySelector('[data-et-type]') : form.querySelector('[data-et-workspace]'))?.focus();
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

    syncLanguageLinks();
}
