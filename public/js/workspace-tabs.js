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
