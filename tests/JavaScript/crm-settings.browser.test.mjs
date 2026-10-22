import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath = process.env.SITE_QA_CHROME;
const fixtures = process.env.CRM_SETTINGS_QA_FIXTURES;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
test('CRM settings matches control center layout and supports tabs, help, routing and validation', {skip: !chromePath || !fixtures}, async () => {
    let origin; const routingRequests=[];
    const server = http.createServer(async (request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (['/settings', '/limited-settings'].includes(url.pathname)) {
            response.setHeader('Content-Type', 'text/html');
            const html = fs.readFileSync(path.join(fixtures, url.pathname.slice(1) + '.html'), 'utf8').replace(/data-initial-tab="[^"]*"/, 'data-initial-tab="'+(url.searchParams.get('tab') || 'channel')+'"');
            response.end(html.replaceAll('https://juraganmigran.my.id',origin).replaceAll('https:\\/\\/juraganmigran.my.id',origin)); return;
        }
        if (url.pathname === '/api/whatsapp/channels') {
            response.setHeader('Content-Type','application/json');
            response.end(JSON.stringify({ready:true,migrationTargets:[],channels:[{channel:{id:42,name:'JM - CS Utama',phone:'+6285353001209',provider:'QR_WEB_SESSION',routing:'ROUND_ROBIN',teamId:1,active:true,status:'CONNECTED'},state:'CONNECTED',health:'HEALTHY',lastHeartbeatAt:'2026-10-21 15:32:03',lastInboundAt:'2026-10-21 15:31:20',queueBacklog:0}]})); return;
        }
        if (url.pathname.endsWith('/routing')) {
            let body='';for await (const chunk of request)body+=chunk;
            routingRequests.push(JSON.parse(body));response.setHeader('Content-Type','application/json');response.end(JSON.stringify({channel:{}}));return;
        }
        if (url.pathname === '/settings/crm/tags' && request.method==='POST') {
            let body='';for await(const chunk of request)body+=chunk;
            response.writeHead(422,{'Content-Type':'application/json'});response.end(JSON.stringify({errors:{name:['Nama tag sudah digunakan.']}}));return;
        }
        const file = path.resolve('public', '.' + url.pathname);
        if ((file.startsWith(path.resolve('public/css') + '/') || file.startsWith(path.resolve('public/js') + '/')) && fs.existsSync(file)) {
            response.setHeader('Content-Type', file.endsWith('.js') ? 'text/javascript' : 'text/css'); response.end(fs.readFileSync(file)); return;
        }
        response.writeHead(404); response.end();
    });
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve)); origin = `http://127.0.0.1:${server.address().port}`;
    const chrome = spawn(chromePath, ['--headless', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=0', '--user-data-dir=/tmp/jm-crm-chrome-' + process.pid, 'about:blank'], {stdio:['ignore','ignore','pipe']});
    let socket;
    try {
        const endpoint = await new Promise((resolve, reject) => {
            let output = ''; const timeout = setTimeout(() => reject(Error('Browser startup timed out')), 15000);
            chrome.stderr.on('data', chunk => { output += chunk; const match = output.match(/DevTools listening on (ws:\/\/[^\s]+)/); if (match) { clearTimeout(timeout); resolve(match[1]); } });
            chrome.once('exit', code => { clearTimeout(timeout); reject(Error('Browser exited ' + code)); });
        });
        const target = (await (await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json`)).json())[0];
        socket = new WebSocket(target.webSocketDebuggerUrl); await new Promise(resolve => socket.addEventListener('open', resolve));
        let id = 0; const pending = new Map();
        socket.addEventListener('message', event => { const data = JSON.parse(event.data); if (pending.has(data.id)) { const p = pending.get(data.id); pending.delete(data.id); data.error ? p.reject(Error(JSON.stringify(data.error))) : p.resolve(data.result); } });
        const send = (method, params = {}) => new Promise((resolve, reject) => { const current = ++id; pending.set(current,{resolve,reject}); socket.send(JSON.stringify({id:current,method,params})); });
        const evaluate = async expression => { const result = await send('Runtime.evaluate', {expression,returnByValue:true,awaitPromise:true}); if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails)); return result.result.value; };
        const checkDark = async selectors => {
            const result = await evaluate(`(${JSON.stringify(selectors)}).map(selector => {const element=document.querySelector(selector); if(!element) return {selector,missing:true}; const style=getComputedStyle(element); return {selector,background:style.backgroundColor,color:style.color};})`);
            for (const item of result) {
                assert.equal(item.missing, undefined, item.selector + ' must exist');
                const channels = item.background.match(/\d+/g).map(Number);
                assert.ok(Math.max(...channels.slice(0,3)) < 110, item.selector + ' must have a dark surface: ' + item.background);
            }
        };
        const checkContrast = async selectors => {
            const pairs = await evaluate(`(${JSON.stringify(selectors)}).map(selector=>{const element=document.querySelector(selector);const style=getComputedStyle(element);return {selector,foreground:style.color,background:style.backgroundColor};})`);
            const luminance = color => {const channels=color.match(/\d+(?:\.\d+)?/g).slice(0,3).map(Number).map(channel=>{const value=channel/255;return value<=0.04045?value/12.92:((value+0.055)/1.055)**2.4;});return channels[0]*0.2126+channels[1]*0.7152+channels[2]*0.0722;};
            for(const pair of pairs){const foreground=luminance(pair.foreground),background=luminance(pair.background);assert.ok((Math.max(foreground,background)+0.05)/(Math.min(foreground,background)+0.05)>=4.5,pair.selector+' needs readable text contrast');}
        };
        await send('Page.enable');
        const navigate = async (page='settings',query='') => {
            await send('Page.navigate',{url:origin+'/'+page+query});
            for(let i=0;i<100;i++){await delay(50);if(await evaluate(`document.readyState==='complete' && !!document.querySelector('[data-settings-root]')?.dataset.activeTab`))return;}
            throw Error('Settings did not initialize');
        };
        const click = async selector => {
            const point = await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return {x:r.x+r.width/2,y:r.y+r.height/2}})()`);
            await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});
            await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});
        };
        const screenshot = async name => {const data=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(fixtures,name+'.png'),Buffer.from(data.data,'base64'));};
        for(const width of [1672,1366,900,390]) {
            await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
            await navigate('settings','?tab=channel');await delay(200);
            await evaluate(`localStorage.setItem('jm-crm-settings-'+document.body.dataset.uiUser+'-coach','seen');document.querySelector('[data-sc-coach]').hidden=true;`);
            assert.equal(await evaluate(`document.querySelectorAll('[data-sc-tab]').length`),7);
            assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),true,'No horizontal page overflow at '+width);
            if(width>=1280){
                assert.equal(await evaluate(`getComputedStyle(document.querySelector('.sc-channel-grid')).gridTemplateColumns.split(' ').length`),2);
                assert.equal(await evaluate(`getComputedStyle(document.querySelector('.sc-help')).display`),'block');
                assert.ok(await evaluate(`Array.from(document.querySelectorAll('.sc-help nav button')).every(button=>button.getBoundingClientRect().height >= 80)`),'Help rows must not overlap');
            }
            const heights=await evaluate(`Array.from(document.querySelectorAll('.sc-main input:not([type=radio]):not([type=checkbox]):not([type=hidden]),.sc-main select,.sc-main button')).filter(e=>e.getClientRects().length && !e.matches('[data-sc-variable]')).map(e=>Math.round(e.getBoundingClientRect().height))`);
            assert.ok(heights.every(height=>height >= (width<768?44:42)),'Controls must have uniform minimum heights at '+width+': '+heights);
            await screenshot('settings-'+width);
            const before=await evaluate(`performance.timeOrigin`);
            await click('[data-sc-tab=tags]');
            assert.equal(await evaluate(`document.querySelector('[data-sc-section=tags]').hidden`),false);
            assert.equal(await evaluate(`document.querySelector('[data-sc-section=channel]').hidden`),true);
            assert.equal(await evaluate(`performance.timeOrigin`),before,'Tab switches must not reload the page');
            assert.equal(await evaluate(`new URLSearchParams(location.search).get('tab')`),'tags');
            await click('[data-sc-tab=replies]');
            await evaluate(`document.querySelector('[data-sc-quick-builder] textarea').value='Halo {{nama}}, saya {{nama_cs}}';document.querySelector('[data-sc-quick-builder] textarea').dispatchEvent(new Event('input',{bubbles:true}));`);
            assert.equal(await evaluate(`document.querySelector('[data-sc-reply-preview]').textContent`),'Halo Siti, saya Tim CS');
            if(width<1280){await click('.sc-page-header [data-sc-help-toggle]');assert.equal(await evaluate(`getComputedStyle(document.querySelector('.sc-help')).display`),'block');await click('.sc-help>header [data-sc-help-toggle]');}
            await click('[data-theme-toggle]');
            await screenshot('settings-dark-'+width);
            assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),true);
            await click('[data-theme-toggle]');
        }
        await send('Emulation.setDeviceMetricsOverride',{width:1366,height:1000,deviceScaleFactor:1,mobile:false});await navigate('settings','?tab=routing');
        assert.equal(await evaluate(`document.querySelector('[data-settings-root]').dataset.activeTab`),'routing');
        await click('[data-sc-routing] input[value=LEAST_ACTIVE]');
        await evaluate(`document.querySelector('[data-sc-routing]').requestSubmit()`);await delay(200);
        assert.equal(routingRequests.at(-1)?.routing_method,'LEAST_ACTIVE');
        assert.equal(routingRequests.at(-1)?.prefer_existing_owner,true);
        await click('[data-sc-open=sc-routing-dialog]');assert.equal(await evaluate(`document.getElementById('sc-routing-dialog').open`),true);
        await evaluate(`const details=document.querySelector('[data-sc-routing-details]');details.elements.routing_enabled.checked=false;details.elements.fallback_user_id.selectedIndex=1;window.__fallback=Number(details.elements.fallback_user_id.value);details.requestSubmit()`);
        await delay(200);
        assert.equal(routingRequests.at(-1)?.routing_enabled,false);
        assert.equal(routingRequests.at(-1)?.fallback_user_id,await evaluate(`window.__fallback`));
        await screenshot('routing-advanced-1366');
        await click('#sc-routing-dialog [data-sc-close]');
        await click('[data-sc-tab=tags]');
        await evaluate(`const form=document.querySelector('[data-sc-tag-builder]');form.action=${JSON.stringify(origin+'/settings/crm/tags')};form.elements.name.value='Jepang';form.requestSubmit()`);await delay(150);
        assert.ok(await evaluate(`document.querySelector('[data-sc-tag-builder] [data-sc-form-feedback]').textContent.includes('Nama tag sudah digunakan')`));
        await navigate();
        assert.equal(await evaluate(`document.querySelector('[data-settings-root]').dataset.activeTab`),'tags','Last tab should restore without an explicit query');
        await navigate('settings','?tab=pipeline');
        assert.equal(await evaluate(`document.querySelector('[data-settings-root]').dataset.activeTab`),'pipeline','Explicit deep links must override saved tabs');
        await click('.sc-page-header [data-sc-help-toggle]');
        await navigate();
        assert.equal(await evaluate(`document.querySelector('[data-settings-root]').classList.contains('sc-help-collapsed')`),true,'Help collapse state should persist');
        await click('.sc-page-header [data-sc-help-toggle]');
        await navigate();
        assert.equal(await evaluate(`document.querySelector('[data-sc-coach]').hidden`),true,'Coach mark should only appear on first visit');
        await navigate('limited-settings');
        assert.equal(await evaluate(`!!document.querySelector('.mc-advanced')`),false);
    } finally { socket?.close(); chrome.kill('SIGTERM'); server.closeAllConnections?.(); await new Promise(resolve => server.close(resolve)); }
});
