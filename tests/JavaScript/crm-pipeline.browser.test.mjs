import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath = process.env.SITE_QA_CHROME;
const fixtures = process.env.CRM_QA_FIXTURES;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
test('Pipeline workspace supports filters, layout, selection and failed stage rollback', {skip: !chromePath || !fixtures}, async () => {
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
        const navigate = async page => { await send('Page.navigate',{url:origin+'/'+page}); for (let i=0;i<80;i++) { await delay(50); if (await evaluate(`document.readyState==='complete' && !!document.querySelector('.cc-heading')`)) return; } throw Error('Navigation failed'); };
        await send('Emulation.setDeviceMetricsOverride',{width:1440,height:1000,deviceScaleFactor:1,mobile:false});
        await send('Page.addScriptToEvaluateOnNewDocument',{source:`
            window.__calls=[];
            window.fetch=async(url,options={})=>{
                const path=new URL(url,location.href).pathname;
                window.__calls.push({path,method:options.method||'GET',body:options.body?JSON.parse(options.body):null});
                if(path.includes('card-layout'))return new Response(JSON.stringify({scope:'USER',version:options.method==='PUT'?1:0,fields:['avatar','source','owner','tags','followup','activity']}),{status:200,headers:{'Content-Type':'application/json'}});
                if(path.endsWith('/move'))return new Response(JSON.stringify({message:'Conflict'}),{status:409,headers:{'Content-Type':'application/json'}});
                return new Response('{}',{status:200,headers:{'Content-Type':'application/json'}});
            };
        `});
        await navigate('pipeline');
        assert.equal(await evaluate(`!!document.querySelector('[data-cp-select]')`),true);
        assert.equal(await evaluate(`document.querySelector('.cp-bulk').hidden`),true);
        await evaluate(`document.querySelector('[data-cc-open="cp-filter"]').click()`);
        assert.equal(await evaluate(`document.querySelector('#cp-filter').open`),true);
        assert.equal(await evaluate(`!!document.querySelector('#cp-filter input[name=utm_medium]')`),true);
        await evaluate(`document.querySelector('#cp-filter [data-cc-close]').click();document.querySelector('[data-cc-open="cp-layout"]').click()`);
        await delay(100);
        await evaluate(`const input=document.querySelector('[data-cp-field=source]');input.checked=false;input.dispatchEvent(new Event('change'));`);
        assert.equal(await evaluate(`document.querySelector('[data-cp-show=source]').hidden`),true);
        assert.equal(await evaluate(`document.querySelector('[data-cp-preview=source]').hidden`),true);
        await evaluate(`document.querySelector('[data-cp-save-layout]').click()`);
        await delay(100);
        assert.equal(await evaluate(`window.__calls.some(row=>row.method==='PUT'&&!row.body.fields.includes('source'))`),true);
        await evaluate(`document.querySelector('#cp-layout [data-cc-close]').click();document.querySelector('[data-cp-select-page]').click()`);
        assert.ok((await evaluate(`document.querySelector('[data-cp-selection]').textContent`)).includes('1 lead'));
        assert.equal(await evaluate(`document.querySelector('.cp-bulk').hidden`),false);
        await evaluate(`document.querySelector('[data-cp-select-filter]').click()`);
        assert.ok((await evaluate(`document.querySelector('[data-cp-selection]').textContent`)).includes('Semua lead'));
        await evaluate(`document.querySelector('[data-cp-clear]').click()`);
        assert.equal(await evaluate(`document.querySelector('[data-cp-select]').checked`),false);
        assert.equal(await evaluate(`document.querySelector('.cp-bulk').hidden`),true);
        await evaluate(`const select=document.querySelector('[data-cp-move]');select.value='CONTACTED';select.dispatchEvent(new Event('change',{bubbles:true}));`);
        await delay(200);
        assert.equal(await evaluate(`document.querySelector('[data-id]').closest('[data-stage]').dataset.stage`),'NEW');
        assert.ok((await evaluate(`document.querySelector('[data-cc-feedback]').textContent`)).includes('pengguna lain'));
        await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
        await checkDark(['.cp-summary a','.cc-column','.cc-lead-card']);
        await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:true});
        assert.equal(await evaluate(`document.documentElement.scrollWidth<=innerWidth`),true);
        await evaluate(`document.querySelector('[data-cc-open="cp-filter"]').click()`);
        assert.equal(await evaluate(`document.querySelector('#cp-filter').getBoundingClientRect().width<=innerWidth`),true);
        const screenshot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,'pipeline-filter-dark-mobile.png'),Buffer.from(screenshot.data,'base64'));
    } finally { socket?.close(); chrome.kill('SIGTERM'); await new Promise(resolve => server.close(resolve)); }
});
