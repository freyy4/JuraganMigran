document.addEventListener('DOMContentLoaded',()=>{document.querySelectorAll('.cpmi-menu').forEach(menu=>menu.addEventListener('toggle',()=>{if(!menu.open)return;const rect=menu.querySelector('summary').getBoundingClientRect();const nav=menu.querySelector('nav');nav.style.right=Math.max(12,innerWidth-rect.right)+'px';nav.style.top=Math.max(12,Math.min(rect.bottom+6,innerHeight-320))+'px';}));document.querySelectorAll('[data-cpmi-toggle]').forEach(button=>button.addEventListener('click',()=>{const target=document.getElementById(button.dataset.cpmiToggle);target.hidden=!target.hidden;button.setAttribute('aria-expanded',String(!target.hidden));}));document.querySelectorAll('[data-portal-form]').forEach(form=>{const checkbox=form.querySelector('[name=contact_verified]'),button=form.querySelector('button[type=submit]');const update=()=>button.disabled=!checkbox.checked;checkbox.addEventListener('change',update);update();});const all=document.querySelector('[data-select-all]');all?.addEventListener('change',()=>{document.querySelectorAll('[data-select-row]').forEach(box=>box.checked=all.checked);updateCount();});const updateCount=()=>{const target=document.querySelector('[data-selection-count]');if(target)target.textContent=document.querySelectorAll('[data-select-row]:checked').length+' dipilih';};document.querySelectorAll('[data-select-row]').forEach(box=>box.addEventListener('change',updateCount));document.querySelectorAll('[data-copy-contact],[data-copy-cpmi]').forEach(button=>button.addEventListener('click',async()=>{try{await navigator.clipboard.writeText(button.dataset.copyContact ?? button.dataset.copyCpmi);button.textContent='Tersalin';}catch{button.textContent='Salin manual: '+(button.dataset.copyContact ?? button.dataset.copyCpmi);}}));document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.cpmi-menu[open]').forEach(menu=>menu.open=false);});document.addEventListener('click',event=>document.querySelectorAll('.cpmi-menu[open]').forEach(menu=>{if(!menu.contains(event.target))menu.open=false;}));});
document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.cpmi-dashboard');
    if (!root) return;
    if (root.hasAttribute('data-cpmi-auto-print')) window.addEventListener('load', () => window.print(), {once: true});
    const tabs = [...root.querySelectorAll('[data-cpmi-tab]')];
    const panels = [...root.querySelectorAll('[data-cpmi-pane]')];
    const summary = root.querySelector('#cpmi-summary');
    const timeline = root.querySelector('.cd-timeline');
    const documents = root.querySelector('.cd-documents');
    const timelineHome = timeline.parentNode;
    const timelineNext = timeline.nextElementSibling;
    const documentsHome = documents.parentNode;
    const activate = (key) => {
        timelineHome.insertBefore(timeline, timelineNext);
        documentsHome.prepend(documents);
        timeline.classList.remove('cd-isolated');
        documents.classList.remove('cd-isolated');
        panels.forEach(panel => panel.hidden = panel.dataset.cpmiPane !== key);
        if (['activity', 'history', 'documents'].includes(key)) {
            summary.hidden = false;
            [...summary.querySelector('.cd-grid').children].forEach(card => card.hidden = true);
            const card = key === 'documents' ? documents : timeline;
            summary.querySelector('.cd-grid').append(card);
            card.hidden = false;
            card.classList.add('cd-isolated');
        } else {
            [...summary.querySelector('.cd-grid').children].forEach(card => card.hidden = false);
            documents.hidden = false;
            timeline.hidden = false;
        }
        tabs.forEach(tab => tab.setAttribute('aria-pressed', String(tab.dataset.cpmiTab === key)));
    };
    tabs.forEach(tab => tab.addEventListener('click', () => activate(tab.dataset.cpmiTab)));
    root.querySelectorAll('[data-cpmi-tab-link]').forEach(link => link.addEventListener('click', () => activate(link.dataset.cpmiTabLink)));
    root.querySelectorAll('[data-cpmi-dialog]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.cpmiDialog)?.showModal()));
    root.querySelectorAll('[data-cpmi-dialog-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    root.querySelectorAll('[data-cpmi-print]').forEach(button => button.addEventListener('click', () => { activate('summary'); window.print(); }));
    const search = root.querySelector('[data-cpmi-timeline-search]');
    const filter = root.querySelector('[data-cpmi-timeline-filter]');
    const filterEvents = () => {
        let count = 0;
        root.querySelectorAll('[data-event-icon]').forEach(event => {
            event.hidden = !event.textContent.toLocaleLowerCase().includes(search.value.toLocaleLowerCase()) || (filter.value && event.dataset.eventIcon !== filter.value);
            if (!event.hidden) count++;
        });
        root.querySelector('[data-cpmi-no-events]').hidden = count > 0;
    };
    search.addEventListener('input', filterEvents);
    filter.addEventListener('change', filterEvents);
    const initial = location.hash.replace('#cpmi-', '');
    if (tabs.some(tab => tab.dataset.cpmiTab === initial)) activate(initial);
});
