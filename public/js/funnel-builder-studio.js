export function createFunnelStudio({form, getState, choose, commit, move, add, names, onShortcut, onError, createEngine}) {
    const workspace = document.getElementById('builder-workspace'), viewport = document.getElementById('funnel-viewport'), canvas = document.getElementById('builder-canvas'), layers = document.getElementById('funnel-layer-list');
    const editable = form.dataset.editable === '1';
    const engine=createEngine({schema:getState().schema,editable,names,getSchema:()=>getState().schema,onSelect:choose,onCommit:commit,onMove:move,onAdd:add,onError});
    form.dataset.editorEngine='grapesjs';form.dataset.editorEngineVersion=engine.inspect().version;Object.defineProperty(form,'funnelEngine',{value:engine});
    addEventListener('pagehide',event=>{if(!event.persisted)engine.destroy();});
    let pendingFrame=null,displayedKey='',contentObserver=null,contentResize=null,customWidth=null,artboardDrag=null;
    const artboardWidth=()=>customWidth??(document.getElementById('device').value==='100%'?1440:parseInt(document.getElementById('device').value,10));
    let frame = null, zoom = null, tool = 'select', drag = null, ghost = null, destination = null, scrollFrame = null, lastPoint = null, suppressClick = 0, frameSequence = 0, entries = [];
    const boundDocuments = new WeakSet();
    const inline = window.JmSiteInline({canvas, editor: form, editable, limits:(index,path)=>path==='label'?100:path==='title'?(entries[index]?.kind==='field'?150:255):(entries[index]?.kind==='thank_you'?5000:10000),accept:(index,path,value)=>{if((entries[index]?.kind==='field'||entries[index]?.kind==='thank_you')&&!value.trim()){onError('Label field dan pesan konfirmasi wajib diisi. Perubahan kosong dibatalkan.');return false;}return true;},
        getBlock:index=>{const entry=entries[index];if(!entry)return null;const item=engine.read(entry.kind,entry.id);return item?{...item,title:entry.kind==='thank_you'?item.headline:entry.kind==='field'?item.label:item.title,label:item.button_text}:null;},
        onSelect: index => {const entry = entries[index];if (entry && entry.kind !== 'thank_you') engine.select(entry.kind, entry.id);},
        onCommit: (index, path, value) => {const entry = entries[index];if (entry) engine.commit(entry.kind, entry.id, entry.kind === 'thank_you' && path === 'title' ? 'headline' : entry.kind === 'field' ? 'label' : path === 'label' ? 'button_text' : path, value);},
        onImage: index => {const entry = entries[index];if (!entry) return;engine.select(entry.kind, entry.id);const panel = document.getElementById('builder-properties');openInspector(true);panel.querySelector('input[type=file],input[type=url]')?.focus();},
    });
    function openInspector(force=false){if(innerWidth>=768&&workspace.classList.contains('properties-hidden')&&!force)return;workspace.classList.remove('properties-hidden');workspace.classList.remove('show-studio-pages');workspace.classList.add('show-studio-properties');syncBackdrop();}
    function syncBackdrop(){const visible=innerWidth>=768?!workspace.classList.contains('properties-hidden'):workspace.classList.contains('show-studio-properties');document.querySelectorAll('[data-funnel-panel=properties]').forEach(button=>{button.setAttribute('aria-expanded',String(visible));button.setAttribute('aria-label',visible?'Sembunyikan properti':'Buka properti');});document.getElementById('funnel-panel-backdrop').hidden=innerWidth>=768||!workspace.matches('.show-studio-pages,.show-studio-properties');}
    addEventListener('resize',syncBackdrop);
    document.getElementById('funnel-panel-backdrop').onclick=()=>{workspace.classList.remove('show-studio-pages','show-studio-properties');syncBackdrop();};
    function leftTab(tab) {
        document.querySelectorAll('[data-funnel-left]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.funnelLeft === tab)));
        document.querySelectorAll('[data-funnel-left-pane]').forEach(pane => pane.hidden = pane.dataset.funnelLeftPane !== tab);
    }
    function setTool(value) {
        inline.finish(); tool = value; viewport.classList.toggle('is-panning', tool === 'hand');
        document.querySelectorAll('[data-funnel-tool]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.funnelTool === tool)));
    }
    function resize() {
        if (!frame?.isConnected) return;
        const width = artboardWidth();
        const scale = zoom ?? Math.min(1, Math.max(.15, (viewport.clientWidth - (innerWidth < 768 ? 48 : 80)) / width));
        frame.style.width = `${width}px`;
        const height = Math.min(20000, Math.max(900, Math.ceil(frame.contentDocument?.body?.getBoundingClientRect().height ?? 900)));
        frame.style.height = `${height}px`;frame.style.transform = `scale(${scale})`;
        frame.parentElement.style.width = `${width * scale}px`;frame.parentElement.style.height = `${height * scale}px`;
        canvas.style.width = `${width * scale}px`;
        const widthInput=frame.parentElement.querySelector('[data-artboard-width]');if(widthInput&&document.activeElement!==widthInput)widthInput.value=String(width);
        frame.parentElement.querySelectorAll('[data-artboard-resize]').forEach(handle=>handle.setAttribute('aria-valuenow',String(width)));
        document.getElementById('funnel-zoom').textContent = `${Math.round(scale * 100)}%`;
    }
    function setArtboardWidth(value) {
        customWidth=Math.max(320,Math.min(3840,Math.round(Number(value)||artboardWidth())));
        document.querySelectorAll('button[data-device]').forEach(button=>button.setAttribute('aria-pressed','false'));
        resize();
    }
    function finishArtboard(cancel=false) {
        if(!artboardDrag)return;
        const current=artboardDrag;artboardDrag=null;
        if(cancel){customWidth=current.customWidth;zoom=current.zoom;}
        if(cancel){canvas.style.marginLeft=current.marginLeft;canvas.style.marginRight=current.marginRight;}workspace.classList.remove('studio-resizing');
        if(current.handle.hasPointerCapture(current.pointer))current.handle.releasePointerCapture(current.pointer);
        resize();
        if(cancel)document.querySelectorAll('button[data-device]').forEach(button=>button.setAttribute('aria-pressed',String(customWidth===null&&button.dataset.device===document.getElementById('device').value)));
    }
    function bindArtboard(surface) {
        const label=document.createElement('label');label.className='studio-artboard-size';label.append('Lebar ');
        const input=document.createElement('input');input.type='number';input.min='320';input.max='3840';input.step='10';input.dataset.artboardWidth='';input.setAttribute('aria-label','Lebar artboard dalam piksel');label.append(input,' px');surface.append(label);
        input.addEventListener('change',()=>{if(zoom===null)zoom=frame.getBoundingClientRect().width/frame.offsetWidth;setArtboardWidth(input.value);input.value=String(artboardWidth());});
        input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();input.blur();}});
        ['left','right'].forEach(side=>{
            const handle=document.createElement('button');handle.type='button';handle.dataset.artboardResize=side;handle.className='studio-artboard-resize';handle.setAttribute('role','slider');handle.setAttribute('aria-label',`Ubah lebar artboard dari ${side==='left'?'kiri':'kanan'}`);handle.setAttribute('aria-valuemin','320');handle.setAttribute('aria-valuemax','3840');handle.setAttribute('aria-orientation','horizontal');surface.append(handle);
            handle.addEventListener('pointerdown',event=>{
                if(event.button!==0||!event.isPrimary||drag)return;
                event.preventDefault();event.stopPropagation();inline.finish();
                artboardDrag={handle,pointer:event.pointerId,x:event.clientX,width:artboardWidth(),left:parseFloat(getComputedStyle(canvas).marginLeft)||0,marginLeft:canvas.style.marginLeft,marginRight:canvas.style.marginRight,customWidth,zoom,side};
                zoom=frame.getBoundingClientRect().width/frame.offsetWidth;
                canvas.style.marginLeft=`${artboardDrag.left}px`;canvas.style.marginRight='0';workspace.classList.add('studio-resizing');handle.setPointerCapture(event.pointerId);
            });
            handle.addEventListener('pointermove',event=>{if(artboardDrag?.pointer!==event.pointerId)return;const current=artboardDrag;setArtboardWidth(current.width+(event.clientX-current.x)*(current.side==='left'?-1:1)/zoom);if(current.side==='left')canvas.style.marginLeft=`${current.left+(current.width-artboardWidth())*zoom}px`;});
            handle.addEventListener('pointerup',()=>finishArtboard());handle.addEventListener('pointercancel',()=>finishArtboard(true));
            handle.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight'].includes(event.key))return;event.preventDefault();if(zoom===null)zoom=frame.getBoundingClientRect().width/frame.offsetWidth;setArtboardWidth(artboardWidth()+(event.key==='ArrowRight'?1:-1)*(event.shiftKey?100:10));});
        });
    }
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&artboardDrag){event.preventDefault();finishArtboard(true);}});
    const observer = new ResizeObserver(() => requestAnimationFrame(resize));observer.observe(viewport);
    function reveal(kind,id){
        const attr=kind==='field'?'field':kind==='step'?'step':'editorBlock';
        const node=[...frame?.contentDocument?.querySelectorAll('[data-field],[data-step],[data-editor-block]')??[]].find(node=>node.dataset[attr]===id);
        if(!node||!frame)return;
        const bounds=node.getBoundingClientRect(),rect=frame.getBoundingClientRect(),scale=rect.width/frame.offsetWidth,outer=viewport.getBoundingClientRect();
        if(rect.top+bounds.top*scale<outer.top+24||rect.top+bounds.bottom*scale>outer.bottom-64)viewport.scrollTop+=rect.top+bounds.top*scale-outer.top-64;
    }
    function selection() {
        const selected = getState().selected;
        frame?.contentDocument?.querySelectorAll('[data-tracking-editor-badge]').forEach(node=>node.remove());
        if(getState().schema.tracking&&getState().schema.tracking.enabled!==false){for(const block of frame?.contentDocument?.querySelectorAll('[data-editor-block]')??[]){const rules=(getState().schema.tracking.rules??[]).filter(rule=>rule.enabled&&rule.section_key===block.dataset.editorBlock);if(rules.length){const badge=frame.contentDocument.createElement('span');badge.dataset.trackingEditorBadge='';badge.textContent=`Tracked: ${rules.length} aturan`;block.append(badge);}}}
        if(selected)engine.select(selected.kind,selected.id,false);else engine.clearSelection();
        layers.querySelectorAll('[data-layer-id]').forEach(row => row.setAttribute('aria-current', String(selected?.kind === row.dataset.layerKind && selected?.id === row.dataset.layerId)));
        frame?.contentDocument?.querySelectorAll('[data-editor-block],[data-field],[data-step]').forEach(node => {
            const kind = node.hasAttribute('data-field') ? 'field' : node.hasAttribute('data-step') ? 'step' : 'block';
            const id = kind === 'field' ? node.dataset.field : kind === 'step' ? node.dataset.step : node.dataset.editorBlock;
            node.toggleAttribute('data-funnel-selected', selected?.kind === kind && selected?.id === id);
        });
    }
    function clearMarker() {
        destination = null;layers.querySelectorAll('.studio-drop-before,.studio-drop-after').forEach(node => node.classList.remove('studio-drop-before', 'studio-drop-after'));
        frame?.contentDocument?.getElementById('funnel-drop-marker')?.remove();
    }
    function point(event) {
        if (event.target.ownerDocument === document || event.target === document) return {x:event.clientX,y:event.clientY};
        const rect = frame.getBoundingClientRect(), scale = rect.width / frame.offsetWidth;
        return {x:rect.left + event.clientX * scale, y:rect.top + event.clientY * scale};
    }
    function locate(position) {
        clearMarker();
        const element = document.elementFromPoint(position.x, position.y), fieldDrag = drag.kind === 'field' || drag.kind === 'add-field', kind = fieldDrag ? 'field' : drag.kind === 'step' ? 'step' : 'block';
        const row = element?.closest('[data-layer-id]');
        if (row) {
            if (row.dataset.layerKind !== kind && !(fieldDrag && row.dataset.layerKind === 'step')) return;
            const after = position.y > row.getBoundingClientRect().top + row.offsetHeight / 2;
            row.classList.add(after ? 'studio-drop-after' : 'studio-drop-before');destination = {kind:row.dataset.layerKind,id:row.dataset.layerId,after};return;
        }
        if (element !== frame || form.dataset.previewPending === 'true') return;
        const rect = frame.getBoundingClientRect(), scale = rect.width / frame.offsetWidth, doc = frame.contentDocument, x = (position.x - rect.left) / scale, y = (position.y - rect.top) / scale;
        const hit = doc.elementFromPoint(x, y), selector = fieldDrag ? '[data-field]' : kind === 'step' ? '[data-step]' : '[data-editor-block]';
        let target = hit?.closest(selector), targetKind = kind;
        if (!target && fieldDrag) {target = hit?.closest('[data-step]');targetKind = 'step';}
        if (!target) {
            const nodes = [...doc.querySelectorAll(selector)];
            target = nodes.find(node => y < node.getBoundingClientRect().bottom) ?? nodes.at(-1);
        }
        if (!target) return;
        const bounds = target.getBoundingClientRect(), after = y > bounds.top + bounds.height / 2;
        destination = {kind:targetKind,id:targetKind === 'field' ? target.dataset.field : targetKind === 'step' ? target.dataset.step : target.dataset.editorBlock,after};
        const marker = doc.createElement('div');marker.id = 'funnel-drop-marker';marker.style.cssText = `position:fixed;z-index:2147483647;left:${bounds.left}px;top:${after ? bounds.bottom : bounds.top}px;width:${bounds.width}px;height:4px;background:#0d99ff;pointer-events:none;box-shadow:0 0 0 1px white`;doc.documentElement.append(marker);
    }
    function autoScroll() {
        if (!drag?.active || !lastPoint) return;
        const rect = viewport.getBoundingClientRect();
        if (lastPoint.x >= rect.left && lastPoint.x <= rect.right) {
            if (lastPoint.y > rect.bottom - 52 && lastPoint.y < rect.bottom) viewport.scrollTop += 16;
            if (lastPoint.y < rect.top + 52 && lastPoint.y > rect.top) viewport.scrollTop -= 16;
            locate(lastPoint);
        }
        scrollFrame = requestAnimationFrame(autoScroll);
    }
    function finishDrag(apply = false) {
        if (!drag) return;
        const current = drag, target = destination;drag = null;clearMarker();cancelAnimationFrame(scrollFrame);ghost?.remove();ghost = null;workspace.classList.remove('studio-dragging');
        if (current.source.hasPointerCapture?.(current.pointer)) current.source.releasePointerCapture(current.pointer);
        if (current.active) suppressClick = Date.now() + 150;
        if (apply && current.active && target) {
            if (current.kind.startsWith('add-')) engine.add(current.kind, current.id, target);
            else engine.move(current.kind, current.id, target);
        }
        renderLayers();
    }
    function source(node, payload, body = false) {
        if (!editable) return;
        node.style.touchAction = 'none';node.draggable = false;
        node.addEventListener('pointerdown', event => {
            if(body&&(event.target.closest('[data-funnel-handle],a,button,input,select,textarea,[contenteditable=true]')||event.target.closest('[data-field],[data-editor-block]')!==node))return;
            if (event.button !== 0 || !event.isPrimary || drag || (node.ownerDocument!==document&&form.dataset.previewPending==='true')) return;
            inline.finish();setTool('select');
            drag = {...payload, source:node, pointer:event.pointerId, start:point(event), active:false};if(!body)node.setPointerCapture(event.pointerId);
        });
        node.addEventListener('dragstart', event => event.preventDefault());
    }
    function bindDocument(doc) {
        if (boundDocuments.has(doc)) return;boundDocuments.add(doc);
        doc.addEventListener('click', event => {if (event.detail && Date.now() < suppressClick) {event.preventDefault();event.stopImmediatePropagation();}}, true);
        doc.addEventListener('pointermove', event => {
            if (!drag || drag.pointer !== event.pointerId) return;
            const position = point(event);
            if (!drag.active && Math.hypot(position.x - drag.start.x, position.y - drag.start.y) < 6) return;
            if (!drag.active) {drag.source.setPointerCapture(drag.pointer);drag.active = true;workspace.classList.add('studio-dragging');ghost = document.createElement('div');ghost.className = 'studio-drag-ghost';ghost.textContent = drag.kind.startsWith('add-') ? '+ Tambah elemen' : '⠿ Pindahkan elemen';document.body.append(ghost);scrollFrame = requestAnimationFrame(autoScroll);}
            event.preventDefault();lastPoint = position;ghost.style.left = `${position.x + 16}px`;ghost.style.top = `${position.y + 14}px`;locate(position);
        }, {passive:false});
        doc.addEventListener('pointerup', event => {if (drag?.pointer !== event.pointerId) return;if (drag.active) locate(point(event));finishDrag(true);});
        doc.addEventListener('pointercancel', () => finishDrag());
        doc.addEventListener('keydown', event => {if (event.key === 'Escape' && drag) {event.preventDefault();finishDrag();}});
    }
    bindDocument(document);
    document.addEventListener('visibilitychange', () => {if (document.hidden) {finishDrag();finishArtboard(true);}});
    let layerSignature='';
    function renderLayers() {
        if(drag)return;
        engine.sync(getState().schema);
        const {schema,page}=getState(),key=JSON.stringify([page,schema.blocks,schema.fields,schema.steps]);
        if(layerSignature===key){selection();return;}layerSignature=key;
        layers.replaceChildren();
        const row = (kind, id, label, hidden = false) => {
            const li = document.createElement('li');li.dataset.layerKind = kind;li.dataset.layerId = id;li.classList.toggle('layer-field', kind === 'field');li.classList.toggle('layer-hidden', hidden);
            if (editable) {const handle = document.createElement('button');handle.type = 'button';handle.textContent = '⠿';handle.className = 'studio-drag-handle';handle.setAttribute('aria-label', `Geser ${label}`);source(handle, {kind,id});li.append(handle);}
            const button = document.createElement('button');button.type = 'button';button.className = 'funnel-layer-select';button.textContent = label;button.onclick = () => {engine.select(kind,id);openInspector();reveal(kind,id);};li.append(button);layers.append(li);
        };
        if(page!=='thank_you')engine.layers(page).forEach(item=>row(item.kind,item.id,item.label,item.hidden));
        if (page === 'thank_you') {const text = document.createElement('p');text.className = 'studio-layer-help';text.textContent = 'Edit judul dan pesan konfirmasi langsung di kanvas atau melalui panel Konten.';layers.append(text);}
        selection();
    }
    function bindPalette(node, kind, id) {engine.registerAsset(kind,id,node.textContent);if (!node.dataset.funnelDrag) {node.dataset.funnelDrag = 'true';source(node,{kind,id});}}
    function attach(doc, key, isCurrent = () => true) {
        inline.finish();finishDrag();finishArtboard(true);pendingFrame=key;const sequence = ++frameSequence;
        doc.querySelectorAll('script').forEach(node => node.remove());
        const surface = document.createElement('div');surface.className = 'canvas-public-viewport';surface.style.cssText='position:absolute;visibility:hidden;pointer-events:none;';const nextFrame=document.createElement('iframe');nextFrame.title='Kanvas desain funnel';nextFrame.setAttribute('sandbox','allow-same-origin');nextFrame.style.width=artboardWidth()+'px';nextFrame.style.height='900px';surface.append(nextFrame);canvas.append(surface);
        nextFrame.addEventListener('load', () => {
            if (sequence !== frameSequence||!isCurrent()){surface.remove();if(sequence===frameSequence)pendingFrame=null;return;}
            pendingFrame=null;displayedKey=key;contentObserver?.disconnect();cancelAnimationFrame(contentResize);const scroll={top:viewport.scrollTop,left:viewport.scrollLeft};frame=nextFrame;
            const inner = frame.contentDocument;entries = [];
            const style = inner.createElement('style');style.textContent = '[data-funnel-selected]{outline:2px solid #0d99ff!important;outline-offset:2px}[data-editor-block],[data-field],[data-step]{position:relative}[data-funnel-handle]{opacity:0;position:absolute;right:6px;top:6px;z-index:50;border:1px solid #0d99ff;border-radius:5px;background:white;color:#087ac1;padding:6px 10px;font:600 14px/1.4 sans-serif;cursor:grab}[data-editor-block]:hover>[data-funnel-handle],[data-field]:hover>[data-funnel-handle],[data-funnel-selected]>[data-funnel-handle],[data-funnel-handle]:focus-visible{opacity:1}[data-tracking-editor-badge]{position:absolute;bottom:4px;right:8px;padding:3px 8px;background:#e5f4ff;color:#087ac1;border-radius:4px;font:11px sans-serif;pointer-events:none}html,body{height:auto!important;min-height:0!important;overflow:hidden!important}body{display:flow-root}.funnel-page .public-main{min-height:0!important}.application-step{display:grid!important}input,select,textarea{pointer-events:none}[data-step]>legend{cursor:pointer}';inner.head.append(style);
            inner.querySelectorAll('[data-editor-block],[data-field]').forEach(node => {
                const kind = node.hasAttribute('data-field') ? 'field' : 'block', id = kind === 'field' ? node.dataset.field : node.dataset.editorBlock;
                node.dataset.siteBlock = String(entries.push({kind,id}) - 1);
                if (editable && (getState().page === 'form' ? kind === 'field' : kind === 'block')) {const handle = inner.createElement('button');handle.type = 'button';handle.dataset.funnelHandle = 'true';handle.textContent = '⠿ Geser';handle.setAttribute('aria-label','Geser elemen');source(handle,{kind,id});handle.onclick = event => {event.preventDefault();event.stopPropagation();engine.select(kind,id);};node.append(handle);source(node,{kind,id},true);}
            });
            if (getState().page === 'thank_you') {const thank = inner.querySelector('.thank-you');if (thank) thank.dataset.siteBlock = String(entries.push({kind:'thank_you',id:'thank_you'}) - 1);}
            inner.querySelectorAll('[data-field]>input,[data-field]>select,[data-field]>textarea,button[type=submit],#form-submit,#form-next,#form-back').forEach(control => {if(control.matches('[data-site-field]')){control.disabled=false;control.type='button';control.title='Klik dua kali untuk mengedit teks tombol';}else control.disabled=true;});
            inner.addEventListener('submit', event => event.preventDefault());
            inner.addEventListener('keydown',onShortcut);
            inner.addEventListener('wheel',event=>{
                event.preventDefault();const multiplier=event.deltaMode===1?16:event.deltaMode===2?viewport.clientHeight:1;
                if(event.ctrlKey||event.metaKey){const position=point(event),current=frame.getBoundingClientRect().width/frame.offsetWidth;setZoom(current*Math.exp(-event.deltaY*.002),position);}
                else viewport.scrollBy({left:event.deltaX*multiplier,top:event.deltaY*multiplier,behavior:'instant'});
            },{passive:false});
            inner.addEventListener('click', event => {if(form.dataset.previewPending==='true'){event.preventDefault();return;}if (event.target.isContentEditable) return;event.preventDefault();const node = event.target.closest('[data-field],[data-step],[data-editor-block]');if (node) engine.select(node.hasAttribute('data-field') ? 'field' : node.hasAttribute('data-step') ? 'step' : 'block', node.dataset.field ?? node.dataset.step ?? node.dataset.editorBlock);if(node)openInspector();});
            bindDocument(inner);inline.bindDocument(inner);form.dataset.previewPending = 'false';selection();bindArtboard(surface);resize();surface.style.cssText='';[...canvas.children].forEach(child=>{if(child!==surface)child.remove();});viewport.scrollTop=scroll.top;viewport.scrollLeft=scroll.left;inner.querySelectorAll('img').forEach(image => image.addEventListener('load',resize));contentObserver=new ResizeObserver(()=>{cancelAnimationFrame(contentResize);contentResize=requestAnimationFrame(resize);});contentObserver.observe(inner.body);
        });nextFrame.srcdoc = '<!doctype html>' + doc.documentElement.outerHTML;
    }
    document.querySelectorAll('[data-funnel-left]').forEach(button => button.onclick = () => leftTab(button.dataset.funnelLeft));
    document.querySelectorAll('[data-funnel-add]').forEach(button => button.onclick = () => {leftTab('assets');workspace.classList.add('show-studio-pages');workspace.classList.remove('show-studio-properties');syncBackdrop();});
    document.querySelectorAll('[data-funnel-panel]').forEach(button => button.onclick = () => {const value = button.dataset.funnelPanel;inline.finish();if(value==='properties'&&innerWidth>=768){workspace.classList.toggle('properties-hidden');syncBackdrop();return;}if(value==='close-properties'){workspace.classList.add('properties-hidden');workspace.classList.remove('show-studio-properties');syncBackdrop();return;}if (value === 'close') workspace.classList.remove('show-studio-pages','show-studio-properties');else {const name = `show-studio-${value}`, open = !workspace.classList.contains(name);workspace.classList.remove('show-studio-pages','show-studio-properties');workspace.classList.toggle(name,open);}syncBackdrop();});
    document.querySelectorAll('[data-funnel-dialog]').forEach(button => button.onclick = () => {inline.finish();const dialog = document.getElementById(button.dataset.funnelDialog);dialog.showModal();dialog.querySelector('details')?.setAttribute('open','');button.closest('.studio-file-menu')?.removeAttribute('open');});
    document.querySelectorAll('[data-funnel-close]').forEach(button => button.onclick = () => button.closest('dialog').close());document.querySelector('[data-open-on-load]')?.showModal();
    function assetTab(tab){document.querySelectorAll('[data-funnel-asset-tab]').forEach(button=>button.setAttribute('aria-pressed',String(button.dataset.funnelAssetTab===tab)));document.querySelectorAll('[data-funnel-asset-group]').forEach(group=>group.hidden=group.dataset.funnelAssetGroup!==tab);}
    document.querySelectorAll('[data-funnel-asset-tab]').forEach(button=>button.onclick=()=>assetTab(button.dataset.funnelAssetTab));
    document.querySelectorAll('[data-builder-page]').forEach(button=>button.addEventListener('click',()=>assetTab(button.dataset.builderPage==='form'?'fields':'blocks')));
    document.querySelectorAll('[data-funnel-settings]').forEach(button=>button.onclick=()=>{openInspector(true);document.querySelector('.funnel-settings').open=true;form.elements.name.focus();document.querySelector('.studio-file-menu').open=false;});
    document.querySelectorAll('[data-funnel-tool]').forEach(button => button.onclick = () => setTool(button.dataset.funnelTool));
    function resetView(){inline.finish();finishDrag();canvas.style.marginLeft='';canvas.style.marginRight='';viewport.scrollTop=0;viewport.scrollLeft=0;}
    function setZoom(value,anchor=null){
        if(!frame)return;
        const rect=frame.getBoundingClientRect(),oldScale=rect.width/frame.offsetWidth,bounds=viewport.getBoundingClientRect();
        const position=anchor??{x:Math.max(rect.left,Math.min(rect.right,bounds.left+bounds.width/2)),y:Math.max(rect.top,Math.min(rect.bottom,bounds.top+bounds.height/2))};
        const logical={x:(position.x-rect.left)/oldScale,y:(position.y-rect.top)/oldScale};zoom=Math.max(.15,Math.min(2,value));resize();
        const next=frame.getBoundingClientRect();viewport.scrollLeft+=next.left+logical.x*zoom-position.x;viewport.scrollTop+=next.top+logical.y*zoom-position.y;
    }
    document.querySelectorAll('[data-funnel-zoom]').forEach(button=>button.onclick=()=>{const current=frame?frame.getBoundingClientRect().width/frame.offsetWidth:.5;setZoom(current+(button.dataset.funnelZoom==='in'?.1:-.1));});
    document.getElementById('funnel-zoom').onclick=()=>{zoom=null;resize();resetView();};
    document.getElementById('device').addEventListener('change', () => {finishArtboard();customWidth=null;zoom = null;resize();resetView();});
    let pan = null;viewport.addEventListener('pointerdown', event => {if (tool !== 'hand' || event.button !== 0) return;pan = {x:event.clientX,y:event.clientY,left:viewport.scrollLeft,top:viewport.scrollTop};viewport.setPointerCapture(event.pointerId);});viewport.addEventListener('pointermove', event => {if (!pan) return;viewport.scrollLeft = pan.left - event.clientX + pan.x;viewport.scrollTop = pan.top - event.clientY + pan.y;});viewport.addEventListener('pointerup', () => pan = null);viewport.addEventListener('pointercancel', () => pan = null);
    document.addEventListener('keydown', event => {if (event.target.closest('input,textarea,select') || event.target.isContentEditable || event.ctrlKey || event.metaKey || event.altKey) return;if (event.key.toLowerCase() === 'h') setTool('hand');if (event.key.toLowerCase() === 'v') setTool('select');});
    return {attach, resetView, isLoading:key=>pendingFrame===key, currentKey:()=>displayedKey, openInspector, renderLayers, selection, resize, bindPalette, finishInline: () => inline.finish(), isInteracting: () => inline.isEditing() || !!drag?.active || !!artboardDrag, isDirty: () => inline.isDirty(), hasFrame: () => !!frame?.isConnected};
}
