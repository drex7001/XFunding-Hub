/**
 * Opens a finished video in Chrome, confirms it plays, and reports whether it has audio.
 * Usage: node verify.mjs <video> <work dir> <audio|silent> [seconds to sample...]
 */
import { rmSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';
import { Browser } from './cdp.mjs';

const [video, work, expected, ...times] = process.argv.slice(2);
const profile = join(work, 'chrome-verify');
rmSync(profile, { recursive: true, force: true });
const browser = new Browser(profile);
let ok = false;
try {
    const blank = join(work, 'blank.html');
    writeFileSync(blank, '<!DOCTYPE html><html><head><meta charset="utf-8"><title>verify</title></head><body style="margin:0;background:#000"></body></html>');
    const page = await browser.newPage('verify');
    await page.send('Emulation.setDeviceMetricsOverride', { width: 960, height: 540, deviceScaleFactor: 1, mobile: false });
    const done = page.loaded();
    await page.send('Page.navigate', { url: `file://${blank}` });
    await done;
    const meta = await page.eval(`new Promise((resolve) => {
        const v = document.createElement('video');
        v.id = 'v'; v.muted = true; v.style.cssText = 'width:960px;height:540px;display:block';
        v.onloadedmetadata = () => resolve({ duration: v.duration, width: v.videoWidth, height: v.videoHeight });
        v.onerror = () => resolve({ error: v.error && v.error.message });
        v.src = ${JSON.stringify(`file://${video}`)};
        document.body.append(v);
    })`);
    if (meta.error) {
        throw new Error(meta.error);
    }
    for (const time of times) {
        await page.eval(`new Promise((resolve) => { const v = document.getElementById('v'); v.onseeked = () => requestAnimationFrame(() => requestAnimationFrame(resolve)); v.currentTime = ${time}; })`);
        const { data } = await page.send('Page.captureScreenshot', { format: 'jpeg', quality: 85 });
        writeFileSync(join(work, `sample-${basename(video, '.mp4')}-${String(time).padStart(4, '0')}.jpg`), Buffer.from(data, 'base64'));
    }
    const played = await page.eval(`new Promise((resolve) => { const v = document.getElementById('v'); v.onseeked = async () => { await v.play(); setTimeout(() => { const q = v.getVideoPlaybackQuality(); v.pause(); resolve({ advanced: v.currentTime > 10.5, dropped: q.droppedVideoFrames, audioBytes: v.webkitAudioDecodedByteCount }); }, 1500); }; v.currentTime = 10; })`);
    ok = played.advanced && (expected === 'audio' ? played.audioBytes > 0 : played.audioBytes === 0);
    console.log(`${basename(video)}: ${meta.width}x${meta.height}, ${meta.duration.toFixed(1)} s, plays ${played.advanced}, audio bytes decoded ${played.audioBytes}, expected ${expected}: ${ok ? 'ok' : 'FAILED'}`);
} finally {
    await browser.close();
    rmSync(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
    process.exit(ok ? 0 : 1);
}
