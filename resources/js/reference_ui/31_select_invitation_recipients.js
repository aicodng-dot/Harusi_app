// Guests page (reference UI/31): advanced list + bulk actions.
// Generation, sending and deletion run in small batches so large events never hit request
// limits; the server re-checks every guest id against the event on each request.
// Search/sort/filters are plain GET forms; JavaScript only makes them live.
const configEl = document.getElementById('rc-config');

if (configEl) {
    const config = JSON.parse(configEl.textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const alertBox = document.getElementById('rc-alert');
    const progress = document.getElementById('rc-progress');
    const progressText = document.getElementById('rc-progress-text');
    const progressBar = document.getElementById('rc-progress-bar');
    const counter = document.getElementById('rc-selected');
    let busy = false;
    let allMatching = false; // "Select all N matching guests" (across pages)

    const results = () => document.querySelector('[data-gm-results]');
    const boxes = () => [...document.querySelectorAll('[data-rc-guest]:not(:disabled)')];
    const matching = () => { try { return JSON.parse(results()?.dataset.matching || '[]'); } catch { return []; } };
    const selected = () => (allMatching ? matching() : boxes().filter((box) => box.checked).map((box) => Number(box.value)));
    const format = (text, values) => Object.entries(values).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);

    const refreshCount = () => {
        const count = selected().length;
        if (counter) counter.textContent = String(count);
        document.querySelector('.rc-bar')?.classList.toggle('has-selection', count > 0);
        const pageAll = document.querySelector('[data-gm-page-all]');
        const pageCount = boxes().filter((box) => box.checked).length;
        if (pageAll) {
            pageAll.checked = pageCount > 0 && pageCount === boxes().length;
            pageAll.indeterminate = pageCount > 0 && pageCount < boxes().length;
        }
        const matchButton = document.querySelector('[data-gm-select-matching]');
        if (matchButton) matchButton.textContent = allMatching ? matchButton.dataset.selected : matchButton.dataset.label;
        const clear = document.querySelector('[data-gm-clear-selection]');
        if (clear) clear.hidden = count === 0;
    };
    const notify = (text, tone = 'info') => {
        alertBox.className = `is-alert is-alert-${tone}`;
        alertBox.textContent = text;
        alertBox.hidden = false;
        alertBox.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };

    // ----- Selection (delegated: the results region is replaced by live search). -----
    document.addEventListener('change', (event) => {
        if (event.target.matches('[data-rc-guest]')) {
            if (!event.target.checked) allMatching = false;
            refreshCount();
        } else if (event.target.matches('[data-gm-page-all]')) {
            allMatching = false;
            boxes().forEach((box) => { box.checked = event.target.checked; });
            refreshCount();
        }
    });
    document.addEventListener('click', (event) => {
        if (event.target.closest('[data-gm-select-matching]')) {
            allMatching = true;
            boxes().forEach((box) => { box.checked = true; });
            refreshCount();
        } else if (event.target.closest('[data-gm-clear-selection]')) {
            allMatching = false;
            boxes().forEach((box) => { box.checked = false; });
            refreshCount();
        }
    });

    // ----- Live search (debounced), sort and filter drawer. -----
    const form = document.querySelector('[data-gm-form]');
    const input = document.querySelector('[data-gm-q]');
    let timer = null;
    let request = 0;
    const load = async (url) => {
        const mine = ++request;
        results()?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const html = await response.text();
            if (mine !== request) return; // a newer search finished first
            const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-gm-results]');
            if (next && results()) {
                results().replaceWith(next);
                allMatching = false;
                history.replaceState(null, '', url);
                refreshCount();
                watchPager();
            }
        } catch {
            window.location.href = url; // fall back to a normal page load
        } finally {
            results()?.removeAttribute('aria-busy');
        }
    };
    const formUrl = () => {
        const url = new URL(form.action);
        for (const [key, value] of new FormData(form)) {
            if (value !== '' && !(key === 'sort' && value === 'name_asc')) url.searchParams.append(key, value);
        }
        return url.toString();
    };
    if (form && input) {
        input.addEventListener('input', () => {
            clearTimeout(timer);
            const value = input.value.trim();
            // Search from 2 characters (or when cleared), 350 ms after the last key.
            if (value.length === 1) return;
            timer = setTimeout(() => load(formUrl()), 350);
        });
        form.querySelector('[data-gm-sort]')?.addEventListener('change', () => load(formUrl()));
        // Keep shared links short: leave out "Any" filters and the default sort.
        form.addEventListener('submit', () => {
            form.querySelectorAll('input[type=radio]:checked, select, input[type=search]').forEach((el) => {
                if (el.value === '' || (el.name === 'sort' && el.value === 'name_asc')) el.disabled = true;
            });
            setTimeout(() => form.querySelectorAll(':disabled').forEach((el) => { el.disabled = false; }), 0);
        });
    }
    // ----- Endless list: the next 50 guests load as the owner scrolls near the end. -----
    // The page links stay as a fallback; without JavaScript they work as normal pages.
    let loadingMore = false;
    const observer = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => { if (entries.some((e) => e.isIntersecting)) loadMore(); }, { rootMargin: '700px 0px' })
        : null;
    function watchPager() {
        observer?.disconnect();
        const nav = results()?.querySelector('.gm-pages');
        const next = nav?.querySelector('a[rel="next"]');
        if (!nav || !next) return;
        nav.classList.add('is-auto');
        next.textContent = nav.dataset.more;
        observer?.observe(nav);
    }
    async function loadMore() {
        const nav = results()?.querySelector('.gm-pages');
        const next = nav?.querySelector('a[rel="next"]');
        if (loadingMore || !next) return;
        loadingMore = true;
        next.textContent = nav.dataset.loading;
        next.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(next.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error();
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const body = results().querySelector('tbody');
            doc.querySelectorAll('[data-gm-results] tbody .gm-row').forEach((row) => {
                const copy = document.importNode(row, true);
                const box = copy.querySelector('[data-rc-guest]');
                if (box && allMatching && !box.disabled) box.checked = true;
                body.append(copy);
            });
            const newNav = doc.querySelector('[data-gm-results] .gm-pages');
            if (newNav) nav.replaceWith(document.importNode(newNav, true)); else nav.remove();
            const showing = results().querySelector('[data-gm-showing]');
            if (showing) showing.textContent = showing.dataset.template.replace('__TO__', String(Number(showing.dataset.from) + body.querySelectorAll('.gm-row').length - 1));
            refreshCount();
        } catch {
            next.textContent = nav.dataset.more; // tap to retry
        } finally {
            next.removeAttribute('aria-busy');
            loadingMore = false;
            watchPager();
        }
    }
    document.addEventListener('click', (event) => {
        const link = event.target.closest('.gm-pages.is-auto a[rel="next"]');
        if (!link) return;
        event.preventDefault();
        loadMore();
    });
    watchPager();

    const drawer = document.querySelector('[data-gm-filters]');
    const scrim = document.querySelector('[data-gm-scrim]');
    const opener = document.querySelector('[data-gm-filters-open]');
    if (drawer && opener) {
        drawer.classList.add('is-enhanced'); // hidden until opened (shown inline without JS)
        const open = () => { drawer.classList.add('is-open'); scrim.hidden = false; opener.setAttribute('aria-expanded', 'true'); drawer.querySelector('input')?.focus(); };
        const close = () => { drawer.classList.remove('is-open'); scrim.hidden = true; opener.setAttribute('aria-expanded', 'false'); opener.focus(); };
        opener.addEventListener('click', () => (drawer.classList.contains('is-open') ? close() : open()));
        drawer.querySelector('[data-gm-filters-close]')?.addEventListener('click', close);
        scrim.addEventListener('click', close);
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape' && drawer.classList.contains('is-open')) close(); });
    }

    // ----- Bulk actions. -----
    const batches = async (ids, url, label, extra = {}, size = config.batch) => {
        const out = [];
        progress.hidden = false;
        for (let i = 0; i < ids.length; i += size) {
            const chunk = ids.slice(i, i + size);
            progressText.textContent = format(label, { done: Math.min(i + chunk.length, ids.length), total: ids.length });
            progressBar.value = i / ids.length;
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ guests: chunk, ...extra }),
            });
            const data = await response.json().catch(() => ({}));
            if (response.status === 409 && data.configured === false) throw new Error(config.messages.notConfigured);
            if (response.status === 409 && data.locked) throw new Error(config.messages.deleteLocked);
            if (!response.ok) throw new Error(config.messages.network);
            out.push(data.results ? data.results : data);
        }
        progressBar.value = 1;
        return out.flat();
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
        const out = await batches(ids, config.routes.generate, config.messages.generating);
        const count = (status) => out.filter((r) => r.status === status).length;
        const problems = out.length - count('generated') - count('current');
        let text = format(config.messages.generated, { generated: count('generated'), current: count('current') });
        if (problems > 0) text += ' ' + format(config.messages.problems, { count: problems });
        notify(`${text} ${config.messages.refresh}`, problems > 0 ? 'warn' : 'ok');
        setTimeout(() => window.location.reload(), 1600);
    }));

    document.getElementById('rc-send')?.addEventListener('click', () => run(async (ids) => {
        const out = await batches(ids, config.routes.send, config.messages.sending);
        const count = (...statuses) => out.filter((r) => statuses.includes(r.status)).length;
        notify(format(config.messages.sendResult, {
            sent: count('sent'), failed: count('failed', 'skipped', 'no_phone'), already: count('already_sent', 'in_progress'), needs: count('needs_card'),
        }), count('failed', 'skipped', 'no_phone') > 0 ? 'warn' : 'ok');
        setTimeout(() => window.location.reload(), 2400);
    }));

    const deleteButton = document.getElementById('rc-delete');
    deleteButton?.addEventListener('click', () => run(async (ids) => {
        if (ids.length > 500) throw new Error(config.messages.deleteTooMany);
        if (!window.confirm(deleteButton.dataset.confirm.replace(':count', String(ids.length)))) return;
        const out = await batches(ids, config.routes.delete, config.messages.loading, { confirm: true }, 100);
        const sum = (key) => out.reduce((total, r) => total + (Number(r[key]) || 0), 0);
        notify(format(config.messages.deleteResult, { deleted: sum('deleted'), admitted: sum('admitted') }), sum('admitted') > 0 ? 'warn' : 'ok');
        setTimeout(() => window.location.reload(), 1600);
    }));

    // Share: hand the selection to the Sharing Center (customer then shares one guest at a time).
    document.getElementById('rc-share')?.addEventListener('click', () => {
        const ids = selected();
        if (ids.length === 0) {
            notify(config.messages.selectFirst, 'warn');
            return;
        }
        const form = document.getElementById('rc-share-form');
        form.querySelectorAll('input[name="guest_ids"]').forEach((el) => el.remove());
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'guest_ids';
        field.value = ids.slice(0, 2000).join(',');
        form.append(field);
        form.submit();
    });

    document.querySelectorAll('[data-rc-download]').forEach((button) => button.addEventListener('click', () => {
        const ids = selected();
        if (ids.length === 0) {
            notify(config.messages.selectFirst, 'warn');
            return;
        }
        const dl = document.getElementById('rc-download-form');
        dl.querySelectorAll('input[name="guests[]"], input[name="guest_ids"]').forEach((el) => el.remove());
        dl.querySelector('input[name="format"]').value = button.dataset.rcDownload;
        const field = document.createElement('input');
        field.type = 'hidden';
        field.name = 'guest_ids';
        field.value = ids.slice(0, 2000).join(',');
        dl.append(field);
        dl.submit();
    }));

    refreshCount();
}
