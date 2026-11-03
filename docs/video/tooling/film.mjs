/**
 * Records the full workflow from the running local app with real clicks.
 *
 * Every frame is captured once per language, so one run produces matching English and
 * Sinhala frame sets and adds only one synthetic deal. Pacing comes from the English
 * narration. The run stops if a Sinhala letter is visible on any English frame.
 */
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Browser, check, finder, report } from './cdp.mjs';

const HERE = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(HERE, '../../..');
const WORK = process.env.VIDEO_WORK_DIR;
const PIPER = process.env.PIPER_BIN;
const MODEL = process.env.PIPER_MODEL;
const AUDIO = process.env.VIDEO_AUDIO_CACHE;
const PREVIEW = process.env.VIDEO_PREVIEW === '1';
const LANGUAGES = ['en', 'si'];
const FPS = 30;
const WIDTH = 1280;
const HEIGHT = 720;
const SAFE_TOP = 56;
const SAFE_BOTTOM = HEIGHT - 120;
const SINHALA = /[඀-෿]/;

const script = Object.fromEntries(LANGUAGES.map((language) => [language, JSON.parse(readFileSync(join(HERE, `script.${language}.json`), 'utf8'))]));
if (Object.values(script.en).some((text) => SINHALA.test(text))) {
    throw new Error('script.en.json contains Sinhala text.');
}
const OVERLAY = readFileSync(join(HERE, 'overlay.js'), 'utf8');
for (const language of LANGUAGES) {
    rmSync(join(WORK, 'frames', language), { recursive: true, force: true });
    mkdirSync(join(WORK, 'frames', language), { recursive: true });
}
mkdirSync(AUDIO, { recursive: true });

const seeded = (file) => [...readFileSync(join(REPO, 'database/seeders', file), 'utf8').matchAll(/'password' => '([^']+)'/g)].map((m) => m[1]);
const bankSecret = seeded('DemoBankSeeder.php')[1];
const lenderThreeSecret = seeded('DemoMarketplaceSeeder.php')[0];
const email = (key, role) => `${key}.${role}@xfunding.example`;

const profile = join(WORK, 'chrome-profile');
rmSync(profile, { recursive: true, force: true });
const browser = new Browser(profile);

const frames = [];
const cues = [];
let totalFrames = 0;
let voiceEnd = 0;
let sequence = 0;
let englishFramesChecked = 0;
let pointer = { x: 760, y: 420 };
let caption = null;

const now = new Date();
const deadline = new Date(now.getTime() + 2 * 3600 * 1000);
const stamp = now.toISOString().slice(5, 16).replace(/[-:T]/g, '');
const TITLE = `Video walkthrough (synthetic) ${stamp}`;
const APPROVE = { css: 'button[value=approve]' };
const ease = (t) => (t < 0.5 ? 4 * t * t * t : 1 - (-2 * t + 2) ** 3 / 2);
const clamp = (value, low, high) => Math.min(high, Math.max(low, value));
const perLanguage = (build) => Object.fromEntries(LANGUAGES.map((language) => [language, build(script[language])]));
const step = (n, who) => perLanguage((text) => text['kicker.step'].replace('{n}', n).replace('{who}', text[`who.${who}`]));

function synth(text) {
    const spoken = text.replaceAll('XFunding Hub', 'X Funding Hub').replaceAll('PDF', 'P D F');
    const file = join(AUDIO, `${createHash('sha1').update(`${MODEL}${spoken}`).digest('hex').slice(0, 16)}.wav`);
    if (!existsSync(file)) {
        execFileSync(PIPER, ['--model', MODEL, '--output_file', file, '--sentence_silence', '0.35', '--length_scale', '1.03'], { input: spoken, stdio: ['pipe', 'ignore', 'ignore'] });
    }
    const wav = readFileSync(file);
    return { file, duration: wav.readUInt32LE(wav.indexOf('data', 12) + 4) / (wav.readUInt32LE(24) * 2) };
}
function voice(text) {
    const { file, duration } = synth(text);
    const at = Math.max(totalFrames / FPS + 0.2, voiceEnd + 0.05);
    cues.push({ text, file, at, duration });
    voiceEnd = at + duration;
    return voiceEnd - totalFrames / FPS;
}
const voiceLeft = () => Math.max(0, voiceEnd - totalFrames / FPS);

async function shoot(page, seconds = 1 / FPS) {
    const file = `${String(++sequence).padStart(6, '0')}.jpg`;
    for (const language of LANGUAGES) {
        await page.eval(`__film.show('${language}')`);
        if (language === 'en') {
            if (await page.eval(`${SINHALA}.test(document.documentElement.innerText)`)) {
                throw new Error(`A Sinhala letter is visible on an English frame at ${page.path}`);
            }
            englishFramesChecked++;
        }
        const { data } = await page.send('Page.captureScreenshot', { format: 'jpeg', quality: 92, captureBeyondViewport: false });
        writeFileSync(join(WORK, 'frames', language, file), Buffer.from(data, 'base64'));
    }
    const count = Math.max(1, Math.round(seconds * FPS));
    frames.push({ file, count });
    totalFrames += count;
}
async function prep(page) {
    await page.eval(OVERLAY);
    await page.eval(`__film.caption(${JSON.stringify(caption)}); __film.pointer(${pointer.x}, ${pointer.y});`);
}
async function say(page, kicker, key, seconds = 3.6) {
    caption = Object.fromEntries(LANGUAGES.map((language) => [language, { kicker: kicker[language], text: script[language][key] }]));
    await page.eval(`__film.caption(${JSON.stringify(caption)})`);
    await shoot(page, Math.max(seconds, voice(script.en[key]) + 0.55));
}
async function card(page, spec, seconds, voiceKey) {
    await page.eval(`__film.card(${JSON.stringify(spec)})`);
    await shoot(page, voiceKey ? Math.max(seconds, voice(script.en[voiceKey]) + 0.6) : seconds);
}
const clearCard = (page) => page.eval('__film.card(null)');
async function scene(page, sceneKey, who, bank, voiceKey = who) {
    caption = null;
    await page.goto('/dashboard');
    await prep(page);
    await card(page, perLanguage((text) => ({ kicker: text[`scene.${sceneKey}`], title: text[`who.${who}`], sub: text[`bank.${bank}`] })), 1.8, `voice.scene.${voiceKey}`);
    await clearCard(page);
}
async function scrollTo(page, target) {
    const start = await page.eval('scrollY');
    const max = await page.eval('document.documentElement.scrollHeight - innerHeight');
    const end = clamp(Math.round(target), 0, Math.max(0, max));
    const steps = clamp(Math.round(Math.abs(end - start) / 26), 0, 30);
    for (let i = 1; i <= steps; i++) {
        await page.eval(`window.scrollTo({ top: ${Math.round(start + (end - start) * ease(i / steps))}, behavior: 'instant' })`);
        await shoot(page);
    }
}
async function locate(page, locator) {
    const box = await page.eval(finder(locator));
    if (!box) {
        throw new Error(`[${page.label}] nothing matches ${JSON.stringify(locator)} at ${page.path}`);
    }
    if (box.inSidebar || (box.top >= SAFE_TOP && box.bottom <= SAFE_BOTTOM)) {
        return box;
    }
    await scrollTo(page, box.scrollY + box.top - 240);
    return page.eval(finder(locator));
}
async function moveTo(page, x, y) {
    const from = { ...pointer };
    const steps = clamp(Math.round(Math.hypot(x - from.x, y - from.y) / 42), 7, 20);
    for (let i = 1; i <= steps; i++) {
        const t = ease(i / steps);
        pointer = { x: from.x + (x - from.x) * t, y: from.y + (y - from.y) * t };
        await page.eval(`__film.pointer(${pointer.x}, ${pointer.y})`);
        await shoot(page);
    }
    await page.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
}
async function click(page, locator, { navigates = true, after = 0.5 } = {}) {
    const box = await locate(page, locator);
    await moveTo(page, box.x, box.y);
    await shoot(page, 0.2);
    const done = navigates ? page.loaded() : null;
    for (const progress of [0, 0.3, 0.6]) {
        await page.eval(`__film.ripple(${box.x}, ${box.y}, ${progress})`);
        await shoot(page);
    }
    await page.eval('__film.ripple(0, 0, null)');
    const at = { x: box.x, y: box.y, button: 'left', clickCount: 1 };
    await page.send('Input.dispatchMouseEvent', { type: 'mousePressed', ...at });
    await page.send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...at });
    if (done) {
        await done;
        await page.settle();
        await prep(page);
    }
    await shoot(page, after);
}
async function type(page, selector, text) {
    await click(page, { css: selector }, { navigates: false, after: 0.1 });
    for (let i = 0; i < text.length; i += 2) {
        await page.send('Input.insertText', { text: text.slice(i, i + 2) });
        await shoot(page, 2 / FPS);
    }
    await shoot(page, 0.25);
}
async function setValue(page, selector, value) {
    const box = await locate(page, { css: selector });
    await moveTo(page, box.x, box.y);
    await page.eval(`(() => { const e = document.querySelector(${JSON.stringify(selector)}); e.value = ${JSON.stringify(value)}; e.dispatchEvent(new Event('input', { bubbles: true })); e.dispatchEvent(new Event('change', { bubbles: true })); })()`);
    await shoot(page, 0.5);
}
const cleanHtml = (page) => page.eval(`(() => { const c = document.documentElement.cloneNode(true); c.querySelector('#film-layer')?.remove(); return c.outerHTML; })()`);
async function noLenderNames(page, where) {
    const html = await cleanHtml(page);
    const leaked = ['Northbridge', 'Meridian', 'lender-one', 'lender-three', 'DEMO-lender'].filter((marker) => html.includes(marker));
    if (!check(`${page.label}: no lender identity on ${where}`, leaked.length === 0, leaked.join(','))) {
        throw new Error(`Lender identity leaked on ${where}`);
    }
}
async function signedIn(label, address, secret) {
    const page = await browser.newPage(label);
    await page.send('Emulation.setFocusEmulationEnabled', { enabled: true });
    await page.send('Emulation.setDeviceMetricsOverride', { width: WIDTH, height: HEIGHT, deviceScaleFactor: 1.5, mobile: false });
    if (secret) {
        await page.goto('/login');
        await page.fill('#email', address);
        await page.fill('#password', secret);
        await page.pressButton({ css: 'button', text: 'Sign in' });
        if (page.path !== '/dashboard') {
            throw new Error(`${label} could not sign in`);
        }
    }
    return page;
}
async function openQueueItem(page, kicker, key) {
    const target = { css: 'a.work-item:not(.work-item--waiting)', text: TITLE };
    if (!check(`${page.label}: Home queue shows the request`, await page.has(target))) {
        throw new Error('Work item missing');
    }
    await say(page, kicker, key, 3.2);
    await click(page, target);
}

async function run() {
    const bm = await signedIn('borrower-maker', email('borrower', 'maker'));
    const others = {};
    if (!PREVIEW) {
        others.ba = await signedIn('borrower-approver', email('borrower', 'approver'), bankSecret);
        others.lom = await signedIn('lender-one-maker', email('lender-one', 'maker'), bankSecret);
        others.loa = await signedIn('lender-one-approver', email('lender-one', 'approver'), bankSecret);
        others.ltm = await signedIn('lender-three-maker', email('lender-three', 'maker'), lenderThreeSecret);
        others.lta = await signedIn('lender-three-approver', email('lender-three', 'approver'), lenderThreeSecret);
        others.aud = await signedIn('borrower-auditor', email('borrower', 'auditor'), bankSecret);
    }
    const { ba, lom, loa, ltm, lta, aud } = others;

    await bm.goto('/login');
    await prep(bm);
    await card(bm, perLanguage((text) => ({ kicker: text['title.kicker'], title: text['title.title'], sub: text['title.sub'] })), 5.5, 'voice.title');
    await clearCard(bm);

    let kicker = step(1, 'borrowerMaker');
    await say(bm, kicker, 'login.signIn', 2.6);
    await type(bm, '#email', email('borrower', 'maker'));
    if (!check('password field stays masked', (await bm.eval(`document.querySelector('#password').type`)) === 'password')) {
        throw new Error('The password field is not masked.');
    }
    await type(bm, '#password', bankSecret);
    await click(bm, { css: 'button', text: 'Sign in' });
    await say(bm, kicker, 'home.work');
    await click(bm, { css: 'a', text: 'Funding requests' });
    await click(bm, { css: 'a', text: 'New funding request' });
    await say(bm, kicker, 'form.enter', 3.4);
    await type(bm, '#title', TITLE);
    await type(bm, '#amount', '25000000.00');
    await setValue(bm, '#deadline', deadline.toISOString().slice(0, 16));
    await setValue(bm, '#settlement', deadline.toISOString().slice(0, 10));
    await type(bm, '#background', 'Synthetic request created for a product walkthrough video. It is not a real funding need.');
    if (PREVIEW) {
        return;
    }
    await click(bm, { css: 'button', text: 'Save draft and continue' });
    const requestPath = bm.path;
    await say(bm, kicker, 'draft.saved', 4);
    const fileInput = await locate(bm, { css: '#document' });
    await moveTo(bm, fileInput.x, fileInput.y);
    await bm.upload('#document', join(HERE, 'financial-statement-demo.pdf'));
    await shoot(bm, 0.8);
    await click(bm, { css: 'button', text: 'Upload privately' });
    await say(bm, kicker, 'draft.uploaded', 3);
    await click(bm, { css: 'button', text: 'Submit for publication approval' });
    check('maker has no approve control', !(await bm.has(APPROVE)));
    await say(bm, kicker, 'draft.submitted', 4);

    kicker = step(2, 'borrowerApprover');
    await scene(ba, 'signedIn', 'borrowerApprover', 'harborDifferent', 'borrowerApproverFirst');
    await openQueueItem(ba, kicker, 'publish.queue');
    await say(ba, kicker, 'publish.review', 4);
    await click(ba, APPROVE);
    await say(ba, kicker, 'publish.approved', 3.4);

    const bid = async (maker, approver, name, bank, rate, firstBid) => {
        const makerKicker = step(3, `${name}Maker`);
        await scene(maker, 'signedIn', `${name}Maker`, bank);
        await click(maker, { css: 'a', text: 'Marketplace' });
        if (firstBid) {
            await say(maker, makerKicker, 'bid.marketplace', 3);
        }
        await click(maker, { css: 'a', text: TITLE });
        if (firstBid) {
            await say(maker, makerKicker, 'bid.request', 5);
        }
        await click(maker, { css: 'a', text: 'Prepare an offer' });
        await say(maker, makerKicker, firstBid ? 'bid.form' : 'bid.second', 3.2);
        await type(maker, '#rate', rate);
        await click(maker, { css: 'button', text: 'Save and send for approval' });
        check(`${name}: maker has no approve control`, !(await maker.has(APPROVE)));
        if (firstBid) {
            await say(maker, makerKicker, 'bid.needsApproval', 3.4);
        }
        const approverKicker = step(3, `${name}Approver`);
        await scene(approver, 'signedIn', `${name}Approver`, bank);
        await openQueueItem(approver, approverKicker, 'bid.approverQueue');
        await click(approver, APPROVE);
        await say(approver, approverKicker, 'bid.live', 2.6);
    };
    await bid(lom, loa, 'lenderOne', 'northbridge', '8.2500', true);
    await bid(ltm, lta, 'lenderThree', 'meridian', '7.9500', false);

    kicker = step(4, 'borrowerMaker');
    await scene(bm, 'signedIn', 'borrowerMaker', 'harbor');
    await openQueueItem(bm, kicker, 'compare.queue');
    if (await bm.has({ css: 'a', text: 'Compare offers' })) {
        await click(bm, { css: 'a', text: 'Compare offers' });
    }
    await noLenderNames(bm, 'blind comparison');
    check('comparison shows both offers', (await bm.eval(`document.querySelectorAll('button[name=offer_token]').length`)) === 2);
    await say(bm, kicker, 'compare.blind', 5);
    await bm.eval(`[...document.querySelectorAll('button[name=offer_token]')].find((e) => e.closest('tr,article,li,section').innerText.includes('7.95')).dataset.pick = '1'`);
    await click(bm, { css: '[data-pick]' });
    await noLenderNames(bm, 'selection proposal');
    await say(bm, kicker, 'compare.proposed', 3.8);

    kicker = step(5, 'borrowerApprover');
    await scene(ba, 'signedIn', 'borrowerApprover', 'harbor');
    await openQueueItem(ba, kicker, 'select.queue');
    await noLenderNames(ba, 'selection review');
    await say(ba, kicker, 'select.review', 3.6);
    await click(ba, APPROVE);
    if (await ba.has({ css: 'a', text: 'View selected deal' })) {
        await click(ba, { css: 'a', text: 'View selected deal' });
    }
    const dealPath = ba.path;
    check('deal opened', /^\/deals\/[0-9A-Z]{26}$/i.test(dealPath), dealPath);

    kicker = step(6, 'platformFee');
    for (let i = 0; i < 20 && !(await ba.has({ css: 'button', text: 'Request demo fee payment' })); i++) {
        await new Promise((r) => setTimeout(r, 1500));
        await ba.goto(dealPath);
        await prep(ba);
    }
    await noLenderNames(ba, 'deal awaiting fee');
    await say(ba, kicker, 'fee.deal', 4.4);
    await click(ba, { css: 'button', text: 'Request demo fee payment' });
    await noLenderNames(ba, 'payment pending');
    const reference = /XFP-[A-Za-z0-9-]+/.exec(await ba.text())?.[0];
    if (!reference) {
        throw new Error('No payment reference on the deal page.');
    }
    await say(ba, kicker, 'fee.requested', 4);

    await scene(ltm, 'meanwhile', 'lenderThreeMaker', 'meridian', 'meanwhile');
    await ltm.goto(dealPath);
    await prep(ltm);
    check('selected lender waits', /waiting for the borrower/i.test(await ltm.text()));
    await say(ltm, kicker, 'fee.lenderWaits', 3.8);

    lom.expectedStatus.add(403);
    lom.expectedStatus.add(404);
    await scene(lom, 'notSelected', 'lenderOneMaker', 'northbridge', 'notSelected');
    await lom.goto(dealPath);
    await prep(lom);
    check('other lender is blocked', /not available/i.test(await lom.text()));
    await say(lom, kicker, 'fee.otherBlocked', 3.4);

    caption = null;
    await ba.goto(dealPath);
    await prep(ba);
    const command = `$ php artisan payments:simulate '${reference}'`;
    voice(script.en['voice.provider.start']);
    for (let i = 2; i < command.length + 3; i += 3) {
        await ba.eval(`__film.card(${JSON.stringify(perLanguage((text) => ({ kicker: text['provider.kicker'], title: text['provider.title'], code: command.slice(0, i) })))})`);
        await shoot(ba, 2 / FPS);
    }
    await shoot(ba, Math.max(0.8, voiceLeft() + 0.2));
    const output = execFileSync('php', ['artisan', 'payments:simulate', reference], { cwd: REPO, encoding: 'utf8' }).trim().split('\n').map((line) => line.trim()).filter(Boolean).join('\n');
    check('provider verified the payment', /verified/i.test(output), output);
    await card(ba, perLanguage((text) => ({ kicker: text['provider.kicker'], title: text['provider.title'], sub: text['provider.sub'], code: `${command}\n${output}` })), 5.5, 'voice.provider.callback');
    await clearCard(ba);

    kicker = step(7, 'identityUnlocks');
    await ba.goto(dealPath);
    await prep(ba);
    check('next step signposts the unlock', /identity and account are now unlocked/.test(await ba.text()));
    await say(ba, kicker, 'unlock.verified', 3.4);
    await click(ba, { css: '.next-step a', text: 'Open the settlement details' });
    const details = await cleanHtml(ba);
    if (!check('selected lender is revealed, other lender is not', details.includes('Meridian') && !details.includes('Northbridge'))) {
        throw new Error('Unexpected identity on the settlement page.');
    }
    await say(ba, kicker, 'unlock.details', 5.5);

    const attest = async (maker, approver, side, bank, voiceSide, firstSide) => {
        const makerKicker = step(8, `${side}Maker`);
        await scene(maker, 'signedIn', `${voiceSide}Maker`, bank, `${side}Maker`);
        await maker.goto(dealPath);
        await prep(maker);
        await say(maker, makerKicker, firstSide ? 'settle.lenderSends' : 'settle.borrowerRecords', firstSide ? 5 : 3);
        await type(maker, '#settlement-reference', `DEMO-${side.toUpperCase()}-${stamp}`);
        await click(maker, { css: 'button', text: 'Propose settlement confirmation' });
        check(`${side}: maker has no approve control on the confirmation`, !(await maker.has(APPROVE)));
        if (firstSide) {
            await say(maker, makerKicker, 'settle.needsApproval', 3);
        }
        const approverKicker = step(8, `${side}Approver`);
        await scene(approver, 'signedIn', `${voiceSide}Approver`, bank, `${side}Approver`);
        await openQueueItem(approver, approverKicker, firstSide ? 'settle.lenderApprover' : 'settle.borrowerApprover');
        if (!(await approver.has(APPROVE))) {
            await approver.goto(dealPath);
            await prep(approver);
        }
        await click(approver, APPROVE);
    };
    await attest(ltm, lta, 'lender', 'meridian', 'lenderThree', true);
    await attest(bm, ba, 'borrower', 'harbor', 'borrower', false);
    await ba.goto(dealPath);
    await prep(ba);
    check('deal is settled', /\bsettled\b/i.test(await ba.text()));
    kicker = step(8, 'settled');
    await say(ba, kicker, 'settle.done', 4);
    const timeline = await ba.eval(finder({ css: 'h2', text: 'Timeline' }));
    await scrollTo(ba, timeline.scrollY + timeline.top - 60);
    await say(ba, kicker, 'settle.timeline', 4);

    kicker = step(9, 'borrowerAuditor');
    await scene(aud, 'signedIn', 'borrowerAuditor', 'harborReadOnly');
    await click(aud, { css: 'a', text: 'My bank' });
    await click(aud, { css: 'a', text: 'Audit history' });
    await say(aud, kicker, 'audit.history', 5.5);

    caption = null;
    await aud.eval(`__film.caption(null)`);
    await card(aud, perLanguage((text) => ({ kicker: 'XFunding Hub', title: text['end.title'], lines: [text['end.line1'], text['end.line2'], text['end.line3']] })), 7, 'voice.end');
    await shoot(aud, 1);
    writeFileSync(join(WORK, 'run.json'), JSON.stringify({ TITLE, requestPath, dealPath, reference }, null, 1));
    console.log(JSON.stringify({ TITLE, requestPath, dealPath, reference }));
}

let failed = false;
try {
    await run();
} catch (error) {
    failed = true;
    check('recording ran to the end', false, error.message);
} finally {
    const total = frames.reduce((sum, frame) => sum + frame.count, 0);
    writeFileSync(join(WORK, 'frames.json'), JSON.stringify(frames));
    writeFileSync(join(WORK, 'cues.json'), JSON.stringify({ seconds: total / FPS, cues }, null, 1));
    writeFileSync(join(WORK, 'report.json'), JSON.stringify(report, null, 1));
    await browser.close();
    rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
    const bad = report.checks.filter((item) => !item.ok);
    console.log(`captured ${frames.length} frames per language, ${cues.length} voice cues, length ${(total / FPS / 60).toFixed(2)} min, checks ${report.checks.length}, failed ${bad.length}, console ${report.console.length}, http ${report.http.length}, overflow ${report.overflow.length}, english frames checked for Sinhala ${englishFramesChecked}`);
    console.log(failed ? 'status FAILED' : 'status ok');
    process.exit(failed ? 1 : 0);
}
