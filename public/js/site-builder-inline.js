(() => {
    window.JmSiteInline = function ({canvas, editable, getBlock, onSelect, onCommit, onImage, editor = canvas.closest('#site-editor'), limits = {title:500,label:255,text:10000}, accept = () => true}) {
        let session = null;
        const documents = new WeakSet();
        const valueAt = (block, path) => {
            if (['title', 'text', 'label'].includes(path)) return block[path] ?? '';
            const match = /^items\.(\d+)\.(title|text)$/.exec(path);
            return match && block.items?.[Number(match[1])] ? block.items[Number(match[1])][match[2]] ?? '' : null;
        };
        function finish(commit = true) {
            if (!session) return;
            const current = session; session = null;
            const limit = typeof limits === 'function' ? limits(current.index,current.path) : current.path.endsWith('title') ? limits.title : current.path === 'label' ? limits.label : limits.text;
            const value = (current.node.innerText ?? current.node.textContent).replaceAll('\u00a0', ' ').slice(0, limit);
            if(commit&&!accept(current.index,current.path,value))commit=false;
            for (const [attribute, old] of Object.entries(current.attributes)) {
                if (old === null) current.node.removeAttribute(attribute); else current.node.setAttribute(attribute, old);
            }
            delete current.node.dataset.studioEditing;
            current.node.textContent = commit ? value : current.displayContent;
            current.node.blur();
            if (commit && value !== current.original) onCommit(current.index, current.path, value);
        }
        function begin(node) {
            const index = Number(node.closest('[data-site-block]').dataset.siteBlock), path = node.dataset.siteField, block = getBlock(index);
            if (!block) return;
            const value = valueAt(block, path);
            if (value === null) return;
            if (session?.node === node) return;
            finish(true); onSelect(index, path);
            session = {node, index, path, original: String(value), displayContent: node.textContent, attributes: Object.fromEntries(['contenteditable', 'role', 'aria-label', 'spellcheck', 'tabindex'].map(key => [key, node.getAttribute(key)]))};
            node.contentEditable = 'plaintext-only'; node.setAttribute('role', 'textbox'); node.setAttribute('aria-label', path.endsWith('title') ? 'Edit judul' : 'Edit teks'); node.spellcheck = true; node.tabIndex = 0;
            node.dataset.studioEditing = 'true'; node.textContent = String(value); node.focus();
            const selection = node.ownerDocument.getSelection(), range = node.ownerDocument.createRange();
            range.selectNodeContents(node); selection.removeAllRanges(); selection.addRange(range);
        }
        return {
            finish,
            isEditing: () => !!session,
            isDirty: () => !!session && session.node.innerText !== session.original,
            bindDocument(doc) {
                if (!editable || documents.has(doc)) return;
                documents.add(doc);
                const style = doc.createElement('style');
                style.textContent = '[data-site-field]{cursor:text}[data-site-field]:empty::before{content:attr(data-placeholder);opacity:.45;font-size:16px;font-weight:400}[data-site-image]{cursor:pointer}[data-studio-editing=true]{outline:2px solid #0d99ff!important;outline-offset:3px;background:#0d99ff0c;min-width:24px;white-space:pre-wrap}'; doc.head.append(style);
                doc.addEventListener('dblclick', event => {
                    if (editor.dataset.previewPending === 'true') return;
                    const image = event.target.closest('[data-site-image]'), text = event.target.closest('[data-site-field]');
                    if (image) { event.preventDefault(); finish(true); onImage(Number(image.closest('[data-site-block]').dataset.siteBlock)); }
                    else if (text && session?.node !== text) { event.preventDefault(); begin(text); }
                });
                doc.addEventListener('focusout', event => { if (session?.node === event.target) finish(true); });
                doc.addEventListener('keydown', event => {
                    if (!session || !session.node.contains(event.target)) return;
                    if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); finish(false); }
                    else if (event.key === 'Enter' && (session.path.endsWith('title') || session.path === 'label' || event.ctrlKey || event.metaKey)) { event.preventDefault(); event.stopImmediatePropagation(); finish(true); }
                }, true);
                doc.addEventListener('paste', event => {
                    if (!session || !session.node.contains(event.target)) return;
                    event.preventDefault();
                    const text = event.clipboardData.getData('text/plain').slice(0, 10000), selection = doc.getSelection();
                    if (!selection.rangeCount) return;
                    const range = selection.getRangeAt(0), node = doc.createTextNode(text);
                    range.deleteContents(); range.insertNode(node); range.setStartAfter(node); range.collapse(true); selection.removeAllRanges(); selection.addRange(range);
                });
                doc.addEventListener('drop', event => { if (session?.node.contains(event.target)) event.preventDefault(); });
            },
        };
    };
})();
