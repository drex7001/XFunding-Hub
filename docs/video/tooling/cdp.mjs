/**
 * A small Chrome DevTools Protocol client over a debugging pipe, with no dependencies.
 * No network port is opened, and each signed-in person gets an isolated browser context.
 */
import { spawn } from 'node:child_process';

export const BASE = process.env.VIDEO_BASE_URL ?? 'http://xfunding-hub.test';

const baseHost = new URL(BASE).hostname;
if (!(baseHost === 'localhost' || baseHost === '127.0.0.1' || baseHost.endsWith('.test') || baseHost.endsWith('.localhost'))) {
    throw new Error(`Refusing to run against ${BASE}: this tool signs in with demo accounts and writes records, so it only runs on a local site.`);
}

export const report = { checks: [], console: [], http: [], overflow: [], pages: 0 };

export const check = (name, ok, detail = '') => {
    report.checks.push({ name, ok: Boolean(ok), detail: String(detail).slice(0, 220) });
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${!ok && detail ? `  :: ${String(detail).slice(0, 220)}` : ''}`);
    return Boolean(ok);
};

export class Browser {
    constructor(profile) {
        this.id = 0;
        this.pending = new Map();
        this.pages = new Map();
        this.buffer = Buffer.alloc(0);
        this.chrome = spawn('google-chrome', ['--headless=new', '--remote-debugging-pipe', `--user-data-dir=${profile}`,
            '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--disable-background-networking',
            '--disable-sync', 'about:blank'], { stdio: ['ignore', 'ignore', 'ignore', 'pipe', 'pipe'] });
        this.chrome.stdio[4].on('data', (chunk) => this.onData(chunk));
    }

    onData(chunk) {
        this.buffer = Buffer.concat([this.buffer, chunk]);
        let end;
        while ((end = this.buffer.indexOf(0)) !== -1) {
            const message = JSON.parse(this.buffer.subarray(0, end).toString());
            this.buffer = this.buffer.subarray(end + 1);
            if (message.id && this.pending.has(message.id)) {
                const { resolve, reject, method } = this.pending.get(message.id);
                this.pending.delete(message.id);
                if (message.error) {
                    reject(new Error(`${method}: ${message.error.message}`));
                } else {
                    resolve(message.result);
                }
            } else if (message.sessionId && this.pages.has(message.sessionId)) {
                this.pages.get(message.sessionId).onEvent(message.method, message.params);
            }
        }
    }

    send(method, params = {}, sessionId) {
        const id = ++this.id;
        return new Promise((resolve, reject) => {
            this.pending.set(id, { resolve, reject, method });
            this.chrome.stdio[3].write(`${JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) })}\0`);
        });
    }

    async newPage(label) {
        const { browserContextId } = await this.send('Target.createBrowserContext');
        const { targetId } = await this.send('Target.createTarget', { url: 'about:blank', browserContextId });
        const { sessionId } = await this.send('Target.attachToTarget', { targetId, flatten: true });
        const page = new Page(this, sessionId, label);
        this.pages.set(sessionId, page);
        for (const domain of ['Page', 'Runtime', 'Log', 'Network', 'DOM']) {
            await page.send(`${domain}.enable`);
        }
        return page;
    }

    /** Closes Chrome and waits for it to exit, so its profile folder is safe to delete. */
    async close() {
        const exited = new Promise((resolve) => {
            this.chrome.once('exit', resolve);
            setTimeout(resolve, 5000);
        });
        this.send('Browser.close').catch(() => {});
        await exited;
        this.chrome.kill();
    }
}

export class Page {
    constructor(browser, sessionId, label) {
        this.browser = browser;
        this.sessionId = sessionId;
        this.label = label;
        this.loadWaiters = [];
        this.expectedStatus = new Set();
    }

    send(method, params) {
        return this.browser.send(method, params, this.sessionId);
    }

    onEvent(method, params) {
        if (method === 'Page.loadEventFired') {
            this.loadWaiters.splice(0).forEach((resolve) => resolve());
        }
        if (method === 'Runtime.exceptionThrown') {
            report.console.push({ page: this.label, url: this.path, text: params.exceptionDetails.exception?.description ?? params.exceptionDetails.text });
        }
        if (method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(params.type)) {
            report.console.push({ page: this.label, url: this.path, text: params.args.map((a) => a.value ?? a.description).join(' ') });
        }
        if (method === 'Log.entryAdded' && ['error', 'warning'].includes(params.entry.level)) {
            const status = /status of (\d+)/.exec(params.entry.text)?.[1];
            if (!(status && this.expectedStatus.has(Number(status)))) {
                report.console.push({ page: this.label, url: params.entry.url ?? this.path, text: params.entry.text });
            }
        }
        if (method === 'Network.responseReceived' && params.response.status >= 400 && !this.expectedStatus.has(params.response.status)) {
            report.http.push({ page: this.label, url: params.response.url.replace(BASE, ''), status: params.response.status });
        }
        if (method === 'Page.javascriptDialogOpening') {
            report.console.push({ page: this.label, url: this.path, text: `dialog: ${params.message}` });
            this.send('Page.handleJavaScriptDialog', { accept: true });
        }
    }

    loaded(timeout = 20000) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => reject(new Error(`[${this.label}] page load timed out at ${this.path}`)), timeout);
            this.loadWaiters.push(() => {
                clearTimeout(timer);
                resolve();
            });
        });
    }

    async eval(expression) {
        const { result, exceptionDetails } = await this.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
        if (exceptionDetails) {
            throw new Error(`eval failed: ${exceptionDetails.exception?.description ?? exceptionDetails.text}`);
        }
        return result.value;
    }

    async settle() {
        this.path = await this.eval('location.pathname + location.search');
        report.pages++;
        const overflow = await this.eval('document.documentElement.scrollWidth > document.documentElement.clientWidth + 1');
        if (overflow) {
            report.overflow.push({ page: this.label, url: this.path });
        }
    }

    async goto(path) {
        const done = this.loaded();
        await this.send('Page.navigate', { url: path.startsWith('http') ? path : BASE + path });
        await done;
        await this.settle();
    }

    async has(locator) {
        return Boolean(await this.eval(finder(locator)));
    }

    async fill(selector, value) {
        const found = await this.eval(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); if (!e) { return false; } e.focus(); e.value = ''; return true; })()`);
        if (!found) {
            throw new Error(`[${this.label}] no field ${selector} at ${this.path}`);
        }
        await this.send('Input.insertText', { text: value });
    }

    async pressButton(locator) {
        const target = await this.eval(finder(locator));
        if (!target) {
            throw new Error(`[${this.label}] nothing matches ${JSON.stringify(locator)} at ${this.path}`);
        }
        const done = this.loaded();
        const at = { x: target.x, y: target.y, button: 'left', clickCount: 1 };
        await this.send('Input.dispatchMouseEvent', { type: 'mousePressed', ...at });
        await this.send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...at });
        await done;
        await this.settle();
    }

    async upload(selector, file) {
        const { root } = await this.send('DOM.getDocument');
        const { nodeId } = await this.send('DOM.querySelector', { nodeId: root.nodeId, selector });
        await this.send('DOM.setFileInputFiles', { files: [file], nodeId });
    }

    text() {
        return this.eval('document.body.innerText');
    }
}

/** Finds the first visible matching element and returns where it is, ignoring the recording overlay. */
export const finder = (locator) => `(() => {
    const l = ${JSON.stringify(locator)};
    const visible = (e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && !e.closest('#film-layer'); };
    const all = [...document.querySelectorAll(l.css ?? 'a,button')].filter(visible);
    const el = l.text ? all.find((e) => e.innerText.replace(/\\s+/g, ' ').includes(l.text)) : all[0];
    if (!el) { return null; }
    const r = el.getBoundingClientRect();
    return { x: r.left + r.width / 2, y: r.top + r.height / 2, top: r.top, bottom: r.bottom, inSidebar: Boolean(el.closest('.sidebar')), scrollY, max: document.documentElement.scrollHeight - innerHeight };
})()`;
