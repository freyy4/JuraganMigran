(() => {
    window.JmSiteDrag = function ({editor, canvas, editable, names, getCount, onAdd, onMove, onSelect, resetTool}) {
        const viewport = canvas.closest('.site-editor-canvas');
        const live = document.createElement('div');
        live.className = 'sr-only';
        live.setAttribute('role', 'status');
        editor.append(live);
        let pointer = null, nativePayload = null, target = null, lastPoint = null, scrollFrame = null, ghost = null, suppressClickUntil = 0;
        const documents = new WeakSet();
        const valid = payload => editable && payload && (payload.kind === 'add'
            ? Object.prototype.hasOwnProperty.call(names, payload.type) && getCount() < 60
            : payload.kind === 'move' && Number.isInteger(payload.from) && payload.from >= 0 && payload.from < getCount());
        const label = payload => payload.kind === 'add' ? names[payload.type] : 'Blok';
        const parentPoint = event => {
            if (event.view === window || event.target === document || event.target.ownerDocument === document) return {x: event.clientX, y: event.clientY};
            const rect = canvas.getBoundingClientRect(), scale = rect.width / canvas.offsetWidth;
            return {x: rect.left + event.clientX * scale, y: rect.top + event.clientY * scale};
        };
        const frameDocument = () => { try { return canvas.contentDocument; } catch { return null; } };
        function clearMarker() {
            editor.querySelectorAll('.studio-drop-before,.studio-drop-after').forEach(row => row.classList.remove('studio-drop-before', 'studio-drop-after'));
            frameDocument()?.getElementById('studio-drop-marker')?.remove();
        }
        function showFrameMarker(doc, top, text) {
            let marker = doc.getElementById('studio-drop-marker');
            if (!marker) {
                marker = doc.createElement('div');
                marker.id = 'studio-drop-marker';
                marker.style.cssText = 'position:fixed;left:0;right:0;height:4px;background:#0d99ff;z-index:2147483647;pointer-events:none;box-shadow:0 0 0 1px #fff;';
                const caption = doc.createElement('span');
                caption.style.cssText = 'position:absolute;left:12px;bottom:4px;background:#0d99ff;color:white;padding:5px 10px;border-radius:5px 5px 0 0;font:600 16px/1.4 sans-serif;white-space:nowrap;';
                marker.append(caption);
                doc.documentElement.append(marker);
            }
            marker.style.top = `${Math.max(4, top)}px`;
            marker.firstChild.textContent = text;
        }
        function locate(point, payload) {
            clearMarker();
            target = null;
            const element = document.elementFromPoint(point.x, point.y);
            const row = element?.closest('#site-block-list li');
            if (row) {
                const index = [...row.parentElement.children].indexOf(row), after = point.y > row.getBoundingClientRect().top + row.offsetHeight / 2;
                row.classList.add(after ? 'studio-drop-after' : 'studio-drop-before');
                target = {slot: index + Number(after), surface: 'layers'};
                return;
            }
            const list = element?.closest('[data-studio-left-pane=layers]');
            if (list) {
                target = {slot: getCount(), surface: 'layers'};
                document.querySelector('#site-block-list li:last-child')?.classList.add('studio-drop-after');
                return;
            }
            if (element?.closest('.site-editor-pages,.site-editor-properties')) return;
            const bounds = viewport.getBoundingClientRect();
            if (point.x < bounds.left || point.x > bounds.right || point.y < bounds.top || point.y > bounds.bottom || element?.closest('.studio-floating-toolbar,dialog')) return;
            if (editor.dataset.previewPending === 'true') return;
            const doc = frameDocument();
            if (!doc) return;
            const blocks = [...doc.querySelectorAll('[data-site-block]')];
            if (blocks.length !== getCount()) return;
            const rect = canvas.getBoundingClientRect(), scale = rect.width / canvas.offsetWidth;
            const y = (point.y - rect.top) / scale;
            const index = blocks.findIndex(block => y < block.getBoundingClientRect().top + block.getBoundingClientRect().height / 2);
            const slot = index < 0 ? blocks.length : index;
            const top = blocks[slot]?.getBoundingClientRect().top ?? blocks.at(-1)?.getBoundingClientRect().bottom ?? doc.querySelector('main')?.getBoundingClientRect().top ?? 40;
            showFrameMarker(doc, top, `${payload.kind === 'add' ? '+ ' + label(payload) : 'Pindahkan blok'} di sini`);
            target = {slot, surface: 'canvas'};
        }
        function autoScroll() {
            if (!lastPoint || (!pointer?.active && !nativePayload)) return;
            const bounds = viewport.getBoundingClientRect();
            if (lastPoint.x >= bounds.left && lastPoint.x <= bounds.right && lastPoint.y >= bounds.top && lastPoint.y <= bounds.bottom) {
                const delta = (value, start, end) => value < start + 52 ? -16 : value > end - 52 ? 16 : 0;
                const dx = delta(lastPoint.x, bounds.left, bounds.right), dy = delta(lastPoint.y, bounds.top, bounds.bottom);
                if (dx || dy) { viewport.scrollBy(dx, dy); locate(lastPoint, pointer?.payload ?? nativePayload); }
            }
            scrollFrame = requestAnimationFrame(autoScroll);
        }
        function begin(payload) {
            resetTool();
            editor.classList.add('studio-dragging');
            ghost = document.createElement('div');
            ghost.className = 'studio-drag-ghost';
            ghost.textContent = payload.kind === 'add' ? `+ ${label(payload)}` : '⋮⋮ Pindahkan blok';
            document.body.append(ghost);
            scrollFrame = requestAnimationFrame(autoScroll);
        }
        function finish(commit = false) {
            const payload = pointer?.payload ?? nativePayload, destination = target;
            const wasActive = pointer?.active || nativePayload;
            if (wasActive) suppressClickUntil = Date.now() + 120;
            const captured = pointer;
            pointer = null;
            if (captured?.source.hasPointerCapture?.(captured.id)) {
                captured.source.releasePointerCapture(captured.id);
            }
            nativePayload = null; target = null; lastPoint = null;
            cancelAnimationFrame(scrollFrame); ghost?.remove(); ghost = null; clearMarker(); editor.classList.remove('studio-dragging');
            if (!commit || !valid(payload) || !destination) return;
            if (payload.kind === 'add') {
                onAdd(payload.type, destination.slot);
                live.textContent = `${label(payload)} ditambahkan pada urutan ${destination.slot + 1}.`;
            } else {
                const to = destination.slot > payload.from ? destination.slot - 1 : destination.slot;
                if (to === payload.from) return;
                onMove(payload.from, to);
                live.textContent = `Blok dipindahkan ke urutan ${to + 1}.`;
            }
        }
        function bindSource(source, getPayload) {
            source.draggable = false;
            source.dataset.studioDragSource = 'true';
            source.style.touchAction = 'none';
            source.addEventListener('pointerdown', event => {
                if (event.button !== 0 || !event.isPrimary) return;
                if (pointer || nativePayload) finish();
                const payload = getPayload();
                if (!valid(payload)) return;
                if (source.ownerDocument !== document && editor.dataset.previewPending === 'true') return;
                const point = parentPoint(event);
                pointer = {id: event.pointerId, source, payload, start: point, active: false};
                source.setPointerCapture(event.pointerId);
            });
            source.addEventListener('click', event => {
                if (Date.now() < suppressClickUntil) { event.preventDefault(); event.stopImmediatePropagation(); }
            }, true);
            source.addEventListener('dragstart', event => {
                const payload = getPayload();
                if (!valid(payload)) { event.preventDefault(); return; }
                nativePayload = payload;
                event.dataTransfer.effectAllowed = payload.kind === 'add' ? 'copy' : 'move';
                event.dataTransfer.setData('application/x-jm-site', JSON.stringify(payload));
                begin(payload);
            });
            source.addEventListener('dragend', () => finish());
        }
        function movePointer(event) {
            if (!pointer || pointer.id !== event.pointerId) return;
            const point = parentPoint(event);
            if (!pointer.active && Math.hypot(point.x - pointer.start.x, point.y - pointer.start.y) < 6) return;
            if (!pointer.active) { pointer.active = true; begin(pointer.payload); }
            event.preventDefault(); lastPoint = point;
            ghost.style.left = `${point.x + 16}px`; ghost.style.top = `${point.y + 14}px`;
            locate(point, pointer.payload);
        }
        function endPointer(event) {
            if (!pointer || pointer.id !== event.pointerId) return;
            const active = pointer.active;
            if (active) { event.preventDefault(); locate(parentPoint(event), pointer.payload); }
            finish(active);
        }
        function bindDropDocument(doc) {
            if (documents.has(doc)) return;
            documents.add(doc);
            doc.addEventListener('click', event => {
                if (event.detail > 0 && Date.now() < suppressClickUntil) { event.preventDefault(); event.stopImmediatePropagation(); }
            }, true);
            doc.addEventListener('pointermove', movePointer, {passive: false});
            doc.addEventListener('pointerup', endPointer);
            doc.addEventListener('pointercancel', () => finish());
            doc.addEventListener('dragover', event => {
                if (!valid(nativePayload)) return;
                event.preventDefault(); event.dataTransfer.dropEffect = nativePayload.kind === 'add' ? 'copy' : 'move';
                lastPoint = parentPoint(event); locate(lastPoint, nativePayload);
            });
            doc.addEventListener('drop', event => {
                if (!valid(nativePayload)) return;
                event.preventDefault(); locate(parentPoint(event), nativePayload); finish(true);
            });
            doc.addEventListener('keydown', event => { if (event.key === 'Escape' && (pointer || nativePayload)) { event.preventDefault(); finish(); } });
        }
        bindDropDocument(document);
        document.addEventListener('visibilitychange', () => { if (document.hidden) finish(); });
        canvas.addEventListener('load', () => { if (pointer || nativePayload) finish(); });
        return {
            isDragging: () => !!pointer?.active || !!nativePayload,
            bindPalette: source => { if (editable) bindSource(source, () => ({kind: 'add', type: source.dataset.addBlock})); },
            bindLayer(row, index) {
                if (!editable) return;
                const handle = document.createElement('button');
                handle.type = 'button'; handle.className = 'studio-drag-handle'; handle.textContent = '⠿';
                handle.setAttribute('aria-label', `Geser blok urutan ${index + 1}`); handle.title = 'Geser untuk memindahkan blok';
                row.prepend(handle); bindSource(handle, () => ({kind: 'move', from: index}));
            },
            bindDocument(doc) {
                bindDropDocument(doc);
                if (!editable) return;
                if (!doc.getElementById('studio-drag-styles')) {
                    const style = doc.createElement('style'); style.id = 'studio-drag-styles';
                    style.textContent = '[data-site-block]>[data-studio-block-handle]{opacity:0;transition:opacity .12s}[data-site-block]:hover>[data-studio-block-handle],[data-site-block][data-studio-selected=true]>[data-studio-block-handle],[data-studio-block-handle]:focus-visible{opacity:1}';
                    doc.head.append(style);
                }
                doc.querySelectorAll('[data-site-block]').forEach(block => {
                    if (block.querySelector(':scope > [data-studio-block-handle]')) return;
                    if (doc.defaultView.getComputedStyle(block).position === 'static') block.style.position = 'relative';
                    const handle = doc.createElement('button');
                    handle.type = 'button'; handle.dataset.studioBlockHandle = 'true'; handle.textContent = '⠿ Geser blok';
                    handle.setAttribute('aria-label', 'Geser blok untuk mengubah urutan');
                    handle.style.cssText = 'position:absolute;top:8px;right:8px;z-index:50;border:1px solid #0d99ff;border-radius:5px;background:#fff;color:#087ac1;padding:7px 12px;font:600 16px/1.4 sans-serif;cursor:grab;box-shadow:0 2px 6px #0002;';
                    handle.addEventListener('click', event => { event.preventDefault(); event.stopPropagation(); onSelect(Number(block.dataset.siteBlock)); });
                    bindSource(handle, () => ({kind: 'move', from: Number(block.dataset.siteBlock)}));
                    block.append(handle);
                });
            },
        };
    };
})();
