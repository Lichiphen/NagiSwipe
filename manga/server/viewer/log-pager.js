/* LOG "もっと見る": appends the next page in place. Without JS it stays a link to that page. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    let busy = false;
    function status(nav) {
        const p = nav.querySelector('.log-pager-status');
        if (p) p.innerHTML = '<span class="log-pager-total">' + nav.dataset.total + '件中</span><b>' + nav.dataset.start + '〜' + nav.dataset.end + '件目</b>';
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
            const posts = [...doc.querySelectorAll('#log-main > .log-post')];
            const next = doc.querySelector('#log-main > .log-pager');
            if (!posts.length || !next) throw new Error('empty');
            posts.forEach(p => { p.classList.add('log-pager-added'); main.insertBefore(document.adoptNode(p), nav); });
            next.dataset.start = nav.dataset.start;
            next.querySelector('.log-pager-back')?.remove();
            const back = nav.querySelector('.log-pager-back');
            if (back) next.append(back);
            nav.replaceWith(next);
            status(next);
            history.replaceState(history.state, '', link.href);
            // Keyboard and screen reader users continue from the first new post.
            const first = posts[0].querySelector('h2 a');
            if (first) first.focus({preventScroll: true});
            window.NagiSwipe?.init?.();
            window.NagiLogEmbeds?.load?.(main);
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
