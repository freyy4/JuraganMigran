import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';

const chromePath = process.env.SITE_QA_CHROME;
const fixtureDirectory = process.env.SITE_QA_FIXTURES;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

test('funnel studio supports canvas editing, drag and drop, form logic and responsive standalone layout', {skip: !chromePath || !fixtureDirectory}, async t => {
    const publicRoot = path.resolve('public');
    const editorFixture = fs.readFileSync(path.join(fixtureDirectory, 'jm-funnel-editor.html'), 'utf8');
    const previewFixture = fs.readFileSync(path.join(fixtureDirectory, 'jm-funnel-landing.html'), 'utf8');
    const previews=Object.fromEntries(['landing','form','thank_you'].map(page=>[page,fs.readFileSync(path.join(fixtureDirectory,`jm-funnel-${page}.html`),'utf8')]));
    let origin;
    const rewrite = html => html.replaceAll('https://juraganmigran.my.id', origin).replaceAll('file:///var/www/juraganmigran/public/', `${origin}/`).replaceAll('file:///tmp/jm-v811-preview.html', `${origin}/preview`);
    const server = http.createServer((request, response) => {
        if (request.url === '/editor' || request.url === '/preview' || request.url === '/readonly') {
            response.setHeader('Content-Type', 'text/html');
            response.end(rewrite(request.url === '/preview' ? previewFixture : request.url === '/readonly' ? editorFixture.replace('data-editable="1"', 'data-editable="0"') : editorFixture));
            return;
        }
        if(request.url.startsWith('/funnel-media/')){response.setHeader('Content-Type','image/png');response.end(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=','base64'));return;}
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
            const result = await send('Runtime.evaluate', {expression:`eval(${JSON.stringify(expression)})`, returnByValue: true, awaitPromise: true});
            if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails));
            return result.result.value;
        };
        const waitFor = async expression => {
            for (let attempt = 0; attempt < 100; attempt++) { if (await evaluate(expression)) return; await delay(50); }
            throw Error(`Timed out: ${expression}; ${JSON.stringify(await evaluate(`({errors:window.__errors,status:document.getElementById('canvas-render-status')?.textContent})`))}`);
        };
        await send('Page.enable');
        await send('Page.addScriptToEvaluateOnNewDocument', {source: `
            window.__errors=[];window.__saves=[];window.__previewIds=[];window.__previewFixtures=${JSON.stringify(Object.fromEntries(Object.entries(previews).map(([page,html])=>[page,rewrite(html)])))};
            addEventListener('error',event=>window.__errors.push(event.message));
            window.fetch=async(url,options={})=>{
                const body=options.body;
                if(String(url).includes('live-preview')){
                    if(!body.get('vacancy_id'))return Response.json({message:'The vacancy id field is required.'},{status:422});window.__previewIds.push(body.get('vacancy_id'));
                    const schema=JSON.parse(body.get('schema')),page=body.get('page')||'landing',doc=new DOMParser().parseFromString(window.__previewFixtures[page],'text/html');
                    if(page==='thank_you'){doc.querySelector('[data-site-field=title]').textContent=schema.thank_you.headline;doc.querySelector('[data-site-field=text]').textContent=schema.thank_you.text;if(schema.thank_you.button_text)doc.querySelector('.whatsapp').textContent=schema.thank_you.button_text;}
                    else{
                        const templates=new Map([...doc.querySelectorAll('[data-editor-block]')].map(node=>[node.dataset.editorBlock,node.cloneNode(true)]));
                        const container=doc.querySelector('.landing-stacked');if(container)container.replaceChildren();
                        schema.blocks.forEach(block=>{
                            if(block.hidden)return;
                            let node=templates.get(block.id);
                            if(!node){node=doc.createElement('section');node.dataset.editorBlock=block.id;node.className='landing-block';const title=doc.createElement('h2');title.dataset.siteField='title';const text=doc.createElement('p');text.dataset.siteField='text';node.append(title,text);}
                            const title=node.querySelector('[data-site-field=title]'),text=node.querySelector('[data-site-field=text]');if(title)title.textContent=block.title||'';if(text)text.textContent=block.text||'';
                            const button=node.querySelector('[data-site-field=label]');if(button&&block.button_text)button.textContent=block.button_text;
                            const image=node.querySelector('img');if(image&&block.url){image.src=block.url;image.style.cssText='width:'+(block.image_width||100)+'%;height:'+(block.image_height||240)+'px;border-radius:'+(block.image_radius||0)+'px;object-fit:'+(block.image_fit||'cover');}
                            if(container)container.append(node);else if(block.type==='form'){doc.querySelector('[data-editor-block]')?.replaceWith(node);}
                        });
                        const application=doc.querySelector('#application-form'),stepTemplates=new Map([...doc.querySelectorAll('[data-step]')].map(node=>[node.dataset.step,node.cloneNode(true)])),fieldTemplates=new Map([...doc.querySelectorAll('[data-field]')].map(node=>[node.dataset.field,node.cloneNode(true)]));
                        application?.querySelectorAll('[data-step]').forEach(node=>node.remove());
                        schema.steps.forEach(step=>{
                            const node=stepTemplates.get(step.id)||doc.createElement('fieldset');node.dataset.step=step.id;node.querySelectorAll('[data-field]').forEach(field=>field.remove());let legend=node.querySelector('legend');if(!legend){legend=doc.createElement('legend');node.prepend(legend);}legend.textContent=step.title;
                            schema.fields.filter(field=>field.step===step.id).forEach(field=>{let fieldNode=fieldTemplates.get(field.key);if(!fieldNode){fieldNode=doc.createElement('div');fieldNode.dataset.field=field.key;fieldNode.className='application-field';const title=doc.createElement('span');title.dataset.siteField='title';fieldNode.append(title,doc.createElement('input'));}fieldNode.querySelector('[data-site-field=title]').textContent=field.label;node.append(fieldNode);});application?.insertBefore(node,application.querySelector('#form-review'));
                        });
                    }
                    return new Response(doc.documentElement.outerHTML,{headers:{'Content-Type':'text/html'}});
                }
                if(String(url).includes('readiness'))return Response.json({ready:true,issues:[{severity:'success',message:'Siap dipublikasikan'}]});
                if(options.method==='POST'){
                    if(window.__failNextSave){window.__failNextSave=false;return Response.json({message:'Draft berubah di tab lain.'},{status:409});}
                    window.__saves.push({schema:JSON.parse(body.get('schema')),name:body.get('name'),vacancy_id:body.get('vacancy_id'),draft_token:body.get('draft_token')});const token='saved-'+window.__saves.length;
                    window.__saving=true;await new Promise(resolve=>setTimeout(resolve,window.__saveDelay||0));window.__saving=false;
                    return Response.json({schema:JSON.parse(body.get('schema')),draft_token:token,message:'Draft tersimpan'});
                }
                if(String(url).includes('funnel-vacancies'))return Response.json({data:[{id:999,label:'Lowongan pengganti aktif'}]});
                return Response.json({data:[]});
            };`});
        await send('Emulation.setDeviceMetricsOverride', {width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await send('Page.navigate', {url:`${origin}/editor`});
        const waitCanvas=()=>waitFor(`document.querySelector('.canvas-public-viewport iframe')?.contentDocument?.querySelector('[data-site-block]')&&document.getElementById('builder-form').dataset.previewPending==='false'`);
        await waitCanvas();
        const schema=()=>evaluate(`JSON.parse(document.getElementById('schema').value)`);
        const saved=()=>evaluate(`window.__saves.at(-1)?.schema`);
        const click=selector=>evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
        const framePoint=async(selector,edge=.5)=>evaluate(`(()=>{const frame=document.querySelector('.canvas-public-viewport iframe'),node=frame.contentDocument.querySelector(${JSON.stringify(selector)}),viewport=document.getElementById('funnel-viewport');let r=frame.getBoundingClientRect(),s=r.width/frame.offsetWidth,b=node.getBoundingClientRect();viewport.scrollTop+=r.top+b.top*s-viewport.getBoundingClientRect().top-100;r=frame.getBoundingClientRect();b=node.getBoundingClientRect();return{x:r.left+(b.left+Math.min(b.width/2,120))*s,y:r.top+(b.top+b.height*${edge})*s};})()`);
        const parentPoint=selector=>evaluate(`(()=>{const r=document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height/2};})()`);
        const doubleClick=async selector=>{const p=await framePoint(selector);for(const count of [1,2]){await send('Input.dispatchMouseEvent',{type:'mousePressed',...p,button:'left',buttons:1,clickCount:count});await send('Input.dispatchMouseEvent',{type:'mouseReleased',...p,button:'left',buttons:0,clickCount:count});}};
        const drag=async(from,to,{touch=false,cancel=false}={})=>{
            if(touch)await send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{...from,id:1}]});else await send('Input.dispatchMouseEvent',{type:'mousePressed',...from,button:'left',buttons:1,clickCount:1});
            for(let i=1;i<=12;i++){const p={x:from.x+(to.x-from.x)*i/12,y:from.y+(to.y-from.y)*i/12};if(touch)await send('Input.dispatchTouchEvent',{type:'touchMove',touchPoints:[{...p,id:1}]});else await send('Input.dispatchMouseEvent',{type:'mouseMoved',...p,buttons:1});await delay(20);}
            const marker=await evaluate(`!!document.querySelector('.studio-drop-before,.studio-drop-after')||!!document.querySelector('.canvas-public-viewport iframe').contentDocument.getElementById('funnel-drop-marker')`);
            if(cancel)await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            if(touch)await send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});else await send('Input.dispatchMouseEvent',{type:'mouseReleased',...to,button:'left',buttons:0,clickCount:1});
            return marker;
        };
        await t.test('properties start visible and can be hidden without reopening on selection',async()=>{
            const width=await evaluate(`document.getElementById('funnel-viewport').clientWidth`);
            assert.notEqual(await evaluate(`getComputedStyle(document.getElementById('funnel-properties-panel')).display`),'none');
            await click('[data-funnel-panel=close-properties]');await delay(100);
            const expanded=await evaluate(`document.getElementById('funnel-viewport').clientWidth`);assert.ok(expanded>width);
            await click('[data-layer-id=hero] .funnel-layer-select');await delay(100);
            assert.equal(await evaluate(`getComputedStyle(document.getElementById('funnel-properties-panel')).display`),'none');
            assert.equal(await evaluate(`document.getElementById('funnel-viewport').clientWidth`),expanded);
            await click('[data-funnel-panel=properties]');
            assert.equal(await evaluate(`document.querySelector('[data-funnel-panel=properties]').getAttribute('aria-expanded')`),'true');
        });
        await t.test('standalone layout fits desktop and dark mode without outer scroll',async()=>{
            assert.equal(await evaluate(`!!document.querySelector('.crm-layout,.crm-sidebar,.marketing-workspace')`),false);
            for(const theme of ['light','dark']){
                await evaluate(`document.documentElement.dataset.theme='${theme}'`);await delay(400);
                if(theme==='dark')assert.equal(await evaluate(`getComputedStyle(document.querySelector('#builder-properties input')).backgroundColor`),'rgb(43, 50, 62)');
                assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth&&document.documentElement.scrollHeight<=innerHeight`),true);
                assert.equal(await evaluate(`document.querySelector('#builder-workspace').getBoundingClientRect().height>700`),true);
                if(process.env.SITE_QA_OUTPUT){fs.mkdirSync(process.env.SITE_QA_OUTPUT,{recursive:true});fs.writeFileSync(path.join(process.env.SITE_QA_OUTPUT,`funnel-${theme}.png`),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));}
            }
        });
        await t.test('GrapesJS manages the existing component tree without changing schema or rendering its default UI',async()=>{
            assert.equal(await evaluate(`document.getElementById('builder-form').dataset.editorEngine`),'grapesjs');
            const details=await evaluate(`document.getElementById('builder-form').funnelEngine.inspect()`),original=await schema();
            assert.equal(details.version,'0.23.8');assert.ok(details.paletteCount>20);
            assert.deepEqual(details.pages.landing.map(item=>item.id),original.blocks.map(item=>item.id));
            assert.ok(details.pages.landing.every(item=>item.componentType==='jm-block'));
            assert.deepEqual(details.pages.form.filter(item=>item.kind==='field').map(item=>item.id),original.steps.flatMap(step=>original.fields.filter(field=>field.step===step.id).map(field=>field.key)));
            assert.deepEqual(original,await evaluate(`JSON.parse(document.getElementById('builder-original-schema').textContent)`));
            assert.equal(await evaluate(`document.querySelectorAll('.gjs-editor,.gjs-pn-panels,.gjs-cv-canvas').length`),0);
            assert.equal(await evaluate(`document.getElementById('builder-form').funnelEngine.commit('field','name','type','textarea')`),false);
            await evaluate(`const copy=document.getElementById('builder-form').funnelEngine.read('block','hero');copy.title='Mutasi luar'`);
            assert.deepEqual(await schema(),original);
            assert.equal(await evaluate(`document.getElementById('builder-form').funnelEngine.commit('block','hero','title','')`),true);
            assert.equal(await evaluate(`document.querySelector('[data-layer-id=hero] .funnel-layer-select').textContent`),'Pembuka');
            await click('#builder-undo');await waitCanvas();assert.deepEqual(await schema(),original);
        });
        await t.test('the standalone vacancy picker preserves its initial ID and updates preview and saved draft',async()=>{
            assert.ok(await evaluate(`Number(document.getElementById('vacancy_id').value)>0`));
            assert.equal(await evaluate(`document.getElementById('vacancy_id').tagName`),'INPUT');
            await click('[data-funnel-settings]');
            await evaluate(`const search=document.querySelector('[data-funnel-vacancy-picker] [role=combobox]');search.value='pengganti';search.dispatchEvent(new Event('input',{bubbles:true}))`);
            await waitFor(`document.querySelector('[data-funnel-vacancy-picker] [role=option]')`);
            await click('[data-funnel-vacancy-picker] [role=option]');await delay(1500);await waitCanvas();
            assert.equal(await evaluate(`document.getElementById('vacancy_id').value`),'999');
            assert.equal(await evaluate(`window.__previewIds.at(-1)`),'999');assert.equal(await evaluate(`window.__saves.at(-1).vacancy_id`),'999');
            await evaluate(`document.querySelector('.funnel-settings').open=false`);
        });
        if(process.env.SITE_QA_VACANCY_ONLY)return;
        await t.test('canvas height shrinks after content or device changes and wheel scrolls the workspace',async()=>{
            await evaluate(`const frame=document.querySelector('.canvas-public-viewport iframe'),extra=frame.contentDocument.createElement('div');extra.id='qa-tall-content';extra.style.height='4000px';frame.contentDocument.body.append(extra);document.querySelector('[data-funnel-zoom=in]').click()`);
            await delay(150);const expanded=await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetHeight`);assert.ok(expanded>4000);
            await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.getElementById('qa-tall-content').remove();document.querySelector('[data-funnel-zoom=out]').click()`);await delay(150);
            const compact=await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetHeight`);assert.ok(compact<expanded-2000,`Height must shrink from ${expanded}, got ${compact}; ${JSON.stringify(await evaluate(`(()=>{const f=document.querySelector('.canvas-public-viewport iframe'),d=f.contentDocument;return{body:d.body.getBoundingClientRect().toJSON(),bodyStyle:d.defaultView.getComputedStyle(d.body).cssText,children:[...d.body.children].map(n=>({tag:n.tagName,id:n.id,height:n.getBoundingClientRect().height,position:d.defaultView.getComputedStyle(n).position})),frame:f.offsetHeight}})()`))}`);
            await click('button[data-device="390px"]');await delay(150);const mobileHeight=await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetHeight`);
            await click('button[data-device="100%"]');await delay(150);const desktopHeight=await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetHeight`);assert.ok(desktopHeight<mobileHeight,`Desktop ${desktopHeight} must shrink from mobile ${mobileHeight}`);
            for(let i=0;i<7;i++)await click('[data-funnel-zoom=in]');await evaluate(`document.getElementById('funnel-viewport').scrollTop=0`);
            const point=await framePoint('[data-editor-block=hero]',.5);await send('Input.dispatchMouseEvent',{type:'mouseWheel',...point,deltaX:0,deltaY:240});await delay(200);
            assert.ok(await evaluate(`document.getElementById('funnel-viewport').scrollTop>0`));assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentWindow.scrollY`),0);
            const anchor=await evaluate(`(()=>{const r=document.getElementById('funnel-viewport').getBoundingClientRect();return{x:r.left+r.width/2,y:r.top+r.height*.4}})()`);
            const logical=()=>evaluate(`(()=>{const f=document.querySelector('.canvas-public-viewport iframe'),r=f.getBoundingClientRect(),s=r.width/f.offsetWidth;return{x:(${anchor.x}-r.left)/s,y:(${anchor.y}-r.top)/s,scale:s}})()`);
            await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.addEventListener('wheel',event=>window.__qaWheel={ctrl:event.ctrlKey,delta:event.deltaY}, {once:true})`);const prior=await logical();await send('Input.dispatchMouseEvent',{type:'mouseWheel',...anchor,modifiers:2,deltaX:0,deltaY:-80});await delay(150);const next=await logical();assert.ok(next.scale>prior.scale,JSON.stringify({prior,next,anchor,details:await evaluate(`({wheel:window.__qaWheel,target:document.elementFromPoint(${anchor.x},${anchor.y})?.tagName,errors:window.__errors})`)}));assert.ok(Math.abs(next.x-prior.x)<3&&Math.abs(next.y-prior.y)<3,'Zoom must preserve the canvas point under the cursor');assert.equal(await evaluate(`visualViewport.scale`),1);
            await click('[data-builder-page=thank_you]');await waitCanvas();assert.equal(await evaluate(`document.getElementById('funnel-viewport').scrollTop`),0);
            await click('[data-builder-page=landing]');await waitCanvas();await click('#funnel-zoom');
            if(process.env.SITE_QA_OUTPUT)fs.writeFileSync(path.join(process.env.SITE_QA_OUTPUT,'canvas-fit.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
        });
        await t.test('artboard resize follows pointer at fitted zoom and leaves page data unchanged',async()=>{
            const original=await schema();
            const start=await parentPoint('[data-artboard-resize=right]');
            const scale=await evaluate(`(()=>{const f=document.querySelector('.canvas-public-viewport iframe');return f.getBoundingClientRect().width/f.offsetWidth})()`);
            await send('Input.dispatchMouseEvent',{type:'mousePressed',...start,button:'left',clickCount:1});
            await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:start.x-60,y:start.y,button:'left',buttons:1});
            const edge=await parentPoint('[data-artboard-resize=right]');assert.ok(Math.abs(edge.x-(start.x-60))<3,'Resize handle should follow the pointer');
            await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:start.x-60,y:start.y,button:'left',clickCount:1});
            assert.ok(Math.abs(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`)-(1440-60/scale))<3);
            await evaluate(`const input=document.querySelector('[data-artboard-width]');input.value='640';input.dispatchEvent(new Event('change',{bubbles:true}))`);
            assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`),640);
            const left=await parentPoint('[data-artboard-resize=left]');
            await send('Input.dispatchMouseEvent',{type:'mousePressed',...left,button:'left',clickCount:1});
            await send('Input.dispatchMouseEvent',{type:'mouseMoved',x:left.x+25,y:left.y,button:'left',buttons:1});
            await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
            await send('Input.dispatchMouseEvent',{type:'mouseReleased',x:left.x+25,y:left.y,button:'left',clickCount:1});
            assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`),640);
            await evaluate(`document.querySelector('[data-artboard-resize=right]').focus()`);
            await send('Input.dispatchKeyEvent',{type:'keyDown',key:'ArrowRight',code:'ArrowRight',windowsVirtualKeyCode:39});
            assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`),650);
            assert.deepEqual(await schema(),original);
            await click('button[data-device="100%"]');await waitCanvas();await click('#funnel-zoom');
            await delay(150);assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`),1440,JSON.stringify(await evaluate(`({device:document.getElementById('device').value,errors:window.__errors,buttons:[...document.querySelectorAll('button[data-device]')].map(n=>[n.dataset.device,n.getAttribute('aria-pressed')])})`)));
        });
        if(process.env.SITE_QA_CANVAS_ONLY)return;
        await t.test('inline text edits save, undo and Escape restore the exact draft',async()=>{
            const original=(await schema()).blocks[0].title;
            await doubleClick('[data-editor-block=hero] [data-site-field=title]');
            assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-site-field=title]').isContentEditable`),true);
            await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-site-field=title]').textContent='Edit teks funnel langsung'`);
            await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
            await delay(1500);await waitCanvas();assert.equal((await saved()).blocks[0].title,'Edit teks funnel langsung');assert.equal(await evaluate(`document.getElementById('builder-form').funnelEngine.read('block','hero').title`),'Edit teks funnel langsung');assert.equal(await evaluate(`document.getElementById('builder-feedback').hidden`),true);
            await click('#builder-undo');await delay(1500);await waitCanvas();assert.equal((await saved()).blocks[0].title,original);
            await doubleClick('[data-editor-block=hero] [data-site-field=title]');await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-site-field=title]').textContent='Batal'`);await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});assert.equal((await schema()).blocks[0].title,original);
        });
        await t.test('layers reorder with pointer and touch, and Escape cancels a drag',async()=>{
            const original=(await schema()).blocks.map(b=>b.id);
            const from=await parentPoint('[data-layer-id=hero] .studio-drag-handle'),to=await parentPoint('[data-layer-id=form] .funnel-layer-select');to.y+=12;
            assert.equal(await drag(from,to),true);await delay(1500);await waitCanvas();assert.deepEqual((await saved()).blocks.map(b=>b.id),['form','hero']);
            await click('#builder-undo');await delay(1500);await waitCanvas();
            assert.equal(await drag(await parentPoint('[data-layer-id=hero] .studio-drag-handle'),to,{cancel:true}),true);assert.deepEqual((await schema()).blocks.map(b=>b.id),original);
            assert.equal(await drag(await parentPoint('[data-layer-id=hero] .studio-drag-handle'),to,{touch:true}),true);await delay(1500);await waitCanvas();assert.deepEqual((await saved()).blocks.map(b=>b.id),['form','hero']);
            await click('#builder-undo');await delay(1500);await waitCanvas();
        });
        await t.test('blocks can be dragged directly from the artboard at a custom width',async()=>{
            await evaluate(`const input=document.querySelector('[data-artboard-width]');input.value='800';input.dispatchEvent(new Event('change',{bubbles:true}))`);
            const from=await framePoint('[data-editor-block=hero] [data-site-field=title]');
            const to=await framePoint('[data-editor-block=form]',.8);
            assert.equal(await drag(from,to),true);await delay(1500);await waitCanvas();
            assert.deepEqual((await saved()).blocks.map(block=>block.id),['form','hero']);
            assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`),800);
            await click('#builder-undo');await delay(1500);await waitCanvas();await click('button[data-device="100%"]');await click('#funnel-zoom');
        });
        await t.test('palette blocks drag into the scaled canvas and undo removes one insertion',async()=>{
            await click('[data-funnel-left=assets]');await click('[data-funnel-asset-tab=blocks]');
            const target=await framePoint('[data-editor-block=hero]',.2),from=await parentPoint('[data-add-block=rich_text]');
            assert.equal(await drag(from,target),true);await delay(1500);await waitCanvas();assert.equal((await saved()).blocks[0].type,'rich_text');assert.equal((await saved()).blocks.length,3);
            await click('#builder-undo');await delay(1500);await waitCanvas();assert.equal((await saved()).blocks.length,2);await click('[data-funnel-left=layers]');
            await click('[data-layer-id=hero] .funnel-layer-select');await evaluate(`document.body.dispatchEvent(new KeyboardEvent('keydown',{key:'d',ctrlKey:true,bubbles:true,cancelable:true}))`);await delay(1500);await waitCanvas();assert.equal((await saved()).blocks.length,3);await evaluate(`document.body.dispatchEvent(new KeyboardEvent('keydown',{key:'Delete',bubbles:true,cancelable:true}))`);await delay(1500);await waitCanvas();assert.equal((await saved()).blocks.length,2);await click('#builder-undo');await delay(1500);await waitCanvas();assert.equal((await saved()).blocks.length,3);await click('#builder-undo');await delay(1500);await waitCanvas();assert.equal((await saved()).blocks.length,2);
        });
        await t.test('form fields move between steps and protected identity stays required',async()=>{
            await click('[data-builder-page=form]');await waitCanvas();
            await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('legend').click()`);assert.equal(await evaluate(`document.querySelector('#builder-properties input').value`),'Data Dasar');
            const from=await parentPoint('[data-layer-id=email] .studio-drag-handle'),to=await parentPoint('[data-layer-id=basic] .funnel-layer-select');
            assert.equal(await drag(from,to),true);await delay(1500);await waitCanvas();assert.equal((await saved()).fields.find(f=>f.key==='email').step,'basic');assert.equal(await evaluate(`document.getElementById('builder-form').funnelEngine.read('field','email').step`),'basic');
            await doubleClick('[data-field=name] [data-site-field=title]');await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-field=name] [data-site-field=title]').textContent='Nama pendaftar'`);await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});await delay(1500);await waitCanvas();assert.equal((await saved()).fields.find(f=>f.key==='name').label,'Nama pendaftar');assert.equal((await saved()).fields.find(f=>f.key==='name').required,true);
            await doubleClick('[data-field=name] [data-site-field=title]');await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-field=name] [data-site-field=title]').textContent=''`);await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});assert.equal((await schema()).fields.find(field=>field.key==='name').label,'Nama pendaftar');
            await click('#add-rule');assert.equal(await evaluate(`document.getElementById('funnel-rules').open`),true);assert.equal((await schema()).rules.length,1);await click('#funnel-rules [data-funnel-close]');await click('#builder-undo');await delay(1500);await waitCanvas();
        });
        await t.test('deleting the active step does not send new fields to a missing step',async()=>{
            await click('[data-layer-id=details] .funnel-layer-select');
            await evaluate(`[...document.querySelectorAll('#builder-properties button')].find(node=>node.textContent==='Hapus langkah').click()`);
            await delay(1500);await waitCanvas();assert.equal((await saved()).steps.length,1);
            await click('[data-funnel-left=assets]');await click('[data-funnel-asset-tab=fields]');
            await evaluate(`[...document.querySelectorAll('#field-library button')].find(node=>node.textContent==='Teks singkat').click()`);
            await delay(1500);await waitCanvas();const current=await saved();assert.equal(current.fields.at(-1).step,'basic');assert.equal(current.fields.length,4);
            await click('#builder-undo');await delay(1500);await waitCanvas();await click('#builder-undo');await delay(1500);await waitCanvas();assert.equal((await saved()).steps.length,2);
            await click('[data-funnel-left=layers]');
        });
        await t.test('canvas refresh preserves scroll and form column layout',async()=>{
            await click('[data-layer-id=email] .funnel-layer-select');
            for(let i=0;i<6;i++)await click('[data-funnel-zoom=in]');
            const top=await evaluate(`document.getElementById('funnel-viewport').scrollTop=300;document.getElementById('funnel-viewport').scrollTop`);assert.equal(top,300);
            await evaluate(`const input=[...document.querySelectorAll('#builder-properties label')].find(node=>node.firstChild.textContent==='Placeholder').querySelector('input');input.value='Alamat email aktif';input.dispatchEvent(new Event('input',{bubbles:true}))`);
            await delay(1500);await waitCanvas();assert.equal(await evaluate(`Math.abs(document.getElementById('funnel-viewport').scrollTop-300)<3`),true);
            assert.equal(await evaluate(`getComputedStyle(document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-step]')).display`),'grid');await click('#funnel-zoom');
        });
        await t.test('manual Save waits for the latest edit and reuses the fresh draft token',async()=>{
            await evaluate(`document.getElementById('auto-save').checked=false;document.getElementById('auto-save').dispatchEvent(new Event('change'));window.__saveDelay=650;const name=document.querySelector('[name=name]');name.value='Versi lambat A';name.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('save-draft').click()`);
            await waitFor('window.__saving');
            await evaluate(`const name=document.querySelector('[name=name]');name.value='Versi terbaru B';name.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('builder-form').requestSubmit()`);
            await waitFor(`!window.__saving&&window.__saves.at(-1)?.name==='Versi terbaru B'&&document.getElementById('save-state').textContent==='Tersimpan'`);
            const requests=await evaluate(`window.__saves.slice(-2)`);assert.deepEqual(requests.map(item=>item.name),['Versi lambat A','Versi terbaru B']);assert.notEqual(requests[0].draft_token,requests[1].draft_token);
            await evaluate(`window.__saveDelay=0;document.getElementById('auto-save').checked=true;document.getElementById('auto-save').dispatchEvent(new Event('change'))`);
        });
        await t.test('image selection exposes upload controls and Style changes persist',async()=>{
            await click('[data-builder-page=landing]');await waitCanvas();await doubleClick('[data-editor-block=hero] [data-site-image]');
            assert.equal(await evaluate(`!!document.querySelector('#builder-properties input[type=file]')`),true);
            await click('[data-property-tab=style]');
            await evaluate(`const labels=[...document.querySelectorAll('#builder-style-panel label')];const input=labels.find(label=>label.textContent.startsWith('Lebar gambar')).querySelector('input');input.value='65';input.dispatchEvent(new Event('input',{bubbles:true}));`);
            await delay(1500);await waitCanvas();assert.equal((await saved()).blocks.find(b=>b.id==='hero').image_width,65);
        });
        await t.test('submit and WhatsApp button labels can be edited without submitting the preview',async()=>{
            for(const [page,selector,label] of [['form','#form-submit','Kirim data saya'],['thank_you','.whatsapp','Hubungi tim kami']]){
                await click('[data-builder-page='+page+']');await waitCanvas();
                await doubleClick(selector);
                assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('${selector}').isContentEditable`),true);
                await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('${selector}').textContent='${label}'`);
                await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});
                await delay(1500);await waitCanvas();
                const draft=await saved();assert.equal(page==='form'?draft.blocks.find(block=>block.type==='form').button_text:draft.thank_you.button_text,label);
                if(page==='thank_you')assert.equal(await evaluate(`document.getElementById('thank-button').value`),label);
            }
        });
        await t.test('Thank You content edits retain post-submit settings',async()=>{
            await click('[data-builder-page=thank_you]');await waitCanvas();await doubleClick('[data-site-field=title]');await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelector('[data-site-field=title]').textContent='Terima kasih sudah mendaftar'`);await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Enter',code:'Enter',windowsVirtualKeyCode:13});await delay(1500);await waitCanvas();assert.equal((await saved()).thank_you.headline,'Terima kasih sudah mendaftar');assert.equal((await saved()).post_submit,'THANK_YOU_WITH_WHATSAPP_CTA');
            await click('#publish-draft');await waitFor(`!document.getElementById('confirm-publish').disabled`);await click('#cancel-publish');
        });
        await t.test('history, schema and tracking remain accessible without leaving the studio',async()=>{
            for(const id of ['funnel-history','funnel-schema','funnel-tracking']){
                await click(`[data-funnel-dialog="${id}"]`);assert.equal(await evaluate(`document.getElementById('${id}').open`),true);
                if(id==='funnel-tracking'){assert.equal(await evaluate(`!!document.querySelector('#funnel-tracking [data-funnel-tracking-form]')`),true);assert.equal(await evaluate(`document.getElementById('builder-form').dataset.trackingEditable`),'1');}
                await click(`#${id} [data-funnel-close]`);
            }
        });
        await t.test('mobile panels and device zoom remain inside the viewport',async()=>{
            await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});await delay(250);await click('[data-funnel-panel=pages]');assert.equal(await evaluate(`getComputedStyle(document.getElementById('builder-library')).display!=='none'`),true);await click('[data-funnel-panel=close]');await click('button[data-device="390px"]');await delay(200);assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth&&document.documentElement.scrollHeight<=innerHeight`),true);assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').offsetWidth`),390);
            await click('[data-funnel-zoom=in]');assert.equal(await evaluate(`parseInt(document.getElementById('funnel-zoom').textContent)>70`),true);await click('#funnel-zoom');
            if(process.env.SITE_QA_OUTPUT)fs.writeFileSync(path.join(process.env.SITE_QA_OUTPUT,'funnel-mobile.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));
        });
        await t.test('conflicting saves keep local edits and stop automatic retries',async()=>{
            const before=await evaluate(`window.__saves.length`);
            await evaluate(`window.__failNextSave=true;const input=document.querySelector('[name=name]');input.value='Draft lokal belum tersimpan';input.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('builder-form').requestSubmit()`);
            await waitFor(`document.getElementById('save-state').textContent.includes('Konflik draft')`);
            await evaluate(`const input=document.querySelector('[name=name]');input.value='Draft lokal tetap aman';input.dispatchEvent(new Event('input',{bubbles:true}))`);await delay(1600);assert.equal(await evaluate(`window.__saves.length`),before);assert.equal(await evaluate(`document.querySelector('[name=name]').value`),'Draft lokal tetap aman');assert.equal(await evaluate(`!!document.getElementById('funnel-export')`),true);
        });
        assert.deepEqual(await evaluate('window.__errors'),[]);
        await t.test('read-only users can inspect without drag handles or editing text',async()=>{
            await send('Page.navigate',{url:`${origin}/readonly`});await waitCanvas();await waitFor(`document.querySelector('#funnel-layer-list .funnel-layer-select')`);assert.equal(await evaluate(`document.querySelectorAll('#funnel-layer-list .studio-drag-handle').length`),0);assert.equal(await evaluate(`document.querySelector('.canvas-public-viewport iframe').contentDocument.querySelectorAll('[data-funnel-handle],[contenteditable]').length`),0);assert.equal(await evaluate(`[...document.querySelectorAll('[data-add-block]')].every(node=>node.disabled)`),true);assert.equal(await evaluate(`document.getElementById('builder-form').funnelEngine.commit('block','hero','title','Tidak diizinkan')`),false);assert.equal(await evaluate(`document.getElementById('builder-form').funnelEngine.move('block','hero',{kind:'block',id:'form',after:true})`),false);assert.deepEqual(await evaluate('window.__errors'),[]);
        });
    } finally {
        socket?.close();chrome.kill();await new Promise(resolve=>server.close(resolve));
    }
});
