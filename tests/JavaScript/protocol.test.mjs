import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

function page({dirty=false,visible=true,online=true,state={version:2,gate_passed:false}}={}){
    const listeners={},notice={hidden:true},reload={count:0},requests={count:0};let poll;
    const root={dataset:{protocolVersion:'1',protocolGate:'0',protocolState:'/protokol/lamaran/1/state'},addEventListener:(name,handler)=>listeners[name]=handler,querySelector:selector=>selector==='[data-protocol-stale]'?notice:{addEventListener(){}}};
    const context={document:{hidden:!visible,querySelector:()=>root,addEventListener(){}},navigator:{onLine:online},location:{reload:()=>reload.count++},setInterval:handler=>poll=handler,fetch:async()=>{requests.count++;return {ok:true,json:async()=>state};}};
    vm.runInNewContext(fs.readFileSync(new URL('../../public/js/protocol.js',import.meta.url),'utf8'),context);
    if(dirty)listeners.input({target:{closest:()=>true}});
    return {poll,notice,reload,requests};
}

test('another owner changing the process never discards an unsaved form',async()=>{
    const browser=page({dirty:true});await browser.poll();
    assert.equal(browser.reload.count,0);assert.equal(browser.notice.hidden,false);
});

test('an untouched page refreshes when a handoff or canonical gate changes',async()=>{
    const handoff=page();await handoff.poll();assert.equal(handoff.reload.count,1);
    const gate=page({state:{version:1,gate_passed:true}});await gate.poll();assert.equal(gate.reload.count,1);
    const current=page({state:{version:1,gate_passed:false}});await current.poll();assert.equal(current.reload.count,0);
});

test('hidden and offline tabs do not repeatedly request the process state',async()=>{
    for(const options of [{visible:false},{online:false}]){
        const browser=page(options);await browser.poll();assert.equal(browser.requests.count,0);assert.equal(browser.reload.count,0);
    }
});
