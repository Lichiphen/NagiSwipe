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
    function fitFacebook(frame) {
        const wrap = frame.closest('.embeddedfacebook');
        const available = wrap?.getBoundingClientRect().width;
        if (!available) return;
        const url = new URL(frame.src);
        if (url.origin !== 'https://www.facebook.com' || !['/plugins/post.php', '/plugins/video.php'].includes(url.pathname)) return;
        const width = Math.max(350, Math.min(500, Math.floor(available)));
        url.searchParams.set('width', String(width));
        if (frame.src !== url.href) frame.src = url.href;
        const scale = Math.min(1, available / width);
        frame.style.width = width + 'px'; frame.style.maxWidth = 'none';
        frame.style.transformOrigin = 'top left'; frame.style.transform = scale < 1 ? `scale(${scale})` : '';
        frame.style.marginBottom = scale < 1 ? -(frame.offsetHeight * (1 - scale)) + 'px' : '';
    }
    function fitFrames(root) {
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
        }
        if (!selector || !Number.isFinite(height) || height < 100 || height > 3000) return;
        const frame = [...document.querySelectorAll(selector)].find(el => el.contentWindow === event.source);
        if (frame) frame.style.height = Math.ceil(height) + 'px';
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
    function load(root = document) {
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
