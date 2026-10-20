(() => {
    const dialog = document.getElementById('logout-confirmation');
    if (!dialog) return;
    const confirm = dialog.querySelector('[data-logout-confirm]');
    let pendingForm = null;
    let trigger = null;
    document.querySelectorAll('form[data-confirm-logout]').forEach(form => {
        form.addEventListener('submit', event => {
            event.preventDefault();
            if (dialog.open) return;
            if (typeof dialog.showModal !== 'function') {
                if (window.confirm('Apakah Anda yakin untuk Logout?')) HTMLFormElement.prototype.submit.call(form);
                return;
            }
            pendingForm = form;
            trigger = event.submitter || form.querySelector('button');
            confirm.disabled = false;
            dialog.showModal();
            dialog.querySelector('[data-logout-cancel]').focus();
        });
    });
    dialog.querySelector('[data-logout-cancel]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => {
        pendingForm = null;
        trigger?.focus();
    });
    dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
    confirm.addEventListener('click', () => {
        if (!pendingForm || confirm.disabled) return;
        confirm.disabled = true;
        confirm.textContent = 'Keluar…';
        HTMLFormElement.prototype.submit.call(pendingForm);
    });
    window.addEventListener('pageshow', () => {
        confirm.disabled = false;
        confirm.textContent = 'Ya, Logout';
        if (dialog.open) dialog.close();
    });
})();
