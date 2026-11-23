// Working Flow 14 — Choose Invitation Template. The server validates and stores the
// choice; this keeps the selected card in sync, carries an unsaved choice across the
// category and language links, and guards repeated taps. No browser storage is used.
const form = document.querySelector('[data-tp-form]');

if (form) {
    const messages = JSON.parse(document.getElementById('tp-messages')?.textContent || '{}');
    const alertBox = document.getElementById('tp-alert');
    const error = document.getElementById('tp-error-template');
    const submit = form.querySelector('[data-tp-submit]');
    const label = submit.querySelector('.tp-submit-label');
    const idleLabel = label.textContent;
    let submitting = false;

    const syncLinks = () => {
        const value = form.querySelector('[data-tp-template]:checked')?.value;
        // Keeping the saved design just continues; a different one is "use & continue".
        if (!submitting) {
            label.textContent = value && value === submit.dataset.current ? submit.dataset.labelContinue : submit.dataset.labelUse;
        }
        document.querySelectorAll('[data-tp-keep]').forEach((link) => {
            const url = new URL(link.href);
            value ? url.searchParams.set('template', value) : url.searchParams.delete('template');
            link.href = url.toString();
        });
    };

    form.addEventListener('change', (event) => {
        if (!event.target.matches('[data-tp-template]')) return;
        form.querySelectorAll('.tp-card').forEach((card) => card.classList.toggle('is-selected', card.contains(event.target)));
        error.hidden = true;
        alertBox.hidden = true;
        syncLinks();
    });

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }
        if (!form.querySelector('[data-tp-template]:checked')) {
            event.preventDefault();
            error.textContent = messages.template;
            error.hidden = false;
            alertBox.textContent = messages.check;
            alertBox.hidden = false;
            form.querySelector('[data-tp-template]')?.focus();
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
        submit.disabled = submit.hasAttribute('data-read-only');
        submit.removeAttribute('aria-busy');
        label.textContent = idleLabel;
        syncLinks();
    });

    // Thumbnails: show a tap-to-retry message instead of a broken image.
    form.querySelectorAll('.tp-preview-bg').forEach((img) => {
        const message = img.parentElement.querySelector('.tp-thumb-error');
        const failed = () => { message.hidden = false; img.style.visibility = 'hidden'; };
        img.addEventListener('error', failed);
        img.addEventListener('load', () => { message.hidden = true; img.style.visibility = ''; });
        if (img.complete && img.naturalWidth === 0 && img.currentSrc) failed();
        message.addEventListener('click', (event) => {
            event.preventDefault();
            const url = new URL(img.src);
            url.searchParams.set('retry', Date.now());
            img.src = url.toString();
        });
    });

    // Large preview dialog; "use" selects that design and submits the same form.
    const dialog = document.getElementById('tp-dialog');
    if (dialog && typeof dialog.showModal === 'function') {
        const image = document.getElementById('tp-dialog-img');
        const dialogError = document.getElementById('tp-dialog-error');
        let previewKey = null;
        image.addEventListener('error', () => { if (image.getAttribute('src')) dialogError.hidden = false; });
        image.addEventListener('load', () => { dialogError.hidden = true; });
        form.addEventListener('click', (event) => {
            const button = event.target.closest('[data-tp-preview]');
            if (!button) return;
            event.preventDefault();
            previewKey = button.dataset.tpPreview;
            document.getElementById('tp-dialog-title').textContent = button.dataset.name;
            dialogError.hidden = true;
            image.alt = button.getAttribute('aria-label');
            image.src = button.dataset.src;
            dialog.showModal();
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog || event.target.closest('[data-tp-close]')) dialog.close();
            if (event.target.closest('[data-tp-use]') && previewKey) {
                const radio = form.querySelector(`[data-tp-template][value="${previewKey}"]`);
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
                dialog.close();
                form.requestSubmit(submit);
            }
        });
        dialog.addEventListener('close', () => { image.removeAttribute('src'); });
    } else {
        document.querySelectorAll('[data-tp-preview]').forEach((button) => { button.hidden = true; });
    }

    syncLinks();
}
