/*
* ============================================================================
 * ![NagiSwipe Library Core]
 * Drop-in gallery library
 * 
 * NagiSwipe v1.0.0
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
         * Check if a point (clientX, clientY) is on the visible part of an image
         * with object-fit: contain (accounts for letterboxing)
         */
        isPointOnVisibleImage: (imgEl, clientX, clientY) => {
            if (!imgEl || !imgEl.complete || imgEl.naturalWidth === 0) return false;
            
            const rect = imgEl.getBoundingClientRect();
            const containerW = rect.width;
            const containerH = rect.height;
            const naturalW = imgEl.naturalWidth;
            const naturalH = imgEl.naturalHeight;
            
            // Calculate actual displayed image dimensions (object-fit: contain)
            const containerRatio = containerW / containerH;
            const imageRatio = naturalW / naturalH;
            
            let displayedW, displayedH, offsetX, offsetY;
            
            if (imageRatio > containerRatio) {
                // Image is wider than container ratio -> fit to width
                displayedW = containerW;
                displayedH = containerW / imageRatio;
                offsetX = 0;
                offsetY = (containerH - displayedH) / 2;
            } else {
                // Image is taller than container ratio -> fit to height
                displayedH = containerH;
                displayedW = containerH * imageRatio;
                offsetX = (containerW - displayedW) / 2;
                offsetY = 0;
            }
            
            // Calculate visible image bounds in viewport coordinates
            const visibleLeft = rect.left + offsetX;
            const visibleTop = rect.top + offsetY;
            const visibleRight = visibleLeft + displayedW;
            const visibleBottom = visibleTop + displayedH;
            
            return clientX >= visibleLeft && clientX <= visibleRight &&
                   clientY >= visibleTop && clientY <= visibleBottom;
        }
    };

    class NagiSwipeViewer {
        constructor() {
            this.items = [];
            this.isOpen = false;
            this.currentIndex = 0;
            this.state = { x: 0, y: 0, scale: 1 };
            
            // Interaction state
            this.pointers = [];
            this.lastCenter = null;
            this.lastDist = 0;
            this.startPos = { x: 0, y: 0 };
            this.isDragging = false;
            this.dragAxis = null; // 'x' or 'y'

            // Double tap state
            this.lastTapTime = 0;
            this.lastTapPos = { x: 0, y: 0 };

            this.slidePool = { current: null, prev: null, next: null };
            
            this.options = {
                imageExtensions: DEFAULT_EXTENSIONS,
                selector: 'a[href]',
                scope: document
            };

            this.domGenerated = false;
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
                <div class="ns-ui" id="ns-ui">
                    <button class="ns-btn ns-zoom" id="ns-zoom" aria-label="Zoom">${SVG_ZOOM_IN}</button>
                    <button class="ns-btn ns-close" id="ns-close" aria-label="Close"></button>
                    <button class="ns-btn ns-prev" id="ns-prev" aria-label="Previous">${SVG_ARROW_LEFT}</button>
                    <button class="ns-btn ns-next" id="ns-next" aria-label="Next">${SVG_ARROW_RIGHT}</button>
                </div>
            `;
            
            this.viewer = document.createElement('div');
            this.viewer.id = 'ns-viewer';
            this.viewer.className = 'ns-viewer';
            this.viewer.innerHTML = html;
            document.body.appendChild(this.viewer);

            this.bg = this.viewer.querySelector('#ns-bg');
            this.stage = this.viewer.querySelector('#ns-stage');
            this.ui = this.viewer.querySelector('#ns-ui');
            this.btnPrev = this.viewer.querySelector('#ns-prev');
            this.btnNext = this.viewer.querySelector('#ns-next');

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

            closeBtn.addEventListener('pointerdown', onDown(() => this.close()));
            closeBtn.addEventListener('click', swallow);

            this.btnZoom = this.viewer.querySelector('#ns-zoom');
            this.btnZoom.addEventListener('pointerdown', onDown(() => this.toggleZoom()));
            this.btnZoom.addEventListener('click', swallow);

            this.btnPrev.addEventListener('pointerdown', onDown(() => this.changeIndex(-1)));
            this.btnPrev.addEventListener('click', swallow);

            this.btnNext.addEventListener('pointerdown', onDown(() => this.changeIndex(1)));
            this.btnNext.addEventListener('click', swallow);

            // Viewer events
            this.viewer.addEventListener('pointerdown', this.onPointerDown.bind(this));
            this.viewer.addEventListener('pointermove', this.onPointerMove.bind(this));
            this.viewer.addEventListener('pointerup', this.onPointerUp.bind(this));
            this.viewer.addEventListener('pointercancel', this.onPointerUp.bind(this));
            this.viewer.addEventListener('contextmenu', e => e.preventDefault());
        }

        _scan() {
            const targets = this.options.scope.querySelectorAll(this.options.selector);
            this.items = [];
            targets.forEach((el, index) => {
                const href = el.href;
                if (href && this.options.imageExtensions.test(href)) {
                    // Try to find a thumb
                    const img = el.querySelector('img');
                    this.items.push({
                        src: href,
                        thumb: img ? img.src : null,
                        thumbEl: img,
                        linkEl: el,
                        index: this.items.length // Store its index in our array
                    });
                    
                    // Mark element to know its index easily if clicked later
                    el.dataset.nsIndex = this.items.length - 1;
                }
            });
        }

        _bindGlobalEvents() {
            // Clean up old listener if exists? 
            // For simplicity, we assume init is called once or we just add listener.
            // Using a named function for handler allows removal if needed, but for "drop-in" we add to body.
            // Using capture to catch before others if needed, but default phase is usually fine.
            
            if (this._clickHandler) document.removeEventListener('click', this._clickHandler);
            
            this._clickHandler = (e) => {
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
        }

        open(index) {
            if (this.isOpen) return;
            index = SmartUtils.clamp(index, 0, this.items.length - 1);
            
            this.isOpen = true;
            this.currentIndex = index;
            this.viewer.style.display = 'block';
            
            // Force reflow
            this.viewer.getBoundingClientRect();
            
            this.state = { x: 0, y: 0, scale: 1 };
            this.pointers = [];
            this.isAnimating = true;

            this.updateUiVisibility();
            this.setupSlides(index);

            // Animation Opening
            const targetItem = this.items[index];
            const currentEl = this.slidePool.current;

            if (currentEl && targetItem.thumbEl) {
                const rect = targetItem.thumbEl.getBoundingClientRect();
                const startX = rect.left + rect.width/2 - window.innerWidth/2;
                const startY = rect.top + rect.height/2 - window.innerHeight/2;
                const startScale = rect.width / window.innerWidth;

                currentEl.style.transition = 'none';
                currentEl.style.transform = `translate3d(${startX}px, ${startY}px, 0) scale(${startScale})`;
                this.bg.style.opacity = 0;
                this.ui.style.opacity = 0;

                requestAnimationFrame(() => {
                    this.bg.style.opacity = 1;
                    this.ui.style.opacity = 1;
                    currentEl.style.transition = 'transform 0.4s cubic-bezier(0.19, 1, 0.22, 1)';
                    currentEl.style.transform = `translate3d(0, 0, 0) scale(1)`;

                    this.loadHighRes(index, currentEl);
                    this.preloadSurrounding(index);

                    setTimeout(() => { this.isAnimating = false; }, 400);
                });
            } else {
                this.bg.style.opacity = 1;
                this.ui.style.opacity = 1;
                this.loadHighRes(index, currentEl);
                this.isAnimating = false;
            }
        }

        _recycleSlidesAfterNav(dir) {
            const oldPrev = this.slidePool.prev;
            const oldCur = this.slidePool.current;
            const oldNext = this.slidePool.next;

            if (dir > 0) {
                this.slidePool.prev = oldCur;
                this.slidePool.current = oldNext;
                this.slidePool.next = oldPrev || this._createEmptyWrap();
                this._setWrapContent(this.slidePool.next, this.currentIndex + 1);
            } else if (dir < 0) {
                this.slidePool.next = oldCur;
                this.slidePool.current = oldPrev;
                this.slidePool.prev = oldNext || this._createEmptyWrap();
                this._setWrapContent(this.slidePool.prev, this.currentIndex - 1);
            }

            this._setWrapContent(this.slidePool.prev, this.currentIndex - 1);
            this._setWrapContent(this.slidePool.next, this.currentIndex + 1);

            const frag = document.createDocumentFragment();
            if (this.slidePool.prev) frag.appendChild(this.slidePool.prev);
            if (this.slidePool.current) frag.appendChild(this.slidePool.current);
            if (this.slidePool.next) frag.appendChild(this.slidePool.next);
            this.stage.appendChild(frag);
        }

        _createEmptyWrap() {
            const wrap = document.createElement('div');
            wrap.className = 'ns-img-wrap';
            wrap.style.display = 'none';
            this.stage.appendChild(wrap);
            return wrap;
        }

        _setWrapContent(wrap, index) {
            if (!wrap) return;
            if (index < 0 || index >= this.items.length) {
                wrap.style.display = 'none';
                wrap.innerHTML = '';
                return;
            }
            const item = this.items[index];
            wrap.style.display = 'block';
            wrap.innerHTML = '';

            const img = document.createElement('img');
            img.className = 'ns-img';
            img.src = item.thumb || item.src;
            img.draggable = false;
            img.decoding = 'async';
            img.loading = 'eager';
            img.style.opacity = '0';
            const fadeIn = () => requestAnimationFrame(() => { img.style.opacity = '1'; });
            if (img.complete) fadeIn();
            else {
                img.onload = fadeIn;
                img.onerror = () => { img.style.opacity = '1'; };
            }
            wrap.appendChild(img);
        }

        close() {
            if (!this.isOpen) return;
            const currentEl = this.slidePool.current;
            
            // Fly back animation
            if (this.items[this.currentIndex] && currentEl) {
                const item = this.items[this.currentIndex];
                if (item.thumbEl) {
                    const rect = item.thumbEl.getBoundingClientRect();
                    const scale = rect.width / window.innerWidth; // rough approx
                    currentEl.style.transition = 'transform 0.3s cubic-bezier(0.2, 0, 0, 1), opacity 0.3s linear';
                    currentEl.style.opacity = 0;
                    // We could try to match position, but simpler fade/shrink is often enough or just fade out
                }
            }

            this.isOpen = false;
            this.bg.style.opacity = 0;
            this.viewer.style.pointerEvents = 'none'; // prevent interaction during fade out

            setTimeout(() => {
                this.viewer.style.display = 'none';
                // Reset everything
                this.stage.innerHTML = ''; 
                this.slidePool = { current:null, prev:null, next:null };
            }, 300);
            
            // Re-enable scroll
            document.documentElement.style.overflow = '';
            document.body.style.overflow = '';
        }

        toggleZoom() {
            if (this.state.scale > 1.01) {
                // Return to 1x
                this.animateTo({ x: 0, y: 0, scale: 1 });
            } else {
                // Zoom to 2.5x (or just fitting 1x if image is huge? Simple: 2.5x for now like double tap)
                this.animateTo({ x: 0, y: 0, scale: 2.5 });
            }
        }

        destroy() {
            if (this.viewer) this.viewer.remove();
            if (this._clickHandler) document.removeEventListener('click', this._clickHandler);
            if (this._keyHandler) window.removeEventListener('keydown', this._keyHandler);
            // Reset
            this.domGenerated = false;
        }

        // --- Logic ---

        setupSlides(index) {
            // Build (or update) the 3-slide pool without nuking DOM every time
            if (!this.slidePool.current) {
                this.slidePool.current = this._createEmptyWrap();
                this.slidePool.prev = this._createEmptyWrap();
                this.slidePool.next = this._createEmptyWrap();
            }
            this._setWrapContent(this.slidePool.current, index);
            this._setWrapContent(this.slidePool.prev, index - 1);
            this._setWrapContent(this.slidePool.next, index + 1);

            // Initial positioning
            const winW = window.innerWidth;
            if (this.slidePool.prev) this.slidePool.prev.style.transform = `translate3d(${-winW}px, 0, 0)`;
            if (this.slidePool.current) this.slidePool.current.style.transform = `translate3d(0, 0, 0)`;
            if (this.slidePool.next) this.slidePool.next.style.transform = `translate3d(${winW}px, 0, 0)`;

            // Ensure DOM order
            const frag = document.createDocumentFragment();
            if (this.slidePool.prev) frag.appendChild(this.slidePool.prev);
            if (this.slidePool.current) frag.appendChild(this.slidePool.current);
            if (this.slidePool.next) frag.appendChild(this.slidePool.next);
            this.stage.appendChild(frag);
        }

        _createSlide(index, offsetDir) {
            if (index < 0 || index >= this.items.length) return null;
            const item = this.items[index];
            const wrap = document.createElement('div');
            wrap.className = 'ns-img-wrap';
            
            const img = document.createElement('img');
            img.className = 'ns-img';
            img.src = item.thumb || item.src;
            img.draggable = false;
            img.decoding = 'async';
            img.loading = 'eager';
            img.style.opacity = '0';
            const fadeInThumb = () => requestAnimationFrame(() => { img.style.opacity = '1'; });
            if (img.complete) fadeInThumb();
            else {
                img.onload = fadeInThumb;
                img.onerror = () => { img.style.opacity = '1'; };
            }
            wrap.appendChild(img);
            
            if (offsetDir !== 0) {
                 const x = offsetDir * (window.innerWidth);
                 wrap.style.transform = `translate3d(${x}px, 0, 0)`;
                 wrap.style.display = 'block'; 
            } else {
                 wrap.style.transform = `translate3d(0, 0, 0)`;
            }

            this.stage.appendChild(wrap);
            return wrap;
        }

        loadHighRes(index, wrapperEl) {
            if (!wrapperEl || index < 0 || index >= this.items.length) return;
            const item = this.items[index];
            
            // Check if last image is already the high res one
            const imgs = wrapperEl.querySelectorAll('img.ns-img');
            const currentImg = imgs[imgs.length - 1];
            if (currentImg && currentImg.src === item.src && currentImg.complete) return;

            const fullImg = document.createElement('img');
            fullImg.className = 'ns-img';
            fullImg.src = item.src;
            fullImg.draggable = false;
            fullImg.style.opacity = '0';
            fullImg.decoding = 'async';
            fullImg.loading = 'eager';

            fullImg.onload = () => {
                if(!this.isOpen) return;
                wrapperEl.appendChild(fullImg);
                requestAnimationFrame(() => fullImg.style.opacity = '1');
                setTimeout(() => {
                    imgs.forEach(img => { if (img !== fullImg) img.remove(); });
                }, 500);
            };
        }

        preloadSurrounding(index) {
            this._preloadOne(index + 1);
            this._preloadOne(index - 1);
        }

        _preloadOne(index) {
            if (index >= 0 && index < this.items.length) {
                const i = new Image();
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
        }

        // --- Inputs ---

        onPointerDown(e) {
            // Priority 1: Buttons should always be responsive if possible
            if (e.target.closest('.ns-btn')) return;

            // Otherwise, don't start new gestures during animation
            if (this.isAnimating) return;

            e.preventDefault();
            this.pointers.push(e);

            // Reset transition for drag
            [this.slidePool.current, this.slidePool.prev, this.slidePool.next].forEach(el => {
                if (el) el.style.transition = 'none';
            });

            if (this.pointers.length === 1) {
                // Double tap detection
                const now = Date.now();
                const dist = Math.hypot(e.clientX - this.lastTapPos.x, e.clientY - this.lastTapPos.y);
                
                // Check if tap is on the visible part of the current image
                const currentImg = this.slidePool.current?.querySelector('.ns-img');
                const isOnVisibleImage = currentImg && SmartUtils.isPointOnVisibleImage(currentImg, e.clientX, e.clientY);
                
                if (isOnVisibleImage && now - this.lastTapTime < 300 && dist < 30) {
                    this.onDoubleTap(e);
                    this.pointers = []; // Cancel current drag
                    return;
                }
                this.lastTapTime = now;
                this.lastTapPos = { x: e.clientX, y: e.clientY };
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
                    // Lock axis only if not zoomed
                    if (this.state.scale <= 1.01) {
                        this.dragAxis = Math.abs(totalDx) > Math.abs(totalDy) ? 'x' : 'y';
                    }
                }
            }

            const dx = currentCenter.x - this.lastCenter.x;
            const dy = currentCenter.y - this.lastCenter.y;

            if (this.pointers.length === 2) {
                // Pinch
                const currentDist = SmartUtils.getDistance(this.pointers[0], this.pointers[1]);
                const scaleFactor = currentDist / this.lastDist;
                
                let newScale = this.state.scale * scaleFactor;
                newScale = SmartUtils.clamp(newScale, 0.5, 5);

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
            const btn = e.target.closest('.ns-btn');
            if (btn) return; // Handled by click listener

            if (this.isAnimating) return;
            
            const prevPointersLen = this.pointers.length;
            this.pointers = this.pointers.filter(p => p.pointerId !== e.pointerId);

            if (this.pointers.length > 0) {
                this.lastCenter = (this.pointers.length === 2) 
                    ? SmartUtils.getCenter(this.pointers[0], this.pointers[1]) 
                    : { x: this.pointers[0].clientX, y: this.pointers[0].clientY };
                if (this.pointers.length === 2) this.lastDist = SmartUtils.getDistance(this.pointers[0], this.pointers[1]);
                return;
            }

            if (prevPointersLen === 0) return;

            // End Gesture detection
            const moveDistThreshold = 15;
            const dist = Math.hypot(e.clientX - this.startPos.x, e.clientY - this.startPos.y);

            // Treat as tap if movement is small
            if (dist < moveDistThreshold) {
                // Check if tap is on the visible part of the current image
                const currentImg = this.slidePool.current?.querySelector('.ns-img');
                const isOnVisibleImage = currentImg && SmartUtils.isPointOnVisibleImage(
                    currentImg, 
                    this.startPos.x, 
                    this.startPos.y
                );
                
                if (!isOnVisibleImage) {
                    this.close();
                }
            } else {
                this.onGestureEnd();
            }
        }

        onDoubleTap(e) {
            if (this.state.scale > 1) {
                this.animateTo({ x: 0, y: 0, scale: 1 });
            } else {
                // Zoom to point
                let targetScale = 2.5;
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
                const threshold = winW * 0.15;
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

            const winW = window.innerWidth;
            const gap = 0; // seamless; avoids visible vertical seam
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

            window.setTimeout(() => {
                this.currentIndex = targetIndex;

                // Reuse existing slide DOM to avoid flashing
                this._recycleSlidesAfterNav(dir);
                this.updateUiVisibility();
                this.loadHighRes(this.currentIndex, this.slidePool.current);
                this.preloadSurrounding(this.currentIndex);
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
            const { x, y, scale } = this.state;
            const winW = window.innerWidth;
            const gap = 0;

            if (this.slidePool.current) {
                this.slidePool.current.style.transform = `translate3d(${x}px, ${y}px, 0) scale(${scale})`;
            }
            
            // Show/Hide neighbors based on zoom
            // Actually, we keep them in DOM but possibly reposition or hide if zoomed?
            // Existing logic:
            if (scale <= 1.01) {
                if (this.slidePool.prev) {
                    this.slidePool.prev.style.display = 'block';
                    this.slidePool.prev.style.transform = `translate3d(${x - winW}px, 0, 0)`;
                }
                if (this.slidePool.next) {
                    this.slidePool.next.style.display = 'block';
                    this.slidePool.next.style.transform = `translate3d(${x + winW}px, 0, 0)`;
                }
            } else {
                if (this.slidePool.prev) this.slidePool.prev.style.display = 'none';
                if (this.slidePool.next) this.slidePool.next.style.display = 'none';
            }

            // Update Zoom Button Icon
            if (this.btnZoom) {
                this.btnZoom.innerHTML = (this.state.scale > 1.01) ? SVG_ZOOM_OUT : SVG_ZOOM_IN;
            }
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