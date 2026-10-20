(() => {
    function format(input, complete = false) {
        const value = input.value;
        if (!/^-?[\d.]*,?\d*$/.test(value) || !value) return;
        const caret = input.selectionStart ?? value.length;
        const logicalPosition = value.slice(0, caret).replace(/\./g, '').length;
        const negative = value.startsWith('-') ? '-' : '';
        const pieces = value.replace(/^-/, '').replace(/\./g, '').split(',');
        let integer = pieces[0].replace(/^0+(?=\d)/, '');
        if (!integer && pieces.length > 1) integer = '0';
        const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        const fraction = pieces[1] ?? '';
        input.value = negative + grouped + (complete && integer
            ? ',' + fraction.padEnd(Number(input.dataset.money), '0')
            : pieces.length > 1 ? ',' + fraction : '');
        if (document.activeElement === input && !complete) {
            let position = 0, characters = 0;
            while (position < input.value.length && characters < logicalPosition) {
                if (input.value[position] !== '.') characters++;
                position++;
            }
            input.setSelectionRange(position, position);
        }
    }
    document.addEventListener('paste', event => {
        const input = event.target;
        if (!input.matches('input[data-money]')) return;
        const text = event.clipboardData?.getData('text')?.trim();
        if (/^-?\d+\.\d{1,2}$/.test(text ?? '')) {
            event.preventDefault();
            input.setRangeText(text.replace('.', ','), input.selectionStart, input.selectionEnd, 'end');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
    document.addEventListener('input', event => {
        if (event.target.matches('input[data-money]')) format(event.target);
    });
    document.addEventListener('focusout', event => {
        if (event.target.matches('input[data-money]')) format(event.target, true);
    });
    document.addEventListener('keydown', event => {
        const input = event.target;
        if (!input.matches('input[data-money]') || input.selectionStart !== input.selectionEnd) return;
        const caret = input.selectionStart;
        if (event.key === 'Backspace' && input.value[caret - 1] === '.') {
            input.setSelectionRange(Math.max(0, caret - 2), caret);
        } else if (event.key === 'Delete' && input.value[caret] === '.') {
            input.setSelectionRange(caret, caret + 2);
        }
    });
})();
