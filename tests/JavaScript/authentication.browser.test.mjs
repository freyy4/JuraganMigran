import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import path from 'node:path';
import {spawn} from 'node:child_process';
const chromePath=process.env.SITE_QA_CHROME,fixtures=process.env.AUTH_QA_FIXTURES;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));
test('Login shells match the supplied references, remain accessible and prevent double submit',{skip:!chromePath||!fixtures},async()=>{
let origin;
const server=http.createServer((request,response)=>{
const url=new URL(request.url,'http://localhost');
if(['/staff','/cpmi','/forgot','/mfa'].includes(url.pathname)){response.setHeader('Content-Type','text/html');response.end(fs.readFileSync(path.join(fixtures,url.pathname.slice(1)+'.html'),'utf8').replaceAll('https://juraganmigran.my.id',origin));return;}
const file=path.resolve('public','.'+url.pathname);
if((file.startsWith(path.resolve('public/css')+'/')||file.startsWith(path.resolve('public/js')+'/')||file.startsWith(path.resolve('public/images')+'/'))&&fs.existsSync(file)){response.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.webp')?'image/webp':'text/css');response.end(fs.readFileSync(file));return;}
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
        const navigate=async page=>{const previous=await evaluate('performance.timeOrigin');await send('Page.navigate',{url:origin+'/'+page});for(let i=0;i<100;i++){await delay(50);if(await evaluate(`performance.timeOrigin!==${previous}&&document.readyState==='complete'&&!!document.querySelector('.auth-shell')`))return;}throw Error('Page failed to load');};
        const click=async selector=>{const point=await evaluate(`(()=>{const e=document.querySelector(${JSON.stringify(selector)});e.scrollIntoView({block:'center'});const r=e.getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2}})()`);await send('Input.dispatchMouseEvent',{type:'mousePressed',button:'left',clickCount:1,...point});await send('Input.dispatchMouseEvent',{type:'mouseReleased',button:'left',clickCount:1,...point});};
        const screenshot=async name=>{const data=await send('Page.captureScreenshot',{format:'png',captureBeyondViewport:true});fs.writeFileSync(path.join(fixtures,name+'.png'),Buffer.from(data.data,'base64'));};
        for(const [width,theme] of [1448,1366,900,390].flatMap(width=>[['light',width],['dark',width]].map(([theme,width])=>[width,theme]))){
          await send('Emulation.setDeviceMetricsOverride',{width,height:1086,deviceScaleFactor:1,mobile:width<768});
          for(const audience of ['staff','cpmi']){
            await navigate(audience);await delay(150);
            if(await evaluate('document.documentElement.dataset.theme')!==theme)await click('[data-theme-toggle]');
            assert.equal(await evaluate('document.documentElement.dataset.theme'),theme);
            assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'No page overflow '+audience+' '+width);
            if(width>760)assert.equal(await evaluate(`getComputedStyle(document.querySelector('.auth-shell')).gridTemplateColumns.split(' ').length`),2);
            else assert.equal(await evaluate(`document.querySelector('.auth-form-panel').getBoundingClientRect().top<document.querySelector('.auth-story').getBoundingClientRect().top`),true,'Form first on mobile');
            assert.equal(await evaluate(`document.querySelector('#password').type`),'password');
            await click('[data-password-toggle]');assert.equal(await evaluate(`document.querySelector('#password').type`),'text');
            await click('[data-password-toggle]');assert.equal(await evaluate(`document.querySelector('#password').type`),'password');
            const contrasts=await evaluate(`(()=>{
              const luminance=c=>{const rgb=c.match(/[\\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});return rgb[0]*.2126+rgb[1]*.7152+rgb[2]*.0722};
              const ratio=(a,b)=>{a=luminance(a);b=luminance(b);return(Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
              const background=e=>{while(e){const c=getComputedStyle(e).backgroundColor;if(c!=='rgba(0, 0, 0, 0)')return c;e=e.parentElement;}return 'rgb(255,255,255)'};
              const input=document.querySelector('input'),link=document.querySelector('.auth-forgot a'),submit=document.querySelector('.auth-submit');
              return [ratio(getComputedStyle(input,'::placeholder').color,background(input)),ratio(getComputedStyle(link).color,background(link)),...getComputedStyle(submit).backgroundImage.match(/rgb\\([^)]*\\)/g).map(c=>ratio(getComputedStyle(submit).color,c))];
            })()`);
            assert.ok(contrasts.every(value=>value>=4.5),'Readable text contrast '+theme+' '+JSON.stringify(contrasts));
            const targets=await evaluate(`Array.from(document.querySelectorAll('.auth-submit,.auth-password-toggle')).map(e=>e.getBoundingClientRect().height)`);
            assert.ok(targets.every(height=>height>=44),'Touch targets at least 44px');
            assert.equal(await evaluate(`Array.from(document.scripts).some(script=>script.src.includes('portal-react'))`),false,'No dashboard bundle before auth');
            await evaluate('document.activeElement?.blur();scrollTo(0,0)');await screenshot(audience+'-'+theme+'-'+width);
          }
        }
        for(const width of [1448,390]){
          await send('Emulation.setDeviceMetricsOverride',{width,height:1086,deviceScaleFactor:1,mobile:width<768});
          await navigate('mfa');
          assert.equal(await evaluate('document.documentElement.scrollWidth<=innerWidth'),true,'MFA has no horizontal overflow');
          assert.equal(await evaluate(`document.querySelector('[name=code]').autocomplete`),'one-time-code');
          await evaluate(`document.querySelector('.auth-secret code').textContent='KUNCI KHUSUS DATA UJI';scrollTo(0,0)`);
          await screenshot('mfa-'+width);
        }
        await navigate('staff');
        await evaluate(`document.querySelector('[name=email]').value='example@example.test';document.querySelector('#password').value='browser-test-passphrase';window.authSubmitCount=0;document.querySelector('form').addEventListener('submit',event=>{event.preventDefault();window.authSubmitCount++});`);
        await click('.auth-submit');await click('.auth-submit');
        assert.equal(await evaluate('window.authSubmitCount'),1,'Double submit blocked');
        assert.equal(await evaluate(`document.querySelector('.auth-submit').getAttribute('aria-busy')`),'true');
        await navigate('forgot');await screenshot('forgot-assisted');
        await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
        assert.equal(await evaluate(`getComputedStyle(document.querySelector('.auth-footer svg')).animationName`),'none');
    }finally{socket?.close();chrome.kill();await new Promise(resolve=>server.close(resolve));}
});
