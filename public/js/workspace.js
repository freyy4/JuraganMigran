(() => {
    const prefix = `jm.ui.${document.body.dataset.uiUser || 'guest'}.`;
    function preference(key, fallback) {
        try {
            const value = localStorage.getItem(prefix + key);
            return value === null ? fallback : value === 'true';
        } catch { return fallback; }
    }
    function persist(key, value) {
        try { localStorage.setItem(prefix + key, String(value)); } catch {}
    }
    function trapFocus(event, container) {
        if (event.key !== 'Tab') return;
        const controls = [...container.querySelectorAll('a,button,summary,input,select,textarea')]
            .filter(control => control.getClientRects().length && !control.disabled && control.tabIndex !== -1);
        if (event.shiftKey && document.activeElement === controls[0]) {
            event.preventDefault();
            controls.at(-1)?.focus();
        } else if (!event.shiftKey && document.activeElement === controls.at(-1)) {
            event.preventDefault();
            controls[0]?.focus();
        }
    }
    const sidebar = document.getElementById('crm-sidebar');
    const toggle = document.getElementById('sidebar-toggle');
    const overlay = document.querySelector('.sidebar-overlay');
    const mobile = matchMedia('(max-width:1000px)');
    let collapsed = preference('sidebarCollapsed', false);
    function desktop() {
        document.body.classList.toggle('sidebar-collapsed', !mobile.matches && collapsed);
        document.querySelectorAll('[data-sidebar-collapse]').forEach(button => {
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', collapsed ? 'Tampilkan menu' : 'Sembunyikan menu');
            button.title = button.getAttribute('aria-label');
        });
        if (toggle) {
            const isOpen = document.body.classList.contains('sidebar-open');
            toggle.setAttribute('aria-expanded', String(mobile.matches ? isOpen : !collapsed));
            toggle.setAttribute('aria-label', mobile.matches ? (isOpen ? 'Tutup menu' : 'Buka menu') : (collapsed ? 'Tampilkan menu' : 'Sembunyikan menu'));
        }
    }
    function openSidebar(value, restore = false) {
        document.body.classList.toggle('sidebar-open', value);
        if (overlay) overlay.hidden = !value;
        sidebar.inert = mobile.matches && !value;
        desktop();
        if (value) sidebar.querySelector('a,button')?.focus();
        else if (restore) toggle?.focus();
    }
    if (sidebar && toggle) {
        toggle.onclick = () => {
            if (mobile.matches) openSidebar(!document.body.classList.contains('sidebar-open'));
            else {
                collapsed = !collapsed;
                persist('sidebarCollapsed', collapsed);
                desktop();
            }
        };
        document.querySelectorAll('[data-sidebar-collapse]').forEach(button => button.onclick = () => {
            collapsed = !collapsed;
            persist('sidebarCollapsed', collapsed);
            desktop();
        });
        document.querySelectorAll('[data-sidebar-close]').forEach(button => button.onclick = () => openSidebar(false, true));
        sidebar.querySelectorAll('summary').forEach(summary => summary.addEventListener('click', event => {
            if (!mobile.matches && collapsed) {
                event.preventDefault();
                collapsed = false;
                persist('sidebarCollapsed', false);
                desktop();
                summary.closest('details').open = true;
            }
        }));
        document.addEventListener('keydown', event => {
            if (!mobile.matches || !document.body.classList.contains('sidebar-open')) return;
            if (event.key === 'Escape') openSidebar(false, true);
            trapFocus(event, sidebar);
        });
        mobile.addEventListener('change', () => openSidebar(false));
        openSidebar(false);
    }
    const workspace = document.getElementById('builder-workspace');
    const library = document.getElementById('builder-library');
    const libraryToggle = document.getElementById('library-toggle');
    const backdrop = document.querySelector('[data-library-backdrop]');
    const small = matchMedia('(max-width:1279px)');
    if (workspace && library && libraryToggle) {
        let hidden = preference('libraryHidden', small.matches);
        function apply() {
            const drawer = !hidden && small.matches;
            workspace.classList.toggle('library-hidden', hidden);
            workspace.classList.toggle('library-drawer-open', drawer);
            library.hidden = hidden;
            if (backdrop) backdrop.hidden = !drawer;
            if (drawer) {
                library.setAttribute('role', 'dialog');
                library.setAttribute('aria-modal', 'true');
                library.setAttribute('aria-label', 'Blok halaman dan field formulir');
            } else {
                library.removeAttribute('role');
                library.removeAttribute('aria-modal');
            }
            libraryToggle.setAttribute('aria-expanded', String(!hidden));
            libraryToggle.setAttribute('aria-label', hidden ? 'Tampilkan blok halaman' : 'Sembunyikan blok halaman');
            libraryToggle.title = libraryToggle.getAttribute('aria-label');
            if (drawer) library.querySelector('input,button')?.focus();
        }
        function closeLibrary() {
            hidden = true;
            persist('libraryHidden', true);
            apply();
            libraryToggle.focus();
        }
        libraryToggle.onclick = () => {
            hidden = !hidden;
            persist('libraryHidden', hidden);
            apply();
        };
        document.querySelector('[data-library-close]')?.addEventListener('click', closeLibrary);
        backdrop?.addEventListener('click', closeLibrary);
        library.addEventListener('click', event => {
            if (small.matches && event.target.closest('[data-add-block],#field-library button')) closeLibrary();
        });
        document.addEventListener('keydown', event => {
            if (hidden || !small.matches) return;
            if (event.key === 'Escape') closeLibrary();
            trapFocus(event, library);
        });
        small.addEventListener('change', apply);
        document.getElementById('block-search')?.addEventListener('input', event => {
            const search = event.target.value.toLocaleLowerCase('id');
            library.querySelectorAll('[data-add-block]').forEach(button => {
                button.hidden = !button.textContent.toLocaleLowerCase('id').includes(search);
            });
        });
        apply();
    }
    document.querySelectorAll('[data-cockpit-tab]').forEach(button => {
        const parent = button.closest('[data-cockpit-tabs]');
        function select() {
            parent.querySelectorAll('[data-cockpit-tab]').forEach(tab => {
                tab.setAttribute('aria-selected', String(tab === button));
                tab.tabIndex = tab === button ? 0 : -1;
            });
            parent.querySelectorAll('[data-cockpit-panel]').forEach(panel => {
                panel.hidden = panel.dataset.cockpitPanel !== button.dataset.cockpitTab;
            });
        }
        button.tabIndex = button.getAttribute('aria-selected') === 'true' ? 0 : -1;
        button.onclick = select;
        button.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const tabs = [...parent.querySelectorAll('[data-cockpit-tab]')];
            let index = tabs.indexOf(button) + (event.key === 'ArrowRight' ? 1 : -1);
            if (event.key === 'Home') index = 0;
            if (event.key === 'End') index = tabs.length - 1;
            const target = tabs[(index + tabs.length) % tabs.length];
            target.click();
            target.focus();
        });
    });
    const integrationDetails = [...document.querySelectorAll('[data-system-detail]')];
    const expandDetails = document.querySelector('[data-system-expand]');
    if (expandDetails) {
        function updateDetailButton() {
            const allOpen = integrationDetails.length > 0 && integrationDetails.every(detail => detail.open);
            expandDetails.textContent = allOpen ? 'Tutup semua detail' : 'Buka semua detail';
            expandDetails.setAttribute('aria-expanded', String(allOpen));
        }
        expandDetails.addEventListener('click', () => {
            const open = integrationDetails.some(detail => !detail.open);
            integrationDetails.forEach(detail => detail.open = open);
            updateDetailButton();
        });
        integrationDetails.forEach(detail => detail.addEventListener('toggle', updateDetailButton));
        updateDetailButton();
    }
    const logSearch = document.getElementById('system-log-search');
    if (logSearch) {
        const rows = [...document.querySelectorAll('[data-system-log]')];
        logSearch.addEventListener('input', () => {
            const query = logSearch.value.trim().toLocaleLowerCase('id');
            let visible = 0;
            rows.forEach(row => {
                row.hidden = !row.textContent.toLocaleLowerCase('id').includes(query);
                if (!row.hidden) visible++;
            });
            document.getElementById('system-log-count').textContent = `${visible} dari ${rows.length} log ditampilkan`;
            document.getElementById('system-log-empty').hidden = rows.length === 0 || visible > 0;
        });
    }
    document.querySelectorAll('[data-finance-day]').forEach(button => {
        button.addEventListener('click', () => {
            document.querySelectorAll('[data-finance-day]').forEach(day => day.setAttribute('aria-pressed', String(day === button)));
            document.querySelector('[data-finance-day-detail]').textContent = `${button.dataset.financeDay} · Kas masuk ${button.dataset.cashIn} · Kas keluar ${button.dataset.cashOut}`;
        });
    });
    const transactionFilter = document.querySelector('[data-finance-transactions]');
    if (transactionFilter) transactionFilter.addEventListener('change', () => {
        const rows = [...document.querySelectorAll('[data-finance-transaction]')];
        rows.forEach(row => row.hidden = transactionFilter.value !== 'all' && row.dataset.financeTransaction !== transactionFilter.value);
        document.querySelector('[data-finance-transaction-empty]').hidden = rows.length === 0 || rows.some(row => !row.hidden);
    });
})();

(() => {
    const reveal = (tabs, item) => {
        if (!item || tabs.scrollWidth <= tabs.clientWidth) return;
        const left = item.getBoundingClientRect().left - tabs.getBoundingClientRect().left + tabs.scrollLeft;
        if (left < tabs.scrollLeft) tabs.scrollLeft = left;
        else if (left + item.offsetWidth > tabs.scrollLeft + tabs.clientWidth) tabs.scrollLeft = left + item.offsetWidth - tabs.clientWidth;
    };
    document.querySelectorAll('.jm-tabs').forEach(tabs => reveal(tabs, tabs.querySelector('[aria-current="page"],[aria-selected="true"],[aria-pressed="true"],.active,a.button:not(.secondary)')));
    document.addEventListener('focusin', event => {
        const tabs = event.target.closest('.jm-tabs');
        if (tabs) reveal(tabs, event.target.closest('a,button'));
    });
    document.addEventListener('keydown', event => {
        const tabs = event.target.closest('.jm-tabs');
        if (!tabs || event.altKey || event.ctrlKey || event.metaKey || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
        const items = [...tabs.children].filter(item => item.matches('a[href],button:not(:disabled)') && item.getClientRects().length);
        const index = items.indexOf(event.target);
        if (index < 0) return;
        event.preventDefault(); event.stopPropagation();
        const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length;
        items[next].focus();
        if (items[next].matches('button[role="tab"],button[aria-selected],button[aria-pressed]')) items[next].click();
    }, true);
})();
