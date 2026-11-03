/**
 * Draws the captions, cards and pointer on top of the app for the recording.
 * It holds the text for every language so one recorded frame can be captured once per language.
 */
(() => {
    document.querySelectorAll('input,textarea').forEach((field) => { field.spellcheck = false; });
    if (window.__film) { return; }

    const make = (css, html = '') => {
        const element = document.createElement('div');
        element.style.cssText = css;
        element.innerHTML = html;
        return element;
    };
    const escapeHtml = (text) => String(text).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
    const story = document.querySelector('.auth__story');
    const left = document.querySelector('.sidebar') ? 268 : (story ? Math.round(story.getBoundingClientRect().right) + 20 : 20);

    const layer = make('position:fixed;inset:0;z-index:2147483647;pointer-events:none;font-family:Inter,"Noto Sans","Noto Sans Sinhala",system-ui,sans-serif;');
    layer.id = 'film-layer';
    const caption = make(`position:absolute;left:${left}px;right:20px;bottom:14px;padding:11px 18px 13px;background:rgba(14,38,50,.97);color:#fff;border-radius:10px;box-shadow:0 10px 30px rgba(14,38,50,.35);display:none;`,
        '<div data-kicker style="font-size:12.5px;font-weight:600;color:#3fd8bf;margin-bottom:3px"></div><div data-text style="font-size:18px;font-weight:600;line-height:1.4"></div>');
    const card = make('position:absolute;inset:0;background:#0e2632;color:#fff;display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:60px;');
    const ripple = make('position:absolute;width:44px;height:44px;margin:-22px 0 0 -22px;border:3px solid #0a7a6b;border-radius:50%;background:rgba(58,214,188,.28);display:none;');
    const pointer = make('position:absolute;width:26px;height:26px;display:none;', '<svg width="26" height="26" viewBox="0 0 24 24"><path d="M5 2.5v17.2l4.7-4.5 3 6.6 3-1.3-3-6.5h6.4z" fill="#fff" stroke="#10242f" stroke-width="1.6" stroke-linejoin="round"/></svg>');
    layer.append(caption, ripple, pointer, card);
    document.documentElement.append(layer);

    const state = { language: 'en', caption: null, card: null };
    const render = () => {
        const line = state.caption?.[state.language];
        caption.style.display = line ? 'block' : 'none';
        caption.querySelector('[data-kicker]').textContent = line?.kicker ?? '';
        caption.querySelector('[data-text]').textContent = line?.text ?? '';

        const spec = state.card?.[state.language];
        card.style.display = spec ? 'flex' : 'none';
        document.documentElement.style.overflow = spec ? 'hidden' : '';
        const parts = [];
        if (spec?.kicker) { parts.push(`<div style="font-size:15px;font-weight:600;color:#3fd8bf;margin-bottom:10px">${escapeHtml(spec.kicker)}</div>`); }
        if (spec?.title) { parts.push(`<div style="font-size:42px;font-weight:700;line-height:1.25;letter-spacing:-.01em">${escapeHtml(spec.title)}</div>`); }
        if (spec?.sub) { parts.push(`<div style="font-size:19px;color:#cfe0e6;margin-top:12px;line-height:1.6;max-width:1000px">${escapeHtml(spec.sub)}</div>`); }
        if (spec?.lines) { parts.push(`<div style="margin-top:26px;text-align:left;font-size:18px;line-height:1.5;max-width:900px">${spec.lines.map((text) => `<div style="margin-top:14px;font-weight:600">${escapeHtml(text)}</div>`).join('')}</div>`); }
        if (spec?.code !== undefined) { parts.push(`<pre style="margin:26px 0 0;width:940px;min-height:120px;padding:20px 24px;background:#071820;border:1px solid #2a4552;border-radius:10px;text-align:left;font:16px/1.6 ui-monospace,'DejaVu Sans Mono',monospace;color:#e6f4f1;white-space:pre-wrap">${escapeHtml(spec.code)}</pre>`); }
        card.innerHTML = parts.join('');
    };

    window.__film = {
        show(language) { state.language = language; render(); },
        caption(spec) { state.caption = spec; render(); },
        card(spec) { state.card = spec; render(); },
        pointer(x, y) { pointer.style.display = 'block'; pointer.style.left = `${x - 4}px`; pointer.style.top = `${y - 2}px`; },
        ripple(x, y, progress) {
            ripple.style.display = progress === null ? 'none' : 'block';
            if (progress !== null) {
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;
                ripple.style.transform = `scale(${0.5 + progress * 0.7})`;
                ripple.style.opacity = String(1 - progress * 0.5);
            }
        },
    };
})();
