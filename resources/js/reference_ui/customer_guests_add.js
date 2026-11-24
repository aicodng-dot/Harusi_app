// Add Guests hub + Review Guests: progressive enhancement only. Every form works without
// JavaScript (tabs are links, rows are rows[] fields); the server parses, validates and
// de-duplicates everything again. No data leaves the page except through these forms.

const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

// ----- Method tabs: switch panels without reloading, keep the URL shareable. -----
const tabs = $$('[data-ga-tab]');
const panels = $$('[data-ga-panel]');
function select(key, focus = false) {
    tabs.forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.gaTab === key)));
    panels.forEach((panel) => { panel.hidden = panel.dataset.gaPanel !== key; });
    const url = new URL(window.location.href);
    url.searchParams.set('method', key);
    url.hash = '';
    history.replaceState(null, '', url);
    if (focus) document.getElementById(`ga-panel-${key}`)?.querySelector('textarea, input:not([type=hidden]), select, button')?.focus({ preventScroll: false });
}
tabs.forEach((tab) => {
    tab.addEventListener('click', (event) => {
        event.preventDefault();
        select(tab.dataset.gaTab, true);
        document.getElementById(`ga-panel-${tab.dataset.gaTab}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    tab.addEventListener('keydown', (event) => {
        const index = tabs.indexOf(tab);
        const next = { ArrowRight: index + 1, ArrowDown: index + 1, ArrowLeft: index - 1, ArrowUp: index - 1 }[event.key];
        if (next === undefined) return;
        event.preventDefault();
        const target = tabs[(next + tabs.length) % tabs.length];
        target.focus();
        select(target.dataset.gaTab);
    });
});

// ----- Pass type <-> number of people (Single 1, Double 2, Special 3..100). -----
function syncPeople(entry) {
    const pass = entry.querySelector('[data-ga-pass]');
    const people = entry.querySelector('[data-ga-people]');
    if (!pass || !people) return;
    const fixed = { single: 1, double: 2 }[pass.value];
    if (fixed) {
        people.value = String(fixed);
        people.readOnly = true;
    } else if (pass.value === 'special') {
        people.readOnly = false;
        if (!(Number(people.value) >= 3)) people.value = '3';
    } else {
        people.readOnly = false;
    }
}
document.addEventListener('change', (event) => {
    const entry = event.target.closest?.('[data-ga-entry]');
    if (entry && event.target.matches('[data-ga-pass]')) syncPeople(entry);
});
$$('[data-ga-entry]').forEach((entry) => {
    // Keep an unknown imported pass word visible until the owner changes it.
    if (['single', 'double', 'special'].includes(entry.querySelector('[data-ga-pass]')?.value)) syncPeople(entry);
});

// ----- "Type guests": add / remove rows. -----
const entries = document.querySelector('[data-ga-single] [data-ga-entries]');
function renumber() {
    if (!entries) return;
    $$('[data-ga-entry]', entries).forEach((entry, index) => {
        const label = entry.querySelector('[data-ga-number]');
        if (label) label.textContent = label.textContent.replace(/\d+/, String(index + 1));
        $$('[name^="rows["]', entry).forEach((input) => { input.name = input.name.replace(/^rows\[\d+\]/, `rows[${index}]`); });
    });
}
function addRow(values = {}) {
    const template = entries.querySelector('[data-ga-entry]');
    const row = template.cloneNode(true);
    $$('input', row).forEach((input) => { input.value = input.dataset.gaField === 'allowed_entries' ? '1' : ''; input.removeAttribute('autofocus'); });
    row.querySelector('[data-ga-pass]').value = 'single';
    Object.entries(values).forEach(([field, value]) => { const input = row.querySelector(`[data-ga-field="${field}"]`); if (input) input.value = value; });
    entries.append(row);
    syncPeople(row);
    renumber();
    return row;
}
function firstEmptyRow() {
    return $$('[data-ga-entry]', entries).find((entry) => !entry.querySelector('[data-ga-field="name"]').value.trim() && !entry.querySelector('[data-ga-field="phone_number"]').value.trim());
}
if (entries) {
    document.querySelector('[data-ga-add]')?.addEventListener('click', () => addRow().querySelector('[data-ga-field="name"]').focus());
    entries.addEventListener('click', (event) => {
        const button = event.target.closest('[data-ga-remove]');
        if (!button) return;
        const entry = button.closest('[data-ga-entry]');
        if ($$('[data-ga-entry]', entries).length > 1) entry.remove(); else $$('input', entry).forEach((input) => { input.value = input.dataset.gaField === 'allowed_entries' ? '1' : ''; });
        renumber();
    });
    // Enter in the last name field adds a new row instead of submitting half a list.
    entries.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || !event.target.matches('[data-ga-field="name"]')) return;
        event.preventDefault();
        const all = $$('[data-ga-entry]', entries);
        const current = event.target.closest('[data-ga-entry]');
        const next = all[all.indexOf(current) + 1] || addRow();
        next.querySelector('[data-ga-field="name"]').focus();
    });
}

// ----- Phone contacts (Contact Picker API: Chrome on Android, secure context only). -----
const contactsButton = document.querySelector('[data-ga-contacts]');
const contactsSupported = 'contacts' in navigator && 'ContactsManager' in window && window.isSecureContext;
if (contactsButton && contactsSupported) {
    contactsButton.hidden = false;
    document.querySelector('[data-ga-contacts-unsupported]')?.setAttribute('hidden', '');
    contactsButton.addEventListener('click', async () => {
        let picked = [];
        try {
            // Permission is requested by the browser only now, after the owner tapped the button.
            picked = await navigator.contacts.select(['name', 'tel'], { multiple: true });
        } catch {
            picked = [];
        }
        const note = document.querySelector('[data-ga-contacts-note]');
        select('single');
        if (!picked.length) {
            if (note) { note.textContent = contactsButton.dataset.none; note.hidden = false; }
            return;
        }
        picked.forEach((contact) => {
            const values = { name: (contact.name?.[0] || '').trim(), phone_number: (contact.tel?.[0] || '').trim() };
            if (!values.name && !values.phone_number) return;
            const row = firstEmptyRow();
            if (row) Object.entries(values).forEach(([field, value]) => { row.querySelector(`[data-ga-field="${field}"]`).value = value; });
            else addRow(values);
        });
        if (note) {
            note.textContent = picked.length === 1 ? contactsButton.dataset.addedOne : contactsButton.dataset.addedMany.replace('__N__', String(picked.length));
            note.hidden = false;
        }
    });
}

// ----- Names textarea: live line counter. -----
const text = document.querySelector('[data-ga-text]');
const counter = document.querySelector('[data-ga-lines]');
if (text && counter) {
    const update = () => {
        const n = text.value.split(/\r?\n/).filter((line) => line.trim() !== '').length;
        counter.textContent = n === 0 ? counter.dataset.zero : n === 1 ? counter.dataset.one : counter.dataset.template.replace('__N__', String(n));
    };
    text.addEventListener('input', update);
    update();
}
const defaultPass = document.querySelector('[data-ga-default-pass]');
const defaultCount = document.querySelector('[data-ga-default-count]');
if (defaultPass && defaultCount) {
    const toggle = () => { defaultCount.hidden = defaultPass.value !== 'special'; };
    defaultPass.addEventListener('change', toggle);
    toggle();
}

// ----- File chooser label. -----
const file = document.querySelector('[data-ig-file]');
if (file) {
    const label = document.getElementById('ig-file-name');
    const empty = label.textContent;
    file.addEventListener('change', () => {
        const name = file.files[0]?.name;
        label.textContent = name ? label.dataset.chosen.replace('__NAME__', name) : empty;
    });
}

// ----- Review: remove / undo rows, remove duplicates, live counts. -----
const review = document.querySelector('[data-gr-form]');
if (review) {
    const save = review.querySelector('[data-gr-save]');
    const refresh = () => {
        const rows = $$('[data-ga-entry]', review);
        const kept = rows.filter((row) => !row.querySelector('[data-gr-remove]')?.checked);
        const count = (status) => kept.filter((row) => row.dataset.status === status).length;
        const set = (key, value) => { const el = document.querySelector(`[data-gr-count="${key}"]`); if (el) el.textContent = String(value); };
        set('total', kept.length); set('ready', count('ready')); set('invalid', count('invalid')); set('duplicate', count('duplicate'));
        rows.forEach((row) => row.classList.toggle('is-removed', Boolean(row.querySelector('[data-gr-remove]')?.checked)));
        if (save && count('invalid') === 0) {
            const n = count('ready');
            save.textContent = n === 0 ? save.dataset.zero : n === 1 ? save.dataset.one : save.dataset.template.replace('__N__', String(n));
        } else if (save) {
            save.textContent = save.dataset.generic;
        }
    };
    review.addEventListener('change', (event) => {
        if (event.target.matches('[data-gr-remove]')) refresh();
        // Edited rows are re-checked by the server; mark them so the counts do not mislead.
        const row = event.target.closest('[data-ga-entry]');
        if (row && event.target.matches('[data-ga-field]') && row.dataset.status !== 'ready') {
            row.dataset.status = 'edited';
            row.classList.add('is-edited');
            refresh();
        }
    });
    const dupes = review.querySelector('[data-gr-remove-duplicates]');
    if (dupes) {
        dupes.hidden = false;
        dupes.addEventListener('click', () => {
            $$('[data-ga-entry][data-status="duplicate"] [data-gr-remove]', review).forEach((box) => { box.checked = true; });
            refresh();
            dupes.hidden = true;
        });
    }
    refresh();
}

// ----- Large lists: send rows as one JSON field (stays under PHP max_input_vars). -----
$$('[data-ga-json]').forEach((form) => {
    form.addEventListener('submit', () => {
        const rows = $$('[data-ga-entry]', form)
            .filter((row) => !row.querySelector('[data-gr-remove]')?.checked)
            .map((row) => Object.fromEntries($$('[data-ga-field]', row).map((input) => [input.dataset.gaField, input.value])));
        let hidden = form.querySelector('input[name="rows_json"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'rows_json';
            form.append(hidden);
        }
        hidden.value = JSON.stringify(rows);
        $$('[name^="rows["]', form).forEach((input) => { input.disabled = true; });
        const busy = form.querySelector('[data-ga-busy]');
        if (busy) busy.textContent = busy.dataset.gaBusy;
    });
});
// Coming back with the browser's Back button: make the fields editable again.
window.addEventListener('pageshow', () => $$('[data-ga-json] [name^="rows["]').forEach((input) => { input.disabled = false; }));
