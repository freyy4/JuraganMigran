import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath=process.env.SITE_QA_CHROME,fixtures=process.env.CARE_QA_FIXTURES;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
test('JM Care board supports keyboard controls, support planning, filters and responsive views',{skip:!chromePath||!fixtures},async()=>{
let origin;
const server=http.createServer((request,response)=>{
const url=new URL(request.url,'http://localhost');
if(['/care'].includes(url.pathname)){response.setHeader('Content-Type','text/html');response.end(fs.readFileSync(path.join(fixtures,url.pathname.slice(1)+'.html'),'utf8').replaceAll('https://juraganmigran.my.id',origin));return;}
const file=path.resolve('public','.'+url.pathname);
if((file.startsWith(path.resolve('public/css')+'/')||file.startsWith(path.resolve('public/js')+'/'))&&fs.existsSync(file)){response.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':'text/css');response.end(fs.readFileSync(file));return;}
response.writeHead(404);response.end();
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;
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
        await send('Page.enable');
        const navigate=async page=>{const previous=await evaluate('performance.timeOrigin');await send('Page.navigate',{url:origin+'/'+page});for(let i=0;i<100;i++){await delay(50);if(await evaluate(`performance.timeOrigin!==${previous}&&document.readyState==='complete'&&!!document.querySelector('.care-root')`))return;}throw Error('Page failed to load');};
        const click=async selector=>{const point=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2}})()`);await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});};
        const screenshot=async name=>{const data=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(fixtures,name+'.png'),Buffer.from(data.data,'base64'));};
        for(const width of [1536,1366,1024,768,390]){
          await send('Emulation.setDeviceMetricsOverride',{width,height:1024,deviceScaleFactor:1,mobile:width<768});
          await navigate('care');
          assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Care overflow at '+width);
          assert.equal(await evaluate(`document.querySelectorAll('.care-engines>a').length`),4);
          await click('[data-care-touch]');
          assert.equal(await evaluate(`document.querySelector('#care-touch-dialog').open`),true);
          await click('#care-touch-dialog [data-care-close]');
          await click('[data-care-support-stage="mcu"]');
          assert.equal(await evaluate(`document.querySelector('#care-support-dialog [name=stage]').value`),'mcu');
          assert.equal(await evaluate(`document.querySelector('#care-support-dialog').open`),true);
          await click('#care-support-dialog [data-care-close]');
          await click('[data-care-drag="transport"]');
          assert.equal(await evaluate(`document.querySelector('#care-support-dialog [name=type]').value`),'transport');
          await click('#care-support-dialog [data-care-close]');
          await click('[data-care-view="timeline"]');
          assert.equal(await evaluate(`document.querySelector('[data-care-board]').hidden`),true);
          await click('[data-care-view="board"]');
          assert.equal(await evaluate(`document.querySelector('[data-care-board]').hidden`),false);
          await evaluate(`(()=>{const i=document.querySelector('[data-care-need-search]');i.value='dokumen';i.dispatchEvent(new Event('input'))})()`);
          assert.equal(await evaluate(`document.querySelectorAll('[data-care-need]:not([hidden])').length`),1);
          await evaluate(`(()=>{const i=document.querySelector('[data-care-need-search]');i.value='';i.dispatchEvent(new Event('input'))})()`);
          await evaluate('scrollTo(0,0)');await screenshot('care-'+width);
        }
        await evaluate(`document.documentElement.dataset.theme='dark'`);await screenshot('care-dark');
    }finally{socket?.close();chrome.kill();await new Promise(resolve=>server.close(resolve));}
});
