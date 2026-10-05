/* LOG new-post marks: a cached page can outlive the period, so marks whose time has passed are taken away here. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const sweep = (root = document) => {
        const now = Date.now() / 1000;
        root.querySelectorAll('[data-new-until]').forEach(mark => { if (Number(mark.dataset.newUntil) <= now) mark.remove(); });
    };
    sweep();
    // Posts added by "もっと見る" are checked too.
    const main = document.querySelector('.log-site-main');
    if (main) new MutationObserver(records => { if (records.some(r => r.addedNodes.length)) sweep(main); }).observe(main, { childList: true, subtree: true });
})();
