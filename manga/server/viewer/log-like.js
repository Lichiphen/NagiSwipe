/* LOG likes: tap for +1, hold for +10 every 70 frames (counted at 60 fps, so 120 Hz screens are not faster). Taps in a burst go out as one request. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const HOLD_MS = 70 * 1000 / 60, HOLD_STEP = 10, SEND_DELAY = 700;
    const endpoint = new URL('like.php', document.baseURI).href;
    const state = new Map(); // id -> {count, left, pending, timer, busy}
    let daily = 100, hold = null;

    const buttonsFor = id => document.querySelectorAll(`[data-like="${CSS.escape(id)}"]`);
    const entry = id => {
        if (!state.has(id)) {
            const btn = buttonsFor(id)[0];
            state.set(id, {count: parseInt(btn?.querySelector('.log-like-count')?.textContent || '0', 10) || 0, left: daily, pending: 0, timer: 0, busy: false});
        }
        return state.get(id);
    };
    function render(id) {
        const st = entry(id);
        buttonsFor(id).forEach(btn => {
            btn.querySelector('.log-like-count').textContent = st.count.toLocaleString('ja-JP');
            btn.classList.toggle('is-liked', st.left < daily);
            btn.classList.toggle('is-full', st.left <= 0);
            btn.setAttribute('aria-label', `いいね（${st.count}）` + (st.left <= 0 ? '。今日はこの記事にもう押せません' : ''));
            btn.title = st.left <= 0 ? '今日はここまで。また明日どうぞ' : 'タップで+1・長押しで+10ずつ';
        });
    }
    function pop(btn, text) {
        const tip = document.createElement('span');
        tip.className = 'log-like-pop'; tip.textContent = text; tip.setAttribute('aria-hidden', 'true');
        btn.append(tip);
        tip.addEventListener('animationend', () => tip.remove());
        setTimeout(() => tip.remove(), 1500);
        btn.classList.remove('is-beat'); void btn.offsetWidth; btn.classList.add('is-beat');
    }
    function add(btn, n) {
        const id = btn.dataset.like, st = entry(id);
        n = Math.min(n, st.left);
        if (n <= 0) { pop(btn, 'MAX'); render(id); return false; }
        st.count += n; st.left -= n; st.pending += n;
        render(id); pop(btn, '+' + n);
        clearTimeout(st.timer);
        st.timer = setTimeout(() => send(id), SEND_DELAY);
        return st.left > 0;
    }
    async function send(id, leaving = false) {
        const st = entry(id);
        if (!st.pending || st.busy) return;
        const n = st.pending; st.pending = 0; st.busy = true;
        try {
            const res = await fetch(endpoint, {method: 'POST', credentials: 'same-origin', keepalive: leaving,
                headers: {'Content-Type': 'application/json', 'X-NagiLog': 'like'}, body: JSON.stringify({id, n})});
            if (!res.ok) throw new Error(String(res.status));
            const data = await res.json();
            // Taps made while this request was on its way stay on top of the server's numbers.
            st.count = data.count + st.pending; st.left = Math.max(0, data.left - st.pending);
        } catch {
            st.count = Math.max(0, st.count - n); st.left = Math.min(daily, st.left + n);
        } finally {
            st.busy = false;
            if (!leaving) { render(id); if (st.pending) { clearTimeout(st.timer); st.timer = setTimeout(() => send(id), SEND_DELAY); } }
        }
    }
    /** Fresh counts (the page itself may come from the server cache) and what this reader may still add today. */
    async function refresh() {
        const ids = [...new Set([...document.querySelectorAll('[data-like]')].map(b => b.dataset.like))].filter(id => !state.get(id)?.fresh);
        if (!ids.length) return;
        try {
            const res = await fetch(endpoint + '?ids=' + ids.slice(0, 100).map(encodeURIComponent).join(','), {credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!res.ok) return;
            const data = await res.json();
            daily = data.daily || daily;
            ids.forEach(id => {
                if (!(id in data.likes)) return;
                const st = entry(id);
                st.count = data.likes[id] + st.pending; st.left = Math.max(0, data.left[id] - st.pending); st.fresh = true;
                render(id);
            });
        } catch { /* the counts in the page stay */ }
    }
    function stopHold(tap) {
        if (!hold) return;
        cancelAnimationFrame(hold.raf);
        hold.btn.style.removeProperty('--like-hold');
        hold.btn.classList.remove('is-holding');
        if (tap && !hold.fired) add(hold.btn, 1);
        hold = null;
    }
    function frame(now) {
        if (!hold) return;
        hold.start ??= now;
        const progress = (now - hold.start) / HOLD_MS;
        hold.btn.style.setProperty('--like-hold', String(Math.min(1, progress)));
        if (progress >= 1) {
            hold.start = now; hold.fired = true;
            if (!add(hold.btn, HOLD_STEP)) { stopHold(false); return; }
        }
        hold.raf = requestAnimationFrame(frame);
    }
    document.addEventListener('pointerdown', e => {
        const btn = e.target.closest?.('[data-like]');
        if (!btn || e.button !== 0 || hold) return;
        hold = {btn, start: null, fired: false, pointer: e.pointerId, raf: 0};
        btn.classList.add('is-holding');
        try { btn.setPointerCapture(e.pointerId); } catch { /* not capturable */ }
        hold.raf = requestAnimationFrame(frame);
    });
    document.addEventListener('pointerup', e => { if (hold && hold.pointer === e.pointerId) stopHold(true); });
    document.addEventListener('pointercancel', e => { if (hold && hold.pointer === e.pointerId) stopHold(false); });
    // Taps and holds are handled above; a click without a pointer (Enter / Space) adds one.
    document.addEventListener('click', e => {
        const btn = e.target.closest?.('[data-like]');
        if (btn && e.detail === 0) add(btn, 1);
    });
    document.addEventListener('contextmenu', e => { if (e.target.closest?.('[data-like]')) e.preventDefault(); });
    addEventListener('pagehide', () => state.forEach((st, id) => { clearTimeout(st.timer); send(id, true); }));
    document.documentElement.classList.add('log-like-ready');
    window.NagiLogLikes = {refresh};
    refresh();
})();
