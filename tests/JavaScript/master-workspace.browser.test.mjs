import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawn} from 'node:child_process';

const sessionFile = process.env.MASTER_QA_SESSION;
const chromePath = process.env.MASTER_QA_CHROME;
const output = process.env.MASTER_QA_OUTPUT;
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

async function browser() {
    const session = JSON.parse(fs.readFileSync(sessionFile, 'utf8'));
    assert.match(session.url, /^http:\/\/127\.0\.0\.1:\d+$/);
    const chrome = spawn(chromePath, ['--headless', '--no-sandbox', '--disable-gpu', '--remote-debugging-port=0', 'about:blank'], {stdio: ['ignore', 'ignore', 'pipe']});
    const endpoint = await new Promise((resolve, reject) => {
        let stderr = '';
        const timer = setTimeout(() => reject(Error('Browser startup timed out')), 15000);
        chrome.stderr.on('data', data => {
            stderr += data.toString();
            const match = stderr.match(/DevTools listening on (ws:\/\/[^\s]+)/);
            if (match) { clearTimeout(timer); resolve(match[1]); }
        });
        chrome.once('exit', code => { clearTimeout(timer); reject(Error(`Browser exited: ${code}\n${stderr}`)); });
    });
    const port = new URL(endpoint).port;
    const target = (await (await fetch(`http://127.0.0.1:${port}/json`)).json())[0];
    const socket = new WebSocket(target.webSocketDebuggerUrl);
    await new Promise(resolve => socket.addEventListener('open', resolve));
    let id = 0;
    const pending = new Map();
    const errors = [];
    socket.addEventListener('message', event => {
        const data = JSON.parse(event.data);
        if (pending.has(data.id)) {
            const callback = pending.get(data.id);
            pending.delete(data.id);
            data.error ? callback.reject(Error(JSON.stringify(data.error))) : callback.resolve(data.result);
        } else if (data.method === 'Runtime.exceptionThrown' || (data.method === 'Runtime.consoleAPICalled' && data.params.type === 'error')) errors.push(data.params);
    });
    const send = (method, params = {}) => new Promise((resolve, reject) => {
        const current = ++id;
        pending.set(current, {resolve, reject});
        socket.send(JSON.stringify({id: current, method, params}));
    });
    const evaluate = async expression => {
        const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
        if (result.exceptionDetails) throw Error(JSON.stringify(result.exceptionDetails));
        return result.result.value;
    };
    const until = async expression => {
        for (let attempt = 0; attempt < 80; attempt++) {
            if (await evaluate(expression)) return;
            await delay(50);
        }
        throw Error(`Timed out waiting for ${expression}`);
    };
    await send('Page.enable');
    await send('Runtime.enable');
    await send('Network.enable');
    await send('Network.setCookie', {name: session.name, value: session.value, url: session.url, httpOnly: true, sameSite: 'Lax'});
    await send('Page.addScriptToEvaluateOnNewDocument', {source: "window.__qaPending=0;const nativeFetch=window.fetch;window.fetch=async(...args)=>{window.__qaPending++;try{return await nativeFetch(...args);}finally{window.__qaPending--;}};"});
    const navigate = async route => {
        await send('Page.navigate', {url: session.url + route});
        await delay(200);
        await until("document.readyState==='complete' && !!document.querySelector('[data-master-workspace]')");
    };
    const screenshot = async name => {
        if (!output) return;
        fs.mkdirSync(output, {recursive: true});
        fs.writeFileSync(path.join(output, name + '.png'), Buffer.from((await send('Page.captureScreenshot', {format: 'png'})).data, 'base64'));
    };
    return {session, send, evaluate, until, navigate, screenshot, errors, close: () => { socket.close(); chrome.kill(); }};
}

test('guided master browser regressions', {skip: !sessionFile || !chromePath}, async t => {
    const b = await browser();
    try {
        await b.send('Emulation.setDeviceMetricsOverride', {width: 1440, height: 900, deviceScaleFactor: 1, mobile: false});
        await t.test('scope search retains the selected reference', async () => {
            await b.navigate(`/master/pemeriksaan-kesehatan/${b.session.healthTemplate}`);
            await b.evaluate("const scope=document.querySelector('[data-config-scope]');scope.value='COUNTRY';scope.dispatchEvent(new Event('change',{bubbles:true}));");
            await delay(350);
            await b.until("!window.__qaPending && [...document.querySelector('[data-config-reference]').options].some(o=>o.text==='Jepang')");
            const selected = await b.evaluate("const references=document.querySelector('[data-config-reference]');references.value=[...references.options].find(o=>o.text==='Jepang').value;references.value;");
            await b.evaluate("const search=document.querySelector('[data-scope-search]');search.value='Jepang';search.dispatchEvent(new Event('input',{bubbles:true}));");
            await delay(350);
            await b.until('!window.__qaPending');
            await b.screenshot('scope-retained');
            assert.equal(await b.evaluate("document.querySelector('[data-config-reference]').value"), selected);
        });
        assert.deepEqual(b.errors, []);
    } finally { b.close(); }
});
