/*
 * ============================================================================
 * NagiManga - manga reader for NagiSwipe
 *
 * NagiManga v0.2.0
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 * ============================================================================
 *
 *   <a href="#" data-nagimanga="ID" data-endpoint="https://example.com/nagimanga/read.php"
 *      data-direction="rtl" data-view="auto" data-cover="1">Read</a>
 *   <script src="https://example.com/nagimanga/viewer/NagiManga.js" defer></script>
 *
 *   data-direction  rtl (default) | ltr | vertical
 *   data-view       auto (two pages on wide screens) | single
 *   data-cover      1 (first page alone) | 0
 *   data-manifest   URL of a static JSON manifest (no PHP needed) instead of data-endpoint
 *
 * Where HTML cannot be written (e.g. Tegalog posts), a plain link to the share URL works too:
 *   https://example.com/nagimanga/read.php?nagimanga=ID&dir=rtl&view=auto&cover=1
 */
(function (global) {
    'use strict';

    if (global.NagiManga) return;

    const VERSION = '0.2.0';
    const SCRIPT = document.currentScript;
    const SCRIPT_URL = SCRIPT ? SCRIPT.src : '';

    const TURN_MS = 280;
    const TURN_EASING = 'cubic-bezier(0.22, 1, 0.36, 1)';
    const MAX_ZOOM = 4;
    const READY_WAIT_MS = 200;  // longest wait for the next spread's images before a page turn
    const END_DELAY_MS = 1200;  // vertical: time at the very bottom before "the end" shows up

    const ZOOM_STEPS = [1, 1.5, 2, 3, 4];
    const BAR_REVEAL_PX = 72;   // mouse this close to the top / bottom edge shows the bars

    const ICON_ZOOM_IN = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5L21 21M8 11h6M11 8v6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    const ICON_ZOOM_OUT = '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M16.5 16.5L21 21M8 11h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    const chevron = d => `<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="${d}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
    const ICON_LEFT = chevron('M15 18l-6-6 6-6');
    const ICON_RIGHT = chevron('M9 18l6-6-6-6');
    const ICON_UP = chevron('M18 15l-6-6-6 6');
    const ICON_DOWN = chevron('M6 9l6 6 6-6');
    const ICON_CLOSE = '<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';

    const store = {
        get(k) { try { return global.localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { global.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
    };

    const clamp = (v, min, max) => Math.min(Math.max(v, min), max);
    const el = (tag, cls, attrs) => {
        const e = document.createElement(tag);
        if (cls) e.className = cls;
        if (attrs) Object.entries(attrs).forEach(([k, v]) => e.setAttribute(k, v));
        return e;
    };

    // Load the stylesheet next to this script (same version query)
    function loadCss() {
        if (!SCRIPT_URL || document.querySelector('link[data-nagimanga-css]')) return;
        const u = new URL(SCRIPT_URL);
        u.pathname = u.pathname.replace(/[^/]*$/, 'NagiManga.css');
        const link = el('link', null, { rel: 'stylesheet', href: u.href, 'data-nagimanga-css': '' });
        document.head.appendChild(link);
    }

    class Reader {
        constructor() {
            this.isOpen = false;
            this.pages = [];
            this.spreads = [];
            this.spreadIndex = 0;
            this.opts = {};
            this.pointers = new Map();
            this.drag = null;
            this.zoom = { s: 1, x: 0, y: 0 };
            this.slides = { prev: null, cur: null, next: null };
            this.historyPushed = false;
            this.historyBackPending = false;
            this.lastTap = { t: 0, x: 0, y: 0 };
            this.tapTimer = null;
            this.animating = false;
            this.built = false;
        }

        // --------------------------------------------------------------------
        // DOM
        // --------------------------------------------------------------------

        build() {
            if (this.built) return;
            this.built = true;
            loadCss();

            const v = this.root = el('div', 'nm-viewer', { role: 'dialog', 'aria-modal': 'true', 'aria-label': 'Manga reader', tabindex: '-1' });
            v.hidden = true;

            this.stage = el('div', 'nm-stage');
            this.scroller = el('div', 'nm-scroll');
            this.scroller.hidden = true;

            const top = this.topBar = el('div', 'nm-bar nm-top');
            this.titleEl = el('div', 'nm-title');
            this.counterEl = el('div', 'nm-counter', { 'aria-live': 'polite' });
            this.closeBtn = el('button', 'nm-btn nm-close', { type: 'button', 'aria-label': 'Close' });
            this.closeBtn.innerHTML = ICON_CLOSE;
            // Zoom controls (mainly for mouse users: pinch covers touch)
            const zoomCtl = el('div', 'nm-zoomctl', { role: 'group', 'aria-label': 'Zoom' });
            this.zoomOutBtn = el('button', 'nm-btn nm-zoom-out', { type: 'button', 'aria-label': 'Zoom out', title: '縮小（-）' });
            this.zoomOutBtn.innerHTML = ICON_ZOOM_OUT;
            this.zoomLabel = el('button', 'nm-zoom-label', { type: 'button', title: '元の大きさ（0）' });
            this.zoomLabel.textContent = '100%';
            this.zoomInBtn = el('button', 'nm-btn nm-zoom-in', { type: 'button', 'aria-label': 'Zoom in', title: '拡大（+）' });
            this.zoomInBtn.innerHTML = ICON_ZOOM_IN;
            zoomCtl.append(this.zoomOutBtn, this.zoomLabel, this.zoomInBtn);
            top.append(this.titleEl, this.counterEl, zoomCtl, this.closeBtn);

            const bottom = this.bottomBar = el('div', 'nm-bar nm-bottom');
            this.slider = el('input', 'nm-slider', { type: 'range', min: '0', max: '0', value: '0', 'aria-label': 'Page' });
            this.navA = el('button', 'nm-btn nm-nav', { type: 'button' });
            this.navB = el('button', 'nm-btn nm-nav', { type: 'button' });
            bottom.append(this.navA, this.slider, this.navB);

            this.spinner = el('div', 'nm-spinner');
            this.message = el('div', 'nm-message');
            this.message.hidden = true;
            this.toast = el('div', 'nm-toast');
            this.toast.hidden = true;

            // Password form
            this.lock = el('form', 'nm-lock');
            this.lock.hidden = true;
            this.lockTitle = el('p', 'nm-lock-title');
            this.lockInput = el('input', 'nm-lock-input', { type: 'password', autocomplete: 'current-password', 'aria-label': 'Password', maxlength: '200', required: '' });
            this.lockBtn = el('button', 'nm-lock-btn', { type: 'submit' });
            this.lockBtn.textContent = '読む';
            this.lockError = el('p', 'nm-lock-error', { role: 'alert' });
            const lockNote = el('p', 'nm-lock-note');
            lockNote.textContent = 'この作品を読むにはパスワードが必要です';
            this.lock.append(this.lockTitle, lockNote, this.lockInput, this.lockBtn, this.lockError);

            v.append(this.stage, this.scroller, top, bottom, this.spinner, this.message, this.lock, this.toast);
            document.body.appendChild(v);

            // Events
            this.closeBtn.addEventListener('click', () => this.close());
            this.lock.addEventListener('submit', e => { e.preventDefault(); this.unlock(); });
            this.slider.addEventListener('input', () => this.onSlider());
            // Using the bars keeps them on screen
            [top, bottom].forEach(bar => {
                bar.addEventListener('pointerdown', () => clearTimeout(this.uiTimer));
                bar.addEventListener('mouseenter', () => { this.overBar = true; clearTimeout(this.uiTimer); });
                bar.addEventListener('mouseleave', () => {
                    this.overBar = false;
                    if (this.barsByHover) this.scheduleHideBars(1200);
                });
            });
            this.zoomInBtn.addEventListener('click', () => this.zoomStep(1));
            this.zoomOutBtn.addEventListener('click', () => this.zoomStep(-1));
            this.zoomLabel.addEventListener('click', () => this.resetZoom(true));
            this.navA.addEventListener('click', () => this.navStep(this.navAStep));
            this.navB.addEventListener('click', () => this.navStep(this.navBStep));
            // Mouse near the top / bottom edge brings the bars back
            v.addEventListener('pointermove', e => {
                if (e.pointerType !== 'mouse' || !this.isOpen || this.pointers.size || this.vdrag) return;
                if (e.clientY < BAR_REVEAL_PX || e.clientY > global.innerHeight - BAR_REVEAL_PX) this.revealBarsByHover();
            });
            this.stage.addEventListener('pointerdown', e => this.onDown(e));
            this.stage.addEventListener('pointermove', e => this.onMove(e));
            this.stage.addEventListener('pointerup', e => this.onUp(e));
            this.stage.addEventListener('pointercancel', e => this.onUp(e, true));
            this.stage.addEventListener('wheel', e => this.onWheel(e), { passive: false });
            this.stage.addEventListener('contextmenu', e => e.preventDefault());
            this.scroller.addEventListener('click', e => {
                // The click that ends a mouse drag is not a tap
                if (this.suppressVClick) {
                    this.suppressVClick = false;
                    return;
                }
                if (e.target.closest('.nm-page-v')) {
                    clearTimeout(this.uiTimer);
                    this.barsByHover = false;
                    this.root.classList.toggle('nm-ui-hidden');
                }
            });
            this.scroller.addEventListener('pointerdown', e => this.onVDown(e));
            this.scroller.addEventListener('pointermove', e => this.onVMove(e));
            this.scroller.addEventListener('pointerup', e => this.onVUp(e));
            this.scroller.addEventListener('pointercancel', e => this.onVUp(e));
            this.scroller.addEventListener('scroll', () => this.onVerticalScroll(), { passive: true });

            this.keyHandler = e => this.onKey(e);
            this.resizeHandler = () => {
                if (!this.isOpen || this.resizeRaf) return;
                this.resizeRaf = requestAnimationFrame(() => {
                    this.resizeRaf = null;
                    this.relayout();
                });
            };
            this.popHandler = () => {
                if (this.historyBackPending) {
                    this.historyBackPending = false;
                    return;
                }
                if (this.isOpen && this.historyPushed) {
                    this.historyPushed = false;
                    this.close();
                }
            };
            global.addEventListener('popstate', this.popHandler);
        }

        // --------------------------------------------------------------------
        // Open / close
        // --------------------------------------------------------------------

        async open(trigger) {
            this.build();
            if (this.isOpen) return;
            const d = trigger.dataset;
            this.trigger = trigger;
            this.opts = {
                id: d.nagimanga || '',
                endpoint: d.endpoint || '',
                manifest: d.manifest || '',
                direction: d.direction || '',
                view: d.view === 'single' ? 'single' : 'auto',
                cover: d.cover !== '0',
                // Vertical: 'page' = each page fits the window height, 'webtoon' = full width strip
                vfit: d.vertical === 'webtoon' ? 'webtoon' : 'page'
            };
            // The reader key of a protected work lives only while the viewer is open:
            // every opening asks for the password again
            this.token = null;
            this.vzoom = 1;
            if (!this.opts.manifest && !(this.opts.id && this.opts.endpoint)) return;

            this.isOpen = true;
            this.returnFocus = document.activeElement;
            this.root.hidden = false;
            // nm-empty: no pages yet (loading / password), so no slider either
            this.root.className = 'nm-viewer nm-empty';
            this.stage.innerHTML = '';
            this.scroller.innerHTML = '';
            this.scroller.hidden = true;
            this.stage.hidden = false;
            this.lock.hidden = true;
            this.message.hidden = true;
            this.toast.hidden = true;
            this.titleEl.textContent = trigger.textContent.trim();
            this.counterEl.textContent = '';
            this.setLoading(true);
            this.lockScroll();
            // On read.php's own page the viewer is the page: the browser's Back leaves it
            if (!this.standalone) this.pushHistory();
            document.addEventListener('keydown', this.keyHandler);
            global.addEventListener('resize', this.resizeHandler);
            requestAnimationFrame(() => this.root.classList.add('nm-open'));
            this.closeBtn.focus({ preventScroll: true });

            await this.load();
        }

        close() {
            if (!this.isOpen) return;
            this.isOpen = false;
            this.saveProgress();
            this.token = null;
            this.stopVInertia();
            this.releaseHistory();
            document.removeEventListener('keydown', this.keyHandler);
            global.removeEventListener('resize', this.resizeHandler);
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
            clearTimeout(this.tapTimer);
            clearTimeout(this.toastTimer);
            clearTimeout(this.endTimer);
            this.endTimer = null;
            clearTimeout(this.uiTimer);
            this.root.classList.remove('nm-open');
            this.unlockScroll();
            setTimeout(() => {
                if (this.isOpen) return;
                this.root.hidden = true;
                this.stage.innerHTML = '';
                this.scroller.innerHTML = '';
            }, 220);
            if (this.returnFocus && this.returnFocus.focus) this.returnFocus.focus({ preventScroll: true });
            // read.php's own page: closing goes back to where the link was (a new tab stays)
            if (this.standalone && global.history.length > 1) global.history.back();
        }

        lockScroll() {
            const html = document.documentElement;
            const sbw = global.innerWidth - html.clientWidth;
            this.savedScroll = { html: html.style.overflow, body: document.body.style.overflow, pad: document.body.style.paddingRight };
            if (sbw > 0) {
                const pad = parseFloat(getComputedStyle(document.body).paddingRight) || 0;
                document.body.style.paddingRight = (pad + sbw) + 'px';
            }
            html.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        }

        unlockScroll() {
            const s = this.savedScroll || { html: '', body: '', pad: '' };
            document.documentElement.style.overflow = s.html;
            document.body.style.overflow = s.body;
            document.body.style.paddingRight = s.pad;
        }

        pushHistory() {
            try {
                global.history.pushState(Object.assign({}, global.history.state || {}, { nagimanga: true }), '');
                this.historyPushed = true;
            } catch (e) { /* sandboxed */ }
        }

        releaseHistory() {
            if (!this.historyPushed) return;
            this.historyPushed = false;
            try {
                const st = global.history.state;
                if (st && st.nagimanga) {
                    this.historyBackPending = true;
                    global.history.back();
                }
            } catch (e) { /* ignore */ }
        }

        // --------------------------------------------------------------------
        // Data
        // --------------------------------------------------------------------

manifestUrl() {
            if (this.opts.manifest) return new URL(this.opts.manifest, location.href).href;
            const u = new URL(this.opts.endpoint, location.href);
            u.searchParams.set('a', 'm');
            u.searchParams.set('id', this.opts.id);
            if (this.token) u.searchParams.set('t', this.token);
            return u.href;
        }

        async load() {
            let data;
            const url = this.manifestUrl();
            try {
                const res = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                if (!res.ok) throw new Error(String(res.status));
                data = await res.json();
            } catch (e) {
                this.fail('読み込めませんでした。時間をおいて、もう一度お試しください。');
                return;
            }
            if (!this.isOpen) return;

            if (data.title) this.titleEl.textContent = String(data.title);
            if (data.locked) {
                this.token = null;
                this.showLock(String(data.title || ''));
                return;
            }
            const base = new URL(this.opts.manifest || this.opts.endpoint, location.href);
            this.pages = (Array.isArray(data.pages) ? data.pages : []).map(p => ({
                src: new URL(String(p.src), base).href,
                thumb: p.thumb ? new URL(String(p.thumb), base).href : '',
                w: Math.max(1, parseInt(p.w, 10) || 1),
                h: Math.max(1, parseInt(p.h, 10) || 1),
                // Optional smaller copy of the page (for phones / slow connections)
                light: p.light && p.light.src ? {
                    src: new URL(String(p.light.src), base).href,
                    w: Math.max(1, parseInt(p.light.w, 10) || 1)
                } : null
            }));
            if (!this.pages.length) {
                this.fail('ページがありません。');
                return;
            }
            const dir = ['rtl', 'ltr', 'vertical'].includes(this.opts.direction) ? this.opts.direction
                : (['rtl', 'ltr', 'vertical'].includes(data.direction) ? data.direction : 'rtl');
            this.direction = dir;
            this.root.classList.toggle('nm-rtl', dir === 'rtl');
            this.root.classList.toggle('nm-vertical', dir === 'vertical');
            this.root.classList.toggle('nm-webtoon', dir === 'vertical' && this.opts.vfit === 'webtoon');
            this.setupNav();
            this.setLoading(false);
            this.lock.hidden = true;
            this.root.classList.remove('nm-empty');

            const saved = parseInt(store.get('nagimanga_pos_' + this.progressKey()) || '0', 10) || 0;
            const startPage = saved > 0 && saved < this.pages.length - 1 ? saved : 0;

            if (dir === 'vertical') this.startVertical(startPage);
            else this.startPaged(startPage);

            if (startPage > 0) this.showResumeToast(startPage);

            // Bars cover the page: tuck them away once the reader has seen them
            clearTimeout(this.uiTimer);
            this.uiTimer = setTimeout(() => this.root.classList.add('nm-ui-hidden'), 2500);
        }

        progressKey() {
            return this.opts.id || this.opts.manifest;
        }

        showLock(title) {
            this.setLoading(false);
            this.lockTitle.textContent = title;
            this.lockError.textContent = '';
            this.lockInput.value = '';
            this.lock.hidden = false;
            this.lockInput.focus({ preventScroll: true });
        }

        async unlock() {
            const pw = this.lockInput.value;
            if (!pw) return;
            this.lockBtn.disabled = true;
            this.lockError.textContent = '';
            try {
                const body = new URLSearchParams({ a: 'u', id: this.opts.id, password: pw });
                const res = await fetch(new URL(this.opts.endpoint, location.href).href, {
                    method: 'POST', body, credentials: 'same-origin', cache: 'no-store'
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok && data.token) {
                    this.token = String(data.token);
                    this.lock.hidden = true;
                    this.setLoading(true);
                    await this.load();
                } else if (res.status === 429) {
                    this.lockError.textContent = '何度も間違えたため、しばらく入力できません。時間をおいてお試しください。';
                } else {
                    this.lockError.textContent = 'パスワードが違います';
                    this.lockInput.select();
                }
            } catch (e) {
                this.lockError.textContent = '通信できませんでした';
            } finally {
                this.lockBtn.disabled = false;
            }
        }

        fail(msg) {
            this.setLoading(false);
            this.message.textContent = msg;
            this.message.hidden = false;
        }

        setLoading(on) {
            this.spinner.classList.toggle('nm-visible', on);
        }

        showResumeToast(page) {
            this.toast.innerHTML = '';
            const t = el('span');
            t.textContent = `前回の続き（${page + 1} ページ目）から開きました`;
            const b = el('button', 'nm-toast-btn', { type: 'button' });
            b.textContent = '最初から読む';
            b.addEventListener('click', () => {
                this.toast.hidden = true;
                this.goToPage(0, false);
            });
            this.toast.append(t, b);
            this.toastKind = 'resume';
            this.toast.hidden = false;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toast.hidden = true; }, 5000);
        }

        saveProgress() {
            if (!this.pages.length) return;
            const page = this.direction === 'vertical' ? this.verticalPage || 0 : this.currentFirstPage();
            // Finished: start from the beginning next time
            const last = page >= this.pages.length - 1 || (this.spreads[this.spreadIndex] || {}).end;
            store.set('nagimanga_pos_' + this.progressKey(), String(last ? 0 : page));
        }

        // --------------------------------------------------------------------
        // Paged mode (rtl / ltr)
        // --------------------------------------------------------------------

        useSpreads() {
            return this.opts.view !== 'single' && global.innerWidth > global.innerHeight && global.innerWidth >= 700;
        }

        /** Group pages into spreads. A landscape page is always shown alone. */
        buildSpreads() {
            const spreads = [];
            const two = this.useSpreads();
            let i = 0;
            if (two && this.opts.cover && this.pages.length) {
                spreads.push({ pages: [0] });
                i = 1;
            }
            while (i < this.pages.length) {
                const p = this.pages[i];
                const wide = p.w > p.h;
                const next = this.pages[i + 1];
                if (two && !wide && next && next.w <= next.h) {
                    spreads.push({ pages: [i, i + 1] });
                    i += 2;
                } else {
                    spreads.push({ pages: [i] });
                    i += 1;
                }
            }
            spreads.push({ pages: [], end: true });
            this.spreads = spreads;
        }

        spreadOfPage(page) {
            const i = this.spreads.findIndex(s => s.pages.includes(page));
            return i < 0 ? 0 : i;
        }

        currentFirstPage() {
            const s = this.spreads[this.spreadIndex];
            if (!s) return 0;
            return s.end ? this.pages.length - 1 : s.pages[0];
        }

        startPaged(page) {
            this.stage.hidden = false;
            this.scroller.hidden = true;
            this.buildSpreads();
            this.spreadIndex = this.spreadOfPage(page);
            this.slider.max = String(this.pages.length - 1);
            this.renderSlides();
        }

        /** The side where the next spread comes from: -1 = left (rtl), 1 = right (ltr) */
        nextSide() {
            return this.direction === 'rtl' ? -1 : 1;
        }

        makeSpread(index) {
            const s = this.spreads[index];
            if (!s) return null;
            const box = el('div', 'nm-spread');
            box._index = index;
            if (s.end) {
                box.classList.add('nm-end');
                const inner = el('div', 'nm-end-card');
                const t = el('p', 'nm-end-title');
                t.textContent = 'おわり';
                const again = el('button', 'nm-end-btn', { type: 'button' });
                again.textContent = '最初から読む';
                again.addEventListener('click', () => this.goToPage(0, false));
                const close = el('button', 'nm-end-btn', { type: 'button' });
                close.textContent = '閉じる';
                close.addEventListener('click', () => this.close());
                inner.append(t, again, close);
                box.appendChild(inner);
                return box;
            }
            const inner = el('div', 'nm-spread-inner');
            // Right-to-left books put the first page on the right
            const order = this.direction === 'rtl' ? s.pages.slice().reverse() : s.pages;
            order.forEach(pi => {
                const p = this.pages[pi];
                const frame = el('div', 'nm-page');
                if (p.thumb) frame.style.backgroundImage = `url("${p.thumb.replace(/"/g, '%22')}")`;
                const img = el('img', 'nm-img', { alt: `${pi + 1}`, draggable: 'false', decoding: 'async' });
                img.addEventListener('load', () => frame.classList.add('nm-loaded'), { once: true });
                frame.appendChild(img);
                frame._page = p;
                frame._img = img;
                inner.appendChild(frame);
            });
            box.appendChild(inner);
            this.sizeSpread(box);
            // Now that the display size is known: light or full version
            box.querySelectorAll('.nm-page').forEach(f => this.setImg(f._img, f._page, parseFloat(f.style.width)));
            // Decode now, while the spread waits off screen: iOS Safari leaves big
            // off-screen images undecoded and shows black when they slide in
            this.spreadReady(box);
            return box;
        }

        /** Resolves once the spread's images can be painted, or after READY_WAIT_MS. */
        spreadReady(box) {
            const imgs = box ? Array.from(box.querySelectorAll('.nm-img')).filter(i => i.src && i.decode) : [];
            if (!imgs.length) return Promise.resolve();
            const all = Promise.all(imgs.map(i => i.decode().catch(() => {})));
            return Promise.race([all, new Promise(r => setTimeout(r, READY_WAIT_MS))]);
        }

        /** Fit the spread's pages into the stage at the same height. */
        sizeSpread(box) {
            const frames = box.querySelectorAll('.nm-page');
            if (!frames.length) return;
            const W = this.stage.clientWidth || global.innerWidth;
            const H = this.stage.clientHeight || global.innerHeight;
            const ratioSum = Array.from(frames).reduce((sum, f) => sum + f._page.w / f._page.h, 0);
            const h = Math.min(H, W / ratioSum);
            frames.forEach(f => {
                f.style.width = (f._page.w / f._page.h * h) + 'px';
                f.style.height = h + 'px';
            });
            box._w = ratioSum * h;
            box._h = h;
        }

        /**
         * Light or full version for a page shown cssWidth CSS pixels wide.
         * The light copy is used while it is sharp enough for this screen.
         */
        pickSrc(p, cssWidth) {
            if (!p.light) return p.src;
            const conn = global.navigator && global.navigator.connection;
            if (conn && conn.saveData) return p.light.src;
            const need = cssWidth * (global.devicePixelRatio || 1);
            return need <= p.light.w * 1.1 ? p.light.src : p.src;
        }

        /** Rough display width of a page (for preloading the right version). */
        estimateWidth(p, pagesInSpread) {
            const W = (this.stage.clientWidth || global.innerWidth) / Math.max(1, pagesInSpread);
            const H = this.stage.clientHeight || global.innerHeight;
            return Math.min(W, H * p.w / p.h);
        }

        /**
         * Point img at the right version. Going from light to full, the full
         * image is loaded first and swapped in (no blank flash); once a page
         * shows the full version it never goes back to the light one.
         */
        setImg(img, p, cssWidth) {
            if (!img || img._full) return;
            const want = this.pickSrc(p, cssWidth);
            if (img._want === want) return;
            const upgrade = !!img._want && want === p.src;
            img._want = want;
            img._full = want === p.src;
            if (!upgrade) {
                img.src = want;
                return;
            }
            const pre = new Image();
            pre.decoding = 'async';
            pre.onload = () => { if (img._want === want) img.src = want; };
            pre.src = want;
        }

        renderSlides() {
            this.stage.innerHTML = '';
            this.resetZoom(false);
            const i = this.spreadIndex;
            this.slides = { prev: this.makeSpread(i - 1), cur: this.makeSpread(i), next: this.makeSpread(i + 1) };
            ['prev', 'cur', 'next'].forEach(k => { if (this.slides[k]) this.stage.appendChild(this.slides[k]); });
            this.positionSlides(0, false);
            this.preload();
            this.updateCounter();
        }

        positionSlides(offset, animate) {
            const W = this.stage.clientWidth || global.innerWidth;
            const side = this.nextSide();
            const t = animate ? `transform ${TURN_MS}ms ${TURN_EASING}` : 'none';
            const place = (node, base) => {
                if (!node) return;
                node.style.transition = t;
                node.style.transform = `translate3d(${base + offset}px, 0, 0)`;
            };
            place(this.slides.prev, -side * W);
            place(this.slides.cur, 0);
            place(this.slides.next, side * W);
        }

        preload() {
            // Warm the cache for the next two spreads and the previous one
            [1, 2, -1].forEach(d => {
                const s = this.spreads[this.spreadIndex + d];
                if (!s || s.end) return;
                s.pages.forEach(pi => {
                    const p = this.pages[pi];
                    const img = new Image();
                    img.decoding = 'async';
                    img.src = this.pickSrc(p, this.estimateWidth(p, s.pages.length));
                });
            });
        }

        updateCounter() {
            const s = this.spreads[this.spreadIndex];
            const n = this.pages.length;
            if (!s) return;
            let text;
            if (s.end) text = `${n} / ${n}`;
            else if (s.pages.length === 2) text = `${s.pages[0] + 1}-${s.pages[1] + 1} / ${n}`;
            else text = `${s.pages[0] + 1} / ${n}`;
            this.counterEl.textContent = text;
            this.slider.value = String(this.currentFirstPage());
            this.saveProgress();
        }

        /** step: +1 = forward, -1 = back */
        turn(step) {
            if (this.animating || this.direction === 'vertical') return;
            const target = this.spreadIndex + step;
            if (target < 0 || target >= this.spreads.length) {
                this.positionSlides(0, true);
                return;
            }
            this.animating = true;
            this.resetZoom(true);
            this.spreadReady(step > 0 ? this.slides.next : this.slides.prev).then(() => {
                if (!this.isOpen) {
                    this.animating = false;
                    return;
                }
                this.slideTo(target, step);
            });
        }

        slideTo(target, step) {
            const W = this.stage.clientWidth || global.innerWidth;
            // Moving forward slides everything towards the opposite of nextSide
            this.positionSlides(-step * this.nextSide() * W, true);
            setTimeout(() => {
                this.spreadIndex = target;
                // Reuse the incoming spread, build only the new neighbour
                const { prev, cur, next } = this.slides;
                if (step > 0) {
                    if (prev) prev.remove();
                    this.slides = { prev: cur, cur: next, next: this.makeSpread(target + 1) };
                    if (this.slides.next) this.stage.appendChild(this.slides.next);
                } else {
                    if (next) next.remove();
                    this.slides = { prev: this.makeSpread(target - 1), cur: prev, next: cur };
                    if (this.slides.prev) this.stage.appendChild(this.slides.prev);
                }
                this.positionSlides(0, false);
                this.preload();
                this.updateCounter();
                this.animating = false;
            }, TURN_MS);
        }

        goToPage(page, animate) {
            this.toast.hidden = true;
            if (this.direction === 'vertical') {
                const node = this.scroller.children[page];
                if (node) this.scroller.scrollTo({ top: node.offsetTop, behavior: animate ? 'smooth' : 'auto' });
                return;
            }
            this.spreadIndex = this.spreadOfPage(page);
            this.renderSlides();
        }

        onSlider() {
            const page = parseInt(this.slider.value, 10) || 0;
            if (this.direction === 'vertical') {
                this.goToPage(page, false);
                return;
            }
            const idx = this.spreadOfPage(page);
            if (idx !== this.spreadIndex) {
                this.spreadIndex = idx;
                this.renderSlides();
            }
        }

        relayout() {
            if (!this.pages.length) return;
            if (this.direction === 'vertical') {
                this.keepVerticalAnchor(() => this.layoutVertical());
                return;
            }
            const page = this.currentFirstPage();
            this.buildSpreads();
            this.spreadIndex = this.spreadOfPage(page);
            this.renderSlides();
        }

        // --------------------------------------------------------------------
        // Zoom (current spread only)
        // --------------------------------------------------------------------

        updateZoomUi(s) {
            if (!this.zoomLabel) return;
            this.zoomLabel.textContent = Math.round(s * 100) + '%';
            this.zoomOutBtn.disabled = s <= 1.01;
            this.zoomInBtn.disabled = s >= MAX_ZOOM - 0.01;
        }

        resetZoom(animate) {
            if (this.direction === 'vertical') {
                this.setVerticalZoom(1);
                return;
            }
            this.zoom = { s: 1, x: 0, y: 0 };
            this.applyZoom(animate);
        }

        zoomBounds(s) {
            const cur = this.slides.cur;
            const W = this.stage.clientWidth, H = this.stage.clientHeight;
            const cw = (cur && cur._w) || W, ch = (cur && cur._h) || H;
            return { x: Math.max(0, (cw * s - W) / 2), y: Math.max(0, (ch * s - H) / 2) };
        }

        clampZoom() {
            const b = this.zoomBounds(this.zoom.s);
            this.zoom.x = clamp(this.zoom.x, -b.x, b.x);
            this.zoom.y = clamp(this.zoom.y, -b.y, b.y);
        }

        applyZoom(animate) {
            if (this.zoom.s > 1.01 && this.slides.cur) {
                this.slides.cur.querySelectorAll('.nm-page').forEach(f =>
                    this.setImg(f._img, f._page, parseFloat(f.style.width) * this.zoom.s));
            }
            const inner = this.slides.cur && this.slides.cur.querySelector('.nm-spread-inner');
            this.root.classList.toggle('nm-zoomed', this.zoom.s > 1.01);
            this.updateZoomUi(this.zoom.s);
            if (!inner) return;
            inner.style.transition = animate ? 'transform 0.25s ease-out' : 'none';
            inner.style.transform = `translate3d(${this.zoom.x}px, ${this.zoom.y}px, 0) scale(${this.zoom.s})`;
        }

        /** Buttons / keys: next zoom step around the centre of the screen. */
        zoomStep(dir) {
            const vertical = this.direction === 'vertical';
            if (!vertical && (!this.slides.cur || this.animating)) return;
            const s = vertical ? this.vzoom : this.zoom.s;
            const next = dir > 0
                ? ZOOM_STEPS.find(z => z > s + 0.01)
                : ZOOM_STEPS.slice().reverse().find(z => z < s - 0.01);
            if (next === undefined) return;
            if (vertical) {
                this.setVerticalZoom(next);
                return;
            }
            const r = this.stage.getBoundingClientRect();
            this.zoomAt(next, r.left + r.width / 2, r.top + r.height / 2, true);
        }

        revealBarsByHover() {
            if (!this.root.classList.contains('nm-ui-hidden') && !this.barsByHover) return;
            this.barsByHover = true;
            this.root.classList.remove('nm-ui-hidden');
            if (!this.overBar) this.scheduleHideBars(1800);
        }

        scheduleHideBars(ms) {
            clearTimeout(this.uiTimer);
            this.uiTimer = setTimeout(() => {
                if (this.overBar || !this.isOpen) return;
                this.barsByHover = false;
                this.root.classList.add('nm-ui-hidden');
            }, ms);
        }

        /** Zoom so that the point (cx, cy) on screen stays under the finger. */
        zoomAt(s, cx, cy, animate) {
            const W = this.stage.clientWidth, H = this.stage.clientHeight;
            const rect = this.stage.getBoundingClientRect();
            const px = cx - rect.left - W / 2, py = cy - rect.top - H / 2;
            const k = s / this.zoom.s;
            this.zoom.x = px - (px - this.zoom.x) * k;
            this.zoom.y = py - (py - this.zoom.y) * k;
            this.zoom.s = s;
            this.clampZoom();
            this.applyZoom(animate);
        }

        onWheel(e) {
            if (!this.isOpen || this.direction === 'vertical') return;
            if (e.ctrlKey || this.zoom.s > 1.01) {
                // Trackpad pinch (ctrl+wheel) or wheel while zoomed
                e.preventDefault();
                if (e.ctrlKey) {
                    const s = clamp(this.zoom.s * Math.exp(-e.deltaY * 0.01), 1, MAX_ZOOM);
                    this.zoomAt(s, e.clientX, e.clientY, false);
                } else {
                    this.zoom.x -= e.deltaX;
                    this.zoom.y -= e.deltaY;
                    this.clampZoom();
                    this.applyZoom(false);
                }
                return;
            }
            // Plain wheel turns pages (debounced)
            e.preventDefault();
            const now = Date.now();
            if (now - (this.lastWheel || 0) < 350 || Math.abs(e.deltaY) + Math.abs(e.deltaX) < 20) return;
            this.lastWheel = now;
            this.turn((e.deltaY || e.deltaX) > 0 ? 1 : -1);
        }

        // --------------------------------------------------------------------
        // Pointer input (paged)
        // --------------------------------------------------------------------

        onDown(e) {
            if (e.pointerType === 'mouse' && e.button !== 0) return;
            if (e.target.closest('button')) return;
            e.preventDefault();
            // Keep receiving moves outside the stage (fails harmlessly for synthetic pointers)
            try { this.stage.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
            if (this.pointers.size === 2) {
                const [a, b] = Array.from(this.pointers.values());
                this.pinch = { d: Math.hypot(a.x - b.x, a.y - b.y), s: this.zoom.s };
                this.drag = null;
                return;
            }
            this.drag = { x0: e.clientX, y0: e.clientY, x: e.clientX, y: e.clientY, t0: performance.now(), moved: false, samples: [], type: e.pointerType };
        }

        onMove(e) {
            if (!this.pointers.has(e.pointerId)) return;
            this.pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

            if (this.pinch && this.pointers.size === 2) {
                const [a, b] = Array.from(this.pointers.values());
                const d = Math.hypot(a.x - b.x, a.y - b.y);
                const s = clamp(this.pinch.s * d / this.pinch.d, 1, MAX_ZOOM);
                this.zoomAt(s, (a.x + b.x) / 2, (a.y + b.y) / 2, false);
                return;
            }
            const g = this.drag;
            if (!g || this.animating) return;
            const dx = e.clientX - g.x, dy = e.clientY - g.y;
            g.x = e.clientX;
            g.y = e.clientY;
            const now = performance.now();
            g.samples.push({ t: now, x: e.clientX });
            while (g.samples.length > 2 && now - g.samples[0].t > 100) g.samples.shift();
            // A mouse hand shakes a little during a (double) click: allow more slack
            if (!g.moved && Math.hypot(e.clientX - g.x0, e.clientY - g.y0) > (g.type === 'mouse' ? 14 : 8)) g.moved = true;
            if (!g.moved) return;

            if (this.zoom.s > 1.01) {
                this.zoom.x += dx;
                this.zoom.y += dy;
                this.clampZoom();
                this.applyZoom(false);
                return;
            }
            let off = e.clientX - g.x0;
            // Resist at the first spread / after the end card
            const forward = -off * this.nextSide() > 0;
            if ((forward && this.spreadIndex >= this.spreads.length - 1) || (!forward && this.spreadIndex <= 0)) off *= 0.3;
            this.positionSlides(off, false);
        }

        onUp(e, cancelled) {
            if (!this.pointers.has(e.pointerId)) return;
            this.pointers.delete(e.pointerId);
            if (this.pinch) {
                if (this.pointers.size < 2) this.pinch = null;
                if (this.zoom.s < 1.05) this.resetZoom(true);
                // The remaining finger must not start a page turn
                this.drag = null;
                return;
            }
            const g = this.drag;
            this.drag = null;
            if (!g || this.animating) return;

            if (!g.moved) {
                if (!cancelled) this.onTap(e.clientX, e.clientY, g.type);
                return;
            }
            if (this.zoom.s > 1.01) return;

            const off = e.clientX - g.x0;
            const W = this.stage.clientWidth || global.innerWidth;
            const first = g.samples[0], last = g.samples[g.samples.length - 1];
            const v = first && last && last.t > first.t && performance.now() - last.t < 80 ? (last.x - first.x) / (last.t - first.t) : 0;
            const step = -Math.sign(off) * this.nextSide();
            if (Math.abs(off) > W * 0.15 || (Math.abs(v) > 0.4 && Math.sign(v) === Math.sign(off) && Math.abs(off) > 20)) {
                this.turn(step);
            } else {
                this.positionSlides(0, true);
            }
        }

        onTap(x, y, pointerType) {
            const rect = this.stage.getBoundingClientRect();
            const rel = (x - rect.left) / rect.width;
            const now = Date.now();
            const dbl = now - this.lastTap.t < 300 && Math.hypot(x - this.lastTap.x, y - this.lastTap.y) < 30;
            this.lastTap = { t: now, x, y };

            if (this.zoom.s > 1.01) {
                if (dbl) this.resetZoom(true);
                return;
            }
            // Edges turn the page at once; the middle toggles the bars or zooms on double tap.
            // With a mouse the edges are narrower, so a double click near the middle zooms
            // instead of turning the page (the zoom buttons are there too).
            const edge = pointerType === 'mouse' ? 0.2 : 0.3;
            if (rel < edge || rel > 1 - edge) {
                const towardsNext = (rel > 0.5) === (this.nextSide() > 0);
                this.turn(towardsNext ? 1 : -1);
                return;
            }
            clearTimeout(this.tapTimer);
            if (dbl) {
                this.zoomAt(2.5, x, y, true);
                return;
            }
            this.tapTimer = setTimeout(() => {
                clearTimeout(this.uiTimer);
                // A click / tap shows the bars for good (until the next one)
                this.barsByHover = false;
                this.root.classList.toggle('nm-ui-hidden');
            }, 260);
        }

        onKey(e) {
            if (!this.isOpen) return;
            if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
                return;
            }
            if (e.target === this.lockInput || !this.lock.hidden || !this.pages.length) return;
            if (e.key === '+' || e.key === ';' || e.key === '=') { e.preventDefault(); this.zoomStep(1); return; }
            if (e.key === '-') { e.preventDefault(); this.zoomStep(-1); return; }
            if (e.key === '0') { e.preventDefault(); this.resetZoom(true); return; }
            if (e.key === 'Home') { e.preventDefault(); this.goToPage(0, true); return; }
            if (e.key === 'End') { e.preventDefault(); this.goToPage(this.pages.length - 1, true); return; }
            if (this.direction === 'vertical') {
                if (e.key === 'ArrowDown' || e.key === 'PageDown' || (e.key === ' ' && !e.shiftKey)) { e.preventDefault(); this.vStep(1); }
                else if (e.key === 'ArrowUp' || e.key === 'PageUp' || (e.key === ' ' && e.shiftKey)) { e.preventDefault(); this.vStep(-1); }
                return;
            }
            const fwd= this.direction === 'rtl' ? 'ArrowLeft' : 'ArrowRight';
            const back = this.direction === 'rtl' ? 'ArrowRight' : 'ArrowLeft';
            if (e.key === fwd || e.key === ' ' || e.key === 'PageDown') { e.preventDefault(); this.turn(1); }
            else if (e.key === back || e.key === 'PageUp') { e.preventDefault(); this.turn(-1); }
        }

        // --------------------------------------------------------------------
        // Prev / next buttons (bottom bar)
        // --------------------------------------------------------------------

        /** Put "next" on the side the book moves to: left for right-to-left books. */
        setupNav() {
            const set = (btn, step, icon) => {
                btn.innerHTML = icon;
                const label = step > 0 ? '次のページ' : '前のページ';
                btn.setAttribute('aria-label', label);
                btn.title = label;
                return step;
            };
            if (this.direction === 'vertical') {
                this.navAStep = set(this.navA, -1, ICON_UP);
                this.navBStep = set(this.navB, 1, ICON_DOWN);
            } else if (this.direction === 'rtl') {
                this.navAStep = set(this.navA, 1, ICON_LEFT);
                this.navBStep = set(this.navB, -1, ICON_RIGHT);
            } else {
                this.navAStep = set(this.navA, -1, ICON_LEFT);
                this.navBStep = set(this.navB, 1, ICON_RIGHT);
            }
        }

        navStep(step) {
            if (!this.pages.length) return;
            if (this.direction === 'vertical') this.vStep(step);
            else this.turn(step);
        }

        // --------------------------------------------------------------------
        // Vertical mode (scroll)
        // --------------------------------------------------------------------

        startVertical(page) {
            this.stage.hidden = true;
            this.scroller.hidden = false;
            this.slider.max = String(this.pages.length - 1);
            const frag = document.createDocumentFragment();
            this.pages.forEach((p, i) => {
                const img = el('img', 'nm-page-v', {
                    alt: `${i + 1}`, width: String(p.w), height: String(p.h),
                    loading: i < 3 ? 'eager' : 'lazy', decoding: 'async', draggable: 'false'
                });
                if (p.thumb) img.style.backgroundImage = `url("${p.thumb.replace(/"/g, '%22')}")`;
                img._index = i;
                frag.appendChild(img);
            });
            this.scroller.appendChild(frag);
            this.layoutVertical();
            this.updateZoomUi(this.vzoom);
            this.verticalPage = page;
            this.updateVerticalCounter(page);
            requestAnimationFrame(() => this.goToPage(page, false));
        }

        /**
         * Page manga: every page starts exactly as tall as the window (the whole
         * page is visible). Webtoon: a strip as wide as the window (max 900px).
         * Zoom multiplies that width.
         */
        layoutVertical() {
            const W = this.scroller.clientWidth || global.innerWidth;
            const H = this.scroller.clientHeight || global.innerHeight;
            const webtoon = this.opts.vfit === 'webtoon';
            Array.from(this.scroller.children).forEach(img => {
                const p = this.pages[img._index];
                const base = webtoon ? Math.min(W, 900) : Math.min(W, H * p.w / p.h);
                const width = Math.max(1, Math.floor(base * this.vzoom));
                img.style.width = width + 'px';
                this.setImg(img, p, width);
            });
        }

        /** Run fn (which changes sizes) while keeping the same spot in the middle of the screen. */
        keepVerticalAnchor(fn) {
            const sc = this.scroller;
            const cur = sc.children[this.verticalPage || 0];
            const H = sc.clientHeight;
            const frac = cur && cur.offsetHeight ? (sc.scrollTop + H / 2 - cur.offsetTop) / cur.offsetHeight : 0;
            const fracX = sc.scrollWidth > 0 ? (sc.scrollLeft + sc.clientWidth / 2) / sc.scrollWidth : 0.5;
            fn();
            if (cur) sc.scrollTop = cur.offsetTop + frac * cur.offsetHeight - H / 2;
            sc.scrollLeft = fracX * sc.scrollWidth - sc.clientWidth / 2;
        }

        setVerticalZoom(z) {
            z = clamp(z, 1, MAX_ZOOM);
            if (Math.abs(z - this.vzoom) < 0.001) return;
            this.stopVInertia();
            this.keepVerticalAnchor(() => {
                this.vzoom = z;
                this.layoutVertical();
            });
            this.root.classList.toggle('nm-zoomed', z > 1.01);
            this.updateZoomUi(z);
        }

        /** Next / previous page; pages taller than the window scroll by a screenful. */
        vStep(step) {
            const sc = this.scroller;
            const H = sc.clientHeight;
            const cur = sc.children[this.verticalPage || 0];
            if (!cur) return;
            const top = sc.scrollTop;
            const bottom = top + H;
            const tall = cur.offsetHeight > H * 1.05;
            if (tall) {
                // Still inside the current page: scroll within it first
                const stillBelow = step > 0 && cur.offsetTop + cur.offsetHeight > bottom + 4;
                const stillAbove = step < 0 && cur.offsetTop < top - 4;
                if (stillBelow || stillAbove) {
                    sc.scrollBy({ top: step * H * 0.85, behavior: 'smooth' });
                    return;
                }
            }
            const target = clamp((this.verticalPage || 0) + step, 0, this.pages.length - 1);
            // Going back to a tall page: land on its bottom part
            const node = sc.children[target];
            if (step < 0 && node && node.offsetHeight > H * 1.05) {
                sc.scrollTo({ top: node.offsetTop + node.offsetHeight - H, behavior: 'smooth' });
            } else {
                this.goToPage(target, true);
            }
        }

        // Mouse drag = grab and scroll, with a little glide after release
        onVDown(e) {
            if (e.pointerType !== 'mouse' || e.button !== 0) return;
            this.stopVInertia();
            e.preventDefault();
            try { this.scroller.setPointerCapture(e.pointerId); } catch (err) { /* ignore */ }
            const now = performance.now();
            this.vdrag = {
                x0: e.clientX, y0: e.clientY,
                sl: this.scroller.scrollLeft, st: this.scroller.scrollTop,
                moved: false, samples: [{ t: now, x: e.clientX, y: e.clientY }]
            };
        }

        onVMove(e) {
            const d = this.vdrag;
            if (!d) return;
            const dx = e.clientX - d.x0, dy = e.clientY - d.y0;
            if (!d.moved && Math.hypot(dx, dy) > 6) {
                d.moved = true;
                this.root.classList.add('nm-grabbing');
            }
            if (!d.moved) return;
            this.scroller.scrollTop = d.st - dy;
            this.scroller.scrollLeft = d.sl - dx;
            const now = performance.now();
            d.samples.push({ t: now, x: e.clientX, y: e.clientY });
            while (d.samples.length > 2 && now - d.samples[0].t > 100) d.samples.shift();
        }

        onVUp() {
            const d = this.vdrag;
            if (!d) return;
            this.vdrag = null;
            this.root.classList.remove('nm-grabbing');
            if (!d.moved) return;
            this.suppressVClick = true;
            setTimeout(() => { this.suppressVClick = false; }, 0);
            const a = d.samples[0], b = d.samples[d.samples.length - 1];
            if (!a || !b || b.t <= a.t || performance.now() - b.t > 80) return;
            let vx = (b.x - a.x) / (b.t - a.t), vy = (b.y - a.y) / (b.t - a.t);
            let last = performance.now();
            const glide = now => {
                const dt = Math.min(now - last, 32);
                last = now;
                this.scroller.scrollTop -= vy * dt;
                this.scroller.scrollLeft -= vx * dt;
                const f = Math.pow(0.994, dt);
                vx *= f;
                vy *= f;
                this.vGlide = Math.abs(vx) + Math.abs(vy) > 0.03 ? requestAnimationFrame(glide) : null;
            };
            this.vGlide = requestAnimationFrame(glide);
        }

        stopVInertia() {
            if (this.vGlide) cancelAnimationFrame(this.vGlide);
            this.vGlide = null;
        }

        onVerticalScroll() {
            this.checkVerticalEnd();
            if (this.vRaf) return;
            this.vRaf = requestAnimationFrame(() => {
                this.vRaf = null;
                // The page crossing the middle of the screen is the current one
                const mid = this.scroller.scrollTop + this.scroller.clientHeight / 2;
                const kids = this.scroller.children;
                let lo = 0, hi = kids.length - 1;
                while (lo < hi) {
                    const m = (lo + hi + 1) >> 1;
                    if (kids[m].offsetTop <= mid) lo = m; else hi = m - 1;
                }
                if (lo !== this.verticalPage) {
                    this.verticalPage = lo;
                    this.updateVerticalCounter(lo);
                }
            });
        }

        /** Vertical: resting at the very bottom for a moment offers to close. */
        checkVerticalEnd() {
            const sc = this.scroller;
            const atEnd = sc.scrollHeight > sc.clientHeight && sc.scrollTop + sc.clientHeight >= sc.scrollHeight - 4;
            const showing = !this.toast.hidden && this.toastKind === 'end';
            if (atEnd) {
                if (!this.endTimer && !showing) {
                    this.endTimer = setTimeout(() => {
                        this.endTimer = null;
                        if (this.isOpen && this.direction === 'vertical') this.showEndToast();
                    }, END_DELAY_MS);
                }
                return;
            }
            clearTimeout(this.endTimer);
            this.endTimer = null;
            if (showing) this.toast.hidden = true;
        }

        showEndToast() {
            this.toast.innerHTML = '';
            const t = el('span');
            t.textContent = 'おわり';
            const again = el('button', 'nm-toast-btn', { type: 'button' });
            again.textContent = '最初から読む';
            again.addEventListener('click', () => {
                this.toast.hidden = true;
                this.goToPage(0, false);
            });
            const close = el('button', 'nm-toast-btn', { type: 'button' });
            close.textContent = '閉じる';
            close.addEventListener('click', () => this.close());
            this.toast.append(t, again, close);
            clearTimeout(this.toastTimer);
            this.toastKind = 'end';
            this.toast.hidden = false;
        }

        updateVerticalCounter(page) {
            this.counterEl.textContent = `${page + 1} / ${this.pages.length}`;
            this.slider.value = String(page);
            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.saveProgress(), 400);
        }
    }

    // ------------------------------------------------------------------------
    // Triggers
    // ------------------------------------------------------------------------

    const reader = new Reader();

    // A plain link to the share URL (for places that cannot hold HTML, e.g. Tegalog posts):
    //   https://example.com/nagimanga/read.php?nagimanga=ID&dir=rtl&view=auto&cover=1
    // The options become the same data-* attributes as the share tag.
    function fromShareUrl(a) {
        let u;
        try { u = new URL(a.getAttribute('href'), location.href); } catch (e) { return false; }
        const q = u.searchParams;
        const id = q.get('nagimanga') || '';
        if (!/^[A-Za-z0-9]{12}$/.test(id) || !/\/read\.php$/.test(u.pathname) || q.has('a')) return false;
        const d = a.dataset;
        d.nagimanga = id;
        d.endpoint = u.origin + u.pathname;
        const dir = q.get('dir');
        if (dir === 'rtl' || dir === 'ltr' || dir === 'vertical') d.direction = dir;
        if (q.get('view') === 'single') d.view = 'single';
        if (q.get('cover') === '0') d.cover = '0';
        if (q.get('vertical') === 'webtoon') d.vertical = 'webtoon';
        return true;
    }

    function onClick(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        let t = e.target.closest('[data-nagimanga], [data-nagimanga-manifest]');
        if (!t) {
            const a = e.target.closest('a[href*="nagimanga="]');
            if (!a || !fromShareUrl(a)) return;
            t = a;
        }
        e.preventDefault();
        if (t.dataset.nagimangaManifest && !t.dataset.manifest) t.dataset.manifest = t.dataset.nagimangaManifest;
        reader.open(t);
    }

    document.addEventListener('click', onClick);

    global.NagiManga = {
        version: VERSION,
        open: (trigger) => reader.open(trigger),
        close: () => reader.close()
    };

    // Drop a stale marker if the page was reloaded while reading
    try {
        const st = global.history.state;
        if (st && st.nagimanga) {
            const rest = Object.assign({}, st);
            delete rest.nagimanga;
            global.history.replaceState(Object.keys(rest).length ? rest : null, '');
        }
    } catch (e) { /* ignore */ }

    // read.php's own page for the share URL opens the viewer at once: NagiManga.js?open=<link id>
    try {
        const openId = SCRIPT_URL ? new URL(SCRIPT_URL).searchParams.get('open') : null;
        const link = openId && document.getElementById(openId);
        if (link) {
            reader.standalone = true;
            reader.open(link);
        }
    } catch (e) { /* ignore */ }
})(window);
