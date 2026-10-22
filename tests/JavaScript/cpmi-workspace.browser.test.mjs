import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath=process.env.SITE_QA_CHROME,fixtures=process.env.CPMI_QA_FIXTURES;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
test('CPMI registry and detail render responsively and support operational controls',{skip:!chromePath||!fixtures},async()=>{
let origin;
const server=http.createServer((request,response)=>{
const url=new URL(request.url,'http://localhost');
if(['/registry','/detail'].includes(url.pathname)){response.setHeader('Content-Type','text/html');response.end(fs.readFileSync(path.join(fixtures,url.pathname.slice(1)+'.html'),'utf8').replaceAll('https://juraganmigran.my.id',origin));return;}
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
        const navigate=async page=>{const previous=await evaluate('performance.timeOrigin');await send('Page.navigate',{url:origin+'/'+page});for(let i=0;i<100;i++){await delay(50);if(await evaluate(`performance.timeOrigin!==${previous}&&document.readyState==='complete'&&!!document.querySelector('.cpmi-root')`))return;}throw Error('Page failed to load');};
        const click=async selector=>{const point=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2}})()`);await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});};
        const screenshot=async name=>{const data=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(fixtures,name+'.png'),Buffer.from(data.data,'base64'));};
        for(const width of [1672,1366,900,390]){
          await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:width<768});
          await navigate('registry');
          assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Registry overflow at '+width);
          assert.equal(await evaluate(`document.querySelector('.cpmi-stats').children.length`),4);
          const heights=await evaluate(`Array.from(document.querySelectorAll('.cpmi-fields:not(.cpmi-advanced) input,.cpmi-fields:not(.cpmi-advanced) select')).map(e=>Math.round(e.getBoundingClientRect().height))`);
          assert.deepEqual([...new Set(heights)],[width<650?44:42],'Uniform filter heights');
          await click('[data-cpmi-toggle="cpmi-advanced"]');
          assert.equal(await evaluate(`document.querySelector('#cpmi-advanced').hidden`),false);
          await click('[data-select-all]');
          assert.equal(await evaluate(`document.querySelector('[data-select-row]').checked`),true);
          await click('.cpmi-menu summary');
          assert.equal(await evaluate(`document.querySelector('.cpmi-menu').open`),true);
          await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape'});
          assert.equal(await evaluate(`document.querySelector('.cpmi-menu').open`),false);
          await evaluate('scrollTo(0,0)');await screenshot('registry-'+width);
          await navigate('detail');
          assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'Detail overflow at '+width);
          if(width>=1280)assert.equal(await evaluate(`getComputedStyle(document.querySelector('.cpmi-detail')).gridTemplateColumns.split(' ').length`),3);
          assert.equal(await evaluate(`document.querySelector('[data-portal-form] button').disabled`),true);
          await click('[name="contact_verified"]');
          assert.equal(await evaluate(`document.querySelector('[data-portal-form] button').disabled`),false);
          await click('[name="contact_verified"]');
          assert.equal(await evaluate(`document.querySelector('[data-portal-form] button').disabled`),true);
          await evaluate('scrollTo(0,0)');await screenshot('detail-'+width);
        }
        await evaluate(`document.documentElement.dataset.theme='dark'`);await screenshot('detail-dark');
    }finally{socket?.close();chrome.kill();await new Promise(resolve=>server.close(resolve));}
});
