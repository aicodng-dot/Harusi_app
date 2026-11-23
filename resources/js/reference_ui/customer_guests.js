// Add Guest (reference 15) and Import Guests (reference 16): small progressive enhancements.
// Both forms work without JavaScript; the server applies the same pass rules.
const form = document.querySelector('[data-ag-form]');
if (form) {
    const count = form.querySelector('[data-ag-count]');
    const help = document.getElementById('ag-count-help');
    const steps = [...form.querySelectorAll('[data-ag-step]')];
    const minSpecial = Number(count.dataset.minSpecial);
    const max = Number(count.dataset.max);
    const pass = () => form.querySelector('[data-ag-pass]:checked')?.value || 'single';
    const sync = () => {
        const type = pass();
        const fixed = { single: 1, double: 2 }[type];
        let value = Math.round(Number(count.value)) || 1;
        value = fixed || Math.min(max, Math.max(minSpecial, value));
        count.value = String(value);
        count.readOnly = Boolean(fixed);
        count.min = String(fixed || minSpecial);
        steps[0].disabled = Boolean(fixed) || value <= minSpecial;
        steps[1].disabled = Boolean(fixed) || value >= max;
        help.textContent = help.dataset[type];
    };
    form.querySelectorAll('[data-ag-pass]').forEach((radio) => radio.addEventListener('change', sync));
    steps.forEach((button) => button.addEventListener('click', () => {
        count.value = String((Number(count.value) || 0) + Number(button.dataset.agStep));
        sync();
    }));
    count.addEventListener('change', sync);
    sync();
}

const file = document.querySelector('[data-ig-file]');
if (file) {
    const label = document.getElementById('ig-file-name');
    const empty = label.textContent;
    file.addEventListener('change', () => {
        const name = file.files[0]?.name;
        label.textContent = name ? label.dataset.chosen.replace('__NAME__', name) : empty;
    });
}
