(() => {
    const key = 'jm-color-theme';
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    let preference;
    try { const saved = localStorage.getItem(key); if (['light', 'dark'].includes(saved)) preference = saved; } catch {}
    const syncFrame = (frame, theme) => {
        try {
            const root = frame.contentDocument?.documentElement;
            if (!root) return;
            root.dataset.theme = theme;
            root.style.colorScheme = theme;
        } catch {}
    };
    const apply = theme => {
        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
        document.querySelectorAll('iframe').forEach(frame => syncFrame(frame, theme));
        document.querySelectorAll('[data-theme-toggle]').forEach(button => {
            const dark = theme === 'dark';
            button.setAttribute('aria-pressed', String(dark));
            button.setAttribute('aria-label', dark ? 'Aktifkan mode terang' : 'Aktifkan mode gelap');
            button.title = button.getAttribute('aria-label');
            const label = button.querySelector('[data-theme-label]');
            if (label) label.textContent = dark ? 'Mode terang' : 'Mode gelap';
        });
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0f172a' : '#245bce');
    };
    apply(preference || (system.matches ? 'dark' : 'light'));
    document.addEventListener('load', event => {
        if (event.target instanceof HTMLIFrameElement) syncFrame(event.target, document.documentElement.dataset.theme);
    }, true);
    const initialize = () => {
        apply(document.documentElement.dataset.theme);
        document.addEventListener('click', event => {
            if (!event.target.closest('[data-theme-toggle]')) return;
            preference = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem(key, preference); } catch {}
            apply(preference);
        });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize); else initialize();
    system.addEventListener('change', event => { if (!preference) apply(event.matches ? 'dark' : 'light'); });
    window.addEventListener('storage', event => {
        if (event.key !== key) return;
        preference = ['light', 'dark'].includes(event.newValue) ? event.newValue : undefined;
        apply(preference || (system.matches ? 'dark' : 'light'));
    });
})();
