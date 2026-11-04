/*
 * NagiScope on the LOG pages: sends what the reader does to scope.php with navigator.sendBeacon. No cookies, no storage.
 * Loaded only while NagiScope is on and the reader is not the logged-in owner (log.php decides).
 *   pv  the page was opened (with the page it came from, the search words or tag of a list)
 *   lv  how far down a post or page was read, and the heading reached (sent again whenever it grows)
 *   rd  read properly: 90% of the text was on screen and the page stayed open long enough for its length
 *   ck  a link was pressed (also with the middle button or Ctrl / ⌘ for a new tab)
 *   fv / fo / fl / fg / fs / ft  a page embed ([ページ埋込]…, usually a game): seen, opened, loaded, the reader went
 *       into it (pressed or tapped inside the game), the game said it started, and the time played
 * MIT License (c) 2026 Lichiphen.
 */
(function () {
    'use strict';
    const me = document.currentScript;
    if (!me || typeof navigator.sendBeacon !== 'function') return;
    let page;
    try { page = JSON.parse(me.dataset.scope || '{}'); } catch { return; }
    const endpoint = new URL('scope.php', document.baseURI).href;
    const sid = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'[b % 62]).join('');
    const base = { s: sid, k: page.k, i: page.i || '' };
    const send = list => {
        try { navigator.sendBeacon(endpoint, new Blob([JSON.stringify(list.length === 1 ? list[0] : list)], { type: 'text/plain' })); } catch { /* never disturb the page */ }
    };
    const event = (e, more = {}) => Object.assign({ e }, base, more);

    const pv = { r: document.referrer || '', tc: navigator.maxTouchPoints > 1 ? 1 : 0 };
    if (page.q) pv.q = page.q;
    if (page.tg) pv.tg = page.tg;
    send([event('pv', pv)]);

    // --- How far a post or fixed page was read -------------------------------------------------------------
    const article = page.k === 'p' || page.k === 'g' ? document.querySelector('.log-page-body, .log-post .log-body, .log-post') : null;
    if (article) {
        const headings = Array.from(article.querySelectorAll('h2, h3, h4')).filter(h => !h.closest('.log-toc, .log-toc-rail'));
        const need = Math.max(10, Math.ceil((article.textContent || '').replace(/\s+/g, '').length / 500 * 60)) * 1000;
        let maxY = 0, heading = -1, sentY = -1, sentH = -2, visibleMs = 0, since = document.visibilityState === 'visible' ? performance.now() : 0, readSent = false;
        const measure = () => {
            const box = article.getBoundingClientRect();
            const seen = Math.min(1, Math.max(0, (innerHeight - box.top) / Math.max(1, box.height)));
            maxY = Math.max(maxY, Math.round(seen * 100));
            // The heading reached: the last one that came above the middle of the screen.
            for (let i = headings.length - 1; i > heading; i--) {
                if (headings[i].getBoundingClientRect().top < innerHeight / 2) { heading = i; break; }
            }
        };
        const elapsed = () => visibleMs + (since ? performance.now() - since : 0);
        const checkRead = () => {
            if (!readSent && maxY >= 90 && elapsed() >= need) { readSent = true; send([event('rd')]); }
        };
        const leave = () => {
            measure();
            if (maxY === sentY && heading === sentH) return;
            sentY = maxY; sentH = heading;
            const h = headings[heading];
            send([event('lv', { y: maxY, hi: heading, h: h ? (h.textContent || '').trim().slice(0, 120) : '' })]);
        };
        let ticking = false;
        addEventListener('scroll', () => {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(() => { ticking = false; measure(); checkRead(); });
        }, { passive: true });
        setInterval(checkRead, 5000);
        measure();
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') { if (since) visibleMs += performance.now() - since; since = 0; leave(); }
            else since = performance.now();
        });
        addEventListener('pagehide', leave);
    }

    // --- Links -----------------------------------------------------------------------------------------------
    const clicked = e => {
        if (e.type === 'auxclick' && e.button !== 1) return;
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a || a.closest('.log-frame-start')) return;
        let url;
        try { url = new URL(a.getAttribute('href'), document.baseURI); } catch { return; }
        if (!/^https?:$/.test(url.protocol)) return;
        send([event('ck', { u: url.href.slice(0, 500) })]);
    };
    document.addEventListener('click', clicked, true);
    document.addEventListener('auxclick', clicked, true);

    // --- Page embeds (games): was the game really started? -----------------------------------------------------
    // The game itself runs on another site, so nothing inside it can be seen. What can: the cover was pressed, the frame
    // finished loading, the reader's press or tap went into the game (the focus moved into its frame), and, if the game
    // sends it, window.parent.postMessage('nagiscope:start', '*') from the game (embed.php keeps it for us).
    const frames = Array.from(document.querySelectorAll('.log-frame[data-frame]:not(.is-blocked)'));
    if (!frames.length) return;
    const urlOf = box => box.querySelector('.log-frame-start, .log-frame-open')?.getAttribute('href') || '';
    const marks = new WeakMap();
    const once = (box, e) => {
        const m = marks.get(box) || {};
        if (m[e]) return;
        m[e] = 1; marks.set(box, m);
        send([event(e, { u: urlOf(box) })]);
    };
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => entries.forEach(en => {
            if (en.isIntersecting) { once(en.target, 'fv'); io.unobserve(en.target); }
        }), { threshold: 0.5 });
        frames.forEach(box => io.observe(box));
    }
    // Play time: only while the reader's focus is inside that game and the page is on screen.
    const play = new Map(); // box -> {ms, since, sent}
    const outerOf = box => box.querySelector('.log-frame-stage iframe');
    const pause = () => {
        const now = performance.now();
        play.forEach(p => { if (p.since) { p.ms += now - p.since; p.since = 0; } });
    };
    const resume = box => {
        if (document.visibilityState !== 'visible') return;
        pause();
        const p = play.get(box) || { ms: 0, since: 0, sent: 0 };
        p.since = performance.now();
        play.set(box, p);
    };
    const flushPlay = () => {
        pause();
        play.forEach((p, box) => { if (p.ms >= 1000 && Math.round(p.ms) !== p.sent) { p.sent = Math.round(p.ms); send([event('ft', { u: urlOf(box), ms: p.sent })]); } });
    };
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'hidden') flushPlay(); else focused(); });
    addEventListener('pagehide', flushPlay);
    // The focus came back to the page itself: the game is not being played.
    addEventListener('focus', pause);
    // Another app took the focus while the tab stayed on screen: no event tells the page, so look every 2 seconds
    // (hasFocus() is true while the focus is in one of the page's frames, the game included).
    setInterval(() => { if (!play.size) return; if (!document.hasFocus()) pause(); else focused(); }, 2000);

    // The reader pressed or tapped inside the game: the focus is now on the game's frame inside the embed page.
    const inGame = box => {
        const outer = outerOf(box);
        if (!outer || document.activeElement !== outer) return false;
        try {
            const doc = outer.contentDocument, inner = doc && doc.querySelector('iframe');
            return !!inner && doc.activeElement === inner;
        } catch { return false; }
    };
    const focused = () => frames.forEach(box => {
        if (!inGame(box)) return;
        once(box, 'fg');
        resume(box);
    });
    const signal = box => { once(box, 'fs'); if (inGame(box)) resume(box); };
    const watch = box => {
        const outer = outerOf(box);
        if (!outer || outer.dataset.scope) return;
        outer.dataset.scope = '1';
        once(box, 'fo');
        outer.addEventListener('load', () => {
            let win, doc;
            try { win = outer.contentWindow; doc = outer.contentDocument; } catch { return; }
            if (!win || !doc || !doc.querySelector('iframe')) return;
            // The embed page waits for the game page before its own load event. A refused or failed page fires it too,
            // so this one is only a guide.
            once(box, 'fl');
            win.addEventListener('blur', () => setTimeout(focused, 0));
            if (win.nagiscopeStart) signal(box);
            win.addEventListener('nagiscope-start', () => signal(box));
        });
    };
    // Straight from the page into the game: the top window loses the focus.
    addEventListener('blur', () => setTimeout(focused, 0));
    // log-embed.js puts the frame in place when the cover or 全画面 is pressed (and takes the cover away, so the
    // box is found before it runs); look for the frame right after.
    document.addEventListener('click', e => {
        const box = e.target.closest && e.target.closest('.log-frame[data-frame]');
        if (box) setTimeout(() => watch(box), 0);
    }, true);
})();
