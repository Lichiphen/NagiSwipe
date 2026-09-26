/*
* ============================================================================
 * ![NagiSwipe Library Core]
 * Drop-in gallery library
 * 
 * NagiSwipe v1.2.0
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 * https://gitlab.com/lichiphen/nagiswipe/-/blob/main/LICENSE
 * ============================================================================
 */
(function (global) {
    'use strict';

    const DEFAULT_EXTENSIONS = /\.(jpg|jpeg|png|webp|gif|bmp|avif|svg)$/i;

    const SVG_ARROW_LEFT = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>`;
    const SVG_ARROW_RIGHT = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>`;
    const SVG_ZOOM_IN = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="11" y1="8" x2="11" y2="14"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>`;
    const SVG_ZOOM_OUT = `<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line><line x1="8" y1="11" x2="14" y2="11"></line></svg>`;

    const SmartUtils = {
        clamp: (val, min, max) => Math.min(Math.max(val, min), max),
        getDistance: (p1, p2) => Math.hypot(p2.x - p1.x, p2.y - p1.y),
        getCenter: (p1, p2) => ({ x: (p1.x + p2.x) / 2, y: (p1.y + p2.y) / 2 }),

        /**
         * Test the URL path only, so "photo.jpg?v=123" or "photo.jpg#x" still match
         */
        isImageUrl: (href, pattern) => {
            if (!href) return false;
            let path = href;
            try { path = new URL(href, document.baseURI).pathname; } catch (e) { /* keep raw */ }
            return pattern.test(path) || pattern.test(href);
        },

        /**
         * Fit scale for an image inside the viewport (never upscale)
         */
        getFitScale: (naturalW, naturalH) => {
            if (!naturalW || !naturalH) return 1;
            return Math.min(window.innerWidth / naturalW, window.innerHeight / naturalH, 1);
        },
        
        /**
         * Check if a point (clientX, clientY) is on the visible part of an image
         * with object-fit: contain (accounts for letterboxing)
         */
        isPointOnVisibleImage: (imgEl, clientX, clientY) => {
            if (!imgEl) return false;
            const rect = imgEl.getBoundingClientRect();
            if (rect.width === 0 || rect.height === 0) return false;

            const naturalW = imgEl.naturalWidth;
            const naturalH = imgEl.naturalHeight;
            if (!naturalW || !naturalH) {
                return clientX >= rect.left && clientX <= rect.right &&
                       clientY >= rect.top && clientY <= rect.bottom;
            }

            const scale = Math.min(rect.width / naturalW, rect.height / naturalH);
            const displayW = naturalW * scale;
            const displayH = naturalH * scale;
            const left = rect.left + (rect.width - displayW) / 2;
            const top = rect.top + (rect.height - displayH) / 2;

            return clientX >= left && clientX <= left + displayW &&
                   clientY >= top && clientY <= top + displayH;
        }
    };

    class NagiSwipeViewer {
        constructor() {
            this.items = [];
            this.isOpen = false;
            this.currentIndex = 0;
            this.state = { x: 0, y: 0, scale: 1 };
            this.maxScale = 1;
            this.allowOverZoom = false;
            
            // Interaction state
            this.pointers = [];
            this.lastCenter = null;
            this.lastDist = 0;
            this.startPos = { x: 0, y: 0 };
            this.isDragging = false;
            this.dragAxis = null; // 'x' or 'y'
            this.bgTapStart = null;
            this.closeTimer = null;
            this.tapTargetIsImage = false;
            this.tapImageEl = null;
            this.suppressNextClick = false;
            this.suppressClickTimer = null;

            // Double tap state
            this.lastTapTime = 0;
            this.lastTapPos = { x: 0, y: 0 };

            this.slidePool = { current: null, prev: null, next: null };
            
            this.options = {
                imageExtensions: DEFAULT_EXTENSIONS,
                selector: 'a[href]',
                scope: document,
                caption: true,  // Show caption (alt etc.) at the bottom
                counter: true   // Show "3 / 12" at the top left
            };

            this.domGenerated = false;
            this._savedOverflow = null;
        }

        init(options = {}) {
            this.options = { ...this.options, ...options };
            if (!this.domGenerated) {
                this._createDOM();
                this.domGenerated = true;
            }
            this._scan();
            this._bindGlobalEvents();
        }

        _createDOM() {
            if (document.getElementById('ns-viewer')) return;

            const html = `
                <div class="ns-bg" id="ns-bg"></div>
                <div class="ns-stage" id="ns-stage"></div>
                <!-- Shared Spinner -->
                <div class="ns-loading-spinner" id="ns-spinner"></div>
                <div class="ns-ui" id="ns-ui">
                    <div class="ns-counter" id="ns-counter" aria-hidden="true"></div>
                    <button class="ns-btn ns-zoom" id="ns-zoom" aria-label="Zoom">${SVG_ZOOM_IN}</button>
                    <button class="ns-btn ns-close" id="ns-close" aria-label="Close"></button>
                    <button class="ns-btn ns-prev" id="ns-prev" aria-label="Previous">${SVG_ARROW_LEFT}</button>
                    <button class="ns-btn ns-next" id="ns-next" aria-label="Next">${SVG_ARROW_RIGHT}</button>
                    <div class="ns-caption" id="ns-caption"><p class="ns-caption-text" id="ns-caption-text"></p></div>
                </div>
            `;
            
            this.viewer = document.createElement('div');
            this.viewer.id = 'ns-viewer';
            this.viewer.className = 'ns-viewer';
            this.viewer.setAttribute('role', 'dialog');
            this.viewer.setAttribute('aria-modal', 'true');
            this.viewer.setAttribute('aria-label', 'Image viewer');
            this.viewer.innerHTML = html;
            document.body.appendChild(this.viewer);

            this.bg = this.viewer.querySelector('#ns-bg');
            this.stage = this.viewer.querySelector('#ns-stage');
            this.spinner = this.viewer.querySelector('#ns-spinner'); // Global Spinner
            this.ui = this.viewer.querySelector('#ns-ui');
            this.btnPrev = this.viewer.querySelector('#ns-prev');
            this.btnNext = this.viewer.querySelector('#ns-next');
            this.counter = this.viewer.querySelector('#ns-counter');
            this.caption = this.viewer.querySelector('#ns-caption');
            this.captionText = this.viewer.querySelector('#ns-caption-text');

            const closeBtn = this.viewer.querySelector('#ns-close');

            // Button interactions: handle on pointerdown for immediate response,
            // and swallow click to avoid duplicate / ghost events.
            const swallow = (e) => {
                e.preventDefault();
                e.stopPropagation();
            };
            const onDown = (fn) => (e) => {
                swallow(e);
                fn();
            };
            // Keyboard activation (Enter / Space) fires click with detail === 0
            const onKeyClick = (fn) => (e) => {
                swallow(e);
                if (e.detail === 0) fn();
            };
            const bindBtn = (btn, fn) => {
                btn.addEventListener('pointerdown', onDown(fn));
                btn.addEventListener('click', onKeyClick(fn));
            };

            this.btnZoom = this.viewer.querySelector('#ns-zoom');
            bindBtn(closeBtn, () => this.close());
            bindBtn(this.btnZoom, () => this.toggleZoom());
            bindBtn(this.btnPrev, () => this.changeIndex(-1));
            bindBtn(this.btnNext, () => this.changeIndex(1));

            // Viewer events
            this.viewer.addEventListener('pointerdown', this.onPointerDown.bind(this));
            this.viewer.addEventListener('pointermove', this.onPointerMove.bind(this));
            this.viewer.addEventListener('pointerup', this.onPointerUp.bind(this));
            this.viewer.addEventListener('pointercancel', this.onPointerUp.bind(this));
            // Allow the context menu (copy) on caption text only
            this.viewer.addEventListener('contextmenu', e => {
                if (!e.target.closest('.ns-caption')) e.preventDefault();
            });
        }

        _scan() {
            const targets = this.options.scope.querySelectorAll(this.options.selector);
            this.items = [];
            targets.forEach((el, index) => {
                const href = el.href;
                if (SmartUtils.isImageUrl(href, this.options.imageExtensions)) {
                    // Try to find a thumb
                    const img = el.querySelector('img');
                    this.items.push({
                        src: href,
                        thumb: img ? img.src : null,
                        thumbEl: img,
                        linkEl: el,
                        caption: this._resolveCaption(el, img),
                        index: this.items.length // Store its index in our array
                    });
                    
                    // Mark element to know its index easily if clicked later
                    el.dataset.nsIndex = this.items.length - 1;
                } else if (el.hasAttribute('data-ns-index')) {
                    // No longer a target after re-scan
                    el.removeAttribute('data-ns-index');
                }
            });
        }

        /**
         * Caption priority:
         *   data-ns-caption (on link; empty string disables) > data-caption
         *   > thumb img alt > link title > thumb img title > figure figcaption
         */
        _resolveCaption(linkEl, img) {
            const pick = (v) => (typeof v === 'string' ? v.trim() : '');
            if (linkEl.hasAttribute('data-ns-caption')) return pick(linkEl.getAttribute('data-ns-caption'));
            let text = pick(linkEl.getAttribute('data-caption'));
            if (!text && img) text = pick(img.getAttribute('alt'));
            if (!text) text = pick(linkEl.getAttribute('title'));
            if (!text && img) text = pick(img.getAttribute('title'));
            if (!text) {
                const fig = linkEl.closest('figure');
                const figcaption = fig ? fig.querySelector('figcaption') : null;
                if (figcaption) text = pick(figcaption.textContent).replace(/\s+/g, ' ');
            }
            return text;
        }

        _updateCaption() {
            if (!this.caption) return;
            const item = this.items[this.currentIndex];
            const text = (this.options.caption && item && item.caption) ? item.caption : '';
            // textContent: never interpret alt text as HTML
            this.captionText.textContent = text;
            this.caption.classList.toggle('ns-has-caption', !!text);
            this.caption.scrollTop = 0;

            if (this.counter) {
                const showCounter = this.options.counter && this.items.length > 1;
                this.counter.textContent = showCounter ? `${this.currentIndex + 1} / ${this.items.length}` : '';
                this.counter.style.display = showCounter ? '' : 'none';
            }
        }

        _lockScroll() {
            if (this._savedOverflow) return;
            this._savedOverflow = {
                html: document.documentElement.style.overflow,
                body: document.body.style.overflow
            };
            document.documentElement.style.overflow = 'hidden';
            document.body.style.overflow = 'hidden';
        }

        _unlockScroll() {
            const saved = this._savedOverflow || { html: '', body: '' };
            document.documentElement.style.overflow = saved.html;
            document.body.style.overflow = saved.body;
            this._savedOverflow = null;
        }

        _bindGlobalEvents() {
            // Clean up old listener if exists? 
            // For simplicity, we assume init is called once or we just add listener.
            // Using a named function for handler allows removal if needed, but for "drop-in" we add to body.
            // Using capture to catch before others if needed, but default phase is usually fine.
            
            if (this._clickHandler) document.removeEventListener('click', this._clickHandler);
            
            this._clickHandler = (e) => {
                if (this.suppressNextClick) {
                    e.preventDefault();
                    e.stopPropagation();
                    this._clearSuppressNextClick();
                    return;
                }
                const link = e.target.closest(this.options.selector);
                if (link && link.hasAttribute('data-ns-index')) {
                    const idx = parseInt(link.getAttribute('data-ns-index'), 10);
                    if (!isNaN(idx)) {
                        e.preventDefault();
                        this.open(idx);
                    }
                }
            };
            document.addEventListener('click', this._clickHandler);

            // Keyboard events (bound to window, checked inside handler)
            if (this._keyHandler) window.removeEventListener('keydown', this._keyHandler);
            this._keyHandler = (e) => {
                if (!this.isOpen) return;
                switch(e.key) {
                    case 'Escape': this.close(); break;
                    case 'ArrowLeft': this.changeIndex(-1); break;
                    case 'ArrowRight': this.changeIndex(1); break;
                }
            };
            window.addEventListener('keydown', this._keyHandler);

            // Re-fit on rotation / window resize
            if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
            this._resizeRaf = null;
            this._resizeHandler = () => {
                if (!this.isOpen || this._resizeRaf) return;
                this._resizeRaf = requestAnimationFrame(() => {
                    this._resizeRaf = null;
                    this._refit();
                });
            };
            window.addEventListener('resize', this._resizeHandler);
        }

        _refit() {
            if (!this.isOpen || this.isAnimating) return;
            [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(wrap => {
                if (!wrap) return;
                if (wrap._nsW) this._setSlideSize(wrap, wrap._nsW, wrap._nsH);
                wrap.style.transition = 'none';
            });
            this.state = { x: 0, y: 0, scale: 1 };
            this.allowOverZoom = false;
            this.render();
            this.updateUiVisibility();
        }

        // Space between slides while swiping
        _getSlideGap() {
            return Math.round(Math.min(48, window.innerWidth * 0.06));
        }

        _setSuppressNextClick() {
            this.suppressNextClick = true;
            if (this.suppressClickTimer) clearTimeout(this.suppressClickTimer);
            this.suppressClickTimer = setTimeout(() => {
                this.suppressNextClick = false;
                this.suppressClickTimer = null;
            }, 400);
        }

        _clearSuppressNextClick() {
            this.suppressNextClick = false;
            if (this.suppressClickTimer) {
                clearTimeout(this.suppressClickTimer);
                this.suppressClickTimer = null;
            }
        }

        open(index) {
            if (this.closeTimer) {
                clearTimeout(this.closeTimer);
                this.closeTimer = null;
            }
            if (this.isOpen) return;
            index = SmartUtils.clamp(index, 0, this.items.length - 1);
            
            this.isOpen = true;
            this.currentIndex = index;
            this.viewer.style.display = 'block';
            this.viewer.style.pointerEvents = 'auto';
            
            // Force reflow
            this.viewer.getBoundingClientRect();
            
            this.state = { x: 0, y: 0, scale: 1 };
            this.pointers = [];
            this.isAnimating = false;
            this.isAnimating = true;
            this.allowOverZoom = false;
            if (this.viewer) this.viewer.classList.remove('ns-dragging');
            this.bgTapStart = null;

            this.updateUiVisibility();
            this.setupSlides(index);
            this._updateCaption();
            this._lockScroll();

            // Animation Opening: Simple Fade In
            this.bg.style.opacity = 0;
            this.ui.style.opacity = 0;
            
            this.state = { x: 0, y: 0, scale: 1 };
            const currentEl = this.slidePool.current;
            
            // アニメーションなしで初期位置をセット
            if (currentEl) {
                currentEl.style.transition = 'none';
                currentEl.style.transform = `translate3d(0, 0, 0)`;
                // _nsBaseScale は _setWrapContent で 1 に初期化されている
            }

            requestAnimationFrame(() => {
                this.bg.style.opacity = 1;
                this.ui.style.opacity = 1;

                this.render();

                this.loadHighRes(index, currentEl);
                this.preloadSurrounding(index);
                this._updateSpinner();
                this.isAnimating = false;
            });
        }

        _recycleSlidesAfterNav(dir) {
            const oldPrev = this.slidePool.prev;
            const oldNext = this.slidePool.next;
            const oldCurr = this.slidePool.current;

            if (dir > 0) { // Next
                if (oldPrev) {
                   oldPrev.remove();
                   oldPrev._nsIndex = null;
                   oldPrev._nsValuesCalculated = false;
                }
                this.slidePool.prev = oldCurr;
                this.slidePool.current = oldNext; // was next
                // Create new next
                const newIdx = this.currentIndex + 1;
                const newWrap = this._createSlideWrap(newIdx); 
                this.slidePool.next = newWrap;
                if(newWrap) this.stage.appendChild(newWrap);
            } else { // Prev
                if (oldNext) {
                   oldNext.remove();
                   oldNext._nsIndex = null;
                   oldNext._nsValuesCalculated = false;
                }
                this.slidePool.next = oldCurr;
                this.slidePool.current = oldPrev; // was prev
                // Create new prev
                const newIdx = this.currentIndex - 1;
                const newWrap = this._createSlideWrap(newIdx); 
                this.slidePool.prev = newWrap;
                if(newWrap) this.stage.appendChild(newWrap);
            }
        }

        setupSlides(index) {
            // Clear stage
            this.stage.innerHTML = '';
            
            this.slidePool = { 
                prev: this._createSlideWrap(index - 1),
                current: this._createSlideWrap(index),
                next: this._createSlideWrap(index + 1)
            };
            
            if (this.slidePool.prev) this.stage.appendChild(this.slidePool.prev);
            if (this.slidePool.current) this.stage.appendChild(this.slidePool.current);
            if (this.slidePool.next) this.stage.appendChild(this.slidePool.next);
        }

        _createSlideWrap(index) {
            if (index < 0 || index >= this.items.length) return null;
            const div = document.createElement('div');
            // コンテナサイズ0、JS制御
            div.className = 'ns-img-wrap'; 
            div._nsIndex = index; // Store index
            
            this._setWrapContent(div, index);
            return div;
        }

        _setWrapContent(wrap, index) {
            if (!wrap) return;
            if (index < 0 || index >= this.items.length) {
                wrap.style.display = 'none';
                wrap.innerHTML = '';
                wrap._nsBaseScale = 1;
                return;
            }
            const item = this.items[index];
            wrap.style.display = 'block';
            wrap.style.opacity = '1';
            wrap.innerHTML = '';
            // 初期値
            wrap._nsBaseScale = 1;
            wrap._nsValuesCalculated = false;
            wrap._nsW = 0;
            wrap._nsH = 0;
            wrap._nsLoading = false;
            wrap._nsLoaded = false;
            wrap._nsFailed = false;

            // 既に寸法が分かっていれば（読み込み済み / data-ns-width）先に枠を決める
            const known = this._getKnownSize(item);
            if (known) this._setSlideSize(wrap, known.w, known.h);

            // サムネイルを仮画像として即座に置く。
            // 高画質版が届くまでの間も、スワイプ中の隣のスライドに絵が見える
            if (item.thumb && item.thumb !== item.src) {
                const ph = document.createElement('img');
                ph.className = 'ns-img ns-img-placeholder';
                ph.alt = '';
                ph.setAttribute('aria-hidden', 'true');
                ph.draggable = false;
                ph.decoding = 'async';
                const sizeFromThumb = () => {
                    if (wrap._nsW || wrap._nsIndex !== index) return;
                    const guess = this._guessSizeFromThumb(ph.naturalWidth, ph.naturalHeight);
                    if (!guess) return;
                    this._setSlideSize(wrap, guess.w, guess.h);
                    if (this.isOpen && !this.isAnimating) this.render();
                };
                ph.onload = sizeFromThumb;
                ph.src = item.thumb;
                wrap.appendChild(ph);
                if (wrap._nsW) {
                    this._setSlideSize(wrap, wrap._nsW, wrap._nsH);
                } else if (ph.complete && ph.naturalWidth) {
                    sizeFromThumb();
                }
            }
            
            // 初期のズームボタン状態更新用
            const isCurrent = wrap === this.slidePool.current;
            if (isCurrent && this.isOpen) this._updateZoomButtonDisplay();
        }

        close() {
            if (!this.isOpen) return;
            this._setSuppressNextClick();
            if (this.closeTimer) {
                clearTimeout(this.closeTimer);
                this.closeTimer = null;
            }
            const currentEl = this.slidePool.current;
            
            // Simple Fade Out
            if (currentEl) {
                // Just fade out, don't move
                currentEl.style.transition = 'opacity 0.25s ease-out';
                currentEl.style.opacity = 0;
            }

            this.isOpen = false;
            this.allowOverZoom = false;
            if (this.viewer) this.viewer.classList.remove('ns-dragging');
            this._setZoomButtonVisible(this.btnZoom, false);
            this.bg.style.opacity = 0;
            this.ui.style.opacity = 0;
            if (this._spinnerTimer) clearTimeout(this._spinnerTimer);
            this.spinner.style.opacity = '0';
            this.viewer.style.pointerEvents = 'none';// prevent interaction during fade out

            this.closeTimer = setTimeout(() => {
                this.closeTimer = null;
                if (this.isOpen) return;
                this.viewer.style.display = 'none';
                // Reset everything
                this.stage.innerHTML = ''; 
                this.slidePool = { current:null, prev:null, next:null };
                this.pointers = [];
                this.isDragging = false;
                this.isAnimating = false;
            }, 300);
            
            // Re-enable scroll
            this._unlockScroll();
        }

        toggleZoom() {
            if (this.state.scale > 1.01) {
                // Return to 1x
                this.allowOverZoom = false;
                this.animateTo({ x: 0, y: 0, scale: 1 });
            } else {
                const targetScale = this._getMaxScaleForCurrent();
                if (targetScale <= 1.01) return;
                this.maxScale = targetScale;
                this.allowOverZoom = true;
                this.animateTo({ x: 0, y: 0, scale: targetScale });
            }
        }

        destroy() {
            if (this.viewer) this.viewer.remove();
            if (this._clickHandler) document.removeEventListener('click', this._clickHandler);
            if (this._keyHandler) window.removeEventListener('keydown', this._keyHandler);
            if (this._resizeHandler) window.removeEventListener('resize', this._resizeHandler);
            if (this.isOpen) this._unlockScroll();
            this.isOpen = false;
            // Reset
            this.domGenerated = false;
        }

        // --- Logic ---

        /**
         * Size of the full image when it is already known:
         * loaded once before, or given by data-ns-width / data-ns-height (optional)
         */
        _getKnownSize(item) {
            if (item.naturalWidth && item.naturalHeight) {
                return { w: item.naturalWidth, h: item.naturalHeight };
            }
            const link = item.linkEl;
            const w = link ? parseInt(link.getAttribute('data-ns-width'), 10) : 0;
            const h = link ? parseInt(link.getAttribute('data-ns-height'), 10) : 0;
            return (w > 0 && h > 0) ? { w, h } : null;
        }

        /**
         * Only the thumbnail is known: keep its aspect ratio and assume the full
         * image is at least as large as the viewport (a thumbnail is usually smaller)
         */
        _guessSizeFromThumb(thumbW, thumbH) {
            if (!thumbW || !thumbH) return null;
            const fill = Math.min(window.innerWidth / thumbW, window.innerHeight / thumbH);
            const k = Math.max(fill, 1);
            return { w: thumbW * k, h: thumbH * k };
        }

        /**
         * Logical size of a slide (= full image pixels). Every image in the slide
         * (placeholder / high-res) is laid out at this size, then scaled by baseScale
         */
        _setSlideSize(wrap, w, h) {
            if (!wrap || !w || !h) return;
            wrap._nsW = w;
            wrap._nsH = h;
            wrap._nsBaseScale = SmartUtils.getFitScale(w, h);
            wrap.querySelectorAll('.ns-img').forEach(img => {
                img.style.width = `${w}px`;
                img.style.height = `${h}px`;
            });
        }

        loadHighRes(index, wrapperEl, priority = 'high') {
            if (!wrapperEl) return;
            const item = this.items[index];
            if (!item) return;

            // 既にロード済み / ロード中なら何もしない
            if (wrapperEl._nsLoaded || wrapperEl._nsLoading || wrapperEl._nsFailed) return;
            wrapperEl._nsLoading = true;

            const fullImg = document.createElement('img');
            fullImg.className = 'ns-img ns-img-highres';
            fullImg.alt = item.caption || '';
            fullImg.draggable = false;
            fullImg.decoding = 'async';
            // 表示中の1枚を優先し、隣は後回しにする（対応ブラウザのみ）
            fullImg.fetchPriority = priority;
            if (wrapperEl._nsW) {
                fullImg.style.width = `${wrapperEl._nsW}px`;
                fullImg.style.height = `${wrapperEl._nsH}px`;
            }

            // 重要: 計算が終わるまで非表示
            fullImg.style.opacity = '0';

            fullImg.onload = () => {
                wrapperEl._nsLoading = false;
                wrapperEl._nsLoaded = true;
                item.naturalWidth = fullImg.naturalWidth;
                item.naturalHeight = fullImg.naturalHeight;

                // スライドが閉じられた / 使い回されて別の画像になった
                if (!this.isOpen || wrapperEl._nsIndex !== index) return;

                // 実寸で枠を確定（画像が画面より小さい場合は拡大しない）
                this._setSlideSize(wrapperEl, fullImg.naturalWidth, fullImg.naturalHeight);
                wrapperEl._nsValuesCalculated = true; // 計算済みフラグ

                // Layout Update Sync
                requestAnimationFrame(() => {
                    if (this.isOpen && !this.isAnimating) this.render();
                    fullImg.style.opacity = '1';
                    wrapperEl.classList.add('ns-img-loaded');
                    if (wrapperEl === this.slidePool.current) this._updateZoomButtonDisplay();
                    this._updateSpinner();
                    // 高画質版がフェードインし終えたら仮画像は捨てる
                    setTimeout(() => {
                        const ph = wrapperEl.querySelector('.ns-img-placeholder');
                        if (ph) ph.remove();
                    }, 450);
                });
            };

            fullImg.onerror = () => {
                wrapperEl._nsLoading = false;
                wrapperEl._nsFailed = true;
                fullImg.remove();
                this._updateSpinner();
            };

            fullImg.src = item.src;
            wrapperEl.appendChild(fullImg);
        }

        /**
         * Spinner only for the visible slide, and only when loading is not instant.
         * With a thumbnail placeholder on screen, wait a bit longer.
         */
        _updateSpinner() {
            if (this._spinnerTimer) clearTimeout(this._spinnerTimer);
            this._spinnerTimer = null;
            const wrap = this.slidePool.current;
            const waiting = this.isOpen && wrap && !wrap._nsLoaded && !wrap._nsFailed;
            if (!waiting) {
                this.spinner.style.opacity = '0';
                return;
            }
            const hasPlaceholder = !!wrap.querySelector('.ns-img-placeholder');
            this._spinnerTimer = setTimeout(() => {
                this._spinnerTimer = null;
                if (this.slidePool.current === wrap && !wrap._nsLoaded && !wrap._nsFailed) {
                    this.spinner.style.opacity = '1';
                }
            }, hasPlaceholder ? 600 : 250);
        }

        preloadSurrounding(index) {
            // 隣のスライドは DOM に置いたまま読み込む（スワイプ中に見える）
            this.loadHighRes(index + 1, this.slidePool.next, 'low');
            this.loadHighRes(index - 1, this.slidePool.prev, 'low');
            // その先はキャッシュを温めるだけ
            this._preloadOne(index + 2);
            this._preloadOne(index - 2);
        }

        _preloadOne(index) {
            if (index >= 0 && index < this.items.length) {
                const i = new Image();
                i.decoding = 'async';
                i.src = this.items[index].src;
            }
        }

        updateUiVisibility() {
            // Updated Arrows
            if (this.currentIndex > 0) this.btnPrev.style.display = 'flex';
            else this.btnPrev.style.display = 'none';

            if (this.currentIndex < this.items.length - 1) this.btnNext.style.display = 'flex';
            else this.btnNext.style.display = 'none';

            // Hide UI if zoomed
            if (this.state.scale > 1.01) {
                this.btnPrev.style.opacity = 0;
                this.btnNext.style.opacity = 0;
                this.btnPrev.style.pointerEvents = 'none';
                this.btnNext.style.pointerEvents = 'none';
            } else {
                this.btnPrev.style.opacity = 1;
                this.btnNext.style.opacity = 1;
                this.btnPrev.style.pointerEvents = 'auto';
                this.btnNext.style.pointerEvents = 'auto';
            }

            this._updateZoomButtonDisplay();
        }

        _getWrapBaseScale(wrap) {
            if (!wrap || typeof wrap._nsBaseScale !== 'number') return 1;
            return wrap._nsBaseScale || 1;
        }

        _applyBaseScale(wrap, img) {
            if (!wrap || !img) return;
            const naturalW = img.naturalWidth;
            const naturalH = img.naturalHeight;
            if (!naturalW || !naturalH) return;
            const fitScale = Math.min(
                window.innerWidth / naturalW,
                window.innerHeight / naturalH,
                1
            );
            wrap._nsBaseScale = fitScale;
            img.style.width = `${naturalW}px`;
            img.style.height = `${naturalH}px`;
            img.style.maxWidth = 'none';
            img.style.maxHeight = 'none';
        }

        _getCurrentImageEl() {
            const imgs = this.slidePool.current?.querySelectorAll('.ns-img');
            return (imgs && imgs.length > 0) ? imgs[imgs.length - 1] : null;
        }

        _getMaxScaleForCurrent() {
            const wrap = this.slidePool.current;
            if (!wrap || !wrap._nsLoaded) return 1;
            const baseScale = this._getWrapBaseScale(wrap);
            if (!baseScale || baseScale <= 0) return 1;
            return Math.min(Math.max(1, 1 / baseScale), 5);
        }

        _isDesktopPointer() {
            return !!(window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches);
        }

        _setZoomButtonVisible(btn, visible, useDefaultOpacity = false) {
            if (!btn) return;
            btn.style.opacity = visible ? (useDefaultOpacity ? '' : '1') : '0';
            btn.style.pointerEvents = visible ? 'auto' : 'none';
        }

        _updateZoomButtonDisplay() {
            this.maxScale = this._getMaxScaleForCurrent();
            const canZoomIn = this.maxScale > 1.01;
            const isZoomed = this.state.scale > 1.01;
            if (this.viewer) {
                this.viewer.classList.toggle('ns-can-zoom', canZoomIn);
                this.viewer.classList.toggle('ns-is-zoomed', isZoomed);
            }

            this._setZoomButtonVisible(this.btnZoom, isZoomed || canZoomIn, true);
        }

        // --- Inputs ---

        onPointerDown(e) {
            // Priority 1: Buttons should always be responsive if possible
            // Caption: leave it to native text selection / scrolling
            if (e.target.closest('.ns-btn, .ns-caption')) return;

            // Otherwise, don't start new gestures during animation
            if (this.isAnimating) return;

            e.preventDefault();
            this.pointers.push(e);

            // Reset transition for drag
            [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(el => {
                if (el) el.style.transition = 'none';
            });

            if (this.pointers.length === 1) {
                this.tapImageEl = e.target.closest('.ns-img');
                this.tapTargetIsImage = !!this.tapImageEl;

                // Double tap detection
                const now = Date.now();
                const dist = Math.hypot(e.clientX - this.lastTapPos.x, e.clientY - this.lastTapPos.y);
                
                // Check if tap is on the visible part of the current image
                // Use the last .ns-img element (newest)
                const imgs = this.slidePool.current?.querySelectorAll('.ns-img');
                const currentImg = this.tapImageEl || (imgs && imgs.length > 0 ? imgs[imgs.length - 1] : null);
                const isOnVisibleImage = currentImg && SmartUtils.isPointOnVisibleImage(currentImg, e.clientX, e.clientY);
                
                if (isOnVisibleImage && now - this.lastTapTime < 300 && dist < 30) {
                    this.onDoubleTap(e);
                    this.pointers = []; // Cancel current drag
                    this.bgTapStart = null;
                    return;
                }
                this.lastTapTime = now;
                this.lastTapPos = { x: e.clientX, y: e.clientY };
                this.bgTapStart = (this.tapTargetIsImage && isOnVisibleImage)
                    ? null
                    : { pointerId: e.pointerId, x: e.clientX, y: e.clientY };
            } else {
                this.bgTapStart = null;
                this.tapTargetIsImage = false;
                this.tapImageEl = null;
            }

            if (this.pointers.length === 2) {
                this.lastDist = SmartUtils.getDistance(this.pointers[0], this.pointers[1]);
                this.lastCenter = SmartUtils.getCenter(this.pointers[0], this.pointers[1]);
            } else {
                this.lastCenter = { x: e.clientX, y: e.clientY };
            }
            
            this.startPos = { x: e.clientX, y: e.clientY };
            this.tapTarget = e.target; // Store the target element for tap detection
            this.isDragging = false;
            this.dragAxis = null;
            this.maxScale = this._getMaxScaleForCurrent();
            if (this.viewer) this.viewer.classList.remove('ns-dragging');
        }

        onPointerMove(e) {
            if (this.isAnimating || this.pointers.length === 0) return;
            const idx = this.pointers.findIndex(p => p.pointerId === e.pointerId);
            if (idx > -1) this.pointers[idx] = e;

            const currentCenter = (this.pointers.length === 2)
                ? SmartUtils.getCenter(this.pointers[0], this.pointers[1])
                : { x: this.pointers[0].clientX, y: this.pointers[0].clientY };

            // Determine Drag
            const totalDx = currentCenter.x - this.startPos.x;
            const totalDy = currentCenter.y - this.startPos.y;
            
            if (!this.isDragging) {
                if (Math.hypot(totalDx, totalDy) > 10) {
                    this.isDragging = true;
                    if (this.viewer) this.viewer.classList.add('ns-dragging');
                    // Lock axis only if not zoomed
                    if (this.state.scale <= 1.01) {
                        this.dragAxis = Math.abs(totalDx) > Math.abs(totalDy) ? 'x' : 'y';
                    }
                }
            }
            if (this.isDragging && this.bgTapStart) {
                this.bgTapStart = null;
            }

            const dx = currentCenter.x - this.lastCenter.x;
            const dy = currentCenter.y - this.lastCenter.y;

            if (this.pointers.length === 2) {
                // Pinch
                const currentDist = SmartUtils.getDistance(this.pointers[0], this.pointers[1]);
                const scaleFactor = currentDist / this.lastDist;
                
                const maxScale = this._getMaxScaleForCurrent();
                this.maxScale = maxScale;
                const isZoomInGesture = scaleFactor > 1;
                if (maxScale <= 1.01 && this.state.scale <= 1.01 && isZoomInGesture) {
                    this.lastDist = currentDist;
                    this.lastCenter = currentCenter;
                    return;
                }
                let newScale = this.state.scale * scaleFactor;
                newScale = SmartUtils.clamp(newScale, 0.5, maxScale || 1);

                const centerOffsetX = currentCenter.x - window.innerWidth / 2;
                const centerOffsetY = currentCenter.y - window.innerHeight / 2;
                
                // Zoom around center
                this.state.x += dx;
                this.state.y += dy;
                this.state.x -= (centerOffsetX - this.state.x) * (scaleFactor - 1);
                this.state.y -= (centerOffsetY - this.state.y) * (scaleFactor - 1);
                this.state.scale = newScale;
                this.lastDist = currentDist;
                
                this.updateUiVisibility();
            } else if (this.isDragging) {
                // Pan
                // Apply Axis Lock
                let applyDx = dx;
                let applyDy = dy;

                if (this.state.scale <= 1.01 && this.dragAxis) {
                    if (this.dragAxis === 'x') applyDy = 0; // Horizontal swipe, no vertical move
                    if (this.dragAxis === 'y') applyDx = 0; // Vertical swipe, no horizontal move (for close)
                }

                if (this.state.scale > 1) {
                    this.state.x += dx;
                    this.state.y += dy;
                } else {
                    // Resistance at edges if moving x
                    this.state.x += applyDx;
                    // Vertical drag for closing -> resistance? or 0.85 approx
                    this.state.y += applyDy;
                }
            }

            this.lastCenter = currentCenter;
            this.render();
        }

        onPointerUp(e) {
            const btn = e.target.closest('.ns-btn, .ns-caption');
            if (btn) return; // Handled by click listener / native behavior

            if (this.isAnimating) return;
            
            const bgTap = this.bgTapStart && this.bgTapStart.pointerId === e.pointerId;
            const bgTapDist = bgTap
                ? Math.hypot(e.clientX - this.bgTapStart.x, e.clientY - this.bgTapStart.y)
                : 0;

            const prevPointersLen = this.pointers.length;
            this.pointers = this.pointers.filter(p => p.pointerId !== e.pointerId);

            if (this.pointers.length > 0) {
                this.lastCenter = (this.pointers.length === 2) 
                    ? SmartUtils.getCenter(this.pointers[0], this.pointers[1]) 
                    : { x: this.pointers[0].clientX, y: this.pointers[0].clientY };
                if (this.pointers.length === 2) this.lastDist = SmartUtils.getDistance(this.pointers[0], this.pointers[1]);
                if (bgTap) this.bgTapStart = null;
                return;
            }

            if (this.viewer) this.viewer.classList.remove('ns-dragging');

            if (prevPointersLen === 0) {
                this.bgTapStart = null;
                this.tapTargetIsImage = false;
                this.tapImageEl = null;
                return;
            }

            // End Gesture detection
            const moveDistThreshold = 28; // Tighter swipe threshold for less travel
            const dist = Math.hypot(e.clientX - this.startPos.x, e.clientY - this.startPos.y);

            if (bgTap && bgTapDist < moveDistThreshold && !this.isDragging) {
                this.bgTapStart = null;
                this.close();
                return;
            }

            // Treat as tap if movement is small
            if (dist < moveDistThreshold) {
                let handledTap = false;
                if (!this.tapTargetIsImage && !this.isDragging) {
                    this.close();
                    this.bgTapStart = null;
                    this.tapTargetIsImage = false;
                    this.tapImageEl = null;
                    return;
                }
                // Check if tap is on the visible part of the current image
                // Use the last .ns-img element (newest, in case loadHighRes hasn't removed old ones yet)
                const imgs = this.slidePool.current?.querySelectorAll('.ns-img');
                const currentImg = this.tapImageEl || (imgs && imgs.length > 0 ? imgs[imgs.length - 1] : null);
                const isOnVisibleImage = currentImg && SmartUtils.isPointOnVisibleImage(
                    currentImg, 
                    this.startPos.x, 
                    this.startPos.y
                );
                
                // console.log('Tap detected', { dist, isOnVisibleImage, startPos: this.startPos });

                if (isOnVisibleImage && !this.isDragging) {
                    if (this.state.scale > 1.01) {
                        this.animateTo({ x: 0, y: 0, scale: 1 });
                        handledTap = true;
                    } else if (this._isDesktopPointer()) {
                        const targetScale = this._getMaxScaleForCurrent();
                        if (targetScale > 1.01) {
                            this.maxScale = targetScale;
                            this.allowOverZoom = true;
                            this.animateTo({ x: 0, y: 0, scale: targetScale });
                            handledTap = true;
                        }
                    }
                }

                if (!handledTap && !isOnVisibleImage) {
                    this.close();
                }
            } else {
                this.onGestureEnd();
            }

            this.bgTapStart = null;
            this.tapTargetIsImage = false;
            this.tapImageEl = null;
        }

        onDoubleTap(e) {
            if (this.state.scale > 1) {
                this.allowOverZoom = false;
                this.animateTo({ x: 0, y: 0, scale: 1 });
            } else {
                // Zoom to point
                const targetScale = this._getMaxScaleForCurrent();
                if (targetScale <= 1.01) return;
                this.allowOverZoom = true;
                const winW = window.innerWidth;
                const winH = window.innerHeight;
                
                const tapX = e.clientX;
                const tapY = e.clientY;
                const centerOffsetX = tapX - winW / 2;
                const centerOffsetY = tapY - winH / 2;
                
                const newX = -centerOffsetX * (targetScale - 1);
                const newY = -centerOffsetY * (targetScale - 1);
                
                this.animateTo({ x: newX, y: newY, scale: targetScale });
            }
        }

        onGestureEnd() {
            const { x, y, scale } = this.state;
            const winW = window.innerWidth;
            [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(el => {
                if(el) el.style.transition = 'transform 0.3s cubic-bezier(0.1, 0.9, 0.2, 1)';
            });

            if (scale < 1) {
                 this.animateTo({ x: 0, y: 0, scale: 1 });
            } else if (scale > 1) {
                 // Should ideally clamp to boundaries, but for simple gallery:
                 // just keep it unless it's way out?
                 this.updateUiVisibility();
            } else {
                // Scale 1 logic
                // Vertical Close
                if (Math.abs(y) > 60) { // Threshold for closure
                    this.close();
                    return;
                }

                // Horizontal Swipe
                const threshold = winW * 0.12;
                if (x < -threshold && this.items[this.currentIndex + 1]) {
                    this.changeIndex(1);
                } 
                else if (x > threshold && this.items[this.currentIndex - 1]) {
                    this.changeIndex(-1);
                } else {
                    this.animateTo({ x: 0, y: 0, scale: 1 });
                }
            }
        }

        changeIndex(dir) {
            if (this.isAnimating) return;

            const targetIndex = this.currentIndex + dir;
            if (targetIndex < 0 || targetIndex >= this.items.length) {
                this.animateTo({ x: 0, y: 0, scale: 1 });
                return;
            }

            // Ensure we slide as a "page turn": both slides animate and no seam is visible.
            this.isAnimating = true;
            this.state.y = 0;
            this.state.scale = 1;
            this.allowOverZoom = false;

            const winW = window.innerWidth;
            const gap = this._getSlideGap();
            const duration = 260;
            const easing = 'cubic-bezier(0.22, 1, 0.36, 1)';

            // Apply transition to ALL involved slides so the incoming slide animates too
            [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(el => {
                if (el) el.style.transition = `transform ${duration}ms ${easing}`;
            });

            // Move to target position; render() will place prev/next accordingly
            const targetX = dir > 0 ? -(winW + gap) : (winW + gap);
            this.state.x = targetX;
            this.render();
            if (this.caption) this.caption.classList.add('ns-caption-switching');

            window.setTimeout(() => {
                this.currentIndex = targetIndex;
                this._updateCaption();
                if (this.caption) this.caption.classList.remove('ns-caption-switching');

                // Reuse existing slide DOM to avoid flashing
                this._recycleSlidesAfterNav(dir);
                this.updateUiVisibility();
                this.loadHighRes(this.currentIndex, this.slidePool.current);
                this.preloadSurrounding(this.currentIndex);
                this._updateSpinner();
             // Reset state & snap to center without animation
                this.state = { x: 0, y: 0, scale: 1 };
                [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(el => {
                    if (el) el.style.transition = 'none';
                });
                this.render();

                this.isAnimating = false;
            }, duration);
        }

        animateTo(targetState) {
            this.isAnimating = true;
            if (targetState.scale <= 1.01) this.allowOverZoom = false;
            this.state = targetState;
            
            [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(el => {
                if(el) el.style.transition = 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)';
            });

            this.render();
            setTimeout(() => {
                this.isAnimating = false;
                this.updateUiVisibility();
            }, 300);
        }

        render() {
            const x = this.state.x;
            const y = this.state.y;
            const scale = this.state.scale;
            const winW = window.innerWidth;
            const winH = window.innerHeight;
            
            // _getWrapBaseScale メソッド依存を排除し、直接プロパティを参照
            const currentBaseScale = (this.slidePool.current && this.slidePool.current._nsBaseScale) ? this.slidePool.current._nsBaseScale : 1;
            const prevBaseScale = (this.slidePool.prev && this.slidePool.prev._nsBaseScale) ? this.slidePool.prev._nsBaseScale : 1;
            const nextBaseScale = (this.slidePool.next && this.slidePool.next._nsBaseScale) ? this.slidePool.next._nsBaseScale : 1;
            
            const currentScale = scale * currentBaseScale;
            const step = winW + this._getSlideGap();

            // --- Absolute Centering Logic ---
            // CSSのセンタリングを廃止したため、JSで中央位置を計算する
            // 画像基準サイズ（BaseScale適用済み）に対し、Scaleを掛ける前の元サイズを逆算...
            // ではなく、単純に img要素の width/height * baseScale * scale が現在の表示サイズ。
            // これを画面中央に置くためのオフセットを計算する。

            // Helper to calc center offset
            const getCenterOffset = (el, baseSc, sc) => {
                // 寸法未確定のスライドは何も描かれていないので位置は問わない
                if (!el || !el._nsW) return { x: 0, y: 0 };
                // スライドの論理サイズ（_setSlideSize で確定）
                const w = el._nsW;
                const h = el._nsH;
                
                const finalW = w * baseSc * sc;
                const finalH = h * baseSc * sc;
                
                // 画面中央 - 画像中央
                const offX = (winW - finalW) / 2;
                const offY = (winH - finalH) / 2;
                // ピクセルパーフェクトのため整数化
                return { x: Math.floor(offX), y: Math.floor(offY) };
            };

            if (this.slidePool.current) {
                const offset = getCenterOffset(this.slidePool.current, currentBaseScale, scale);
                // state.x, y はパンニング移動量なので加算する
                // transform-origin: 0 0 なので、オフセット位置まで移動させる必要がある
                const finalX = offset.x + x;
                const finalY = offset.y + y;
                this.slidePool.current.style.transform = `translate3d(${finalX}px, ${finalY}px, 0) scale(${currentScale})`;
            }
            
            // 隣接スライドの処理（拡大時は非表示）
            if (scale <= 1.01) {
                if (this.slidePool.prev) {
                    this.slidePool.prev.style.display = 'block';
                    this.slidePool.prev.style.opacity = '1';
                    this.slidePool.prev.style.visibility = 'visible';
                    const offset = getCenterOffset(this.slidePool.prev, prevBaseScale, 1);
                    // 前の画像は画面幅分左に配置（余白なしの場合）
                    // ここではシンプルに「画面外に置く」処理
                    // slidePool自体を動かすより、絶対座標計算で配置
                    // ただし単純化のため、prev/nextは translate(-winW ... ) のロジックを維持しつつ
                    // 内部のオフセットを考慮する必要があるが、
                    // 現状の構造だと slidePool 自体に transform をかけているので
                    // 「画面中央へのオフセット」-「画面幅」 で配置すればよい
                    // 隣のスライドも指に追従させる（スワイプ中に見える）
                    const finalX = offset.x - step + x;
                    const finalY = offset.y;
                    this.slidePool.prev.style.transform = `translate3d(${finalX}px, ${finalY}px, 0) scale(${prevBaseScale})`;
                }
                if (this.slidePool.next) {
                    this.slidePool.next.style.display = 'block';
                    this.slidePool.next.style.opacity = '1';
                    this.slidePool.next.style.visibility = 'visible';
                    const offset = getCenterOffset(this.slidePool.next, nextBaseScale, 1);
                    const finalX = offset.x + step + x;
                    const finalY = offset.y;
                    this.slidePool.next.style.transform = `translate3d(${finalX}px, ${finalY}px, 0) scale(${nextBaseScale})`;
                }
            } else {
                if (this.slidePool.prev) {
                    this.slidePool.prev.style.display = 'none';
                    this.slidePool.prev.style.opacity = '0';
                    this.slidePool.prev.style.visibility = 'hidden';
                }
                if (this.slidePool.next) {
                    this.slidePool.next.style.display = 'none';
                    this.slidePool.next.style.opacity = '0';
                    this.slidePool.next.style.visibility = 'hidden';
                }
            }
            
            const zoomIcon = (this.state.scale > 1.01) ? SVG_ZOOM_OUT : SVG_ZOOM_IN;
            if (this.btnZoom) this.btnZoom.innerHTML = zoomIcon;
        }
    }

    // Expose Single Instance
    global.NagiSwipe = new NagiSwipeViewer();

    // Auto Init on DOM Ready (if desired for "drop-in")
    // Use requestAnimationFrame to ensure body exists
    const autoInit = () => {
        if (!document.body) {
            requestAnimationFrame(autoInit);
            return;
        }
        global.NagiSwipe.init();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => autoInit());
    } else {
        autoInit();
    }

})(window);
