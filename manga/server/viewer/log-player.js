/*
 * NagiLog players for video and audio. MIT License (c) 2026 Lichiphen.
 * Without this script the browser's own player is shown. With it: one row for audio (play, the waveform
 * as the seek bar, time) and, for video, a large play button with a slim control bar that hides while
 * watching. Only one plays at a time. The waveform comes from data-peaks, measured once at upload.
 * Audio can repeat without a gap; a song with a loop marked inside it (data-loop: start,end in seconds,
 * end 0 = the end) plays its intro once and then repeats only that part, as in games.
 */
(() => {
    'use strict';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = text => i18n[text] ?? text;
    const svg = path => `<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">${path}</svg>`;
    const ICON = {
        play: svg('<path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.4-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z"/>'),
        pause: svg('<rect x="6.5" y="5" width="4" height="14" rx="1.2"/><rect x="13.5" y="5" width="4" height="14" rx="1.2"/>'),
        sound: svg('<path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5Z"/><path class="w" d="M15.5 9a4 4 0 0 1 0 6M18 6.5a7.5 7.5 0 0 1 0 11"/>'),
        mute: svg('<path d="M4 9.5v5h3.5L12 18.5v-13L7.5 9.5Z"/><path class="w" d="m16 9.5 5 5m0-5-5 5"/>'),
        full: svg('<path class="w" d="M4.5 9V4.5H9M15 4.5h4.5V9M19.5 15v4.5H15M9 19.5H4.5V15"/>'),
        exit: svg('<path class="w" d="M9 4.5V9H4.5M19.5 9H15V4.5M15 19.5V15h4.5M4.5 15H9v4.5"/>'),
        loop: svg('<path class="w" d="M17 3.5 20 6.5l-3 3M4 11.5v-1a4 4 0 0 1 4-4h12M7 20.5l-3-3 3-3M20 12.5v1a4 4 0 0 1-4 4H4"/>'),
    };
    const time = s => {
        if (!Number.isFinite(s) || s < 0) s = 0;
        s = Math.floor(s);
        const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), sec = String(s % 60).padStart(2, '0');
        return h ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
    };
    const el = (tag, className, html) => { const n = document.createElement(tag); if (className) n.className = className; if (html) n.innerHTML = html; return n; };
    const players = new Set();
    let current = null;
    // Only one plays with sound at a time; a video playing silently by itself never stops the others.
    const claim = media => { if (media.muted) return; if (current && current !== media && !current.paused && !current.muted) current.pause(); current = media; };
    const still = matchMedia('(prefers-reduced-motion: reduce)');

    /** The seek bar: a pointer drag or the arrow keys move playback; the audio one draws the waveform. */
    function seeker(media, track, onDraw) {
        const length = () => Number.isFinite(media.duration) && media.duration > 0 ? media.duration : 0;
        let dragging = false;
        const at = event => {
            const box = track.getBoundingClientRect();
            return Math.min(1, Math.max(0, (event.clientX - box.left) / box.width)) * length();
        };
        track.setAttribute('role', 'slider'); track.tabIndex = 0;
        track.setAttribute('aria-label', t('再生位置')); track.setAttribute('aria-valuemin', '0');
        const update = () => {
            const total = length();
            track.setAttribute('aria-valuemax', String(Math.round(total)));
            track.setAttribute('aria-valuenow', String(Math.round(media.currentTime)));
            track.setAttribute('aria-valuetext', `${time(media.currentTime)} / ${time(total)}`);
            onDraw(total ? media.currentTime / total : 0);
        };
        track.addEventListener('pointerdown', event => {
            if (!length()) return;
            dragging = true; track.setPointerCapture(event.pointerId);
            media.currentTime = at(event); update();
        });
        track.addEventListener('pointermove', event => { if (dragging) { media.currentTime = at(event); update(); } });
        const stop = () => { dragging = false; };
        track.addEventListener('pointerup', stop); track.addEventListener('pointercancel', stop);
        track.addEventListener('keydown', event => {
            const total = length();
            if (!total) return;
            const step = { ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5, PageUp: 30, PageDown: -30 }[event.key];
            if (step) media.currentTime = Math.min(total, Math.max(0, media.currentTime + step));
            else if (event.key === 'Home') media.currentTime = 0;
            else if (event.key === 'End') media.currentTime = total;
            else return;
            event.preventDefault(); update();
        });
        ['timeupdate', 'durationchange', 'loadedmetadata', 'seeked'].forEach(type => media.addEventListener(type, update));
        return update;
    }

    function playButton(media, className) {
        const button = el('button', className, ICON.play);
        button.type = 'button'; button.setAttribute('aria-label', t('再生'));
        const sync = () => {
            const playing = !media.paused && !media.ended;
            button.innerHTML = playing ? ICON.pause : ICON.play;
            button.setAttribute('aria-label', playing ? t('一時停止') : t('再生'));
        };
        button.addEventListener('click', () => { if (media.paused || media.ended) media.play().catch(() => {}); else media.pause(); });
        ['play', 'pause', 'ended'].forEach(type => media.addEventListener(type, sync));
        return button;
    }

    /*
     * The audio as the controls see it (currentTime, duration, paused, play(), pause() and the same events).
     * Normally that is the <audio> element. With repeat on, the song is decoded once and played by Web Audio,
     * which loops at the exact sample with no gap: the whole song, or the loop marked inside it (data-loop:
     * start,end in seconds, end 0 = the end), whose intro then plays once. Songs too long to hold in memory,
     * or a browser that cannot decode them, repeat with the element instead.
     */
    const LONGEST = 8 * 60;
    let context = null;
    function track(audio, box) {
        const events = new EventTarget();
        const emit = type => events.dispatchEvent(new Event(type));
        const [markStart, markEnd] = (box.dataset.loop || '').split(',').map(Number);
        const marked = Number.isFinite(markStart) && Number.isFinite(markEnd);
        let web = false, buffer = null, source = null, playing = false, startedAt = 0, offset = 0, ticker = 0, quiet = false, frame = 0, on = false;
        // Element events reach the controls only while the element plays (not while handing over to Web Audio).
        ['play', 'pause', 'ended', 'timeupdate', 'seeked', 'durationchange', 'loadedmetadata'].forEach(type => audio.addEventListener(type, () => { if (!web && !quiet) emit(type); }));
        const span = () => {
            const length = buffer.duration;
            let a = marked ? markStart : 0, b = marked && markEnd > 0 ? markEnd : length;
            if (b > length) b = length;
            if (a >= b - 0.01) { a = 0; b = length; }
            return [a, b];
        };
        // Started after a marked loop's end (a seek into the outro): the rest of the song once, then the loop.
        // Web Audio would wrap such a start back into the loop at once, away from where the reader seeked.
        let tail = false;
        const position = () => {
            if (!playing) return offset;
            const [a, b] = span();
            const at = offset + context.currentTime - startedAt;
            if (tail) return Math.min(at, buffer.duration);
            return at < b ? at : a + (at - b) % (b - a);
        };
        const stop = () => {
            clearInterval(ticker); ticker = 0;
            if (source) { source.onended = null; try { source.stop(); } catch { /* not started */ } source.disconnect(); source = null; }
        };
        function start(at) {
            stop();
            const [a, b] = span();
            if (at >= buffer.duration - 0.01 || at < 0) at = a;
            tail = at >= b;
            const node = context.createBufferSource();
            node.buffer = buffer; node.loop = !tail; node.loopStart = a; node.loopEnd = b;
            if (tail) node.onended = () => { if (source === node && playing) start(a); };
            node.connect(context.destination);
            source = node;
            node.start(0, at);
            startedAt = context.currentTime; offset = at; playing = true;
            ticker = setInterval(() => emit('timeupdate'), 100);
        }
        // Repeat with the element (a long song, or one that cannot be decoded): a marked loop is watched every frame.
        const watch = () => {
            frame = 0;
            if (!on || web || audio.paused) return;
            if (marked && markEnd > 0 && audio.currentTime >= markEnd) audio.currentTime = markStart + (audio.currentTime - markEnd);
            frame = requestAnimationFrame(watch);
        };
        audio.addEventListener('play', () => { if (on && !web && marked && !frame) watch(); });
        // While Web Audio plays, the element stays silent: a play from outside (the OS media keys) goes to Web Audio.
        audio.addEventListener('play', () => {
            if (!web) return;
            quiet = true; audio.pause(); quiet = false;
            if (!playing) { context.resume?.(); start(offset); emit('play'); }
        });
        audio.addEventListener('ended', () => { if (on && !web && marked) { audio.currentTime = markStart; audio.play().catch(() => {}); } });
        let loading = null;
        function load() {
            loading ??= (async () => {
                const length = Number.isFinite(audio.duration) ? audio.duration : Number(box.dataset.duration) || 0;
                if (length > LONGEST) return null;
                const response = await fetch(audio.currentSrc || audio.src, { credentials: 'same-origin' });
                if (!response.ok) throw new Error('fetch');
                const data = await response.arrayBuffer();
                return await new Promise((resolve, reject) => { const p = context.decodeAudioData(data, resolve, reject); if (p?.then) p.then(resolve, reject); });
            })().catch(() => null);
            return loading;
        }
        const button = el('button', 'log-player-loop', ICON.loop);
        const label = marked ? t('ループ再生（曲の中のループ位置で）') : t('ループ再生');
        button.type = 'button'; button.title = label; button.setAttribute('aria-label', label); button.setAttribute('aria-pressed', 'false');
        async function set(value) {
            on = value;
            button.setAttribute('aria-pressed', String(on)); box.classList.toggle('is-looping', on);
            if (on) {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (Ctx && !context) {
                    // iPhone: play through the silent switch like the <audio> element does.
                    try { if (navigator.audioSession) navigator.audioSession.type = 'playback'; } catch { /* older Safari */ }
                    context = new Ctx();
                }
                context?.resume?.();
                box.classList.add('is-preparing');
                buffer = context ? await load() : null;
                box.classList.remove('is-preparing');
                if (!on) return;
                if (!buffer) { audio.loop = !marked; if (marked && !audio.paused && !frame) watch(); return; }
                const was = !audio.paused, at = audio.currentTime;
                quiet = true; audio.pause(); quiet = false;
                web = true; offset = at;
                if (was) start(at);
                emit('timeupdate');
            } else {
                audio.loop = false;
                if (!web) return;
                const was = playing, at = position();
                stop(); playing = false; web = false;
                audio.currentTime = at;
                if (was) audio.play().catch(() => {});
                else emit('timeupdate');
            }
        }
        button.addEventListener('click', () => set(!on));
        return {
            button,
            addEventListener: (type, fn) => events.addEventListener(type, fn),
            get duration() { return Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : buffer ? buffer.duration : NaN; },
            get paused() { return web ? !playing : audio.paused; },
            get ended() { return web ? false : audio.ended; },
            get muted() { return audio.muted; },
            get currentTime() { return web ? position() : audio.currentTime; },
            set currentTime(value) {
                if (!web) { audio.currentTime = value; return; }
                if (playing) start(value); else offset = value;
                emit('seeked'); emit('timeupdate');
            },
            play() {
                if (!web) return audio.play();
                context.resume?.();
                start(offset); emit('play');
                return Promise.resolve();
            },
            pause() {
                if (!web) { audio.pause(); return; }
                if (!playing) return;
                offset = position(); stop(); playing = false; emit('pause');
            },
        };
    }

    function setupAudio(box) {
        const audio = box.querySelector('audio');
        audio.controls = false;
        const media = track(audio, box);
        const peaks = (box.dataset.peaks || '').split(',').filter(Boolean).map(Number);
        const row = el('div', 'log-player-row');
        const wave = el('div', 'log-player-wave' + (peaks.length ? '' : ' is-plain'));
        const canvas = el('canvas'); wave.append(canvas);
        const clock = el('span', 'log-player-time');
        const fallback = Number(box.dataset.duration) || 0;
        let ratio = 0;
        const draw = () => {
            const width = wave.clientWidth, height = wave.clientHeight, dpr = window.devicePixelRatio || 1;
            if (!width || !height) return;
            if (canvas.width !== Math.round(width * dpr) || canvas.height !== Math.round(height * dpr)) { canvas.width = Math.round(width * dpr); canvas.height = Math.round(height * dpr); }
            const ctx = canvas.getContext('2d');
            const style = getComputedStyle(box);
            const played = style.getPropertyValue('--player-played').trim() || '#225dcb', rest = style.getPropertyValue('--player-rest').trim() || '#c9d4e0';
            ctx.setTransform(dpr, 0, 0, dpr, 0, 0); ctx.clearRect(0, 0, width, height);
            if (!peaks.length) {
                // No waveform (it could not be measured): a plain rounded bar.
                const y = height / 2 - 2;
                ctx.fillStyle = rest; ctx.beginPath(); ctx.roundRect(0, y, width, 4, 2); ctx.fill();
                ctx.fillStyle = played; ctx.beginPath(); ctx.roundRect(0, y, Math.max(4, width * ratio), 4, 2); ctx.fill();
                return;
            }
            const gap = 2, bar = Math.max(2, (width - gap * (peaks.length - 1)) / peaks.length);
            const count = Math.min(peaks.length, Math.floor((width + gap) / (bar + gap)));
            const step = peaks.length / count;
            for (let i = 0; i < count; i++) {
                let v = 0;
                for (let j = Math.floor(i * step); j < Math.floor((i + 1) * step); j++) v = Math.max(v, peaks[j] || 0);
                const h = Math.max(3, (v / 100) * (height - 2));
                const x = i * (bar + gap);
                ctx.fillStyle = (x + bar / 2) / width <= ratio ? played : rest;
                ctx.beginPath(); ctx.roundRect(x, (height - h) / 2, bar, h, Math.min(1.5, bar / 2)); ctx.fill();
            }
        };
        const update = seeker(media, wave, value => { ratio = value; draw(); clock.textContent = `${time(media.currentTime)} / ${time(media.duration || fallback)}`; });
        row.append(playButton(media, 'log-player-play'), wave, clock, media.button);
        box.querySelector('.log-player-length')?.remove();
        audio.after(row);
        clock.textContent = `0:00 / ${time(fallback)}`;
        if (typeof ResizeObserver === 'function') new ResizeObserver(draw).observe(wave);
        media.addEventListener('play', () => { claim(media); box.classList.add('is-playing'); });
        media.addEventListener('pause', () => box.classList.remove('is-playing'));
        // Theme switches (dark/light) change the colours.
        matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change', draw);
        requestAnimationFrame(() => { update(); draw(); });
        players.add(media);
    }

    function setupVideo(box) {
        const video = box.querySelector('video');
        video.controls = false;
        const screen = el('div', 'log-video-screen');
        video.before(screen); screen.append(video);
        const big = playButton(video, 'log-video-big');
        const bar = el('div', 'log-video-bar');
        const track = el('div', 'log-video-track');
        const loaded = el('span', 'log-video-loaded'), done = el('span', 'log-video-done');
        track.append(loaded, done);
        const clock = el('span', 'log-player-time');
        const mute = el('button', 'log-video-tool', ICON.sound); mute.type = 'button'; mute.setAttribute('aria-label', t('ミュート'));
        const volume = el('input', 'log-video-volume'); volume.type = 'range'; volume.min = '0'; volume.max = '1'; volume.step = '0.05'; volume.value = '1'; volume.setAttribute('aria-label', t('音量'));
        const full = el('button', 'log-video-tool', ICON.full); full.type = 'button'; full.setAttribute('aria-label', t('全画面'));
        bar.append(playButton(video, 'log-video-tool'), track, clock, mute, volume, full);
        screen.append(big, bar);
        seeker(video, track, value => {
            done.style.width = `${value * 100}%`;
            clock.textContent = `${time(video.currentTime)} / ${time(video.duration)}`;
        });
        video.addEventListener('progress', () => {
            const total = video.duration;
            if (total > 0 && video.buffered.length) loaded.style.width = `${video.buffered.end(video.buffered.length - 1) / total * 100}%`;
        });
        const sound = () => {
            const off = video.muted || video.volume === 0;
            mute.innerHTML = off ? ICON.mute : ICON.sound;
            mute.setAttribute('aria-label', off ? t('ミュートを解除') : t('ミュート'));
            volume.value = video.muted ? '0' : String(video.volume);
            screen.classList.toggle('is-muted', off);
        };
        mute.addEventListener('click', () => { video.muted = !video.muted; if (!video.muted && video.volume === 0) video.volume = 1; });
        volume.addEventListener('input', () => { video.volume = Number(volume.value); video.muted = video.volume === 0; });
        video.addEventListener('volumechange', () => { sound(); if (!video.muted && !video.paused) claim(video); });
        // Full screen: the whole player where the browser allows it; iPhone only plays a video full screen itself.
        const fullscreen = () => document.fullscreenElement === screen || document.webkitFullscreenElement === screen;
        full.addEventListener('click', () => {
            if (fullscreen()) (document.exitFullscreen || document.webkitExitFullscreen)?.call(document);
            else if (screen.requestFullscreen) screen.requestFullscreen().catch(() => {});
            else if (screen.webkitRequestFullscreen) screen.webkitRequestFullscreen();
            else if (video.webkitEnterFullscreen) video.webkitEnterFullscreen();
        });
        const fullSync = () => { const on = fullscreen(); full.innerHTML = on ? ICON.exit : ICON.full; full.setAttribute('aria-label', on ? t('全画面を終了') : t('全画面')); };
        document.addEventListener('fullscreenchange', fullSync); document.addEventListener('webkitfullscreenchange', fullSync);
        // The bar hides while playing once the pointer rests; any movement or tap brings it back.
        let idle = 0;
        const wake = () => {
            screen.classList.add('is-awake'); clearTimeout(idle);
            if (!video.paused) idle = setTimeout(() => screen.classList.remove('is-awake'), 2500);
        };
        screen.addEventListener('pointermove', wake); screen.addEventListener('focusin', wake);
        video.addEventListener('click', () => {
            // A tap on a phone first shows the bar; a click with a mouse plays or pauses.
            if (matchMedia('(hover: none)').matches && !screen.classList.contains('is-awake') && !video.paused) { wake(); return; }
            if (video.paused || video.ended) video.play().catch(() => {}); else video.pause();
        });
        video.addEventListener('play', () => { claim(video); screen.classList.add('is-started', 'is-playing'); wake(); });
        video.addEventListener('pause', () => { screen.classList.remove('is-playing'); wake(); });
        screen.addEventListener('keydown', event => {
            if (event.target === volume || event.target.closest('.log-video-track')) return;
            const key = event.key.toLowerCase();
            if (key === ' ' || key === 'k') { if (event.target.tagName === 'BUTTON' && key === ' ') return; event.preventDefault(); video.paused ? video.play().catch(() => {}) : video.pause(); }
            else if (key === 'f') { event.preventDefault(); full.click(); }
            else if (key === 'm') { event.preventDefault(); mute.click(); }
            else if (key === 'arrowright' || key === 'arrowleft') { event.preventDefault(); video.currentTime = Math.max(0, video.currentTime + (key === 'arrowright' ? 5 : -5)); }
            wake();
        });
        screen.tabIndex = -1;
        // Set to play by itself (data-autoplay, always without sound): it plays while at least half of it is in
        // view and stops outside. Once the reader stops it, it stays stopped. Not for readers who ask for less motion.
        if (box.hasAttribute('data-autoplay') && typeof IntersectionObserver === 'function') {
            box.classList.add('is-auto');
            // Playing without sound: a button that says so and brings the sound in.
            const unmute = el('button', 'log-video-unmute', ICON.mute);
            unmute.type = 'button'; unmute.append(el('span', '', t('音を出す')));
            unmute.addEventListener('click', event => { event.stopPropagation(); video.muted = false; if (video.volume === 0) video.volume = 1; });
            screen.append(unmute);
            let ours = false, stopped = false;
            video.addEventListener('pause', () => { if (!ours && !video.ended) stopped = true; ours = false; });
            video.addEventListener('play', () => { stopped = false; });
            new IntersectionObserver(([entry]) => {
                if (still.matches || stopped) return;
                if (entry.isIntersecting && video.paused) { video.muted = true; video.play().catch(() => {}); }
                else if (!entry.isIntersecting && !video.paused) { ours = true; video.pause(); }
            }, { threshold: 0.5 }).observe(screen);
        }
        sound(); wake();
        players.add(video);
    }

    function load(root = document) {
        root.querySelectorAll('[data-player]:not(.is-ready)').forEach(box => {
            box.classList.add('is-ready');
            if (box.dataset.player === 'audio' && box.querySelector('audio')) setupAudio(box);
            else if (box.dataset.player === 'video' && box.querySelector('video')) setupVideo(box);
        });
    }
    window.NagiLogPlayers = { load };
    load();
    // Posts added later (もっと見る, the editor's preview) bring their players with them.
    if (typeof MutationObserver === 'function') new MutationObserver(records => {
        if (records.some(r => [...r.addedNodes].some(n => n.nodeType === 1 && (n.matches?.('[data-player]') || n.querySelector?.('[data-player]'))))) load();
    }).observe(document.body, { childList: true, subtree: true });
})();
