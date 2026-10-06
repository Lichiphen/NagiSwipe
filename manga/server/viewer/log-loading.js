/*
 * A calm "loading" sign for page changes that take more than a second (slow line, busy server).
 * Quick page changes show nothing. Clicks that open a picture, a new tab or a download, and forms handled by
 * script, never show it. MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    const DELAY = 1000;
    let timer = 0, giveUp = 0, sign = null;
    function make() {
        sign = document.createElement('div');
        sign.className = 'log-nav-wait';
        sign.setAttribute('role', 'status');
        sign.innerHTML = '<span class="log-nav-drop" aria-hidden="true"><i></i><i></i><i></i></span><span class="log-nav-text">読み込んでいます</span>';
        document.body.append(sign);
    }
    function show() {
        if (!sign) make();
        document.documentElement.classList.add('log-navigating');
        // A download or a stopped page change never unloads this page: give up after a while.
        giveUp = setTimeout(hide, 15000);
    }
    function hide() {
        clearTimeout(timer); clearTimeout(giveUp); timer = 0;
        document.documentElement.classList.remove('log-navigating');
    }
    function start() { hide(); timer = setTimeout(show, DELAY); }
    document.addEventListener('click', event => {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest?.('a[href]');
        if (!link || (link.target && link.target !== '_self') || link.hasAttribute('download')) return;
        const url = new URL(link.href, location.href);
        if (!/^https?:$/.test(url.protocol)) return;
        if (url.origin === location.origin && url.pathname === location.pathname && url.search === location.search && url.hash) return;
        // Wait for the other handlers: the image viewer, the manga reader and the menus stop the page change themselves.
        setTimeout(() => { if (!event.defaultPrevented) start(); });
    });
    document.addEventListener('submit', event => {
        const form = event.target;
        if (form.target && form.target !== '_self') return;
        if (form.querySelector('[name="do"][value$="backup"]')) return;
        setTimeout(() => { if (!event.defaultPrevented) start(); });
    });
    window.addEventListener('pageshow', hide);
})();
