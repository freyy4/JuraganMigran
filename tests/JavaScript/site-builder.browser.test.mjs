import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';

const chromePath = process.env.SITE_QA_CHROME;
const fixtureDirectory = process.env.SITE_QA_FIXTURES;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

test('website drag and drop preserves insertion order, history and saved draft at different zoom levels', {skip: !chromePath || !fixtureDirectory}, async t => {
    const publicRoot = path.resolve('public');
    const editorFixture = fs.readFileSync(path.join(fixtureDirectory, 'jm-v811-editor.html'), 'utf8');
    const previewFixture = fs.readFileSync(path.join(fixtureDirectory, 'jm-v811-preview.html'), 'utf8');
    let origin;
    const rewrite = html => html.replaceAll('https://juraganmigran.my.id', origin).replaceAll('file:///var/www/juraganmigran/public/', `${origin}/`).replaceAll('file:///tmp/jm-v811-preview.html', `${origin}/preview`);
    const server = http.createServer((request, response) => {
        if (request.url === '/editor' || request.url === '/preview' || request.url === '/readonly') {
            response.setHeader('Content-Type', 'text/html');
            response.end(rewrite(request.url === '/preview' ? previewFixture : request.url === '/readonly' ? editorFixture.replace('data-editable="true"', 'data-editable="false"') : editorFixture));
            return;
        }
        const file = path.resolve(publicRoot, `.${request.url.split('?')[0]}`);
        if ((file.startsWith(`${publicRoot}/css/`) || file.startsWith(`${publicRoot}/js/`)) && fs.existsSync(file)) {
            response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css');
            response.end(fs.readFileSync(file));
            return;
        }
        response.writeHead(404); response.end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    origin = `http://127.0.0.1:${server.address().port}`;
    const chrome = spawn(chromePath, ['--headless', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=0', 'about:blank'], {stdio: ['ignore', 'ignore', 'pipe']});
    let socket;
    try {
        const endpoint = await new Promise((resolve, reject) => {
            let stderr = '';
            const timeout = setTimeout(() => reject(Error('Browser startup timed out')), 15000);
            chrome.stderr.on('data', data => {
                stderr += data.toString();
                const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);
                if (match) { clearTimeout(timeout); resolve(match[1]); }
            });
            chrome.once('exit', code => { clearTimeout(timeout); reject(Error(`Browser exited: ${code}`)); });
        });
        const target = (await (await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json`)).json())[0];
        socket = new WebSocket(target.webSocketDebuggerUrl);
        await new Promise(resolve => socket.addEventListener('open', resolve));
        let id = 0;
        const pending = new Map();
        socket.addEventListener('message', event => {
            const data = JSON.parse(event.data);
            if (data.method === 'Page.javascriptDialogOpening') socket.send(JSON.stringify({id: ++id, method: 'Page.handleJavaScriptDialog', params: {accept: true}}));
            if (pending.has(data.id)) {
                const callback = pending.get(data.id); pending.delete(data.id);
                data.error ? callback.reject(Error(JSON.stringify(data.error))) : callback.resolve(data.result);
            }
        });
        const send = (method, params = {}) => new Promise((resolve, reject) => {
            const current = ++id; pending.set(current, {resolve, reject}); socket.send(JSON.stringify({id: current, method, params}));
        });
        const evaluate = async expression => {
            const result = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
            if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        const waitFor = async expression => {
            for (let attempt = 0; attempt < 100; attempt++) { if (await evaluate(expression)) return; await delay(50); }
            throw Error(`Timed out: ${expression}`);
        };
        await send('Page.enable');
        await send('Page.addScriptToEvaluateOnNewDocument', {source: `
            window.__errors=[];window.__saves=[];
            addEventListener('error',event=>window.__errors.push(event.message));
            window.fetch=async(url,options={})=>{
                const body=options.body?JSON.parse(options.body):null;
                if(String(url).includes('live-preview')){
                    const doc=new DOMParser().parseFromString(${JSON.stringify(rewrite(previewFixture))},'text/html');
                    const main=doc.querySelector('main');main.replaceChildren();
                    body.schema.blocks.forEach((block,index)=>{const section=doc.createElement('section');section.dataset.siteBlock=index;section.style.cssText='min-height:180px;padding:32px;border-bottom:1px solid #ddd';const title=doc.createElement('h2');title.dataset.siteField='title';title.textContent=block.title||block.type;section.append(title);if(['hero','image'].includes(block.type)){const image=doc.createElement('div');image.dataset.siteImage='';image.style.minHeight='100px';image.textContent='Gambar halaman';section.append(image);}main.append(section);});
                    return new Response(doc.documentElement.outerHTML,{headers:{'Content-Type':'text/html'}});
                }
                if(options.method==='POST'){window.__saves.push(body);return new Response(JSON.stringify({updated_at:'2026-10-17T00:00:00Z',draft_token:'test'}),{headers:{'Content-Type':'application/json'}});}
                return new Response(JSON.stringify({data:[{id:123,label:'Gambar Uji',url:'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='}]}),{headers:{'Content-Type':'application/json'}});
            };`});
        await send('Emulation.setDeviceMetricsOverride', {width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false});
        await send('Page.navigate', {url: `${origin}/editor`});
        await waitFor(`document.querySelector('#site-canvas')?.contentDocument?.querySelector('[data-studio-block-handle]') && document.querySelector('#site-block-list .studio-drag-handle')`);
        const savedBlocks = () => evaluate(`window.__saves.at(-1)?.schema.blocks`);
        const waitPreview = () => waitFor(`document.getElementById('site-editor').dataset.previewPending!=='true'`);
        const point = expression => evaluate(`(()=>{${expression}})()`);
        const framePoint = async (index, edge = 'after', handle = false) => point(`
            const frame=document.getElementById('site-canvas'),rect=frame.getBoundingClientRect(),scale=rect.width/frame.offsetWidth;
            const block=frame.contentDocument.querySelectorAll('[data-site-block]')[${index}],node=${handle ? "block.querySelector('[data-studio-block-handle]')" : 'block'},bounds=node.getBoundingClientRect();
            return {x:rect.left+(bounds.left+Math.min(bounds.width/2,100))*scale,y:rect.top+(bounds.top+${handle ? 'bounds.height/2' : edge === 'after' ? 'bounds.height*.8' : 'bounds.height*.2'})*scale};`);
        const layerPoint = index => point(`const row=document.querySelectorAll('#site-block-list li')[${index}],r=row.querySelector('.studio-drag-handle').getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2};`);
        const drag = async (from, to, cancel = false, touch = false) => {
            if (touch) await send('Input.dispatchTouchEvent', {type: 'touchStart', touchPoints: [{x: from.x, y: from.y, id: 1}]});
            else await send('Input.dispatchMouseEvent', {type: 'mousePressed', x: from.x, y: from.y, button: 'left', buttons: 1, clickCount: 1});
            for (let step = 1; step <= 12; step++) {
                const x = from.x + (to.x - from.x) * step / 12, y = from.y + (to.y - from.y) * step / 12;
                if (touch) await send('Input.dispatchTouchEvent', {type: 'touchMove', touchPoints: [{x, y, id: 1}]});
                else await send('Input.dispatchMouseEvent', {type: 'mouseMoved', x, y, buttons: 1});
                await delay(16);
            }
            const marker = await evaluate(`!!document.getElementById('site-canvas').contentDocument.getElementById('studio-drop-marker') || !!document.querySelector('.studio-drop-before,.studio-drop-after')`);
            if (process.env.SITE_QA_OUTPUT && marker) {
                fs.mkdirSync(process.env.SITE_QA_OUTPUT, {recursive: true});
                fs.writeFileSync(path.join(process.env.SITE_QA_OUTPUT, `drag-${Date.now()}.png`), Buffer.from((await send('Page.captureScreenshot', {format: 'png'})).data, 'base64'));
            }
            if (cancel) { await send('Input.dispatchKeyEvent', {type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27}); await send('Input.dispatchKeyEvent', {type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27}); }
            if (touch) await send('Input.dispatchTouchEvent', {type: 'touchEnd', touchPoints: []});
            else await send('Input.dispatchMouseEvent', {type: 'mouseReleased', x: to.x, y: to.y, button: 'left', buttons: 0, clickCount: 1});
            return marker;
        };
        const doubleClickFrame = async selector => {
            const location = await point(`const frame=document.getElementById('site-canvas'),node=frame.contentDocument.querySelector(${JSON.stringify(selector)}),viewport=document.querySelector('.site-editor-canvas');let rect=frame.getBoundingClientRect(),scale=rect.width/frame.offsetWidth,bounds=node.getBoundingClientRect();viewport.scrollTop+=rect.top+bounds.top*scale-viewport.getBoundingClientRect().top-160;rect=frame.getBoundingClientRect();bounds=node.getBoundingClientRect();return{x:rect.left+(bounds.left+Math.min(bounds.width/2,120))*scale,y:rect.top+(bounds.top+bounds.height/2)*scale};`);
            for (const count of [1,2]) {
                await send('Input.dispatchMouseEvent', {type:'mousePressed',x:location.x,y:location.y,button:'left',buttons:1,clickCount:count});
                await send('Input.dispatchMouseEvent', {type:'mouseReleased',x:location.x,y:location.y,button:'left',buttons:0,clickCount:count});
            }
        };
        const initial = await evaluate(`JSON.parse(document.getElementById('site-schema').textContent).blocks`);
        await t.test('inline text commits to draft, supports undo and cancels with Escape', async () => {
            await doubleClickFrame('[data-site-block="0"] [data-site-field="title"]');
            assert.equal(await evaluate(`document.getElementById('site-canvas').contentDocument.querySelector('[data-site-field=title]').isContentEditable`), true);
            await evaluate(`document.getElementById('site-canvas').contentDocument.querySelector('[data-site-field=title]').textContent='Judul hasil edit kanvas'`);
            await send('Input.dispatchKeyEvent', {type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
            await delay(1500); await waitPreview();
            assert.equal((await savedBlocks())[0].title,'Judul hasil edit kanvas');
            assert.equal(await evaluate(`document.querySelector('[data-site-property=title]').value`),'Judul hasil edit kanvas');
            await evaluate(`document.getElementById('site-undo').click()`); await delay(1500); await waitPreview();
            assert.equal((await savedBlocks())[0].title,initial[0].title);
            await doubleClickFrame('[data-site-block="0"] [data-site-field="title"]');
            await evaluate(`document.getElementById('site-canvas').contentDocument.querySelector('[data-site-field=title]').textContent='Perubahan yang dibatalkan'`);
            await send('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            assert.equal(await evaluate(`document.getElementById('site-canvas').contentDocument.querySelector('[data-site-field=title]').textContent`),initial[0].title);
            await evaluate(`document.querySelector('.site-editor-canvas').scrollTop=0`);
        });
        await t.test('a block dragged inside the scaled iframe moves to its indicated slot', async () => {
            assert.equal(await drag(await framePoint(0, 'after', true), await framePoint(1)), true);
            await delay(1500); await waitPreview();
            const blocks = await savedBlocks();
            assert.deepEqual(blocks.map(block => block.type), [initial[1].type, initial[0].type, ...initial.slice(2).map(block => block.type)]);
            assert.equal(await evaluate(`document.querySelectorAll('#site-block-list li').length`), initial.length);
        });
        await t.test('a palette component is inserted between existing blocks without a duplicate click', async () => {
            await evaluate(`document.querySelector('[data-studio-left=assets]').click()`);
            const from = await point(`const r=document.querySelector('[data-add-block=rich_text]').getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2};`);
            assert.equal(await drag(from, await framePoint(0)), true);
            await delay(1500); await waitPreview();
            const blocks = await savedBlocks();
            assert.equal(blocks.length, initial.length + 1);
            assert.equal(blocks[1].type, 'rich_text');
            assert.equal(blocks[2].type, initial[0].type);
            assert.equal(await evaluate(`document.querySelector('#site-block-properties h3').textContent`), '2. Teks');
        });
        await t.test('layer drag works at 100 percent and undo/redo restores exact order', async () => {
            await evaluate(`document.querySelector('[data-studio-left=layers]').click();document.getElementById('studio-zoom').value='100';document.getElementById('studio-zoom').dispatchEvent(new Event('change'))`);
            const before = await savedBlocks();
            const to = await framePoint(2);
            assert.equal(await drag(await layerPoint(0), to), true);
            await delay(1500); await waitPreview();
            const after = await savedBlocks();
            assert.deepEqual(after.map(block => block.type), [before[1].type, before[2].type, before[0].type, ...before.slice(3).map(block => block.type)]);
            await evaluate(`document.getElementById('site-undo').click()`); await delay(1500); await waitPreview();
            assert.deepEqual(await savedBlocks(), before);
            await evaluate(`document.getElementById('site-redo').click()`); await delay(1500); await waitPreview();
            assert.deepEqual(await savedBlocks(), after);
        });
        await t.test('Escape cancels a drag without changing the saved order', async () => {
            const before = await savedBlocks();
            const to = await point(`const r=document.querySelectorAll('#site-block-list li')[3].getBoundingClientRect();return{x:r.left+80,y:r.bottom-3};`);
            await drag(await layerPoint(0), to, true); await delay(1500);
            assert.deepEqual(await savedBlocks(), before);
            assert.equal(await evaluate(`!!document.querySelector('.studio-drag-ghost,.studio-drop-before,.studio-drop-after')`), false);
        });
        await t.test('touch can reorder layers without producing a duplicate or page overflow', async () => {
            await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 1000, deviceScaleFactor: 1, mobile: true});
            await evaluate(`document.querySelector('[data-studio-mobile=pages]').click()`);
            const before = await savedBlocks();
            const to = await point(`const r=document.querySelectorAll('#site-block-list li')[1].getBoundingClientRect();return{x:r.left+80,y:r.bottom-3};`);
            assert.equal(await drag(await layerPoint(0), to, false, true), true);
            await delay(1500); await waitPreview();
            const blocks = await savedBlocks();
            assert.deepEqual(blocks, [before[1], before[0], ...before.slice(2)]);
            assert.equal(await evaluate(`document.documentElement.scrollWidth===innerWidth`), true);
        });
        await t.test('touch can drag a palette component across the mobile panel into the canvas', async () => {
            await evaluate(`document.querySelector('[data-studio-left=assets]').click()`);
            const before = await savedBlocks();
            const from = await point(`const r=document.querySelector('[data-add-block=rich_text]').getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2};`);
            const destination = await framePoint(0);
            destination.x = 350;
            assert.equal(await drag(from, destination, false, true), true);
            await delay(1500); await waitPreview();
            const blocks = await savedBlocks();
            assert.equal(blocks.length, before.length + 1);
            assert.equal(blocks[1].type, 'rich_text');
            assert.deepEqual(blocks.slice(2), before.slice(1));
        });
        await t.test('image replacement and object properties are stored and can be undone', async () => {
            await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
            await evaluate(`document.getElementById('studio-zoom').value='fit';document.getElementById('studio-zoom').dispatchEvent(new Event('change'))`);
            const blocks=await savedBlocks(),index=blocks.findIndex(block=>block.type==='hero');
            await doubleClickFrame(`[data-site-block="${index}"] [data-site-image]`);
            await waitFor(`document.getElementById('site-asset-picker').open&&document.querySelector('#site-asset-results button')`);
            await evaluate(`document.querySelector('#site-asset-results button').click()`);await delay(1500);await waitPreview();
            assert.equal((await savedBlocks())[index].asset_id,123);
            await evaluate(`document.querySelector('[data-inspector-tab=style]').click();for(const [label,value] of [['Lebar gambar',65],['Tinggi gambar',220],['Sudut gambar',18]]){const control=[...document.querySelectorAll('#site-block-properties label')].find(node=>node.textContent.startsWith(label)).querySelector('input');control.value=value;control.dispatchEvent(new Event('input'));}const fit=[...document.querySelectorAll('#site-block-properties label')].find(node=>node.textContent.startsWith('Cara gambar')).querySelector('select');fit.value='contain';fit.dispatchEvent(new Event('change'));`);
            await delay(1500);await waitPreview();
            const image=(await savedBlocks())[index];
            assert.equal(image.image_width,65);assert.equal(image.image_height,220);assert.equal(image.image_radius,18);assert.equal(image.image_fit,'contain');
            await evaluate(`document.getElementById('site-undo').click()`);await delay(1500);await waitPreview();
            assert.equal((await savedBlocks())[index].image_fit,undefined);
            await evaluate(`document.querySelector('[data-inspector-tab=content]').click();document.querySelector('.site-editor-canvas').scrollTop=0`);
        });
        await t.test('a component can be dropped on a new empty page', async () => {
            await send('Emulation.setDeviceMetricsOverride', {width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false});
            await evaluate(`document.getElementById('studio-zoom').value='fit';document.getElementById('studio-zoom').dispatchEvent(new Event('change'));for(let i=0;i<60&&document.querySelector('#site-block-list li');i++){[...document.querySelectorAll('#site-block-properties button')].find(button=>button.textContent==='Hapus Blok').click();}`);
            await delay(1500); await waitPreview();
            assert.equal((await savedBlocks()).length, 0);
            await evaluate(`document.querySelector('[data-studio-left=assets]').click()`);
            const from = await point(`const r=document.querySelector('[data-add-block=hero]').getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2};`);
            const to = await point(`const r=document.querySelector('.site-editor-canvas').getBoundingClientRect();return{x:r.left+r.width*.6,y:r.top+260};`);
            assert.equal(await drag(from, to), true);
            await delay(1500); await waitPreview();
            assert.equal((await savedBlocks()).length, 1);
            assert.equal((await savedBlocks())[0].type, 'hero');
        });
        assert.deepEqual(await evaluate('window.__errors'), []);
        await t.test('read-only previews expose no drag handles or editable palette controls', async () => {
            await send('Page.navigate', {url: `${origin}/readonly`});
            await waitFor(`document.getElementById('site-editor')?.dataset.editable==='false'&&document.querySelector('#site-block-list .site-layer-select')&&document.getElementById('site-canvas')?.contentDocument?.readyState==='complete'`);
            assert.equal(await evaluate(`document.querySelectorAll('.studio-drag-handle,[data-studio-drag-source]').length`), 0);
            assert.equal(await evaluate(`document.getElementById('site-canvas').contentDocument.querySelectorAll('[data-studio-block-handle]').length`), 0);
            assert.equal(await evaluate(`[...document.querySelectorAll('[data-add-block]')].every(button=>button.disabled)`), true);
            assert.equal(await evaluate(`document.getElementById('site-canvas').contentDocument.querySelectorAll('[contenteditable]').length`),0);
            assert.deepEqual(await evaluate('window.__errors'), []);
        });
    } finally { socket?.close(); chrome.kill(); await new Promise(resolve => server.close(resolve)); }
});
