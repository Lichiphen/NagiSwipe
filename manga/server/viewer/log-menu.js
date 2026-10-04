/* Mobile LOG menu. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const button = document.querySelector('.log-menu-toggle');
    const menu = document.querySelector('.log-sidebar');
    const backdrop = document.querySelector('.log-menu-backdrop');
    if (!button || !menu || !backdrop) return;
    const mobile = matchMedia('(max-width:900px)');
    const background = [document.querySelector('.log-site-header'), document.querySelector('.log-site-main'), document.querySelector('.log-site-footer'), button];
    let opened = false;
    function setOpen(value, restore = true) {
        opened = value && mobile.matches;
        document.body.classList.toggle('log-menu-open', opened);
        backdrop.hidden = !opened;
        button.setAttribute('aria-expanded', String(opened));
        background.forEach(el => { if (el) el.inert = opened; });
        menu.inert = mobile.matches && !opened;
        if (opened) {
            menu.setAttribute('role', 'dialog'); menu.setAttribute('aria-modal', 'true');
            menu.querySelector('.log-menu-close').focus();
        } else {
            menu.removeAttribute('role'); menu.removeAttribute('aria-modal');
            if (restore && mobile.matches) button.focus();
        }
    }
    button.addEventListener('click', () => setOpen(true));
    menu.querySelector('.log-menu-close').addEventListener('click', () => setOpen(false));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', event => {
        if (!opened || document.querySelector('dialog[open]')) return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false); }
        if (event.key === 'Tab') {
            const items = [...menu.querySelectorAll('a[href],button:not([disabled])')].filter(el => el.getClientRects().length);
            const first = items[0], last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    mobile.addEventListener('change', () => setOpen(false, false));
    window.addEventListener('pageshow', () => setOpen(false, false));
    setOpen(false, false);
})();
