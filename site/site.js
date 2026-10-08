/* NagiSeries documentation site: menu dialog, current heading, search, copy buttons. */
(function () {
    'use strict';

    const menu = document.getElementById('menu');
    const root = document.documentElement;

    // ---- menu (narrow screens) ----
    function openMenu() {
        if (!menu || menu.open) return;
        menu.showModal();
        root.classList.add('menu-open');
        const current = menu.querySelector('.nav-toc a.is-active') || menu.querySelector('.nav-item.is-current > .nav-link');
        if (current) current.scrollIntoView({ block: 'center' });
    }
    function closeMenu() {
        if (menu && menu.open) menu.close();
    }
    if (menu) {
        menu.addEventListener('close', () => root.classList.remove('menu-open'));
        // A click on the backdrop lands on the dialog itself.
        menu.addEventListener('click', (e) => {
            if (e.target === menu) closeMenu();
            else if (e.target.closest('a[href]')) closeMenu();
        });
    }
    document.querySelectorAll('[data-open-menu]').forEach((b) => b.addEventListener('click', openMenu));
    document.querySelectorAll('[data-close-menu]').forEach((b) => b.addEventListener('click', closeMenu));
    // Back to the wide layout: the dialog has nothing to show there.
    const wide = window.matchMedia('(min-width: 961px)');
    const onWide = () => { if (wide.matches) closeMenu(); };
    if (wide.addEventListener) wide.addEventListener('change', onWide);

    // ---- current heading in the sidebar ----
    const headings = Array.from(document.querySelectorAll('.doc-body h2[id], .doc-body h3[id]'));
    const sidebar = document.querySelector('.sidebar');
    const links = new Map();
    document.querySelectorAll('.nav-toc a[data-id]').forEach((a) => {
        const id = a.getAttribute('data-id');
        if (!links.has(id)) links.set(id, []);
        links.get(id).push(a);
    });
    let activeId = null;
    function setActive(id) {
        if (id === activeId) return;
        activeId = id;
        document.querySelectorAll('.nav-toc a.is-active').forEach((a) => a.classList.remove('is-active'));
        (links.get(id) || []).forEach((a) => {
            a.classList.add('is-active');
            // Keep it in view inside the sidebar only (never scroll the page).
            const box = a.closest('.sidebar');
            if (box && box === sidebar) {
                const top = a.getBoundingClientRect().top - box.getBoundingClientRect().top + box.scrollTop;
                if (top < box.scrollTop + 80 || top > box.scrollTop + box.clientHeight - 80) {
                    box.scrollTop = top - box.clientHeight / 2;
                }
            }
        });
    }
    function updateActive() {
        if (!headings.length) return;
        const line = 120;
        let current = null;
        for (const h of headings) {
            if (h.getBoundingClientRect().top - line <= 0) current = h;
            else break;
        }
        // At the very bottom, the last heading wins even if it never reaches the line.
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2) {
            current = headings[headings.length - 1];
        }
        setActive(current ? current.id : null);
    }
    let ticking = false;
    window.addEventListener('scroll', () => {
        if (ticking) return;
        ticking = true;
        requestAnimationFrame(() => { ticking = false; updateActive(); });
    }, { passive: true });
    updateActive();

    // Words of the page, in its language (the pages are Japanese or English).
    const en = document.documentElement.lang === 'en';
    const say = en
        ? { copy: 'Copy', copied: 'Copied', failed: 'Could not copy', none: (q) => 'Nothing found for “' + q + '”.' }
        : { copy: 'コピー', copied: 'コピーしました', failed: 'コピーできませんでした', none: (q) => '「' + q + '」は見つかりませんでした。' };

    // ---- copy buttons for code ----
    document.querySelectorAll('.doc pre').forEach((pre) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'copy';
        btn.textContent = say.copy;
        btn.addEventListener('click', async () => {
            const text = pre.querySelector('code') ? pre.querySelector('code').innerText : pre.innerText;
            try {
                await navigator.clipboard.writeText(text);
                btn.textContent = say.copied;
            } catch (e) {
                btn.textContent = say.failed;
            }
            btn.classList.add('is-done');
            setTimeout(() => { btn.textContent = say.copy; btn.classList.remove('is-done'); }, 1600);
        });
        pre.appendChild(btn);
    });

    // ---- search ----
    const script = document.currentScript || document.querySelector('script[data-index]');
    const indexUrl = script && script.getAttribute('data-index');
    let indexData = null;
    let loading = null;
    function loadIndex() {
        if (indexData) return Promise.resolve(indexData);
        if (!loading) {
            loading = fetch(indexUrl).then((r) => r.json()).then((d) => {
                indexData = d.map((e) => Object.assign(e, { lt: e.t.toLowerCase(), lx: e.x.toLowerCase() }));
                return indexData;
            }).catch(() => { loading = null; return []; });
        }
        return loading;
    }
    function escapeHtml(s) {
        return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }
    function highlight(text, terms) {
        let out = escapeHtml(text);
        terms.forEach((t) => {
            if (!t) return;
            const re = new RegExp(escapeHtml(t).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'gi');
            out = out.replace(re, (m) => '<mark>' + m + '</mark>');
        });
        return out;
    }
    function snippet(e, terms) {
        const pos = terms.reduce((p, t) => {
            const i = e.lx.indexOf(t);
            return i >= 0 && (p < 0 || i < p) ? i : p;
        }, -1);
        if (pos < 0) return e.x.slice(0, 80) + (e.x.length > 80 ? '…' : '');
        const start = Math.max(0, pos - 30);
        return (start > 0 ? '…' : '') + e.x.slice(start, start + 96) + (start + 96 < e.x.length ? '…' : '');
    }
    function search(q) {
        const terms = q.toLowerCase().split(/[\s　]+/).filter(Boolean);
        if (!terms.length) return [];
        const hits = [];
        for (const e of indexData) {
            let score = 0;
            let ok = true;
            for (const t of terms) {
                const inTitle = e.lt.includes(t);
                const inText = e.lx.includes(t);
                if (!inTitle && !inText) { ok = false; break; }
                score += (inTitle ? 10 : 0) + (inText ? 1 : 0);
            }
            if (ok) hits.push({ e, score });
        }
        hits.sort((a, b) => b.score - a.score);
        return hits.slice(0, 30).map((h) => ({ e: h.e, terms }));
    }
    document.querySelectorAll('[data-search-box]').forEach((box) => {
        const input = box.querySelector('input');
        const results = box.querySelector('.search-results');
        let selected = -1;
        function render() {
            const q = input.value.trim();
            if (!q) { results.hidden = true; results.innerHTML = ''; return; }
            loadIndex().then(() => {
                if (input.value.trim() !== q) return;
                const hits = search(q);
                selected = -1;
                results.hidden = false;
                if (!hits.length) {
                    results.innerHTML = '<p class="search-empty">' + say.none(escapeHtml(q)) + '</p>';
                    return;
                }
                results.innerHTML = hits.map(({ e, terms }) =>
                    '<a role="listitem" href="' + escapeHtml(e.u) + '"><span class="r-page">' + escapeHtml(e.p) + '</span>' +
                    '<span class="r-title">' + highlight(e.t, terms) + '</span>' +
                    '<span class="r-text">' + highlight(snippet(e, terms), terms) + '</span></a>').join('');
            });
        }
        function move(d) {
            const items = results.querySelectorAll('a');
            if (!items.length) return;
            selected = (selected + d + items.length) % items.length;
            items.forEach((a, i) => a.classList.toggle('is-selected', i === selected));
            items[selected].scrollIntoView({ block: 'nearest' });
        }
        input.addEventListener('focus', loadIndex, { once: true });
        input.addEventListener('input', render);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
            else if (e.key === 'Enter') {
                const items = results.querySelectorAll('a');
                const a = items[selected >= 0 ? selected : 0];
                if (a) { e.preventDefault(); a.click(); }
            } else if (e.key === 'Escape' && input.value) {
                e.preventDefault();
                e.stopPropagation();
                input.value = '';
                render();
            }
        });
        results.addEventListener('click', (e) => {
            const a = e.target.closest('a');
            if (!a) return;
            // Same page: just jump; the result list stays for the next pick.
            input.blur();
        });
    });
    // "/" focuses the search on wide screens.
    document.addEventListener('keydown', (e) => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        const t = e.target;
        if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
        const input = wide.matches ? document.querySelector('.sidebar [data-search-box] input') : null;
        if (input) { e.preventDefault(); input.focus(); }
    });
})();
