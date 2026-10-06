/*
 * Top menu that scrolls sideways when its links do not fit (phones, or many links).
 * Fades and arrows show the hidden side; the first time the row overflows it slides a little to show it moves,
 * and the arrow keeps nudging until the reader scrolls once. The current link is brought into view.
 * MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    const nav = document.querySelector('.log-topmenu');
    const scroller = nav?.querySelector('[data-topmenu-scroller]');
    if (!scroller) return;
    const prev = nav.querySelector('.log-topmenu-arrow.is-prev');
    const next = nav.querySelector('.log-topmenu-arrow.is-next');
    const still = matchMedia('(prefers-reduced-motion: reduce)');
    let hinted = false, touched = false;
    function update() {
        const max = scroller.scrollWidth - scroller.clientWidth;
        const over = max > 2;
        // In right-to-left layouts scrollLeft runs negative; the menu is always left to right here.
        const left = scroller.scrollLeft > 2, right = scroller.scrollLeft < max - 2;
        nav.classList.toggle('is-overflowing', over);
        nav.classList.toggle('can-prev', over && left);
        nav.classList.toggle('can-next', over && right);
        prev.hidden = !(over && left); next.hidden = !(over && right);
        if (over && !hinted && !touched) hint();
    }
    // A short slide to the side and back: the row moves under the finger.
    function hint() {
        hinted = true;
        if (still.matches) return;
        nav.classList.add('is-hinting');
        setTimeout(() => nav.classList.remove('is-hinting'), 1900);
    }
    function settle() {
        if (touched) return;
        touched = true;
        nav.classList.add('is-touched');
        nav.classList.remove('is-hinting');
    }
    const step = dir => { settle(); scroller.scrollBy({ left: dir * Math.max(120, scroller.clientWidth * 0.7), behavior: still.matches ? 'auto' : 'smooth' }); };
    prev.addEventListener('click', () => step(-1));
    next.addEventListener('click', () => step(1));
    scroller.addEventListener('scroll', () => { if (scroller.scrollLeft > 4) settle(); requestAnimationFrame(update); }, { passive: true });
    scroller.addEventListener('pointerdown', settle, { passive: true });
    // A mouse wheel turned over the row scrolls it sideways while there is room; at either end the page scrolls as usual.
    scroller.addEventListener('wheel', event => {
        if (!nav.classList.contains('is-overflowing') || Math.abs(event.deltaX) > Math.abs(event.deltaY)) return;
        const max = scroller.scrollWidth - scroller.clientWidth;
        if ((event.deltaY < 0 && scroller.scrollLeft <= 0) || (event.deltaY > 0 && scroller.scrollLeft >= max - 1)) return;
        event.preventDefault(); settle();
        scroller.scrollLeft += event.deltaY;
    }, { passive: false });
    // Keyboard users moving through the links see each one.
    scroller.addEventListener('focusin', event => event.target.scrollIntoView?.({ block: 'nearest', inline: 'nearest' }));
    const current = scroller.querySelector('[aria-current="page"]');
    if (current) {
        const left = current.offsetLeft - (scroller.clientWidth - current.offsetWidth) / 2;
        scroller.scrollLeft = Math.max(0, left);
        if (left > 4) { hinted = true; touched = true; nav.classList.add('is-touched'); }
    }
    if ('ResizeObserver' in window) new ResizeObserver(() => update()).observe(scroller);
    else window.addEventListener('resize', update);
    document.fonts?.ready.then(update);
    update();
})();
