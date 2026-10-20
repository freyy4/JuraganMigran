(() => {
    const script = document.currentScript;
    const menu = document.querySelector('[data-site-menu]');
    menu?.addEventListener('click', () => {
        const expanded = menu.getAttribute('aria-expanded') !== 'true';
        menu.setAttribute('aria-expanded', String(expanded)); document.getElementById('site-navigation').classList.toggle('is-open', expanded);
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && menu) { menu.setAttribute('aria-expanded','false'); document.getElementById('site-navigation').classList.remove('is-open'); } });
    const send = data => { const configuration = document.getElementById('jm-tracking-config'); if (configuration) { try { if (!JSON.parse(configuration.textContent).consent?.analytics) return; } catch { return; } } return fetch(script.dataset.events, { method:'POST', credentials:'same-origin', keepalive:true, headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content}, body:JSON.stringify(data) }).catch(() => {}); };
    document.addEventListener('click', event => { const link=event.target.closest('[data-site-event]'); if (link) send({event:link.dataset.siteEvent,vacancy_slug:link.closest('[data-vacancy]')?.dataset.vacancy}); });
    if ('IntersectionObserver' in window) {
        const observer=new IntersectionObserver(entries=>entries.forEach(entry=>{if(entry.isIntersecting){send({event:'vacancy_impression',vacancy_slug:entry.target.dataset.vacancy});observer.unobserve(entry.target);}}),{threshold:.5});
        document.querySelectorAll('.site-job-card').forEach(card=>observer.observe(card));
    }
    const filters=document.querySelector('.site-filter-panel');
    if(filters){
        const mobile=matchMedia('(max-width:767px)');
        if(!mobile.matches)filters.open=true;
        filters.addEventListener('toggle',()=>{document.body.style.overflow=mobile.matches&&filters.open?'hidden':'';});
        document.addEventListener('keydown',event=>{if(event.key==='Escape'&&mobile.matches){filters.open=false;filters.querySelector('summary').focus();}});
        mobile.addEventListener('change',()=>{filters.open=!mobile.matches;document.body.style.overflow='';});
    }
    const metrics={};
    if ('PerformanceObserver' in window) {
        for (const type of ['largest-contentful-paint','layout-shift','event']) {
            try {new PerformanceObserver(list=>{for(const entry of list.getEntries()){if(type==='largest-contentful-paint')metrics.lcp=entry.startTime;else if(type==='layout-shift'&&!entry.hadRecentInput)metrics.cls=(metrics.cls||0)+entry.value;else if(type==='event')metrics.inp=Math.max(metrics.inp||0,entry.duration);}}).observe(type==='event'?{type,buffered:true,durationThreshold:40}:{type,buffered:true});}catch{}
        }
        const nav=performance.getEntriesByType('navigation')[0];if(nav)metrics.ttfb=nav.responseStart;
        let sent=false;document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden'&&!sent&&Object.keys(metrics).length){sent=true;send({event:'web_vitals',metrics});}});
    }
})();
