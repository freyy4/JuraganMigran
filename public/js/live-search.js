(() => {
    const main = document.getElementById('main-content');
    if (!main || !window.fetch || !window.AbortController) return;
    const form = [...main.querySelectorAll('form')].find(item => item.method.toLowerCase() === 'get' && item.querySelector('input[name="search"],input[name="q"],input[name="keyword"]'));
    if (!form || form.parentElement !== main) return;
    const search = form.querySelector('input[name="search"],input[name="q"],input[name="keyword"]');
    const results = document.createElement('div');
    results.dataset.liveResults = '';
    while (form.nextSibling) results.append(form.nextSibling);
    form.after(results);
    const status = document.createElement('p');
    status.className = 'live-search-status muted';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.textContent = 'Hasil diperbarui otomatis saat Anda mengetik.';
    form.after(status);
    search.setAttribute('aria-controls', 'live-search-results');
    results.id = 'live-search-results';
    let timer, controller, generation = 0, composing = false;
    const stop = () => { clearTimeout(timer); controller?.abort(); generation++; };
    const queryUrl = () => {
        const url = new URL(form.action || location.href, location.href);
        const current = new URL(location.href);
        current.searchParams.forEach((value, key) => {
            if (!url.searchParams.has(key)) url.searchParams.append(key, value);
        });
        [...url.searchParams.keys()].filter(key => key === 'page' || key.endsWith('_page')).forEach(key => url.searchParams.delete(key));
        [...form.elements].filter(control => control.name).forEach(control => url.searchParams.delete(control.name));
        new FormData(form).forEach((value, key) => { if (typeof value === 'string' && value !== '') url.searchParams.append(key, value); });
        return url;
    };
    const load = async (url, synchronize = false) => {
        stop();
        const requestId = generation;
        controller = new AbortController();
        results.setAttribute('aria-busy', 'true');
        results.inert = true;
        status.textContent = 'Mencari…';
        try {
            const response = await fetch(url, { signal: controller.signal, credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' });
            if (requestId !== generation) return;
            if (response.redirected && new URL(response.url).pathname !== url.pathname) { location.assign(response.url); return; }
            if (!response.ok) throw new Error('response');
            const html = new DOMParser().parseFromString(await response.text(), 'text/html');
            if (requestId !== generation) return;
            const nextMain = html.getElementById('main-content');
            const nextForm = [...(nextMain?.querySelectorAll('form') || [])].find(item => item.method.toLowerCase() === 'get' && item.querySelector(`input[name="${search.name}"]`));
            if (!nextForm || nextForm.parentElement !== nextMain) throw new Error('markup');
            const fragment = document.createDocumentFragment();
            let sibling = nextForm.nextSibling;
            while (sibling) { const next = sibling.nextSibling; fragment.append(sibling); sibling = next; }
            fragment.querySelectorAll('script').forEach(script => script.remove());
            results.replaceChildren(fragment);
            if (synchronize) {
                [...form.elements].forEach(control => {
                    const other = [...nextForm.elements].find(item => item.name === control.name && item.type === control.type);
                    if (!other || !control.name) return;
                    if (['checkbox', 'radio'].includes(control.type)) control.checked = other.checked;
                    else control.value = other.value;
                });
            }
            history.replaceState(null, '', url);
            status.textContent = 'Hasil pencarian diperbarui.';
            document.dispatchEvent(new CustomEvent('crm:results-updated', { detail: { container: results } }));
        } catch (error) {
            if (error.name !== 'AbortError' && requestId === generation) status.textContent = 'Pencarian belum berhasil. Coba lagi atau tekan tombol Cari/Terapkan.';
        } finally {
            if (requestId === generation) { results.removeAttribute('aria-busy'); results.inert = false; }
        }
    };
    const schedule = () => {
        stop();
        // A new keystroke invalidates a request before the debounce completes.
        results.removeAttribute('aria-busy'); results.inert = false;
        if (!composing) timer = setTimeout(() => load(queryUrl()), 350);
    };
    search.addEventListener('compositionstart', () => { composing = true; stop(); });
    search.addEventListener('compositionend', () => { composing = false; schedule(); });
    search.addEventListener('input', schedule);
    form.addEventListener('change', event => { if (event.target !== search) load(queryUrl()); });
    form.addEventListener('submit', event => { event.preventDefault(); if (form.reportValidity()) load(queryUrl()); });
    main.addEventListener('click', event => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) return;
        const pagination = results.contains(link) && link.closest('.pagination');
        const reset = form.contains(link) && link.textContent.trim().toLowerCase() === 'reset';
        if (!pagination && !reset) return;
        const url = new URL(link.href);
        if (url.origin !== location.origin || url.pathname !== location.pathname) return;
        event.preventDefault(); load(url, true);
    });
    window.addEventListener('popstate', () => load(new URL(location.href), true));
})();
