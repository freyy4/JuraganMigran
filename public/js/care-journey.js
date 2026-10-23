document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.care-root');
    if (!root) return;
    const open = id => document.getElementById(id)?.showModal();
    root.querySelectorAll('[data-care-dialog]').forEach(button => button.addEventListener('click', () => open(button.dataset.careDialog)));
    root.querySelectorAll('[data-care-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    root.querySelectorAll('[data-care-touch]').forEach(button => button.addEventListener('click', () => {
        document.querySelector('#care-touch-dialog [name=stage]').value = button.dataset.careTouch;
        open('care-touch-dialog');
    }));
    const support = (stage, type) => {
        const dialog = document.getElementById('care-support-dialog');
        if (!dialog) return;
        if (stage) dialog.querySelector('[name=stage]').value = stage;
        if (type) dialog.querySelector('[name=type]').value = type;
        open('care-support-dialog');
    };
    root.querySelectorAll('[data-care-support-stage]').forEach(button => button.addEventListener('click', () => support(button.dataset.careSupportStage)));
    root.querySelectorAll('[data-care-drag]').forEach(handle => {
        handle.addEventListener('click', () => support(null, handle.dataset.careDrag));
        handle.addEventListener('dragstart', event => {
            event.dataTransfer.setData('application/x-jm-support', handle.dataset.careDrag);
            event.dataTransfer.effectAllowed = 'copy';
        });
    });
    root.querySelectorAll('[data-care-stage]').forEach(stage => {
        stage.addEventListener('dragover', event => {
            if (!event.dataTransfer.types.includes('application/x-jm-support')) return;
            event.preventDefault();
            stage.classList.add('is-drop-target');
            event.dataTransfer.dropEffect = 'copy';
        });
        stage.addEventListener('dragleave', event => { if (!stage.contains(event.relatedTarget)) stage.classList.remove('is-drop-target'); });
        stage.addEventListener('drop', event => {
            event.preventDefault();
            stage.classList.remove('is-drop-target');
            const type = event.dataTransfer.getData('application/x-jm-support');
            if (root.querySelector(`[data-care-need="${CSS.escape(type)}"]`)) support(stage.dataset.careStage, type);
        });
    });
    root.querySelector('[data-care-need-search]')?.addEventListener('input', event => root.querySelectorAll('[data-care-need]').forEach(need => need.hidden = !need.textContent.toLocaleLowerCase().includes(event.target.value.toLocaleLowerCase())));
    root.querySelectorAll('[data-care-view]').forEach(button => button.addEventListener('click', () => {
        const board = button.dataset.careView === 'board';
        root.querySelector('[data-care-board]').hidden = !board;
        root.querySelector('[data-care-ledger]').hidden = board;
        root.querySelector('[data-care-ledger]').classList.toggle('is-list', button.dataset.careView === 'list');
        root.querySelectorAll('[data-care-view]').forEach(item => item.setAttribute('aria-pressed', String(item === button)));
    }));
    root.querySelectorAll('[data-care-engine]').forEach(link => link.addEventListener('click', () => root.querySelectorAll('[data-care-engine]').forEach(item => item.setAttribute('aria-current', String(item === link)))));
    root.querySelectorAll('form').forEach(form => form.addEventListener('submit', event => {
        if (form.dataset.submitting) { event.preventDefault(); return; }
        form.dataset.submitting = 'true';
        // Keep the clicked outcome successful in the native form submission.
        form.setAttribute('aria-busy', 'true');
    }));
    window.addEventListener('pageshow', () => root.querySelectorAll('form').forEach(form => { delete form.dataset.submitting; form.removeAttribute('aria-busy'); }));
});

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-care-template-form]');
    if (!form) return;
    const container = form.querySelector('[data-care-template-stages]');
    let next = container.children.length;
    form.querySelector('[data-care-add-stage]').addEventListener('click', () => {
        const row = document.createElement('div');
        row.className = 'care-template-row';
        for (const [key, text, limit] of [['key', 'Kode tahap', 60], ['label', 'Nama tahap', 100]]) {
            const label = document.createElement('label');
            label.textContent = text;
            const input = document.createElement('input');
            input.name = `stages[${next}][${key}]`;
            input.required = true;
            input.maxLength = limit;
            label.append(input);
            row.append(label);
        }
        const remove = document.createElement('button');
        remove.type = 'button'; remove.className = 'care-button'; remove.dataset.careRemoveStage = ''; remove.textContent = 'Hapus';
        row.append(remove); container.append(row); next++;
        row.querySelector('input').focus();
    });
    container.addEventListener('click', event => { const button = event.target.closest('[data-care-remove-stage]'); if (button) button.closest('.care-template-row').remove(); });
});
