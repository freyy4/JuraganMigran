import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath = process.env.SITE_QA_CHROME;
const fixtures = process.env.QR_QA_FIXTURES;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
test('QR pairing expires, refreshes and connects into team routing without exposing session credentials', {skip: !chromePath || !fixtures}, async () => {
    let origin;
    const server = http.createServer((request, response) => {
        const url = new URL(request.url, 'http://localhost');
        if (['/inbox', '/pipeline', '/profile', '/settings'].includes(url.pathname)) {
            response.setHeader('Content-Type', 'text/html');
            const html = fs.readFileSync(path.join(fixtures, url.pathname.slice(1) + '.html'), 'utf8');
            response.end(html.replaceAll(/https?:\/\/[^/"\s]+\/(css|js)\//g, `${origin}/$1/`)); return;
        }
        if(url.pathname==='/test-qr.png'){response.setHeader('Content-Type','image/png');response.end(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aD1sAAAAASUVORK5CYII=','base64'));return;}
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
        const navigate = async page => { await send('Page.navigate',{url:origin+'/'+page}); for (let i=0;i<80;i++) { await delay(50); if (await evaluate(`document.readyState==='complete' && !!document.querySelector('.cc-heading')`)) return; } throw Error('Navigation failed'); };

        await send('Page.addScriptToEvaluateOnNewDocument',{source:`
            window.__qrCalls=[];window.__qrState=null;window.__qrReady=true;window.__qrExpiry=0;
            window.__qrResult=()=>({channel:{id:77,name:'JM QR Test',phone:window.__qrState==='CONNECTED'?'+628123456789':'',displayName:'JM Utama',status:'TEST_REQUIRED',active:false,routing:'ROUND_ROBIN',teamId:null},state:window.__qrState,health:'HEALTHY',pairingSessionId:'fixture-pairing',expiresAt:window.__qrExpiry?new Date(window.__qrExpiry).toISOString():null,qrImageUrl:window.__qrState==='WAITING_FOR_SCAN'?location.origin+'/test-qr.png?at='+window.__qrExpiry:null,lastHeartbeatAt:'2026-10-18 00:10:00',queueBacklog:0});
            window.fetch=async(url,options={})=>{const path=String(url),body=options.body?JSON.parse(options.body):{};window.__qrCalls.push({url:path,body});
                if(path.endsWith('/channels')&&!options.body)return Response.json({ready:window.__qrReady,channels:window.__qrState?[window.__qrResult()]:[]});
                if(path.endsWith('/channels'))return Response.json({channel:{id:77}});
                if(path.endsWith('/start')||path.endsWith('/rescan')){window.__qrState='WAITING_FOR_SCAN';window.__qrExpiry=Date.now()+(path.endsWith('/rescan')?45000:1800);return Response.json(window.__qrResult());}
                if(path.endsWith('/qr/status'))return Response.json(window.__qrResult());
                if(path.endsWith('/routing'))return Response.json({...window.__qrResult(),channel:{...window.__qrResult().channel,active:true}});
                if(path.endsWith('/disconnect')){window.__qrState='DISCONNECTED_BY_ADMIN';return Response.json(window.__qrResult());}
                return Response.json({});
            };
        `});
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await navigate('settings');
        const waitFor=async expression=>{for(let i=0;i<120;i++){if(await evaluate(expression))return;await delay(50);}throw Error('QR timeout: '+expression);};
        await waitFor(`!!document.querySelector('[data-qr-create]')`);
        assert.equal(await evaluate(`document.querySelector('[data-qr-root]').textContent.includes('Compatibility')`),true);
        await evaluate(`document.querySelector('[data-qr-create]').requestSubmit()`);
        await waitFor(`!document.querySelector('[data-qr-image]').hidden`);
        assert.equal(await evaluate(`document.querySelector('[data-qr-image]').getAttribute('src').includes('test-qr.png')`),true);
        await waitFor(`document.querySelector('[data-qr-pair-state]').textContent.includes('kedaluwarsa')`);
        assert.equal(await evaluate(`document.querySelector('[data-qr-image]').hasAttribute('src')`),false);
        await evaluate(`document.querySelector('[data-qr-refresh]').click()`);
        await waitFor(`!document.querySelector('[data-qr-image]').hidden`);
        await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
        await checkDark(['.qr-workspace','.qr-workspace>header','.qr-channel','.qr-image-box']);
        await checkContrast(['.qr-channel']);
        const dark=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'qr-dark-desktop.png'),Buffer.from(dark.data,'base64'));
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.qr-mobile-hint')).display`),'block');
        assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),true);
        await evaluate(`window.__qrState='CONNECTED';window.__qrExpiry=0`);
        await waitFor(`!document.querySelector('[data-qr-settings]').hidden`);
        assert.equal(await evaluate(`document.querySelector('[data-qr-image]').hasAttribute('src')`),false);
        await checkDark(['[data-qr-routing] select']);
        const mobile=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'qr-dark-mobile.png'),Buffer.from(mobile.data,'base64'));
        await evaluate(`document.querySelector('[data-qr-routing]').requestSubmit()`);
        await waitFor(`document.querySelector('[data-qr-feedback]').textContent.includes('WhatsApp terhubung')`);
        assert.equal(await evaluate(`window.__qrCalls.find(row=>row.url.endsWith('/routing')).body.strict_owner`),true);
        assert.equal(await evaluate(`document.querySelector('[data-qr-root]').textContent.includes('webhook_credential')`),false);
        await evaluate(`document.querySelector('[data-qr-help]').click()`);
        assert.equal(await evaluate(`document.querySelector('[data-qr-guide]').hidden`),false);
        await checkDark(['.qr-guide','.qr-guide>header']);
        await evaluate(`document.querySelector('[data-qr-guide-close]').click();document.querySelector('[data-theme-toggle]').click()`);
        assert.equal(await evaluate(`document.documentElement.dataset.theme`),'light');
    } finally { socket?.close(); chrome.kill('SIGTERM'); await new Promise(resolve => server.close(resolve)); }
});
