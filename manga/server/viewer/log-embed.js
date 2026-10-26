/* LOG embeds: load each provider's official script once, only when the page has its embed. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const providers = [
        {selector: 'blockquote.twitter-tweet', src: 'https://platform.twitter.com/widgets.js', run: root => window.twttr?.widgets?.load?.(root)},
        {selector: 'blockquote.instagram-media', src: 'https://www.instagram.com/embed.js', run: () => window.instgrm?.Embeds?.process?.()},
        {selector: 'iframe.note-embed', src: 'https://note.com/scripts/embed.js', run: () => {}},
    ];
    const loading = new Map();
    const fitted = new WeakSet();
    let facebookFrames = 0;
    function fitFacebook(frame) {
        const wrap = frame.closest('.embeddedfacebook');
        const available = wrap?.getBoundingClientRect().width;
        if (!available) return;
        const url = new URL(frame.src);
        if (url.origin !== 'https://www.facebook.com' || !['/plugins/post.php', '/plugins/video.php'].includes(url.pathname)) return;
        const width = Math.max(350, Math.min(500, Math.floor(available)));
        url.searchParams.set('width', String(width));
        // With the channel Facebook's own script would add, the plugin reports its height (type=resize) without that script.
        if (!frame.dataset.fbCb) frame.dataset.fbCb = 'nagilog' + (++facebookFrames);
        url.searchParams.set('sdk', 'joey'); url.searchParams.set('app_id', ''); url.searchParams.set('container_width', String(width));
        url.searchParams.set('channel', 'https://staticxx.facebook.com/x/connect/xd_arbiter/?version=46#cb=' + frame.dataset.fbCb + '&domain=' + encodeURIComponent(location.hostname)
            + '&is_canvas=false&origin=' + encodeURIComponent(location.origin + '/' + frame.dataset.fbCb) + '&relation=parent.parent');
        if (frame.src !== url.href) frame.src = url.href;
        const scale = Math.min(1, available / width);
        frame.style.width = width + 'px'; frame.style.maxWidth = 'none';
        frame.style.transformOrigin = 'top left'; frame.style.transform = scale < 1 ? `scale(${scale})` : '';
        frame.style.marginBottom = scale < 1 ? -(frame.offsetHeight * (1 - scale)) + 'px' : '';
    }
    // Bluesky and Threads report their height only while loading, so reload them after the width settles.
    function watchWidth(frame) {
        if (fitted.has(frame) || typeof ResizeObserver !== 'function') return;
        fitted.add(frame);
        let width = 0, timer = 0;
        new ResizeObserver(([entry]) => {
            const next = Math.round(entry.contentRect.width);
            if (!width) { width = next; return; }
            if (!next || next === width) return;
            clearTimeout(timer);
            timer = setTimeout(() => { width = next; frame.src = frame.src; }, 400);
        }).observe(frame.parentElement);
    }
    function fitFrames(root) {
        root.querySelectorAll('.embeddedbluesky iframe, .embeddedthreads iframe').forEach(watchWidth);
        root.querySelectorAll('.embeddedfacebook iframe').forEach(frame => {
            fitFacebook(frame);
            if (fitted.has(frame)) return;
            fitted.add(frame);
            if (typeof ResizeObserver === 'function') new ResizeObserver(() => fitFacebook(frame)).observe(frame.parentElement);
            else window.addEventListener('resize', () => fitFacebook(frame));
        });
    }
    // Only a frame we created at the exact official origin may resize itself.
    window.addEventListener('message', event => {
        let height = 0, selector = '';
        if (event.origin === 'https://embed.bsky.app' && event.data && typeof event.data === 'object') {
            selector = '.embeddedbluesky iframe'; height = Number(event.data.height);
        } else if (event.origin === 'https://www.threads.com' && (typeof event.data === 'number' || (typeof event.data === 'string' && /^\d{1,4}$/.test(event.data)))) {
            selector = '.embeddedthreads iframe'; height = Number(event.data);
        } else if (event.origin === 'https://www.facebook.com' && typeof event.data === 'string' && event.data.startsWith('type=resize&')) {
            selector = '.embeddedfacebook iframe'; height = Number(new URLSearchParams(event.data).get('height'));
        }
        if (!selector || !Number.isFinite(height) || height < 100 || height > 3000) return;
        const frame = [...document.querySelectorAll(selector)].find(el => el.contentWindow === event.source);
        if (!frame) return;
        frame.style.height = Math.ceil(height) + 'px';
        if (selector === '.embeddedfacebook iframe') fitFacebook(frame);
    });
    function script(src) {
        if (!loading.has(src)) loading.set(src, new Promise(resolve => {
            const el = document.createElement('script');
            el.src = src; el.async = true; el.charset = 'utf-8';
            el.onload = resolve; el.onerror = resolve;
            document.head.append(el);
        }));
        return loading.get(src);
    }
    // Page embeds ([ページ埋込]URL[/ページ埋込]): the page loads only when the reader presses its cover, since a game can be
    // heavy on a phone. 全画面 uses the browser's full screen, or covers the window where there is none (an iPhone).
    const FRAME_ALLOW = 'autoplay; gamepad; clipboard-write; screen-wake-lock';
    function startFrame(box) {
        const old = box.querySelector('.log-frame-stage iframe');
        if (old) return old;
        const start = box.querySelector('.log-frame-start');
        const frame = document.createElement('iframe');
        frame.src = start.dataset.frameSrc; frame.title = start.dataset.frameTitle || '';
        frame.allow = FRAME_ALLOW; frame.allowFullscreen = true;
        start.replaceWith(frame);
        box.querySelector('[data-frame-full]')?.removeAttribute('hidden');
        frame.focus({ preventScroll: true });
        return frame;
    }
    const fullElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;
    let covered = null, coverOpener = null;
    function cover(box, on) {
        if (on) {
            covered = box;
            box.style.minHeight = box.offsetHeight + 'px';
            box.classList.add('is-cover');
            document.documentElement.classList.add('log-frame-covered');
            box.querySelector('.log-frame-close')?.focus();
        } else if (covered) {
            covered.classList.remove('is-cover');
            covered.style.minHeight = '';
            document.documentElement.classList.remove('log-frame-covered');
            covered = null;
            coverOpener?.focus(); coverOpener = null;
        }
    }
    function goFull(box, opener) {
        const frame = startFrame(box), stage = box.querySelector('.log-frame-stage');
        const request = stage.requestFullscreen || stage.webkitRequestFullscreen;
        coverOpener = opener;
        if (!request || !(document.fullscreenEnabled || document.webkitFullscreenEnabled)) { cover(box, true); return; }
        Promise.resolve(request.call(stage)).then(() => frame.focus(), () => cover(box, true));
    }
    function leaveFull() {
        if (covered) { cover(null, false); return; }
        const exit = document.exitFullscreen || document.webkitExitFullscreen;
        if (fullElement()) exit?.call(document);
    }
    ['fullscreenchange', 'webkitfullscreenchange'].forEach(type => document.addEventListener(type, () => {
        const stage = fullElement();
        document.querySelectorAll('.log-frame.is-full').forEach(box => { if (box !== stage?.closest('.log-frame')) box.classList.remove('is-full'); });
        if (stage?.classList.contains('log-frame-stage')) stage.closest('.log-frame').classList.add('is-full');
        else { coverOpener?.focus(); coverOpener = null; }
    }));
    document.addEventListener('click', event => {
        const start = event.target.closest?.('.log-frame-start');
        // A middle or Ctrl click still opens the page in a new tab.
        if (start && event.button === 0 && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
            event.preventDefault(); startFrame(start.closest('.log-frame'));
            return;
        }
        const full = event.target.closest?.('[data-frame-full]');
        if (full) { goFull(full.closest('.log-frame'), full); return; }
        if (event.target.closest?.('[data-frame-close]')) leaveFull();
    });
    document.addEventListener('keydown', event => { if (event.key === 'Escape' && covered) { event.preventDefault(); cover(null, false); } });
    function load(root = document) {
        root.querySelectorAll('.log-frame [data-frame-full][hidden]').forEach(button => { button.hidden = false; });
        fitFrames(root);
        if (document.documentElement.dataset.embedScripts === 'off') return;
        providers.forEach(p => {
            if (!root.querySelector(p.selector)) return;
            const first = !loading.has(p.src);
            script(p.src).then(() => { if (!first || root !== document) p.run(root); });
        });
    }
    window.NagiLogEmbeds = {load};
    load();
})();
