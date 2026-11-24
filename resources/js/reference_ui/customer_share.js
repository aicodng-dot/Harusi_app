// Invitation Sharing Center (manual sharing, no messaging API).
// Prepares personal messages and opens WhatsApp / SMS / the share sheet ONLY from a tap by the
// customer. It never sends anything itself and never opens more than one app per tap; the
// customer presses Send in WhatsApp or Messages and then tells us ("I sent it").
// Text rules mirror app/Services/Sharing/ShareMessage.php (the server re-validates).

const configEl = document.getElementById('sh-config');
const cfg = configEl ? JSON.parse(configEl.textContent) : null;
const i18n = cfg?.i18n ?? {};
const PLACEHOLDERS = cfg?.placeholders ?? ['guest_name', 'event_name', 'event_date', 'event_time', 'venue', 'invitation_url'];

// ----- Text helpers -----------------------------------------------------------------

export function render(body, values) {
    return String(body).split('\n').reduce((lines, line) => {
        let empty = false;
        const out = line.replace(/\{\{\s*([a-z_]+)\s*\}\}/g, (match, name) => {
            if (!PLACEHOLDERS.includes(name)) return match;
            const value = String(values[name] ?? '').replace(/[\r\n]+/g, ' ').trim();
            if (value === '') empty = true;
            return value;
        });
        if (!(empty && /:\s*$/u.test(out))) lines.push(out);
        return lines;
    }, []).join('\n');
}

export function problems(body, max) {
    if (String(body).trim() === '') return ['empty'];
    const list = [];
    if ([...body].length > max) list.push('too_long');
    for (const m of body.matchAll(/\{\{\s*([^{}]*?)\s*\}\}/gu)) {
        if (!PLACEHOLDERS.includes(m[1])) { list.push('unknown_placeholder'); break; }
    }
    const stripped = body.replace(/\{\{\s*[a-z_]+\s*\}\}/g, '');
    if (stripped.includes('{{') || stripped.includes('}}')) list.push('broken_placeholder');
    if (!/\{\{\s*invitation_url\s*\}\}/.test(body)) list.push('missing_url');
    return [...new Set(list)];
}

const GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const GSM_EXT = '^{}\\[~]|€';

export function segments(text) {
    let units = 0;
    let unicode = false;
    for (const ch of text) {
        if (GSM_EXT.includes(ch)) units += 2;
        else if (GSM.includes(ch)) units += 1;
        else { unicode = true; break; }
    }
    if (unicode) {
        units = text.length; // UTF-16 code units
        return { characters: [...text].length, segments: text === '' ? 0 : (units <= 70 ? 1 : Math.ceil(units / 67)), unicode };
    }
    return { characters: [...text].length, segments: text === '' ? 0 : (units <= 160 ? 1 : Math.ceil(units / 153)), unicode };
}

export const whatsappUrl = (digits, text) => `https://wa.me/${digits}?text=${encodeURIComponent(text)}`;
export const smsUrl = (digits, text) => `sms:${digits ? `+${digits}` : ''}?&body=${encodeURIComponent(text)}`;

const fill = (template, map) => Object.entries(map).reduce((s, [k, v]) => s.split(k).join(String(v)), String(template ?? ''));
const problemText = (keys) => keys.map((k) => String(i18n.problems?.[k] ?? k).replace(':max', cfg?.max ?? '')).join(' ');

async function copy(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.append(area);
        area.select();
        let ok = false;
        try { ok = document.execCommand('copy'); } catch { ok = false; }
        area.remove();
        return ok;
    }
}

function toast(el, message) {
    if (!el) return;
    el.textContent = message;
    el.hidden = false;
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.hidden = true; }, 3200);
}

async function post(url, data, keepalive = false) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(data),
    });
    if (!response.ok) throw new Error(String(response.status));
    return response.json();
}

function counterText(text) {
    const s = segments(text);
    return `${fill(i18n.chars, { __COUNT__: s.characters })} · ${fill(i18n.sms_parts, { __COUNT__: s.segments })}`;
}

// Native share sheet (Web Share API). Always called straight from a tap.
async function shareText(text, toastEl) {
    if (!navigator.share) { toast(toastEl, i18n.share_unsupported); return false; }
    try { await navigator.share({ text }); return true; } catch (error) { if (error?.name !== 'AbortError') toast(toastEl, i18n.share_unsupported); return false; }
}

async function loadImage(url, name) {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('image');
    const blob = await response.blob();
    if (!blob.type.startsWith('image/')) throw new Error('image');
    return new File([blob], `${name || 'invitation'}.png`, { type: 'image/png' });
}

async function shareImage(file, text, toastEl) {
    if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
        try { await navigator.share({ files: [file], text }); return true; } catch (error) { if (error?.name === 'AbortError') return false; }
    }
    toast(toastEl, i18n.image_unsupported);
    return shareText(text, toastEl);
}

const safeName = (name) => String(name).normalize('NFKD').replace(/[^\w-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40) || 'invitation';

// ----- Message editors (wizard, templates page, session "edit for everyone") ---------

function initEditor(root, getValues) {
    const select = root.querySelector('[data-sh-template]');
    const body = root.querySelector('[data-sh-body]');
    const preview = root.querySelector('[data-sh-preview]');
    const counter = root.querySelector('[data-sh-counter]');
    const unicode = root.querySelector('[data-sh-unicode]');
    const problemEl = root.querySelector('[data-sh-problems]');
    const rendered = root.hasAttribute('data-rendered');
    let edited = false;

    const update = () => {
        const text = rendered ? body.value : render(body.value, getValues());
        if (preview) preview.textContent = text;
        if (counter) counter.textContent = counterText(text);
        if (unicode) unicode.hidden = !segments(text).unicode;
        if (problemEl && !rendered) {
            const list = problems(body.value, cfg.max);
            problemEl.hidden = list.length === 0;
            problemEl.textContent = problemText(list);
        }
        root.dispatchEvent(new CustomEvent('sh:change', { bubbles: true }));
    };
    const apply = (key) => {
        const template = cfg.templates?.[key];
        if (!template) return;
        body.value = rendered ? render(template.body, getValues()) : template.body;
        edited = false;
        const hidden = document.querySelector('[data-sh-template-key]');
        if (hidden) hidden.value = key;
        update();
    };
    select?.addEventListener('change', () => apply(select.value));
    body.addEventListener('input', () => { edited = true; update(); });
    root.querySelectorAll('[data-sh-insert]').forEach((button) => button.addEventListener('click', () => {
        const token = `{{${button.dataset.shInsert}}}`;
        const start = body.selectionStart ?? body.value.length;
        const end = body.selectionEnd ?? start;
        body.setRangeText(token, start, end, 'end');
        body.focus();
        edited = true;
        update();
    }));
    update();

    return { body, update, apply, isEdited: () => edited, select };
}

// ----- Bulk wizard -------------------------------------------------------------------

function initWizard(form) {
    const steps = [...form.querySelectorAll('[data-step]')];
    const labels = [...document.querySelectorAll('[data-step-label]')];
    const pool = cfg.pool;
    const selection = new Set(cfg.selection);
    const startButton = form.querySelector('[data-sh-start]');
    const noCard = form.querySelector('[data-sh-no-card]');
    const passes = form.querySelector('[data-sh-passes]');
    let current = 0;
    let remaining = 0;
    const editor = initEditor(form.querySelector('[data-sh-editor]'), () => cfg.values);

    const value = (name) => form.querySelector(`input[name="${name}"]:checked`)?.value;
    const compute = () => {
        const audience = value('audience');
        const chosenPasses = [...form.querySelectorAll('[data-sh-pass]:checked')].map((el) => el.value);
        const withPhone = form.querySelector('[data-sh-with-phone]').checked;
        const skipDone = form.querySelector('[data-sh-skip-done]').checked;
        const base = pool.filter((g) => ({
            selection: selection.has(g.id),
            not_shared: g.share === null || g.share === 'skipped',
            all: true,
            pass: chosenPasses.includes(g.pass),
        })[audience] ?? false);
        const sums = {
            selected: base.length,
            with_phone: base.filter((g) => g.phone).length,
            missing_phone: base.filter((g) => !g.phone).length,
            shared: base.filter((g) => g.share === 'confirmed').length,
            no_card: base.filter((g) => !g.card).length,
            remaining: base.filter((g) => g.card && (!withPhone || g.phone) && (!skipDone || g.share !== 'confirmed')).length,
        };
        remaining = sums.remaining;
        Object.entries(sums).forEach(([key, n]) => { const el = form.querySelector(`[data-sum="${key}"]`); if (el) el.textContent = n.toLocaleString(); });
        noCard.hidden = sums.no_card === 0;
        noCard.querySelector('span').textContent = fill(i18n.no_card, { __COUNT__: sums.no_card });
        passes.hidden = audience !== 'pass';
        startButton.textContent = remaining > 0 ? fill(i18n.start, { __COUNT__: remaining.toLocaleString() }) : i18n.start_plain;
        form.querySelectorAll('[data-sh-next]')[0].disabled = remaining === 0;
        startButton.disabled = remaining === 0;
    };
    const go = (index) => {
        current = Math.max(0, Math.min(steps.length - 1, index));
        steps.forEach((step, i) => { step.hidden = i !== current; });
        labels.forEach((label, i) => { if (i === current) label.setAttribute('aria-current', 'step'); else label.removeAttribute('aria-current'); label.classList.toggle('is-done', i < current); });
        steps[current].querySelector('h2')?.setAttribute('tabindex', '-1');
        steps[current].querySelector('h2')?.focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    form.addEventListener('change', (event) => {
        if (event.target.matches('[data-sh-channel]') && !editor.isEdited()) {
            const key = event.target.value === 'sms' ? 'default_sms' : 'default_whatsapp';
            editor.apply(key);
            if (editor.select) editor.select.value = key;
        }
        if (event.target.matches('[data-sh-save-toggle]')) form.querySelector('[data-sh-save-name]').hidden = !event.target.checked;
        compute();
    });
    form.querySelectorAll('[data-sh-next]').forEach((button) => button.addEventListener('click', () => go(current + 1)));
    form.querySelectorAll('[data-sh-prev]').forEach((button) => button.addEventListener('click', () => go(current - 1)));
    form.addEventListener('submit', (event) => {
        const list = problems(editor.body.value, cfg.max);
        if (remaining === 0) { event.preventDefault(); go(0); return; }
        if (list.length) { event.preventDefault(); go(2); editor.body.focus(); return; }
        startButton.disabled = true;
    });
    compute();
    // Server-side errors land on the step they belong to.
    go(Number(form.dataset.errorStep || 0));
}

// ----- Guided one-by-one session --------------------------------------------------------

function initSession(main) {
    const list = cfg.recipients;
    const total = list.length;
    const $ = (sel) => main.querySelector(sel);
    const els = {
        recipient: $('[data-sh-recipient]'), done: $('[data-sh-done]'), nav: $('[data-sh-nav]'),
        of: $('[data-sh-of]'), name: $('[data-sh-name]'), initials: $('[data-sh-initials]'), pass: $('[data-sh-pass]'), phone: $('[data-sh-phone]'),
        state: $('[data-sh-state]'), already: $('[data-sh-already]'), unavailable: $('[data-sh-unavailable]'), nophone: $('[data-sh-nophone]'),
        card: $('[data-sh-card]'), openLink: $('[data-sh-open-link]'), text: $('[data-sh-text]'), counter: $('[data-sh-counter]'),
        confirm: $('[data-sh-confirm]'), confirmQ: $('[data-sh-confirm-q]'), undo: $('[data-sh-undo]'), toast: $('[data-sh-toast]'),
        download: $('[data-sh-download]'), bar: $('[data-sh-bar]'), listEl: $('[data-sh-list]'),
    };
    let index = Math.min(cfg.position, total);
    let image = null;
    let pendingConfirm = false;

    const guest = () => list[index];
    const values = (g) => ({ ...cfg.values, guest_name: g.name, invitation_url: g.url ?? '' });
    const save = (data, keepalive = false) => post(cfg.routes.progress, data, keepalive).catch(() => { toast(els.toast, i18n.save_failed); throw new Error('save'); });

    const counts = () => {
        const c = { total, initiated: 0, confirmed: 0, skipped: 0 };
        list.forEach((g) => { if (g.state && c[g.state] !== undefined) c[g.state] += 1; });
        c.remaining = total - c.confirmed - c.skipped;
        Object.entries(c).forEach(([key, n]) => { const el = main.querySelector(`[data-sh-count="${key}"]`); if (el) el.textContent = n.toLocaleString(); });
        els.bar.max = Math.max(1, total);
        els.bar.value = c.confirmed + c.skipped;
        return c;
    };
    const drawList = () => {
        els.listEl.replaceChildren(...list.map((g, i) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sh-jump';
            if (i === index) button.setAttribute('aria-current', 'true');
            const name = document.createElement('span');
            name.textContent = `${i + 1}. ${g.name}`;
            const state = document.createElement('small');
            state.className = `sh-dot is-${g.state ?? 'none'}`;
            state.textContent = g.state ? i18n.state[g.state] : '';
            button.append(name, state);
            button.addEventListener('click', () => show(i));
            li.append(button);
            return li;
        }));
    };
    const updateLinks = () => {
        const g = guest();
        if (!g) return;
        const text = els.text.value;
        main.querySelectorAll('a[data-sh-action="whatsapp"]').forEach((a) => { a.href = whatsappUrl(g.phone, text); });
        main.querySelectorAll('a[data-sh-action="sms"]').forEach((a) => { a.href = smsUrl(g.phone, text); });
        els.counter.textContent = counterText(text);
    };
    const drawState = () => {
        const g = guest();
        els.state.hidden = !g.state;
        els.state.className = `sh-state is-pill ${({ confirmed: 'ok', initiated: 'warn', skipped: 'muted' })[g.state] ?? ''}`;
        els.state.textContent = g.state ? i18n.state[g.state] : '';
        els.undo.hidden = g.state !== 'confirmed';
        els.confirm.hidden = !(pendingConfirm && g.state !== 'confirmed');
        counts();
    };

    function show(i) {
        index = Math.max(0, Math.min(total, i));
        pendingConfirm = false;
        image = null;
        save({ action: 'move', position: index }).catch(() => {});
        if (index >= total) { finishView(); return; }
        els.recipient.hidden = false;
        els.done.hidden = true;
        els.nav.hidden = false;
        const g = guest();
        els.of.textContent = fill(i18n.of, { __N__: index + 1, __TOTAL__: total });
        els.name.textContent = g.name;
        els.initials.textContent = g.initials.replace(' & ', '');
        els.initials.className = `rc-avatar t${g.id % 4}`;
        els.pass.textContent = g.pass;
        els.phone.textContent = g.phone_display || '—';
        els.nophone.hidden = g.phone !== '' || cfg.channel === 'share';
        els.nophone.textContent = cfg.channel === 'sms' ? i18n.no_phone_sms : i18n.no_phone;
        els.unavailable.hidden = !g.unavailable;
        els.already.hidden = !g.elsewhere;
        if (g.elsewhere) {
            const date = g.elsewhere.at ? new Date(String(g.elsewhere.at).replace(' ', 'T')).toLocaleDateString(i18n.locale) : '';
            els.already.textContent = fill(i18n.already, { __CHANNEL__: i18n.channels[g.elsewhere.channel] ?? '', __DATE__: date });
        }
        els.card.hidden = g.unavailable;
        if (!g.unavailable) {
            els.card.src = g.card;
            els.card.alt = g.name;
            els.openLink.href = g.url;
            els.download.href = `${g.card}?download=1`;
        }
        els.text.value = render(cfg.message, values(g));
        main.querySelectorAll('[data-sh-action], [data-sh-copy], [data-sh-share-image], [data-sh-download]').forEach((el) => {
            el.toggleAttribute('hidden', el.hasAttribute('data-sh-action') && !el.hasAttribute('data-secondary') && el.dataset.shAction !== cfg.channel);
            el.setAttribute('aria-disabled', g.unavailable ? 'true' : 'false');
            el.classList.toggle('is-disabled', g.unavailable);
        });
        els.confirmQ.textContent = fill(i18n.did_send, { __NAME__: g.name });
        updateLinks();
        drawState();
        drawList();
        $('[data-sh-prev]').disabled = index === 0;
        // Prepare the card image now so "Share card image" can run inside the tap.
        if (!g.unavailable && navigator.canShare) {
            const wanted = g.id;
            loadImage(g.card, safeName(g.name)).then((file) => { if (guest()?.id === wanted) image = file; }).catch(() => { image = null; });
        }
        els.name.setAttribute('tabindex', '-1');
        els.name.focus({ preventScroll: true });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function finishView() {
        els.recipient.hidden = true;
        els.nav.hidden = true;
        els.done.hidden = false;
        const left = list.map((g, i) => ({ g, i })).filter(({ g }) => !g.state || g.state === 'initiated');
        const wrap = $('[data-sh-left]');
        wrap.hidden = left.length === 0;
        $('[data-sh-left-list]').replaceChildren(...left.map(({ g, i }) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sh-jump';
            button.textContent = `${i + 1}. ${g.name}${g.state ? ` — ${i18n.state[g.state]}` : ''}`;
            button.addEventListener('click', () => show(i));
            li.append(button);
            return li;
        }));
        counts();
        drawList();
        $('#sh-done-title').setAttribute('tabindex', '-1');
        $('#sh-done-title').focus();
    }

    const setState = (state) => { if (state !== undefined) guest().state = state; drawState(); drawList(); };
    const initiated = (channel) => {
        const g = guest();
        if (!g || g.unavailable) return;
        pendingConfirm = true;
        if (g.state !== 'confirmed') g.state = 'initiated';
        drawState();
        drawList();
        save({ action: 'initiated', guest_id: g.id, channel, position: index }, true).catch(() => {});
    };

    main.addEventListener('click', async (event) => {
        const target = event.target.closest('[data-sh-action], [data-sh-copy], [data-sh-share-image]');
        if (!target) return;
        const g = guest();
        if (!g || g.unavailable) { event.preventDefault(); return; }
        if (target.matches('[data-sh-action]')) {
            const channel = target.dataset.shAction;
            if (channel === 'share') {
                if (await shareText(els.text.value, els.toast)) initiated('share');
            } else {
                initiated(channel); // the link itself opens the app; we do not block it
            }
        } else if (target.matches('[data-sh-copy]')) {
            const ok = await copy(target.dataset.shCopy === 'link' ? g.url : els.text.value);
            toast(els.toast, ok ? i18n.copied : i18n.copy_failed);
        } else if (target.matches('[data-sh-share-image]')) {
            if (await shareImage(image, els.text.value, els.toast)) initiated('share');
        }
    });
    els.text.addEventListener('input', updateLinks);
    $('[data-sh-sent]').addEventListener('click', async () => {
        const g = guest();
        pendingConfirm = false;
        try {
            const result = await save({ action: 'confirmed', guest_id: g.id, channel: cfg.channel, position: index + 1 });
            setState(result.state);
            setTimeout(() => show(index + 1), 350);
        } catch { drawState(); }
    });
    $('[data-sh-notyet]').addEventListener('click', () => { pendingConfirm = false; drawState(); });
    els.undo.addEventListener('click', async () => {
        const g = guest();
        try { const result = await save({ action: 'unconfirm', guest_id: g.id, channel: cfg.channel }); setState(result.state); } catch { /* toast shown */ }
    });
    $('[data-sh-prev]').addEventListener('click', () => show(index - 1));
    $('[data-sh-next-guest]').addEventListener('click', () => show(index + 1));
    $('[data-sh-skip]').addEventListener('click', async () => {
        const g = guest();
        if (g && g.state !== 'confirmed') {
            try { const result = await save({ action: 'skipped', guest_id: g.id, channel: cfg.channel, position: index + 1 }); g.state = result.state; } catch { return; }
        }
        show(index + 1);
    });
    $('[data-sh-pause]').addEventListener('click', async () => {
        try { await save({ action: 'pause', position: index }); } catch { return; }
        window.location.href = cfg.routes.hub;
    });
    $('[data-sh-finish]').addEventListener('click', async () => {
        try { await save({ action: 'complete', position: total }); } catch { return; }
        window.location.href = cfg.routes.hub;
    });
    $('[data-sh-review]').addEventListener('click', () => {
        const first = list.findIndex((g) => !g.state || g.state === 'initiated');
        show(first === -1 ? 0 : first);
    });

    // "Change the message for everyone".
    const allEditor = main.querySelector('.sh-edit-all [data-sh-editor]');
    if (allEditor) {
        const editor = initEditor(allEditor, () => (guest() ? values(guest()) : cfg.values));
        main.querySelector('[data-sh-save-all]').addEventListener('click', async () => {
            try {
                const result = await post(cfg.routes.progress, { action: 'message', message: editor.body.value });
                cfg.message = result.message;
                if (guest()) els.text.value = render(cfg.message, values(guest()));
                updateLinks();
                toast(els.toast, i18n.saved_all);
            } catch { toast(els.toast, i18n.save_failed); }
        });
    }

    // Coming back from WhatsApp / Messages: ask whether it was sent.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && pendingConfirm && guest()?.state !== 'confirmed') {
            els.confirm.hidden = false;
            $('[data-sh-sent]').focus({ preventScroll: false });
        }
    });

    show(index);
}

// ----- One guest ---------------------------------------------------------------------------

function initGuest(main) {
    const editorRoot = main.querySelector('[data-sh-editor]');
    const editor = initEditor(editorRoot, () => cfg.values);
    const toastEl = main.querySelector('[data-sh-toast]');
    const confirmBox = main.querySelector('[data-sh-confirm]');
    const statePill = main.querySelector('[data-sh-state]');
    let image = null;
    let channel = null;

    const updateLinks = () => {
        main.querySelectorAll('a[data-sh-action="whatsapp"]').forEach((a) => { a.href = whatsappUrl(cfg.phone, editor.body.value); });
        main.querySelectorAll('a[data-sh-action="sms"]').forEach((a) => { a.href = smsUrl(cfg.phone, editor.body.value); });
    };
    editorRoot.addEventListener('sh:change', updateLinks);
    updateLinks();
    main.querySelector('[data-sh-confirm-q]').textContent = fill(i18n.did_send, { __NAME__: cfg.name });
    if (navigator.canShare) loadImage(cfg.card, safeName(cfg.name)).then((file) => { image = file; }).catch(() => {});

    const record = (action) => post(cfg.routes.record, { guest_id: cfg.guest, channel: channel ?? 'whatsapp', action, template: editor.select?.value ?? null }, action === 'initiated')
        .then((result) => {
            if (result.state && statePill) {
                statePill.textContent = i18n.state[result.state] ?? result.state;
                statePill.className = `is-pill ${({ confirmed: 'ok', initiated: 'warn', skipped: 'muted' })[result.state] ?? 'muted'}`;
            }
            return result;
        })
        .catch(() => toast(toastEl, i18n.save_failed));
    const opened = (used) => { channel = used; confirmBox.hidden = false; record('initiated'); };

    main.addEventListener('click', async (event) => {
        const target = event.target.closest('[data-sh-action], [data-sh-copy], [data-sh-share-image]');
        if (!target) return;
        if (target.matches('[data-sh-action]')) {
            const used = target.dataset.shAction;
            if (used === 'share') { if (await shareText(editor.body.value, toastEl)) opened('share'); } else opened(used);
        } else if (target.matches('[data-sh-copy]')) {
            const ok = await copy(target.dataset.shCopy === 'link' ? cfg.url : editor.body.value);
            toast(toastEl, ok ? i18n.copied : i18n.copy_failed);
        } else if (await shareImage(image, editor.body.value, toastEl)) {
            opened('share');
        }
    });
    main.querySelector('[data-sh-sent]').addEventListener('click', () => { confirmBox.hidden = true; record('confirmed'); });
    main.querySelector('[data-sh-notyet]').addEventListener('click', () => { confirmBox.hidden = true; });
}

// ----- Boot --------------------------------------------------------------------------------

if (cfg) {
    const wizard = document.querySelector('[data-sh-wizard]');
    const session = document.querySelector('[data-sh-session]');
    const guestPage = document.querySelector('[data-sh-guest]');
    if (wizard) initWizard(wizard);
    else if (session) initSession(session);
    else if (guestPage) initGuest(guestPage);
    else document.querySelectorAll('[data-sh-editor]').forEach((root) => initEditor(root, () => cfg.values));
}
document.querySelectorAll('[data-sh-confirm-submit]').forEach((form) => form.addEventListener('submit', (event) => {
    if (!window.confirm(form.dataset.shConfirmSubmit)) event.preventDefault();
}));
