import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath=process.env.SITE_QA_CHROME, fixtures=process.env.SITE_QA_FIXTURES;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
test('PRD 8.13 settings, builder assignment and consent-gated public rules',{skip:!chromePath||!fixtures},async t=>{
    let origin;
    const rewrite=html=>html.replaceAll('https://juraganmigran.my.id',origin);
    const fixtureNames={'/settings':'jm-tracking-settings.html','/editor':'jm-tracking-editor.html','/public':'jm-tracking-public.html'};
    const server=http.createServer((request,response)=>{
        const url=new URL(request.url,'http://localhost');
        if(fixtureNames[url.pathname]){response.setHeader('Content-Type','text/html');response.end(rewrite(fs.readFileSync(path.join(fixtures,fixtureNames[url.pathname]),'utf8')));return;}
        const file=path.resolve('public','.'+url.pathname);
        if((file.startsWith(path.resolve('public/css')+'/')||file.startsWith(path.resolve('public/js')+'/'))&&fs.existsSync(file)){response.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':'text/css');response.end(fs.readFileSync(file));return;}
        response.writeHead(404);response.end();
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;
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
        await send('Network.enable');await send('Network.setBlockedURLs',{urls:['*facebook.net*','*tiktok.com*','*googletagmanager.com*','*fonts.googleapis.com*']});
        await send('Page.addScriptToEvaluateOnNewDocument',{source:`
            window.__errors=[];window.__requests=[];window.__vendor=[];window.fbq=(...args)=>window.__vendor.push(args);
            addEventListener('error',event=>window.__errors.push(event.message));
            window.fetch=async(url,options={})=>{
                let body=options.body instanceof FormData?Object.fromEntries(options.body):JSON.parse(options.body||'{}');window.__requests.push({url:String(url),body});
                if(String(url).includes('live-preview'))return new Response(${JSON.stringify(rewrite(fs.readFileSync(path.join(fixtures,'jm-tracking-public.html'),'utf8')))},{headers:{'Content-Type':'text/html'}});
                if(String(url).includes('/api/funnels/')&&String(url).endsWith('/tracking/test')){if(window.__trackingTestConflict){window.__trackingTestConflict=false;return Response.json({message:'Draft telah berubah. Tunggu penyimpanan selesai dan coba kembali.'},{status:409});}const schema=JSON.parse(body.schema),rule=schema.tracking.rules.find(rule=>rule.id===body.rule_id);return Response.json({is_test:true,delivery:'LOCAL_VALIDATION_ONLY',event_id:'11111111-1111-4111-8111-111111111111',canonical_event:rule?.canonical_event||'PAGE_VIEW',summary:{issues:[]},mappings:[{destination_id:1,event:rule?.canonical_event||'PAGE_VIEW',provider_event:'ViewContent'}],message:'Event uji tercatat di Diagnostics. Tidak membuat lead, mengirim konversi vendor, atau mengubah draft.'});}
                if(String(url).includes('readiness'))return Response.json({ready:true,issues:[]});
                if(String(url).includes('consent'))return Response.json(body);
                if(String(url).includes('/tracking/events'))return Response.json({ok:true,event_id:body.event_id,destinations:[{id:1,provider:'META',pixel:'123456789',event:body.event,provider_event:body.event==='PAGE_VIEW'?'PageView':'ViewContent'}]});
                if(String(url).includes('/settings/tracking'))return Response.json({id:1,duplicate:true});
                return Response.json({schema:body.schema?JSON.parse(body.schema):{},draft_token:'saved',message:'Tersimpan'});
            };
        `});
        const click=selector=>evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
        const navigate=async page=>{await send('Page.navigate',{url:origin+page});await waitFor('document.readyState==="complete"');};
        const screenshot=async name=>{if(process.env.SITE_QA_OUTPUT)fs.writeFileSync(path.join(process.env.SITE_QA_OUTPUT,name+'.png'),Buffer.from((await send('Page.captureScreenshot',{format:'png'})).data,'base64'));};
        await t.test('settings, guided drawers and 42px controls fit desktop, tablet and mobile',async()=>{
            for(const width of [1440,1024,390]){
                await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});await navigate('/settings');await waitFor('!!document.querySelector("[data-tracking-settings]")');
                assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,`No outer horizontal scroll at ${width}`);
                if(width<768){await click('[data-tracking-filter-open]');assert.equal(await evaluate(`document.querySelector('[data-tracking-filter-dialog]').open`),true);}
                assert.ok(await evaluate('document.querySelector(".tracking-filter select").getBoundingClientRect().height')>=(width<768?44:42));
                if(width<768)await click('[data-tracking-filter-close]');
                await click('[data-tracking-help]');assert.equal(await evaluate('document.getElementById("tracking-help").open'),true);
                await click('[data-help-platform=TIKTOK]');assert.equal(await evaluate('document.querySelector("[data-help-pane=TIKTOK]").hidden'),false);
                await screenshot('tracking-help-'+width);await click('#tracking-help [data-tracking-close]');
                await click('[data-destination-add]');assert.equal(await evaluate('document.getElementById("tracking-destination-drawer").open'),true);
                assert.equal(await evaluate('document.querySelector("[data-destination-form] [name=access_token]").value'),'');
                await screenshot('tracking-drawer-'+width);await click('#tracking-destination-drawer [data-tracking-close]');
                await screenshot('tracking-settings-'+width);
                if(width===1440){await evaluate(`document.documentElement.dataset.theme='dark'`);await click('[data-destination-add]');assert.notEqual(await evaluate(`getComputedStyle(document.getElementById('tracking-destination-drawer')).backgroundColor`),'rgb(255, 255, 255)');await screenshot('tracking-dark-1440');await click('#tracking-destination-drawer [data-tracking-close]');await evaluate(`document.documentElement.dataset.theme='light'`);}
            }
            await click('[data-destination-add]');await evaluate(`const form=document.querySelector('[data-destination-form]');form.elements.display_name.value='Pixel Baru';form.elements.pixel_reference.value='99999999';form.elements.domains.value='example.com';form.requestSubmit()`);
            await waitFor(`document.querySelector('[data-destination-message]').textContent.includes('ID ini')`);
            const request=await evaluate('window.__requests.at(-1)');assert.deepEqual(request.body.allowed_domains,['example.com']);assert.equal(request.body.browser_enabled,true);assert.equal(request.body.server_enabled,false);
            assert.deepEqual(await evaluate('window.__errors'),[]);
        });
        await t.test('tracking assignments apply through existing draft history and display editor-only badges',async()=>{
            await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});await navigate('/editor');
            await waitFor(`document.querySelector('.canvas-public-viewport iframe')?.contentDocument?.querySelector('[data-tracking-editor-badge]')`);
            await click('[data-funnel-dialog=funnel-tracking]');await waitFor(`document.getElementById('funnel-tracking').open`);
            assert.equal(await evaluate(`document.querySelector('[data-tracking-enabled]').checked`),true);
            await click('[data-section-tracking-add]');assert.equal(await evaluate(`document.querySelectorAll('.tracking-rule-row').length`),2);
            const originalSchema=await evaluate(`document.getElementById('schema').value`);
            await click('[data-section-tracking-test="hero-visible"]');await waitFor(`!document.querySelector('[data-funnel-tracking-test-result]').hidden`);
            assert.ok(await evaluate(`document.querySelector('[data-funnel-tracking-test-result]').textContent.includes('LOCAL_VALIDATION_ONLY')`));
            assert.equal(await evaluate(`window.__requests.at(-1).body.rule_id`),'hero-visible');assert.equal(await evaluate(`document.getElementById('schema').value`),originalSchema);
            await evaluate(`window.__trackingTestConflict=true`);await click('[data-funnel-tracking-test]');await waitFor(`document.querySelector('[data-funnel-tracking-feedback]').textContent.includes('Draft telah berubah')`);
            assert.equal(await evaluate(`document.querySelector('[data-funnel-tracking-test-result]').hidden`),true);assert.equal(await evaluate(`document.getElementById('schema').value`),originalSchema);
            await evaluate(`document.querySelector('[data-funnel-tracking-form]').requestSubmit()`);
            assert.equal(await evaluate(`JSON.parse(document.getElementById('schema').value).tracking.rules.length`),2);
            await click('#funnel-tracking [data-funnel-close]');await click('#builder-undo');
            assert.equal(await evaluate(`JSON.parse(document.getElementById('schema').value).tracking.rules.length`),1);
            assert.deepEqual(await evaluate('window.__errors'),[]);
        });
        await t.test('public events and scripts stay off before consent and never read form answers',async()=>{
            await navigate('/public');await waitFor(`document.querySelector('[data-tracking-consent]')&&!document.querySelector('[data-tracking-consent]').hidden`);
            assert.equal(await evaluate('window.__vendor.length'),0);assert.equal(await evaluate('window.__requests.length'),0);
            await click('[data-consent-essential]');await delay(100);assert.equal(await evaluate(`window.__vendor.filter(call=>call[0]!=='consent').length`),0);
            await click('[data-consent-open]');await click('[data-consent-ads]');await click('[data-consent-save]');await waitFor(`window.__vendor.some(call=>call[0]==='trackSingle')`);
            await waitFor(`window.__requests.some(request=>request.body.rule_id==='hero-visible')`);
            await evaluate(`const input=document.querySelector('[name="answers[name]"]');input.value='PRIVATE MEDICAL VALUE';input.dispatchEvent(new Event('input',{bubbles:true}))`);await delay(100);
            const requests=await evaluate('window.__requests');assert.ok(requests.some(request=>request.body.event==='FORM_START'));assert.ok(!JSON.stringify(requests).includes('PRIVATE MEDICAL VALUE'));assert.ok(!requests.some(request=>['LEAD_CREATED','FORM_SUBMIT'].includes(request.body.event)));
            await click('[data-consent-open]');await click('[data-consent-essential]');const count=await evaluate('window.__vendor.length');await evaluate(`document.querySelector('a').click()`);await delay(100);assert.equal(await evaluate('window.__vendor.length'),count);
            assert.deepEqual(await evaluate('window.__errors'),[]);
        });
    }finally{socket?.close();chrome.kill();await new Promise(resolve=>server.close(resolve));}
});
