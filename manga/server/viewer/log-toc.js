/*
 * The table of contents of an article or fixed page: beside the article on wide screens (the rail from log.php),
 * a floating button with a bottom sheet on narrower ones. Both follow the reading position.
 * MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    // Icons come from viewer/icons/ui.svg (its address is on <html data-icons>).
    const icon = (id, cls = '') => '<svg' + (cls ? ' class="' + cls + '"' : '') + ' viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + document.documentElement.dataset.icons + '#' + id + '"/></svg>';
    const rail = document.querySelector('[data-toc-rail]');
    if (!rail) return;
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = text => i18n[text] ?? text;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    const article = document.querySelector('.log-site-main .log-body, .log-site-main .log-page-body');
    const railList = rail.querySelector('.log-toc-list');
    const ids = [...railList.querySelectorAll('a')].map(a => a.getAttribute('href').slice(1));
    const heads = ids.map(id => document.getElementById(id));
    if (!article || heads.some(h => !h)) return;

    // A soft pill that slides to the heading being read.
    const marker = document.createElement('span');
    marker.className = 'log-toc-marker'; marker.setAttribute('aria-hidden', 'true');
    railList.prepend(marker);

    // The floating button (a ring shows how far the article is read) and its sheet.
    const fab = document.createElement('button');
    fab.type = 'button'; fab.className = 'log-toc-fab';
    fab.setAttribute('aria-label', t('目次を開く')); fab.setAttribute('aria-haspopup', 'dialog');
    fab.innerHTML = '<svg class="log-toc-fab-ring" viewBox="0 0 60 60" aria-hidden="true"><circle cx="30" cy="30" r="27"/><circle class="log-toc-fab-done" cx="30" cy="30" r="27" pathLength="100"/></svg>'
        + icon('toc', 'log-toc-fab-icon');
    const label = document.createElement('span'); label.className = 'log-toc-fab-label'; label.textContent = t('目次');
    fab.append(label);
    // The owner's 書く and 管理 buttons sit in the corner: the button goes above them.
    if (document.querySelector('.log-fab')) fab.classList.add('is-owner');
    const sheet = document.createElement('dialog');
    sheet.className = 'log-toc-sheet'; sheet.setAttribute('aria-labelledby', 'log-toc-sheet-title');
    const head = document.createElement('div'); head.className = 'log-toc-sheet-head';
    const title = document.createElement('h2'); title.id = 'log-toc-sheet-title'; title.textContent = t('目次');
    const close = document.createElement('button'); close.type = 'button'; close.className = 'log-toc-sheet-close'; close.textContent = t('閉じる');
    head.append(title, close);
    const sheetList = railList.cloneNode(true);
    sheetList.querySelector('.log-toc-marker')?.remove();
    sheet.append(head, sheetList);
    document.body.append(fab, sheet);
    fab.addEventListener('click', () => { sheet.showModal(); (sheetList.querySelector('.is-active a') || sheetList.querySelector('a'))?.focus({ preventScroll: true }); sheetList.querySelector('.is-active')?.scrollIntoView({ block: 'center' }); });
    close.addEventListener('click', () => sheet.close());
    sheet.addEventListener('click', event => { if (event.target === sheet) sheet.close(); });

    // Every link to a heading (the rail, the sheet and the list in the article) glides there.
    document.addEventListener('click', event => {
        const link = event.target.closest('.log-toc a, .log-toc-rail a, .log-toc-sheet a');
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
        const target = document.getElementById(link.getAttribute('href').slice(1));
        if (!target) return;
        event.preventDefault();
        if (sheet.open) sheet.close();
        target.scrollIntoView({ behavior: reduce.matches ? 'auto' : 'smooth', block: 'start' });
        history.replaceState(history.state, '', '#' + encodeURIComponent(target.id));
        target.classList.remove('is-toc-target'); void target.offsetWidth; target.classList.add('is-toc-target');
        target.addEventListener('animationend', () => target.classList.remove('is-toc-target'), { once: true });
    });

    const railItems = [...railList.querySelectorAll('li')], sheetItems = [...sheetList.querySelectorAll('li')];
    const inline = document.querySelector('.log-site-main .log-toc');
    let current = -2, frame = 0;
    function update() {
        frame = 0;
        const line = window.innerHeight * 0.28;
        let active = -1;
        heads.forEach((h, i) => { if (h.getBoundingClientRect().top <= line) active = i; });
        const box = article.getBoundingClientRect();
        const progress = Math.min(1, Math.max(0, (window.innerHeight * 0.7 - box.top) / Math.max(1, box.height)));
        rail.style.setProperty('--toc-progress', progress.toFixed(4));
        fab.style.setProperty('--toc-progress', progress.toFixed(4));
        // The button comes once the list in the article (or the first heading) has gone up out of sight.
        const anchor = (inline || heads[0]).getBoundingClientRect();
        fab.classList.toggle('is-shown', anchor.bottom < 0 && box.bottom > window.innerHeight * 0.2);
        if (active === current) return;
        current = active;
        [railItems, sheetItems].forEach(items => items.forEach((li, i) => {
            li.classList.toggle('is-active', i === active);
            li.classList.toggle('is-past', i < active);
            const a = li.querySelector('a');
            if (i === active) a?.setAttribute('aria-current', 'location'); else a?.removeAttribute('aria-current');
        }));
        const item = railItems[active];
        marker.classList.toggle('is-on', !!item);
        if (item) { marker.style.transform = 'translateY(' + item.offsetTop + 'px)'; marker.style.height = item.offsetHeight + 'px'; }
        // Keep the heading being read in view inside a long rail.
        const card = rail.querySelector('.log-toc-rail-card');
        if (item && card.scrollHeight > card.clientHeight) {
            const top = item.offsetTop + railList.offsetTop - card.clientHeight / 2;
            card.scrollTo({ top, behavior: reduce.matches ? 'auto' : 'smooth' });
        }
    }
    const schedule = () => { if (!frame) frame = requestAnimationFrame(update); };
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', () => { current = -2; schedule(); });
    // Pictures loading above a heading move it: measure again.
    article.addEventListener('load', schedule, true);
    update();
    requestAnimationFrame(() => rail.classList.add('is-ready'));
})();
