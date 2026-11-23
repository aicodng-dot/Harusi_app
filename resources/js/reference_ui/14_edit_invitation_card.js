// Invitation editor (reference UI/14_Edit_Invitation_Card.png).
// The server renders every preview with the same engine as the final cards, so the
// browser only edits the design document: no QR value or guest data lives here.
const configEl = document.getElementById('ed-config');

if (configEl) {
    const config = JSON.parse(configEl.textContent);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const $ = (id) => document.getElementById(id);
    const clone = (value) => JSON.parse(JSON.stringify(value));

    // PHP sends empty maps as JSON arrays; string-keyed maps must be objects to survive JSON.stringify.
    const normalize = (d) => {
        const asObject = (value) => (value && !Array.isArray(value) && typeof value === 'object' ? value : {});
        d.layout = asObject(d.layout);
        d.layout.blocks = asObject(d.layout.blocks);
        d.layout.photo = asObject(d.layout.photo);
        d.decor = asObject(d.decor);
        d.decor.items = asObject(d.decor.items);
        d.colors = asObject(d.colors);
        d.fonts = asObject(d.fonts);
        return d;
    };
    let doc = normalize(clone(config.document));
    let version = config.version;
    let saved = config.version > 0 ? JSON.stringify(doc) : null;
    const history = [JSON.stringify(doc)];
    let cursor = 0;
    let previewTimer = null;
    let previewController = null;
    let previewUrl = null;
    let saving = false;

    const img = $('ed-preview-img');
    const figure = $('ed-preview');
    const spinner = $('ed-spinner');
    const state = $('ed-state');
    const alertBox = $('ed-alert');
    const warningsBox = $('ed-warnings');

    // ----- document helpers ------------------------------------------------------
    const getPath = (path) => path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), doc);
    const setPath = (path, value) => {
        const keys = path.split('.');
        let node = doc;
        keys.slice(0, -1).forEach((k) => { node[k] ??= {}; node = node[k]; });
        node[keys.at(-1)] = value;
    };
    const effectiveColor = (key) => doc.colors?.[key] ?? config.templateColors[key];
    const effectiveFont = (role) => doc.fonts?.[role] ?? config.templateFonts[role];

    const dirty = () => JSON.stringify(doc) !== saved;
    const showState = () => {
        const isDirty = dirty();
        state.textContent = isDirty ? config.messages.unsaved : config.messages.allSaved;
        state.classList.toggle('is-dirty', isDirty);
        $('ed-undo').disabled = cursor === 0;
        $('ed-redo').disabled = cursor >= history.length - 1;
    };

    const commit = (coalesce = false) => {
        const snapshot = JSON.stringify(doc);
        if (snapshot === history[cursor]) {
            showState();
            return;
        }
        if (coalesce && cursor > 0 && history.length - 1 === cursor && commit.lastCoalesce) {
            history[cursor] = snapshot;
        } else {
            history.splice(cursor + 1);
            history.push(snapshot);
            cursor = history.length - 1;
            if (history.length > 80) {
                history.shift();
                cursor--;
            }
        }
        commit.lastCoalesce = coalesce;
        showState();
        schedulePreview();
    };

    // ----- preview ---------------------------------------------------------------
    const showWarnings = (codes) => {
        const list = codes.filter(Boolean).map((code) => config.messages.warnings[code]).filter(Boolean);
        warningsBox.hidden = list.length === 0;
        warningsBox.innerHTML = '';
        if (list.length) {
            const ul = document.createElement('ul');
            list.forEach((text) => { const li = document.createElement('li'); li.textContent = text; ul.append(li); });
            warningsBox.append(ul);
        }
    };

    const renderPreview = async () => {
        previewController?.abort();
        previewController = new AbortController();
        figure.classList.add('is-loading');
        spinner.hidden = false;
        try {
            const response = await fetch(config.routes.preview, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'image/png' },
                body: JSON.stringify({ document: doc }),
                signal: previewController.signal,
            });
            if (!response.ok) throw new Error(String(response.status));
            const blob = await response.blob();
            if (previewUrl) URL.revokeObjectURL(previewUrl);
            previewUrl = URL.createObjectURL(blob);
            img.src = previewUrl;
            showWarnings((response.headers.get('X-Invitation-Warnings') || '').split(','));
            const rawLayout = response.headers.get('X-Invitation-Layout');
            if (rawLayout) {
                const parsed = JSON.parse(atob(rawLayout));
                layoutMap = Array.isArray(parsed) ? {} : parsed;
                renderOverlay();
                syncCrop();
            }
            alertBox.hidden = true;
        } catch (error) {
            if (error.name === 'AbortError') return;
            alertBox.textContent = config.messages.previewFailed;
            alertBox.hidden = false;
        } finally {
            figure.classList.remove('is-loading');
            spinner.hidden = true;
        }
    };
    const schedulePreview = () => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(renderPreview, 350);
    };

    // ----- controls <-> document ---------------------------------------------------
    const syncControls = () => {
        document.querySelectorAll('[data-path]').forEach((input) => {
            const path = input.dataset.path;
            let value = getPath(path);
            if (path.startsWith('colors.')) value = effectiveColor(path.slice(7));
            if (path.startsWith('fonts.')) value = effectiveFont(path.slice(6));
            if (input.type === 'radio') {
                input.checked = String(value ?? '') === input.value;
            } else if (input.type === 'checkbox') {
                input.checked = Boolean(value);
            } else {
                input.value = value ?? '';
            }
        });
        document.querySelectorAll('[data-swatch]').forEach((swatch) => { swatch.style.background = effectiveColor(swatch.dataset.swatch); });
        document.querySelectorAll('[data-palette]').forEach((button) => {
            const palette = config.palettes[button.dataset.palette];
            button.classList.toggle('is-active', Object.keys(palette).every((key) => palette[key] === effectiveColor(key)));
        });
        const chip = $('ed-font-chip');
        const heading = effectiveFont('heading');
        chip.style.fontFamily = `'inv-${heading}'`;
        chip.textContent = document.querySelector(`[data-path="fonts.heading"] option[value="${heading}"]`)?.textContent || heading;
        $('ed-scale-out').textContent = `${Math.round((doc.font_scale || 1) * 100)}%`;
        syncHosts();
        syncWording();
        syncPhoto();
        syncArrange();
        loadCropPhoto();
        syncCrop();
    };

    const syncHosts = () => {
        const mode = doc.content.host_mode;
        const one = document.querySelector('[data-host="one"]');
        const two = document.querySelector('[data-host="two"]');
        one.hidden = !['bride_family', 'both_families', 'other'].includes(mode);
        two.hidden = !['groom_family', 'both_families'].includes(mode);
        const label = one.querySelector('[data-host-label]');
        label.textContent = mode === 'other' ? label.dataset.labelOther : label.dataset.labelFamily;
    };

    const syncWording = () => {
        const options = config.wording[doc.content.language]?.[doc.content.host_mode] || {};
        document.querySelectorAll('[data-style-preview]').forEach((el) => { el.textContent = options[el.dataset.stylePreview]?.intro || ''; });
        const intro = document.querySelector('[data-path="content.intro"]');
        const message = document.querySelector('[data-path="content.message"]');
        intro.placeholder = options[doc.content.style]?.intro || '';
        message.placeholder = options[doc.content.style]?.message || '';
    };

    const syncPhoto = () => {
        const has = Boolean(doc.photo.id);
        $('ed-photo-crop').hidden = !has;
        $('ed-photo-remove').hidden = !has;
        const chip = $('ed-photo-chip');
        if (has && chip.dataset.id !== doc.photo.id && config.photoUrls?.[doc.photo.id]) {
            chip.src = config.photoUrls[doc.photo.id];
            chip.dataset.id = doc.photo.id;
        }
        chip.hidden = !has;
    };

    document.getElementById('ed-form').addEventListener('input', (event) => {
        const input = event.target.closest('[data-path]');
        if (!input || input.type === 'radio' || input.type === 'checkbox' || input.tagName === 'SELECT') return;
        const path = input.dataset.path;
        let value = input.value;
        if ('number' in input.dataset) value = Number(value);
        if ('nullable' in input.dataset && value === '') value = null;
        setPath(path, value);
        if (path === 'font_scale') $('ed-scale-out').textContent = `${Math.round(value * 100)}%`;
        if (path.startsWith('colors.')) syncControls();
        commit(true);
    });

    document.getElementById('ed-form').addEventListener('change', (event) => {
        const input = event.target.closest('[data-path]');
        if (!input) return;
        const path = input.dataset.path;
        let value = input.type === 'checkbox' ? input.checked : input.value;
        if ('nullable' in input.dataset && value === '') value = null;
        if ('number' in input.dataset) value = Number(value);
        setPath(path, value);
        // Picking a tone returns to the suggested wording for that tone.
        if ('style' in input.dataset) {
            doc.content.intro = null;
            doc.content.message = null;
        }
        syncControls();
        commit(false);
    });

    document.querySelectorAll('[data-palette]').forEach((button) => button.addEventListener('click', () => {
        const palette = config.palettes[button.dataset.palette];
        doc.colors = {};
        Object.keys(palette).forEach((key) => { if (palette[key] !== config.templateColors[key]) doc.colors[key] = palette[key]; });
        syncControls();
        commit(false);
    }));

    // Rows open their panel (one at a time keeps the phone layout calm).
    document.querySelectorAll('.ed-row').forEach((row) => row.addEventListener('click', () => {
        const open = row.getAttribute('aria-expanded') === 'true';
        document.querySelectorAll('.ed-row').forEach((other) => {
            other.setAttribute('aria-expanded', 'false');
            document.getElementById(other.getAttribute('aria-controls')).hidden = true;
        });
        if (!open) {
            row.setAttribute('aria-expanded', 'true');
            document.getElementById(row.getAttribute('aria-controls')).hidden = false;
            syncCrop();
        }
    }));

    // ----- photo -----------------------------------------------------------------
    config.photoUrls = {};
    const photoChip = $('ed-photo-chip');
    if (doc.photo.id && photoChip.getAttribute('src')) config.photoUrls[doc.photo.id] = photoChip.getAttribute('src');
    $('ed-photo-input').addEventListener('change', async (event) => {
        const file = event.target.files?.[0];
        if (!file) return;
        const status = $('ed-photo-status');
        const idle = status.textContent;
        status.textContent = config.messages.uploading;
        const body = new FormData();
        body.append('photo', file);
        try {
            const response = await fetch(config.routes.photo, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' }, body });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok) {
                status.textContent = data.message || config.messages.uploadFailed;
                return;
            }
            config.photoUrls[data.id] = data.url;
            doc.photo = { ...doc.photo, id: data.id, zoom: 1, x: 0.5, y: 0.4 };
            status.textContent = idle;
            syncControls();
            commit(false);
        } catch {
            status.textContent = config.messages.network;
        } finally {
            event.target.value = '';
        }
    });
    $('ed-photo-remove').addEventListener('click', () => {
        doc.photo = { ...doc.photo, id: null };
        syncControls();
        commit(false);
    });

    // ----- history, reset, save --------------------------------------------------
    const restore = (index) => {
        cursor = index;
        doc = normalize(JSON.parse(history[cursor]));
        syncControls();
        showState();
        schedulePreview();
    };
    $('ed-undo').addEventListener('click', () => cursor > 0 && restore(cursor - 1));
    $('ed-redo').addEventListener('click', () => cursor < history.length - 1 && restore(cursor + 1));
    $('ed-reset').addEventListener('click', () => {
        if (!window.confirm(config.messages.resetConfirm)) return;
        const photo = doc.photo;
        doc = normalize(clone(config.defaults));
        doc.template = JSON.parse(history[cursor]).template;
        doc.photo = photo;
        syncControls();
        commit(false);
    });

    // Resolves true once the design is saved; "Save & Continue" then moves to the next step.
    const save = async () => {
        if (saving || config.readOnly) return false;
        saving = true;
        const button = $('ed-save');
        const label = $('ed-save-label');
        button.disabled = true;
        label.textContent = config.messages.saving;
        const snapshot = JSON.stringify(doc);
        try {
            const response = await fetch(config.routes.save, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify({ document: doc, version }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.ok) {
                alertBox.textContent = data.message || config.messages.network;
                alertBox.hidden = false;
                return false;
            }
            version = data.version;
            saved = snapshot;
            alertBox.hidden = true;
            showWarnings(data.warnings || []);
            state.textContent = data.message;
            return true;
        } catch {
            alertBox.textContent = config.messages.network;
            alertBox.hidden = false;
            return false;
        } finally {
            saving = false;
            button.disabled = false;
            label.textContent = config.messages.save;
            showState();
        }
    };
    $('ed-save').addEventListener('click', async () => {
        if (await save()) window.location.assign(config.routes.next);
    });
    $('ed-save-only').addEventListener('click', save);
    document.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
            event.preventDefault();
            save();
        }
    });

    window.addEventListener('beforeunload', (event) => {
        if (dirty() && history.length > 1 && !config.readOnly) {
            event.preventDefault();
            event.returnValue = config.messages.leave;
        }
    });

    // ----- free arrangement: drag, resize, hide ------------------------------------
    // Positions come from the renderer (X-Invitation-Layout) in logical card units, so
    // handles always match what was actually drawn, including any QR protection lift.
    const CARD_W = 1200;
    const CARD_H = 1600;
    const overlay = $('ed-overlay');
    const arrangeToggle = $('ed-arrange-toggle');
    const elementByKey = Object.fromEntries(config.elements.map((element) => [element.key, element]));
    let layoutMap = {};
    let arrangeMode = false;
    let selectedKey = null;
    let drag = null;

    const elementState = (key) => {
        if (key === 'photo') return doc.layout.photo || {};
        if (key.startsWith('decor.')) return doc.decor.items[key.slice(6)] || {};
        return doc.layout.blocks[key] || {};
    };
    const setElement = (key, patch) => {
        const next = { ...elementState(key), ...patch };
        ['dx', 'dy'].forEach((axis) => {
            if (!next[axis] || Math.abs(next[axis]) < 0.5) delete next[axis];
            else next[axis] = Math.round(Math.max(-1200, Math.min(1200, next[axis])) * 10) / 10;
        });
        if (next.size === undefined || Math.abs(next.size - 1) < 0.001) delete next.size;
        else next.size = Math.round(next.size * 100) / 100;
        if (!next.hidden) delete next.hidden;
        const empty = Object.keys(next).length === 0;
        if (key === 'photo') doc.layout.photo = next;
        else if (key.startsWith('decor.')) { if (empty) delete doc.decor.items[key.slice(6)]; else doc.decor.items[key.slice(6)] = next; }
        else if (empty) delete doc.layout.blocks[key];
        else doc.layout.blocks[key] = next;
    };
    const resize = (key, delta) => {
        const element = elementByKey[key];
        if (!element) return;
        const size = (elementState(key).size || 1) + delta;
        setElement(key, { size: Math.max(element.min, Math.min(element.max, size)) });
    };

    const renderOverlay = () => {
        overlay.replaceChildren();
        Object.entries(layoutMap).forEach(([key, [x, y, w, h, kind]]) => {
            const handle = document.createElement(kind === 'protected' ? 'div' : 'button');
            handle.className = `ed-handle is-${kind}${key === selectedKey ? ' is-selected' : ''}`;
            Object.assign(handle.style, { left: `${(x / CARD_W) * 100}%`, top: `${(y / CARD_H) * 100}%`, width: `${(w / CARD_W) * 100}%`, height: `${(h / CARD_H) * 100}%` });
            if (kind === 'protected') {
                handle.setAttribute('aria-hidden', 'true');
                handle.dataset.zone = key;
                handle.dataset.label = config.protectedLabels[key] || '';
            } else {
                handle.type = 'button';
                handle.dataset.key = key;
                handle.setAttribute('aria-label', elementByKey[key]?.label || key);
                handle.title = elementByKey[key]?.label || key;
            }
            overlay.append(handle);
        });
    };
    const select = (key) => {
        selectedKey = key;
        overlay.querySelectorAll('.ed-handle').forEach((handle) => handle.classList.toggle('is-selected', handle.dataset.key === key));
        document.querySelectorAll('[data-arrange-row]').forEach((row) => row.classList.toggle('is-selected', row.dataset.arrangeRow === key));
    };

    arrangeToggle?.addEventListener('click', () => {
        arrangeMode = !arrangeMode;
        overlay.hidden = !arrangeMode;
        figure.classList.toggle('is-arranging', arrangeMode);
        arrangeToggle.setAttribute('aria-pressed', String(arrangeMode));
        $('ed-arrange-label').textContent = arrangeMode ? config.messages.modeOff : config.messages.modeOn;
    });

    overlay.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest('button.ed-handle');
        if (!handle || !arrangeMode || config.readOnly) return;
        event.preventDefault();
        select(handle.dataset.key);
        handle.focus({ preventScroll: true });
        const state = elementState(handle.dataset.key);
        drag = { handle, key: handle.dataset.key, startX: event.clientX, startY: event.clientY, dx: state.dx || 0, dy: state.dy || 0,
            ratio: CARD_W / overlay.clientWidth, moved: false, pointer: event.pointerId };
        handle.setPointerCapture(event.pointerId);
    });
    overlay.addEventListener('pointermove', (event) => {
        if (!drag || event.pointerId !== drag.pointer) return;
        const mx = event.clientX - drag.startX;
        const my = event.clientY - drag.startY;
        if (Math.abs(mx) + Math.abs(my) > 3) drag.moved = true;
        drag.handle.style.transform = `translate(${mx}px, ${my}px)`;
    });
    const endDrag = (event, cancelled = false) => {
        if (!drag || event.pointerId !== drag.pointer) return;
        const current = drag;
        drag = null;
        if (cancelled || !current.moved) {
            current.handle.style.transform = '';
            return;
        }
        setElement(current.key, { dx: current.dx + (event.clientX - current.startX) * current.ratio, dy: current.dy + (event.clientY - current.startY) * current.ratio });
        syncArrange();
        commit(false);
    };
    overlay.addEventListener('pointerup', (event) => endDrag(event));
    overlay.addEventListener('pointercancel', (event) => endDrag(event, true));
    overlay.addEventListener('keydown', (event) => {
        const key = event.target.closest('button.ed-handle')?.dataset.key;
        if (!key || config.readOnly) return;
        const step = event.shiftKey ? 40 : 10;
        const state = elementState(key);
        const moves = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] };
        if (moves[event.key]) {
            setElement(key, { dx: (state.dx || 0) + moves[event.key][0], dy: (state.dy || 0) + moves[event.key][1] });
        } else if (event.key === '+' || event.key === '=') {
            resize(key, 0.05);
        } else if (event.key === '-' || event.key === '_') {
            resize(key, -0.05);
        } else {
            return;
        }
        event.preventDefault();
        select(key);
        syncArrange();
        commit(true);
    });
    overlay.addEventListener('wheel', (event) => {
        const key = event.target.closest('button.ed-handle')?.dataset.key;
        if (!key || !arrangeMode || config.readOnly) return;
        event.preventDefault();
        resize(key, event.deltaY < 0 ? 0.05 : -0.05);
        select(key);
        syncArrange();
        commit(true);
    }, { passive: false });

    // Arrangement list (size, visibility, reset) and per-piece decoration toggles.
    const arrangeList = $('ed-arrange-list');
    const piecesList = $('ed-decor-pieces');
    config.elements.forEach((element) => {
        const row = document.createElement('div');
        row.className = 'ed-arrange-row';
        row.dataset.arrangeRow = element.key;
        row.innerHTML = `<div class="ed-arrange-head"><strong></strong><span class="is-pill info" data-moved hidden></span></div>
            <div class="ed-arrange-controls">
                ${element.hideable ? `<label class="ed-check"><input type="checkbox" data-element-visible="${element.key}"><span></span></label>` : ''}
                <label class="ed-arrange-size"><span></span><input class="ed-range" type="range" min="${element.min}" max="${element.max}" step="0.05" data-element-size="${element.key}"></label>
                <button type="button" class="ed-tool" data-element-reset="${element.key}"></button>
            </div>`;
        row.querySelector('strong').textContent = element.label;
        row.querySelector('[data-moved]').textContent = config.messages.moved;
        row.querySelector('.ed-arrange-size span').textContent = config.messages.size;
        row.querySelector(`[data-element-reset]`).textContent = config.messages.reset;
        if (element.hideable) row.querySelector('.ed-check span').textContent = config.messages.visible;
        arrangeList.append(row);
        if (element.kind === 'decor' || element.key === 'motif' || element.key === 'divider') {
            const piece = document.createElement('label');
            piece.className = 'ed-check';
            piece.innerHTML = `<input type="checkbox" data-element-visible="${element.key}"><span></span>`;
            piece.querySelector('span').textContent = element.label;
            piecesList.append(piece);
        }
    });
    const syncArrange = () => {
        document.querySelectorAll('[data-element-visible]').forEach((input) => { input.checked = !elementState(input.dataset.elementVisible).hidden; });
        document.querySelectorAll('[data-element-size]').forEach((input) => { input.value = String(elementState(input.dataset.elementSize).size || 1); });
        document.querySelectorAll('[data-arrange-row]').forEach((row) => {
            const state = elementState(row.dataset.arrangeRow);
            row.querySelector('[data-moved]').hidden = !(state.dx || state.dy);
        });
    };
    const onArrangeInput = (event, coalesce) => {
        const target = event.target;
        if (target.dataset.elementVisible) {
            setElement(target.dataset.elementVisible, { hidden: !target.checked });
        } else if (target.dataset.elementSize) {
            setElement(target.dataset.elementSize, { size: Number(target.value) });
        } else {
            return;
        }
        event.stopPropagation();
        syncArrange();
        commit(coalesce);
    };
    [arrangeList, piecesList].forEach((list) => {
        list.addEventListener('input', (event) => onArrangeInput(event, true));
        list.addEventListener('change', (event) => onArrangeInput(event, false));
    });
    arrangeList.addEventListener('click', (event) => {
        const key = event.target.closest('[data-element-reset]')?.dataset.elementReset;
        const row = event.target.closest('[data-arrange-row]');
        if (key) {
            setElement(key, { dx: 0, dy: 0, size: 1 });
            syncArrange();
            commit(false);
        }
        if (row) select(row.dataset.arrangeRow);
    });
    $('ed-arrange-reset')?.addEventListener('click', () => {
        doc.layout = { blocks: {}, photo: {} };
        doc.decor.items = {};
        syncArrange();
        commit(false);
    });

    // ----- drag-to-crop photo tool -------------------------------------------------
    // Mirrors the renderer's cover-fit maths (Canvas::clippedImage): zoom scales the
    // cover fit, x/y are the focal point (0..1) of the visible window.
    const crop = $('ed-crop');
    let cropImage = null;
    const cropPointers = new Map();
    let cropGesture = null;
    const clamp = (value, min, max) => Math.max(min, Math.min(max, value));
    const loadCropPhoto = () => {
        const url = doc.photo.id ? config.photoUrls?.[doc.photo.id] : null;
        if (!url) {
            cropImage = null;
            crop.style.backgroundImage = '';
            return;
        }
        if (cropImage?.dataset?.src === url) return;
        const image = new Image();
        image.dataset.src = url;
        image.onload = () => { cropImage = image; syncCrop(); };
        image.src = url;
        crop.style.backgroundImage = `url("${url}")`;
    };
    const cropGeometry = () => {
        const frameW = crop.clientWidth;
        const frameH = crop.clientHeight;
        const iw = cropImage.naturalWidth;
        const ih = cropImage.naturalHeight;
        const scale = Math.max(frameW / iw, frameH / ih) * (doc.photo.zoom || 1);
        const visibleW = frameW / scale;
        const visibleH = frameH / scale;
        return { iw, ih, scale, visibleW, visibleH,
            sx: clamp((doc.photo.x ?? 0.5) * iw - visibleW / 2, 0, iw - visibleW),
            sy: clamp((doc.photo.y ?? 0.5) * ih - visibleH / 2, 0, ih - visibleH) };
    };
    const syncCrop = () => {
        const rect = layoutMap.photo;
        crop.style.aspectRatio = rect ? `${rect[2]} / ${rect[3]}` : '3 / 4';
        crop.classList.toggle('is-round', ['circle', 'oval'].includes(doc.photo.shape));
        if (!cropImage || crop.clientWidth === 0) return;
        const g = cropGeometry();
        crop.style.backgroundSize = `${g.iw * g.scale}px ${g.ih * g.scale}px`;
        crop.style.backgroundPosition = `${-g.sx * g.scale}px ${-g.sy * g.scale}px`;
    };
    const panCrop = (dxPx, dyPx, origin) => {
        const g = cropGeometry();
        doc.photo.x = Math.round(clamp((origin.sx + g.visibleW / 2 - dxPx / g.scale) / g.iw, 0, 1) * 1000) / 1000;
        doc.photo.y = Math.round(clamp((origin.sy + g.visibleH / 2 - dyPx / g.scale) / g.ih, 0, 1) * 1000) / 1000;
        syncCrop();
    };
    crop.addEventListener('pointerdown', (event) => {
        if (!cropImage || config.readOnly) return;
        crop.setPointerCapture(event.pointerId);
        cropPointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        const g = cropGeometry();
        const points = [...cropPointers.values()];
        cropGesture = { startX: event.clientX, startY: event.clientY, sx: g.sx, sy: g.sy, zoom: doc.photo.zoom || 1,
            distance: points.length === 2 ? Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y) : 0 };
    });
    crop.addEventListener('pointermove', (event) => {
        if (!cropGesture || !cropPointers.has(event.pointerId)) return;
        cropPointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        const points = [...cropPointers.values()];
        if (points.length === 2 && cropGesture.distance > 0) {
            doc.photo.zoom = Math.round(clamp(cropGesture.zoom * Math.hypot(points[0].x - points[1].x, points[0].y - points[1].y) / cropGesture.distance, 1, 3) * 100) / 100;
            syncCrop();
        } else if (points.length === 1) {
            panCrop(event.clientX - cropGesture.startX, event.clientY - cropGesture.startY, cropGesture);
        }
    });
    const endCrop = (event) => {
        if (!cropPointers.delete(event.pointerId)) return;
        if (cropPointers.size === 0 && cropGesture) {
            cropGesture = null;
            syncControls();
            commit(false);
        }
    };
    crop.addEventListener('pointerup', endCrop);
    crop.addEventListener('pointercancel', endCrop);
    crop.addEventListener('wheel', (event) => {
        if (!cropImage || config.readOnly) return;
        event.preventDefault();
        doc.photo.zoom = Math.round(clamp((doc.photo.zoom || 1) + (event.deltaY < 0 ? 0.1 : -0.1), 1, 3) * 100) / 100;
        syncControls();
        commit(true);
    }, { passive: false });
    crop.addEventListener('keydown', (event) => {
        if (!cropImage || config.readOnly) return;
        const moves = { ArrowLeft: [-0.02, 0], ArrowRight: [0.02, 0], ArrowUp: [0, -0.02], ArrowDown: [0, 0.02] };
        if (moves[event.key]) {
            doc.photo.x = Math.round(clamp((doc.photo.x ?? 0.5) - moves[event.key][0], 0, 1) * 1000) / 1000;
            doc.photo.y = Math.round(clamp((doc.photo.y ?? 0.5) - moves[event.key][1], 0, 1) * 1000) / 1000;
        } else if (event.key === '+' || event.key === '=' || event.key === '-') {
            doc.photo.zoom = Math.round(clamp((doc.photo.zoom || 1) + (event.key === '-' ? -0.1 : 0.1), 1, 3) * 100) / 100;
        } else {
            return;
        }
        event.preventDefault();
        syncControls();
        commit(true);
    });
    window.addEventListener('resize', syncCrop);

    syncControls();
    showState();
    renderPreview();
}
