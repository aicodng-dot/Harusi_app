// Personalised card preview (reference UI/06_Invitation_Preview.png): create or
// recreate this guest's card and send it, then show the real resulting state.
const configEl = document.getElementById('pv-config');

if (configEl) {
    const config = JSON.parse(configEl.textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const alertBox = document.getElementById('pv-alert');
    const notify = (text, tone) => {
        alertBox.className = `is-alert is-alert-${tone}`;
        alertBox.textContent = text;
        alertBox.hidden = false;
    };
    const post = async (url, body) => {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body),
        });
        return { response, data: await response.json().catch(() => ({})) };
    };
    const busy = (button, on) => {
        button.disabled = on;
        if (on) {
            button.dataset.label = button.innerHTML;
            button.textContent = config.messages.working;
        } else if (button.dataset.label) {
            button.innerHTML = button.dataset.label;
        }
    };

    document.querySelectorAll('[data-pv-generate]').forEach((button) => button.addEventListener('click', async () => {
        busy(button, true);
        try {
            const { response } = await post(config.routes.generate, { guests: [config.guest], force: true });
            if (!response.ok) throw new Error();
            window.location.reload();
        } catch {
            notify(config.messages.network, 'error');
            busy(button, false);
        }
    }));

    document.querySelectorAll('[data-pv-send]').forEach((button) => button.addEventListener('click', async () => {
        busy(button, true);
        try {
            const { response, data } = await post(config.routes.send, { guests: [config.guest], resend: button.dataset.resend === '1' });
            if (response.status === 409 && data.configured === false) {
                notify(config.messages.notConfigured, 'warn');
                return;
            }
            if (!response.ok) throw new Error();
            const status = data.results?.[0]?.status || 'failed';
            const label = config.messages.states[status] || status;
            document.getElementById('pv-message').textContent = label;
            notify(config.messages.sentResult.replace(':status', label), ['sent', 'delivered', 'read'].includes(status) ? 'ok' : 'warn');
        } catch {
            notify(config.messages.network, 'error');
        } finally {
            busy(button, false);
        }
    }));
}
