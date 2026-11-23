// Select Invitation Recipients (reference UI/31_Select_Invitation_Recipients.png).
// Generation and sending run in small batches so large events never hit request
// limits; the server re-checks every guest id against the event on each request.
const configEl = document.getElementById('rc-config');

if (configEl) {
    const config = JSON.parse(configEl.textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const boxes = () => [...document.querySelectorAll('[data-rc-guest]:not(:disabled)')];
    const selected = () => boxes().filter((box) => box.checked).map((box) => Number(box.value));
    const counter = document.getElementById('rc-selected');
    const all = document.getElementById('rc-all');
    const alertBox = document.getElementById('rc-alert');
    const progress = document.getElementById('rc-progress');
    const progressText = document.getElementById('rc-progress-text');
    const progressBar = document.getElementById('rc-progress-bar');
    let busy = false;

    const format = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);
    const refreshCount = () => {
        const count = selected().length;
        if (counter) counter.textContent = String(count);
        if (all) {
            all.checked = count > 0 && count === boxes().length;
            all.indeterminate = count > 0 && count < boxes().length;
        }
    };
    const notify = (text, tone = 'info') => {
        alertBox.className = `is-alert is-alert-${tone}`;
        alertBox.textContent = text;
        alertBox.hidden = false;
        alertBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-rc-guest]')) refreshCount();
    });
    all?.addEventListener('change', () => {
        boxes().forEach((box) => { box.checked = all.checked; });
        refreshCount();
    });

    const batches = async (ids, url, label, extra = {}) => {
        const results = [];
        progress.hidden = false;
        for (let i = 0; i < ids.length; i += config.batch) {
            const chunk = ids.slice(i, i + config.batch);
            progressText.textContent = format(label, { done: Math.min(i + chunk.length, ids.length), total: ids.length });
            progressBar.value = i / ids.length;
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ guests: chunk, ...extra }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409 && data.configured === false) {
                throw new Error(config.messages.notConfigured);
            }
            if (!response.ok) throw new Error(config.messages.network);
            results.push(...(data.results || []));
        }
        progressBar.value = 1;
        return results;
    };

    const run = async (task) => {
        if (busy) return;
        const ids = selected();
        if (ids.length === 0) {
            notify(config.messages.selectFirst, 'warn');
            return;
        }
        busy = true;
        document.querySelectorAll('.rc-bar button').forEach((button) => { button.disabled = true; });
        try {
            await task(ids);
        } catch (error) {
            notify(error.message || config.messages.network, 'error');
        } finally {
            busy = false;
            document.querySelectorAll('.rc-bar button').forEach((button) => { button.disabled = button.id === 'rc-send' && !config.whatsapp; });
        }
    };

    document.getElementById('rc-generate')?.addEventListener('click', () => run(async (ids) => {
        const results = await batches(ids, config.routes.generate, config.messages.generating);
        const count = (status) => results.filter((r) => r.status === status).length;
        const problems = results.length - count('generated') - count('current');
        let text = format(config.messages.generated, { generated: count('generated'), current: count('current') });
        if (problems > 0) text += ' ' + format(config.messages.problems, { count: problems });
        notify(`${text} ${config.messages.refresh}`, problems > 0 ? 'warn' : 'ok');
        setTimeout(() => window.location.reload(), 1600);
    }));

    document.getElementById('rc-send')?.addEventListener('click', () => run(async (ids) => {
        const results = await batches(ids, config.routes.send, config.messages.sending);
        const count = (...statuses) => results.filter((r) => statuses.includes(r.status)).length;
        notify(format(config.messages.sendResult, {
            sent: count('sent'), failed: count('failed', 'skipped'), already: count('already_sent', 'in_progress'), needs: count('needs_card'),
        }), count('failed', 'skipped') > 0 ? 'warn' : 'ok');
        setTimeout(() => window.location.reload(), 2400);
    }));

    document.querySelectorAll('[data-rc-download]').forEach((button) => button.addEventListener('click', () => {
        const ids = selected();
        if (ids.length === 0) {
            notify(config.messages.selectFirst, 'warn');
            return;
        }
        const form = document.getElementById('rc-download-form');
        form.querySelectorAll('input[name="guests[]"]').forEach((input) => input.remove());
        form.querySelector('input[name="format"]').value = button.dataset.rcDownload;
        ids.forEach((id) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'guests[]';
            input.value = String(id);
            form.append(input);
        });
        form.submit();
    }));

    refreshCount();
}
