import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import http from 'node:http';
import {spawn} from 'node:child_process';
const fixtures=process.env.WORKSPACE_QA_FIXTURES,chromePath=process.env.SITE_QA_CHROME;
const delay=ms=>new Promise(resolve=>setTimeout(resolve,ms));

test('all CRM menus and forms use readable dark surfaces and fit mobile', {skip:!fixtures||!chromePath},async t=>{
    const pages=JSON.parse(fs.readFileSync(path.join(fixtures,'manifest.json'),'utf8')).filter(page=>!process.env.WORKSPACE_QA_PAGES||process.env.WORKSPACE_QA_PAGES.split(',').includes(page.key));
    let origin;
    const server=http.createServer((request,response)=>{
        const url=new URL(request.url,'http://localhost');
        if(url.pathname.startsWith('/qa/')){
            const key=url.pathname.slice(4);if(!pages.some(page=>page.key===key)){response.writeHead(404);response.end();return;}
            const html=fs.readFileSync(path.join(fixtures,key+'.html'),'utf8').replaceAll('https://juraganmigran.my.id',origin);
            response.setHeader('Content-Type','text/html');response.end(html);return;
        }
        const file=path.resolve('public','.'+url.pathname);
        if((file.startsWith(path.resolve('public/css')+'/')||file.startsWith(path.resolve('public/js')+'/')||file.startsWith(path.resolve('public/images')+'/'))&&fs.existsSync(file)){
            response.setHeader('Content-Type',file.endsWith('.js')?'text/javascript':file.endsWith('.css')?'text/css':file.endsWith('.webp')?'image/webp':'image/png');response.end(fs.readFileSync(file));return;
        }
        response.writeHead(404);response.end();
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));origin=`http://127.0.0.1:${server.address().port}`;
    const chrome=spawn(chromePath,['--headless','--no-sandbox','--disable-gpu','--remote-debugging-port=0','--user-data-dir=/tmp/jm-appearance-chrome-'+process.pid,'about:blank'],{stdio:['ignore','ignore','pipe']});
    let socket;
    try{
        const endpoint=await new Promise((resolve,reject)=>{let output='';const timeout=setTimeout(()=>reject(Error('Browser startup timed out')),15000);chrome.stderr.on('data',chunk=>{output+=chunk;const match=output.match(/DevTools listening on (ws:\/\/[^\s]+)/);if(match){clearTimeout(timeout);resolve(match[1]);}});chrome.once('exit',code=>{clearTimeout(timeout);reject(Error('Browser exited '+code));});});
        const target=(await(await fetch(`http://127.0.0.1:${new URL(endpoint).port}/json`)).json())[0];
        socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.addEventListener('open',resolve));
        let id=0;const pending=new Map();socket.addEventListener('message',event=>{const data=JSON.parse(event.data);if(pending.has(data.id)){const callback=pending.get(data.id);pending.delete(data.id);data.error?callback.reject(Error(JSON.stringify(data.error))):callback.resolve(data.result);}});
        const send=(method,params={})=>new Promise((resolve,reject)=>{const current=++id;pending.set(current,{resolve,reject});socket.send(JSON.stringify({id:current,method,params}));});
        const evaluate=async expression=>{const result=await send('Runtime.evaluate',{expression,returnByValue:true,awaitPromise:true});if(result.exceptionDetails)throw Error(JSON.stringify(result.exceptionDetails));return result.result.value;};
        await send('Page.enable');
        await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-color-scheme',value:'dark'}]});
        await send('Page.addScriptToEvaluateOnNewDocument',{source:`localStorage.setItem('jm-color-theme',new URLSearchParams(location.search).get('theme')||'light');`});
        const failures=[];
        for(const [theme,width,height] of [['dark',1440,1000],['light',390,844],['dark',390,844]]){
            await send('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:width<768});
            for(const page of pages){
                const previous=await evaluate('performance.timeOrigin');
                await send('Page.navigate',{url:origin+'/qa/'+page.key+'?theme='+theme});
                for(let attempt=0;attempt<100;attempt++){await delay(20);if(await evaluate(`performance.timeOrigin!==${previous}&&document.readyState==='complete'&&!!document.getElementById('main-content')&&!!document.querySelector('link[href*="workspace-appearance"]')`))break;}
                const issues=await evaluate(`(()=>{
                    const issues=[],main=document.getElementById('main-content');if(!main)return ['missing main content'];
                    if(document.documentElement.dataset.theme!==${JSON.stringify(theme)})issues.push('theme not applied');
                    if(document.documentElement.scrollWidth>innerWidth+2)issues.push('outer overflow '+document.documentElement.scrollWidth+' > '+innerWidth);
                    const masterMenu=document.querySelector('[data-master-more]');
                    if(masterMenu){
                        masterMenu.open=true;
                        const popup=masterMenu.querySelector('.master-navigation-menu').getBoundingClientRect();
                        if(popup.width===0||popup.left<0||popup.right>innerWidth)issues.push('master overflow menu must be visible within viewport');
                        masterMenu.querySelector('summary').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));
                        if(masterMenu.open)issues.push('master overflow menu must close with Escape');
                    }
                    const rail=document.querySelector('.crm-topbar .jm-tabs--rail');
                    if(rail){
                        if(document.querySelector('.crm-topbar').getBoundingClientRect().height!==64)issues.push('top rail must be 64px');
                        if(rail.classList.contains('task-tabs')&&rail.querySelectorAll('[aria-current=page]').length!==1)issues.push('section must have one active tab');
                        if(main.querySelector('.jm-tabs--rail'))issues.push('section tabs duplicated in content');
                    }
                    for(const tabs of document.querySelectorAll('.jm-tabs')){
                        for(const control of tabs.querySelectorAll(':scope>a,:scope>button')){
                            const rect=control.getBoundingClientRect();
                            if(rect.height&&getComputedStyle(control).borderRadius!=='0px')issues.push('tab must use shared underline shape');
                            if(rect.height&&getComputedStyle(control).whiteSpace!=='nowrap')issues.push('tab label must stay on one line');
                        }
                    }

                    const visible=node=>{const box=node.getBoundingClientRect(),style=getComputedStyle(node);return box.width>0&&box.height>0&&style.visibility!=='hidden';};
                    const rgb=color=>(color.match(/[\\d.]+/g)||[]).map(Number);
                    const luminance=color=>{const channels=rgb(color).slice(0,3).map(channel=>{const v=channel/255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;});return channels[0]*.2126+channels[1]*.7152+channels[2]*.0722;};
                    const background=node=>{while(node){const value=getComputedStyle(node).backgroundColor,channels=rgb(value);if(channels.length===3||channels[3]>=.95)return value;node=node.parentElement;}return ${theme==='dark'?"'rgb(15,23,42)'":"'rgb(255,255,255)'"};};
                    if(${theme==='dark'})for(const node of main.querySelectorAll('section,article,dialog,[class],a,button')){
                        if(!visible(node)||node.matches('svg,svg *,img,canvas,iframe,input[type=color],.sc-color,[data-color-swatch]'))continue;const color=getComputedStyle(node).backgroundColor,channels=rgb(color);if((channels.length===3||channels[3]>.9)&&Math.min(...channels.slice(0,3))>170)issues.push('light surface '+node.tagName+'.'+node.className+' in '+node.parentElement.className+' '+node.textContent.trim().slice(0,35)+' '+color);
                    }
                    for(const node of main.querySelectorAll('form input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=color]):not([type=range]),form select,form textarea')){
                        if(!visible(node))continue;const box=node.getBoundingClientRect(),style=getComputedStyle(node);
                        if(box.width>innerWidth-16)issues.push('control too wide '+node.name);
                        if(node.matches('select[multiple]')&&box.height<80)issues.push('multiple select clipped '+node.name);
                        if(${width<768}&&parseFloat(style.fontSize)<16)issues.push('small mobile control '+node.name+' '+style.fontSize);
                        if(box.height<38&&!node.closest('.pagination'))issues.push('short control '+node.name+' '+box.height);
                        const fg=luminance(style.color),bg=luminance(background(node)),contrast=(Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);
                        if(contrast<4.5)issues.push('control contrast '+node.name+' '+contrast.toFixed(2));
                    }
                    for(const node of main.querySelectorAll('form label,form legend')){
                        if(!visible(node))continue;const fg=luminance(getComputedStyle(node).color),bg=luminance(background(node)),contrast=(Math.max(fg,bg)+.05)/(Math.min(fg,bg)+.05);if(contrast<4.5)issues.push('label contrast '+node.textContent.trim().slice(0,30)+' '+contrast.toFixed(2));
                    }
                    return [...new Set(issues)].slice(0,12);
                })()`);
                if(issues.length)failures.push({page:page.key,theme,width,issues});
                if(['form-candidates','form-settings.roles','crm-settings','menu-10-0','menu-7-2','menu-1-2','candidate-detail','care-journey','protocol-queue','protocol-detail','protocol-interview','protocol-family','protocol-golden'].includes(page.key)){
                    const screenshot=await send('Page.captureScreenshot',{format:'png'});fs.writeFileSync(path.join(fixtures,page.key+'-'+theme+'-'+width+'.png'),Buffer.from(screenshot.data,'base64'));
                }
            }
            t.diagnostic(`${pages.length} pages checked at ${width}px in ${theme} mode`);
        }
        fs.writeFileSync(path.join(fixtures,'appearance-issues.json'),JSON.stringify(failures,null,2));
        assert.deepEqual(failures,[],'Appearance regressions: '+JSON.stringify(failures.slice(0,15)));
    }finally{socket?.close();chrome.kill('SIGTERM');await new Promise(resolve=>server.close(resolve));}
});
