/*
 * The admin menu button (lib/admin-menu.php): on the admin pages it slides in once the header has scrolled away
 * (Android has no tap-to-top); on the LOG pages of the logged-in owner it floats in right after the page opens.
 * With a mouse, and the button at the top of the screen (PCs, wide screens), pointing at it drops the menu right below
 * it; otherwise (phones, touch) a press opens every entry in a dialog in the middle of the screen.
 * MIT License (c) 2026 Lichiphen.
 */
(function () {
    'use strict';
    const fab = document.querySelector('[data-nav-fab]');
    const dialog = document.getElementById('nav-dialog');
    if (!fab || !dialog || typeof dialog.showModal !== 'function') return;

    // --- The drop-down below the button (mouse) ---
    const mouse = matchMedia('(hover: hover) and (pointer: fine)');
    const drop = document.createElement('div');
    drop.className = 'nav-drop'; drop.id = 'nav-drop'; drop.hidden = true;
    drop.setAttribute('role', 'group'); drop.setAttribute('aria-label', dialog.querySelector('h2')?.textContent || '');
    dialog.querySelectorAll('.nav-dialog-main, .nav-dialog-more').forEach(part => drop.append(part.cloneNode(true)));
    fab.after(drop);
    // Only while the button sits at the top (on phones it is at the bottom and opens the dialog).
    const dropMode = () => mouse.matches && fab.getBoundingClientRect().top < innerHeight / 2;
    let closeTimer = 0;
    const place = () => {
        const r = fab.getBoundingClientRect();
        drop.style.top = Math.round(r.bottom + 8) + 'px';
        drop.style.right = Math.max(8, Math.round(document.documentElement.clientWidth - r.right)) + 'px';
    };
    const openDrop = () => {
        clearTimeout(closeTimer);
        if (!drop.hidden) return;
        place(); drop.hidden = false;
        fab.setAttribute('aria-expanded', 'true');
        requestAnimationFrame(() => drop.classList.add('is-open'));
    };
    const closeDrop = () => {
        clearTimeout(closeTimer);
        if (drop.hidden) return;
        drop.classList.remove('is-open'); drop.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
    };
    // A short wait before closing, so the pointer can cross the gap between the button and the menu.
    const closeSoon = () => { clearTimeout(closeTimer); closeTimer = setTimeout(closeDrop, 280); };
    fab.addEventListener('pointerenter', e => { if (e.pointerType === 'mouse' && dropMode() && fab.classList.contains('is-shown')) openDrop(); });
    fab.addEventListener('pointerleave', e => { if (e.pointerType === 'mouse') closeSoon(); });
    drop.addEventListener('pointerenter', () => clearTimeout(closeTimer));
    drop.addEventListener('pointerleave', e => { if (e.pointerType === 'mouse') closeSoon(); });
    drop.addEventListener('focusout', e => { if (!drop.contains(e.relatedTarget) && e.relatedTarget !== fab) closeSoon(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !drop.hidden) { closeDrop(); fab.focus(); } });
    document.addEventListener('pointerdown', e => { if (!drop.hidden && !drop.contains(e.target) && !fab.contains(e.target)) closeDrop(); });
    addEventListener('resize', () => { if (!drop.hidden) place(); });

    const setShown = on => {
        fab.classList.toggle('is-shown', on);
        fab.tabIndex = on ? 0 : -1;
        fab.setAttribute('aria-hidden', on ? 'false' : 'true');
        if (!on) closeDrop();
    };
    const header = document.querySelector('header.top');
    if (fab.dataset.navFab === 'admin' && header && 'IntersectionObserver' in window) {
        new IntersectionObserver(([en]) => setShown(!en.isIntersecting)).observe(header);
    } else {
        // Shown a moment after the page appears, so it floats in rather than being there from the first paint.
        setTimeout(() => requestAnimationFrame(() => setShown(true)), 250);
    }
    fab.addEventListener('click', () => {
        document.querySelectorAll('.nav-more[open]').forEach(m => { m.open = false; });
        // With a mouse at the top the press keeps the drop-down (and opens it for the keyboard); the dialog otherwise.
        if (dropMode()) {
            if (drop.hidden) { openDrop(); drop.querySelector('a, button')?.focus({ preventScroll: true }); }
            return;
        }
        dialog.showModal();
    });
    dialog.addEventListener('click', e => {
        if (e.target === dialog || e.target.closest('[data-nav-close]')) dialog.close();
    });
})();
