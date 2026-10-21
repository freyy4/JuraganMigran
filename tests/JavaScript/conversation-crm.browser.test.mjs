import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath = process.env.SITE_QA_CHROME;
const fixtures = process.env.CRM_QA_FIXTURES;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
test('CRM inbox supports compact responsive panes, visible composer, internal notes and help', {skip: !chromePath || !fixtures}, async () => {
    let origin;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (['/inbox', '/pipeline', '/profile', '/settings'].includes(url.pathname)) {
            response.setHeader('Content-Type', 'text/html');
            const html = fs.readFileSync(path.join(fixtures, url.pathname.slice(1) + '.html'), 'utf8');
            response.end(html.replaceAll(/https?:\/\/[^/"\s]+\/(css|js)\//g, `${origin}/$1/`)); return;
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
        const navigate = async page => { await send('Page.navigate',{url:origin+'/'+page}); for (let i=0;i<80;i++) { await delay(50); if (await evaluate(`document.readyState==='complete' && !!document.querySelector('.crm-top-rail')`)) return; } throw Error('Navigation failed'); };
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await navigate('inbox');
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-inspector')).display`), 'block');
        assert.equal(await evaluate(`document.querySelector('.crm-topbar').getBoundingClientRect().height`), 64);
        await send('Emulation.setDeviceMetricsOverride',{width:1366,height:768,deviceScaleFactor:1,mobile:false});
        const sizes = await evaluate(`['.cc-list','.cc-thread','.cc-inspector'].map(s=>document.querySelector(s).getBoundingClientRect().width)`);
        assert.ok(sizes[1] > sizes[0] && sizes[1] > sizes[2], 'Chat must be the largest pane at 1366px');
        assert.ok(await evaluate(`document.querySelector('.cc-composer').getBoundingClientRect().height > 120`));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});

        assert.equal(await evaluate(`document.querySelector('[data-cc-attachment]').hidden`), true);
        await evaluate(`document.querySelector('[data-cc-mode="attachment"]').click()`);
        assert.equal(await evaluate(`document.querySelector('[data-cc-attachment]').hidden`), false);
        await evaluate(`document.querySelector('[data-cc-mode="note"]').click()`);
        assert.equal(await evaluate(`document.querySelector('[data-cc-composer]').elements.mode.value`), 'note');
        assert.equal(await evaluate(`document.querySelector('[data-cc-composer]').elements.attachment.disabled`), true);
        await evaluate(`document.querySelector('[data-cc-inspector]').click()`);
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-inspector')).display`), 'none');
        await evaluate(`document.querySelector('[data-cc-inspector]').click()`);

        assert.equal(await evaluate(`document.querySelector('.cc-inbox').getBoundingClientRect().width <= document.querySelector('main').getBoundingClientRect().width`), true);
        await evaluate(`document.querySelector('[data-cc-open="cc-help"]').click()`);
        assert.equal(await evaluate(`document.getElementById('cc-help').open`), true);
        await evaluate(`document.querySelector('#cc-help [data-cc-close]').click()`);
        await evaluate(`document.querySelector('[data-cc-composer]').elements.mode.value='note';document.querySelector('[data-cc-composer]').elements.mode.dispatchEvent(new Event('change'))`);
        assert.equal(await evaluate(`document.querySelector('[data-cc-text]').hidden`), false);
        assert.equal(await evaluate(`document.querySelector('[data-cc-template]').hidden`), true);
        await send('Emulation.setDeviceMetricsOverride',{width:900,height:1000,deviceScaleFactor:1,mobile:false});
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-inspector')).display`), 'none');
        await evaluate(`document.querySelector('[data-cc-inspector]').click()`);
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-inspector')).display`), 'block');
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await evaluate(`document.querySelector('.cc-inspector').classList.remove('is-open')`);
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-list')).display`), 'none');
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-thread')).display`), 'flex');
        assert.equal(await evaluate(`document.querySelector('.cc-inbox').getBoundingClientRect().bottom <= innerHeight`), true);
        assert.equal(await evaluate(`document.querySelector('.cc-composer').getBoundingClientRect().bottom <= innerHeight`), true);
        assert.ok(await evaluate(`document.querySelector('.cc-composer').getBoundingClientRect().height > 120`), 'Composer must remain visible on mobile');
        await delay(300);
        const screenshot = await send('Page.captureScreenshot',{format:'png'}); fs.writeFileSync(path.join(fixtures,'mobile.png'),Buffer.from(screenshot.data,'base64'));
        await navigate('inbox');
        assert.equal(await evaluate(`!!document.querySelector('.cc-heading')`), false);
        await navigate('pipeline');
        assert.equal(await evaluate(`document.querySelectorAll('.cc-column.mobile-active').length`), 1);
        await evaluate(`document.querySelector('.cc-mobile-stages').value='DISCUSSION';document.querySelector('.cc-mobile-stages').dispatchEvent(new Event('change'))`);
        assert.equal(await evaluate(`document.querySelector('.cc-column.mobile-active').dataset.stage`), 'DISCUSSION');
        await navigate('settings');
        assert.equal(await evaluate(`!!document.querySelector('[data-mc-start]')`), true);
        assert.equal(await evaluate(`!!document.querySelector('[data-cc-meta-connect]')`), false);
        await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
        await checkDark(['.mc-connection','.mc-connection>header','.mc-channel','.mc-connection .cc-coach']);
        await checkContrast(['.mc-channel','.mc-connection .cc-coach']);
        await evaluate(`document.querySelector('[data-mc-guide]').click()`);
        await checkDark(['.mc-guide','.mc-guide>header']);
        await evaluate(`document.querySelector('[data-mc-guide-close]').click()`);
        await evaluate(`
            window.__metaRequests=[];window.__metaEmpty=true;window.__testSent=false;window.open=()=>null;
            window.__metaChannel={id:55,name:'JM CS Utama',display_phone_number:'+628123456789',status:'TEST_REQUIRED',is_active:true,webhook_ready:true,team_id:null,routing_method:'ROUND_ROBIN',strict_owner:true,prefer_existing_owner:true};
            window.fetch=async(url,options={})=>{
                const path=String(url),body=options.body?JSON.parse(options.body):{};window.__metaRequests.push({url:path,body});let result={};
                if(path.endsWith('/preflight'))result={ready:true};
                else if(path.endsWith('/connect/start'))result={sessionId:'qa-session',authorizationUrl:'https://www.facebook.com/dialog/oauth?state=qa',expiresAt:'2099-01-01'};
                else if(path.endsWith('/connect/qa-session'))result={sessionId:'qa-session',status:'ASSET_SELECTION_REQUIRED',selection:{}};
                else if(path.endsWith('/businesses'))result={items:[{id:'200',name:'Juragan Migran'}]};
                else if(path.includes('/wabas?'))result={items:window.__metaEmpty?[]:[{id:'300',name:'JM WhatsApp'}]};
                else if(path.includes('/phones?'))result={items:[{id:'400',display_phone_number:'+628123456789',verified_name:'JM Utama',quality_rating:'GREEN',code_verification_status:'VERIFIED',platform_type:'CLOUD_API'}]};
                else if(path.endsWith('/finalize'))result={channel:window.__metaChannel};
                else if(path.endsWith('/test-message')){window.__testSent=true;window.__metaChannel.inbound_test_at='2026-10-17 10:00:00';window.__metaChannel.outbound_test_at='2026-10-17 10:01:00';result={test:{status:'SENT'}};}
                else if(path.endsWith('/tests'))result={channel:window.__metaChannel,templates:[],tests:window.__testSent?[{recipient_masked:'••••7890',status:'SENT'}]:[]};
                else if(path.endsWith('/routing')){window.__metaChannel.status='CONNECTED';result={channel:window.__metaChannel};}
                return Response.json(result);
            };
            document.querySelector('[data-mc-start]').click();
        `);
        const waitMeta=async expression=>{for(let i=0;i<100;i++){if(await evaluate(expression))return;await delay(50);}throw Error('Meta wizard timeout: '+expression);};
        await waitMeta(`document.querySelector('[data-mc-content]').textContent.includes('Popup diblokir')`);
        await evaluate(`[...document.querySelectorAll('[data-mc-content] button')].find(node=>node.textContent.includes('Saya sudah login')).click()`);
        await waitMeta(`!!document.querySelector('[data-mc-options] button')`);
        await checkDark(['.mc-wizard','.mc-wizard>header','.mc-stepper li','.mc-stepper [aria-current=step]','[data-mc-options] button']);
        await checkContrast(['.mc-wizard>header','.mc-stepper li','.mc-stepper [aria-current=step]','[data-mc-options] button']);
        await evaluate(`document.querySelector('[data-mc-options] button').click()`);
        await waitMeta(`document.querySelector('[data-mc-content]').textContent.includes('Akun WhatsApp Business belum tersedia')`);
        await evaluate(`window.__metaEmpty=false;[...document.querySelectorAll('[data-mc-content] button')].find(node=>node.textContent==='Muat ulang').click()`);
        await waitMeta(`!!document.querySelector('[data-mc-options] button')`);
        await evaluate(`document.querySelector('[data-mc-options] button').click()`);
        await waitMeta(`document.querySelector('[data-mc-content]').textContent.includes('Kualitas: GREEN')`);
        await evaluate(`document.querySelector('[data-mc-options] button').click()`);
        await waitMeta(`!!document.querySelector('[data-mc-content] form')`);
        await checkDark(['.mc-wizard input']);
        await checkContrast(['.mc-wizard input']);
        await evaluate(`document.querySelector('[data-mc-content] form').requestSubmit()`);
        await waitMeta(`document.querySelector('[data-mc-content]').textContent.includes('Kirim Pesan Test')`);
        await checkDark(['.mc-test-summary','.mc-wizard select']);
        await checkContrast(['.mc-test-summary','.mc-wizard select']);
        assert.equal(await evaluate(`[...document.querySelectorAll('[data-mc-content] button')].find(node=>node.textContent==='Lanjut ke tim CS').disabled`),true);
        await evaluate(`const form=document.querySelector('[data-mc-content] form');form.elements.recipient.value='081234567890';form.querySelector('[type=checkbox]').checked=true;form.requestSubmit()`);
        await waitMeta(`[...document.querySelectorAll('[data-mc-content] button')].some(node=>node.textContent==='Lanjut ke tim CS'&&!node.disabled)`);
        await evaluate(`[...document.querySelectorAll('[data-mc-content] button')].find(node=>node.textContent==='Lanjut ke tim CS').click()`);
        await waitMeta(`!!document.querySelector('[data-mc-content] select[name=routing_method]')`);
        await checkDark(['.mc-wizard input','.mc-wizard select']);
        const wizardDark=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'meta-wizard-dark-mobile.png'),Buffer.from(wizardDark.data,'base64'));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await evaluate(`document.querySelector('[data-mc-wizard]').scrollIntoView({block:'start'})`);
        const wizardDesktop=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'meta-wizard-dark-desktop.png'),Buffer.from(wizardDesktop.data,'base64'));
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await evaluate(`document.querySelector('[data-mc-content] form').requestSubmit()`);
        await waitMeta(`document.querySelector('[data-mc-content]').textContent.includes('WhatsApp berhasil terhubung')`);
        assert.equal(await evaluate(`window.__metaRequests.some(row=>JSON.stringify(row.body).includes('access_token'))`),false);
        assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),true);
        await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
        const metaMobile=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'meta-connect-mobile.png'),Buffer.from(metaMobile.data,'base64'));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await evaluate(`window.channelForm=Array.from(document.querySelectorAll('.cc-channel-form')).at(-1);channelForm.closest('details').open=true;channelForm.closest('.mc-advanced').open=true;`);
        assert.equal(await evaluate(`Math.abs(channelForm.elements.name.getBoundingClientRect().top-channelForm.elements.display_phone_number.getBoundingClientRect().top)<2`),true);
        assert.equal(await evaluate(`new FormData(channelForm).getAll('strict_owner').at(-1)`),'1');
        await evaluate(`channelForm.querySelector('[type="checkbox"][name="strict_owner"]').checked=false`);
        assert.equal(await evaluate(`new FormData(channelForm).getAll('strict_owner').at(-1)`),'0');
        await evaluate(`channelForm.scrollIntoView({block:'start'})`);
        const formDesktop=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'channel-form-desktop.png'),Buffer.from(formDesktop.data,'base64'));
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        assert.equal(await evaluate(`channelForm.elements.display_phone_number.getBoundingClientRect().top>channelForm.elements.name.getBoundingClientRect().bottom`),true);
        assert.equal(await evaluate(`channelForm.getBoundingClientRect().right<=innerWidth`),true);
        await delay(350);
        await evaluate(`channelForm.scrollIntoView({block:'start'})`);
        const formMobile=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'channel-form-mobile.png'),Buffer.from(formMobile.data,'base64'));
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await navigate('inbox'); const desktop = await send('Page.captureScreenshot',{format:'png'}); fs.writeFileSync(path.join(fixtures,'desktop.png'),Buffer.from(desktop.data,'base64'));
        await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
        assert.equal(await evaluate(`document.documentElement.dataset.theme`), 'dark');
        await checkContrast(['.cc-message','.cc-tabs a:not(.active)','.cc-tabs a.active']);
        await checkDark(['.cc-inbox','.cc-composer','.cc-panel','.cc-messages','.cc-message','.cc-tabs a:not(.active)']);
        await evaluate(`document.querySelector('[data-cc-open="cc-help"]').click()`);
        await checkDark(['.cc-dialog']);
        await evaluate(`document.querySelector('#cc-help [data-cc-close]').click()`);
        const dark = await send('Page.captureScreenshot',{format:'png'}); fs.writeFileSync(path.join(fixtures,'desktop-dark.png'),Buffer.from(dark.data,'base64'));
        for (const page of ['settings','pipeline','profile']) {
            await navigate(page);
            assert.equal(await evaluate(`document.documentElement.dataset.theme`), 'dark');
            await checkDark(page === 'pipeline' ? ['.cp-summary a','.cc-column','.cc-lead-card'] : ['.cc-panel']);
        }
        await navigate('inbox');
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        await checkDark(['.cc-composer','.cc-message']);
        const darkMobile = await send('Page.captureScreenshot',{format:'png'}); fs.writeFileSync(path.join(fixtures,'mobile-dark.png'),Buffer.from(darkMobile.data,'base64'));
        await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
        assert.equal(await evaluate(`document.documentElement.dataset.theme`), 'light');
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cc-panel')).backgroundColor`), 'rgb(255, 255, 255)');
    } finally { socket?.close(); chrome.kill('SIGTERM'); await new Promise(resolve => server.close(resolve)); }
});
