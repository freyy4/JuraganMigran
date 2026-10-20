(() => {
    const scope = document.querySelector('[data-config-scope]');
    const references = document.querySelector('[data-config-reference]');
    if (scope && references) {
        const update = () => {
            [...references.options].forEach(option => {
                const visible = !option.dataset.scope || option.dataset.scope === scope.value;
                option.hidden = !visible;
                option.disabled = !visible;
                if (!visible && option.selected) references.value = '';
            });
            references.required = scope.value !== 'GLOBAL';
            references.disabled = scope.value === 'GLOBAL';
            references.closest('div').hidden = scope.value === 'GLOBAL';
            const search = document.querySelector('[data-scope-search]');
            if (search) search.closest('div').hidden = scope.value === 'GLOBAL';
        };
        scope.addEventListener('change', update);
        update();
    }
    const scopeSearch = document.querySelector('[data-scope-search]');
    if (scopeSearch && scope && references) {
        let timer, pending;
        scopeSearch.addEventListener('input', () => {
            clearTimeout(timer);
            pending?.abort();
            const requestedScope = scope.value;
            if (requestedScope === 'GLOBAL') {
                scopeSearch.setCustomValidity('');
                return;
            }
            const request = new AbortController();
            pending = request;
            timer = setTimeout(async () => {
                const url = new URL(scopeSearch.dataset.url, location.origin);
                url.searchParams.set('scope', requestedScope); url.searchParams.set('q', scopeSearch.value);
                if (references.value) url.searchParams.append('selected[]', references.value);
                try {
                    const response = await fetch(url, {signal: request.signal, headers: {'Accept':'application/json'}});
                    if (!response.ok) throw new Error('Acuan tidak dapat dimuat. Coba kembali.');
                    const data = await response.json();
                    if (request.signal.aborted || scope.value !== requestedScope) return;
                    const selected = references.selectedOptions[0]?.cloneNode(true);
                    references.replaceChildren(new Option('Pilih acuan', ''));
                    for (const item of data.items) {
                        const option = new Option(item.name, String(item.id)); option.dataset.scope = requestedScope; references.append(option);
                    }
                    if (selected?.value) {
                        if (![...references.options].some(option => option.value === selected.value)) references.append(selected);
                        references.value = selected.value;
                    }
                    scopeSearch.setCustomValidity('');
                } catch (error) { if (error.name !== 'AbortError' && !request.signal.aborted && scope.value === requestedScope) { scopeSearch.setCustomValidity(error.message); scopeSearch.reportValidity(); } }
            }, 300);
        });
        scope.addEventListener('change', () => { scopeSearch.value = ''; scopeSearch.dispatchEvent(new Event('input')); });
    }
    const form = document.querySelector('[data-health-form]');
    if (form) {
        const update = () => {
            let count = 0, total = 0;
            const mainValues = {};
            form.querySelectorAll('[data-health-question]').forEach(question => {
                const controls=[...question.querySelectorAll('[data-health-main] input,[data-health-main] select')];
                mainValues[question.dataset.questionCode]=controls.filter(control=>!['radio','checkbox'].includes(control.type)||control.checked).map(control=>control.value);
            });
            form.querySelectorAll('[data-health-question]').forEach(question => {
                const dependency=JSON.parse(question.dataset.dependency || 'null');
                const active=!dependency || (mainValues[dependency.question_code] || []).some(value=>dependency.values.includes(value));
                question.hidden=!active; question.disabled=!active;
                if (!active) return;
                total++;
                const controls = [...question.querySelectorAll('[data-health-main] input,[data-health-main] select')];
                const values = controls.filter(control => !['radio', 'checkbox'].includes(control.type) || control.checked).map(control => control.value).filter(value => value !== '');
                if (values.length) count++;
                const detail = question.querySelector('[data-health-detail]');
                if (detail) {
                    const visible = values.some(value => JSON.parse(detail.dataset.triggers).includes(value));
                    detail.hidden = !visible;
                    detail.querySelectorAll('textarea,input,select').forEach(control => control.disabled = !visible);
                }
            });
            const counter = document.querySelector('[data-health-count]');
            if (counter) counter.textContent = count;
            const totalCounter=document.querySelector('[data-health-total]');
            if (totalCounter) totalCounter.textContent=total;
        };
        form.addEventListener('change', update);
        form.addEventListener('input', update);
        if (form.querySelector('[name$="[value]"]')) update();
    }
    const rows = document.querySelector('[data-requirement-rows]');
    const add = document.querySelector('[data-add-rule]');
    if (rows && add) {
        let index = Math.max(...[...rows.querySelectorAll('[name]')].map(node => Number(node.name.match(/rules\[(\d+)\]/)?.[1] || 0))) + 1;
        add.addEventListener('click', () => {
            const row = rows.querySelector('[data-requirement-row]').cloneNode(true);
            row.querySelectorAll('[name]').forEach(control => {
                control.name = control.name.replace(/rules\[\d+\]/, `rules[${index}]`);
                if (control.type === 'checkbox') control.checked = false;
                else if (control.type === 'number') control.value = '0';
                else if (control.type !== 'hidden') control.value = control.tagName === 'SELECT' ? control.options[0].value : '';
            });
            row.querySelectorAll('[data-condition-row]').forEach((condition, number) => { if (number) condition.remove(); });
            row.querySelectorAll('.master-condition-entity').forEach(element=>element.remove());
            row.querySelector('[data-condition-builder]')?.removeAttribute('data-next-index');
            rows.append(row);
            document.dispatchEvent(new Event('master:rows-added'));
            index++;
        });
        rows.addEventListener('click', event => {
            if (!event.target.closest('[data-remove-rule]')) return;
            if (rows.children.length > 1) event.target.closest('[data-requirement-row]').remove();
        });
    }
    document.addEventListener('click', event => {
        const button=event.target.closest('[data-select-eligible]');
        if (!button) return;
        button.closest('form').querySelectorAll('[data-eligible]:not(:disabled)').forEach(box=>box.checked=true);
    });
})();
