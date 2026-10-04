/* LOG embeds: load each provider's official script once, only when the page has its embed. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const providers = [
        {selector: 'blockquote.twitter-tweet', src: 'https://platform.twitter.com/widgets.js', run: root => window.twttr?.widgets?.load?.(root)},
        {selector: 'blockquote.instagram-media', src: 'https://www.instagram.com/embed.js', run: () => window.instgrm?.Embeds?.process?.()},
        {selector: 'iframe.note-embed', src: 'https://note.com/scripts/embed.js', run: () => {}},
    ];
    const loading = new Map();
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
        providers.forEach(p => {
            if (!root.querySelector(p.selector)) return;
            const first = !loading.has(p.src);
            script(p.src).then(() => { if (!first || root !== document) p.run(root); });
        });
    }
    window.NagiLogEmbeds = {load};
    load();
})();
