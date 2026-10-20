(() => {
    const rules = document.querySelector('[data-policy-rules]');
    if (!rules) return;
    document.querySelector('[data-add-policy-rule]')?.addEventListener('click', () => {
        if (rules.children.length >= 20) return;
        const row = rules.firstElementChild.cloneNode(true);
        row.querySelectorAll('input, textarea').forEach(input => { input.value = ''; });
        rules.append(row);
        renumber();
    });
    rules.addEventListener('click', event => {
        if (event.target.closest('[data-remove-policy-rule]') && rules.children.length > 1) {
            event.target.closest('[data-policy-rule]').remove();
            renumber();
        }
    });
    function renumber() {
        [...rules.children].forEach((row, index) => {
            row.querySelectorAll('[name]').forEach(input => {
                input.name = input.name.replace(/rules\[\d+\]/, `rules[${index}]`);
            });
        });
    }
})();
