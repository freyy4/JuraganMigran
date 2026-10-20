(() => {
    const script = document.currentScript;
    if ('serviceWorker' in navigator && window.isSecureContext) {
        navigator.serviceWorker.register(script.dataset.worker, { scope: '/portal' }).catch(() => {});
    }
    let installPrompt;
    const installed = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    const hideInstall = () => document.querySelectorAll('.portal-install').forEach(el => el.hidden = true);
    if (installed()) hideInstall();
    window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); installPrompt = event; });
    window.addEventListener('appinstalled', hideInstall);
    document.querySelectorAll('[data-install]').forEach(button => button.addEventListener('click', async () => {
        if (installPrompt) {
            const prompt = installPrompt; installPrompt = null;
            await prompt.prompt();
            const choice = await prompt.userChoice;
            if (choice.outcome === 'accepted') hideInstall();
        } else {
            const help = button.closest('.portal-install').querySelector('[data-install-help]');
            help.hidden = false;
            help.textContent = /iphone|ipad|ipod/i.test(navigator.userAgent) ? 'Di Safari, ketuk Bagikan lalu Tambahkan ke Layar Utama.' : 'Buka menu browser, lalu pilih Instal aplikasi atau Tambahkan ke layar utama jika tersedia.';
        }
    }));
    const network = () => document.querySelectorAll('.portal-network').forEach(el => el.hidden = navigator.onLine);
    window.addEventListener('online', network); window.addEventListener('offline', network); network();
    document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        const show = input.type === 'password'; input.type = show ? 'text' : 'password';
        button.textContent = show ? 'Sembunyikan' : 'Tampilkan'; button.setAttribute('aria-pressed', String(show));
    }));
    document.querySelectorAll('[data-document-filter]').forEach(button => button.addEventListener('click', () => {
        document.querySelectorAll('[data-document-filter]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        let visible = 0;
        document.querySelectorAll('[data-document-status]').forEach(card => {
            const match = button.dataset.documentFilter === 'all' || (button.dataset.documentFilter === 'action' ? ['missing','rejected','expired'].includes(card.dataset.documentStatus) : card.dataset.documentStatus === 'verified');
            card.hidden = !match; if (match) visible++;
        });
        const empty = document.querySelector('[data-document-empty]'); if (empty) empty.hidden = visible > 0;
    }));
    document.querySelectorAll('[data-refresh]').forEach(button => button.addEventListener('click', () => { button.disabled = true; button.textContent = 'Memperbarui…'; window.location.reload(); }));
})();
