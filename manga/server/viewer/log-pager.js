/* LOG "もっと見る": appends the next page in place. Without JS it stays a link to that page. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    let busy = false;
    function status(nav) {
        const p = nav.querySelector('.log-pager-status');
        if (p) p.innerHTML = '<span class="log-pager-total">' + t('{n}件中', { n: nav.dataset.total }) + '</span><b>' + t('{start}〜{end}件目', { start: nav.dataset.start, end: nav.dataset.end }) + '</b>';
    }
    async function more(link) {
        const nav = link.closest('.log-pager'), main = nav?.parentElement;
        if (!nav || !main || busy) return;
        busy = true;
        link.setAttribute('aria-busy', 'true');
        try {
            const res = await fetch(link.href, {credentials: 'same-origin', headers: {Accept: 'text/html'}});
            if (!res.ok) throw new Error(String(res.status));
            const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
            // Mini blog: whole posts before the pager. Tiles: items added to the tile list.
            const tiles = main.querySelector(':scope > .log-tiles');
            const posts = [...doc.querySelectorAll(tiles ? '#log-main > .log-tiles > .log-tile' : '#log-main > .log-post')];
            const next = doc.querySelector('#log-main > .log-pager');
            if (!posts.length || !next) throw new Error('empty');
            posts.forEach(p => {
                p.classList.add('log-pager-added');
                const node = document.adoptNode(p);
                if (tiles) tiles.append(node); else main.insertBefore(node, nav);
            });
            next.dataset.start = nav.dataset.start;
            next.querySelector('.log-pager-back')?.remove();
            const back = nav.querySelector('.log-pager-back');
            if (back) next.append(back);
            nav.replaceWith(next);
            status(next);
            history.replaceState(history.state, '', link.href);
            // Keyboard and screen reader users continue from the first new post.
            const first = posts[0].querySelector(tiles ? 'a' : 'h2 a');
            if (first) first.focus({preventScroll: true});
            window.NagiSwipe?.init?.();
            window.NagiLogEmbeds?.load?.(main);
            window.NagiLogLikes?.refresh?.();
        } catch {
            location.href = link.href;
        } finally {
            busy = false;
            link.removeAttribute('aria-busy');
        }
    }
    document.addEventListener('click', e => {
        const link = e.target.closest?.('[data-pager-more]');
        if (!link || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        more(link);
    });
})();
