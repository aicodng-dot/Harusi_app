const passwordToggle = document.querySelector('[data-password-toggle]');
passwordToggle?.addEventListener('click', () => {
    const password = document.getElementById(passwordToggle.getAttribute('aria-controls'));
    const visible = password.type === 'password';
    password.type = visible ? 'text' : 'password';
    passwordToggle.setAttribute('aria-pressed', String(visible));
    passwordToggle.setAttribute('aria-label', visible ? passwordToggle.dataset.hideLabel : passwordToggle.dataset.showLabel);
});

document.querySelectorAll('[data-availability]').forEach((link) => {
    link.addEventListener('click', (event) => {
        event.preventDefault();
        document.querySelectorAll('.login-availability').forEach((notice) => notice.classList.remove('is-visible'));
        const notice = document.getElementById(link.dataset.availability);
        notice.classList.add('is-visible');
        notice.focus();
    });
});
