/* LOG comments: the list and the form under a post or fixed page, filled from comment.php (the page itself stays cached). MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const box = document.querySelector('[data-comments]');
    if (!box) return;
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const endpoint = new URL('comment.php', document.baseURI).href;
    const id = box.dataset.comments;
    const list = box.querySelector('[data-comments-list]'), formSlot = box.querySelector('[data-comments-form]'), countEl = box.querySelector('[data-comments-count]');
    // The reader's own random key: their ID is made from it on the server, so it stays when the IP address changes.
    const store = {
        get: k => { try { return localStorage.getItem(k) || ''; } catch { return ''; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch { /* private mode: the ID lasts for this page */ } },
    };
    let key = store.get('nl-comment-key');
    if (!/^[a-f0-9]{32}$/.test(key)) key = '';
    const newKey = () => {
        if (!key) { key = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join(''); store.set('nl-comment-key', key); }
        return key;
    };
    const el = (tag, className, text) => { const n = document.createElement(tag); if (className) n.className = className; if (text !== undefined) n.textContent = text; return n; };
    const pad = n => String(n).padStart(2, '0');
    const when = s => { const d = new Date(s * 1000); return `${d.getFullYear()}/${pad(d.getMonth() + 1)}/${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`; };
    let data = null, me = '';

    function iconOf(key) { return data.icons.find(i => i.key === key) || data.icons[0]; }
    function item(c) {
        const li = el('li', 'log-comment' + (c.wait ? ' is-waiting' : ''));
        const icon = iconOf(c.icon), img = el('img', 'log-comment-icon');
        img.src = icon ? icon.url : ''; img.alt = ''; img.width = 40; img.height = 40; img.loading = 'lazy'; img.decoding = 'async';
        const body = el('div', 'log-comment-body'), meta = el('p', 'log-comment-meta');
        meta.append(el('b', 'log-comment-id', 'ID:' + c.uid));
        if (c.uid === me) meta.append(el('span', 'log-comment-me', t('あなた')));
        const time = el('time', '', when(c.t)); time.dateTime = new Date(c.t * 1000).toISOString(); meta.append(time);
        body.append(meta);
        if (c.stamp) body.append(el('p', 'log-comment-stamp', c.stamp));
        if (c.text) body.append(el('p', 'log-comment-text', c.text));
        if (c.wait) body.append(el('p', 'log-comment-wait', t('承認待ち：管理者が確認すると、ほかの人にも表示されます（いまはあなたにだけ見えています）。')));
        li.append(img, body);
        return li;
    }
    function render() {
        const shown = data.items.filter(c => !c.wait).length;
        if (countEl) countEl.textContent = String(shown);
        // The button beside Share on this page shows the same count.
        document.querySelectorAll(`.log-comment-link[data-comments-for="${CSS.escape(id)}"] span`).forEach(n => { n.textContent = String(shown); });
        if (!data.items.length) { list.replaceChildren(el('p', 'log-comments-note', t('まだコメントはありません。スタンプひとつから、どうぞ。'))); return; }
        const ul = el('ol', 'log-comment-list');
        data.items.forEach(c => ul.append(item(c)));
        list.replaceChildren(ul);
    }
    function form() {
        const f = el('form', 'log-comment-form'); f.noValidate = true;
        // Icons: a row of radio buttons drawn as pictures; the last choice is remembered.
        // Each group says in a few words what it is for, so icons and stamps are not taken for one another.
        const legend = (title, hint) => { const l = el('legend', 'log-comment-legend'); l.append(el('b', '', title), el('span', 'log-comment-hint', hint)); return l; };
        const icons = el('fieldset', 'log-comment-icons'); icons.append(legend(t('アイコン'), t('名前の代わりに表示します')));
        const saved = store.get('nl-comment-icon');
        data.icons.forEach((icon, i) => {
            const label = el('label', 'log-comment-icon-pick'), input = el('input'), img = el('img');
            input.type = 'radio'; input.name = 'icon'; input.value = icon.key; input.checked = saved ? icon.key === saved : i === 0;
            img.src = icon.url; img.alt = icon.name; img.width = 36; img.height = 36; img.title = icon.name;
            label.append(input, img); icons.append(label);
        });
        if (!icons.querySelector('input:checked')) icons.querySelector('input')?.click();
        f.append(icons);
        let stamp = '';
        if (data.stamps.length) {
            const set = el('fieldset', 'log-comment-stamp-set'); set.append(legend(t('スタンプ'), t('押して送るだけでもOK')));
            const stamps = el('div', 'log-comment-stamps');
            data.stamps.forEach(s => {
                const b = el('button', 'log-comment-stamp-btn', s); b.type = 'button'; b.setAttribute('aria-pressed', 'false');
                b.addEventListener('click', () => {
                    stamp = stamp === s ? '' : s;
                    stamps.querySelectorAll('button').forEach(x => x.setAttribute('aria-pressed', String(x.textContent === stamp)));
                    ready();
                });
                stamps.append(b);
            });
            set.append(stamps); f.append(set);
        }
        let area = null, count = null;
        if (data.text) {
            const label = el('label', 'log-comment-text-label');
            const head = el('span', 'log-comment-legend'); head.append(el('b', '', t('ひとこと')), el('span', 'log-comment-hint', t('任意・確認後に表示（{n}文字まで）', { n: data.max })));
            label.append(head);
            area = el('textarea'); area.rows = 2; area.maxLength = data.max; area.name = 'text';
            count = el('span', 'log-comment-counter', `0/${data.max}`);
            area.addEventListener('input', () => { count.textContent = `${[...area.value].length}/${data.max}`; ready(); });
            label.append(area, count); f.append(label);
        }
        const foot = el('div', 'log-comment-foot');
        const who = el('span', 'log-comment-who', me ? t('あなたのID：{id}', { id: me }) : t('名前は出ません。最初の書き込みで、このブラウザー用のIDを作ります。'));
        const send = el('button', 'log-comment-send', t('送る')); send.type = 'submit'; send.disabled = true;
        foot.append(who, send);
        const status = el('p', 'log-comment-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        f.append(foot, status);
        const ready = () => { send.disabled = !stamp && !(area && area.value.trim()); };
        f.addEventListener('submit', async event => {
            event.preventDefault();
            if (send.disabled) return;
            const icon = f.querySelector('input[name="icon"]:checked')?.value || data.icons[0]?.key;
            store.set('nl-comment-icon', icon);
            send.disabled = true; status.textContent = t('送っています…');
            try {
                const res = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-NagiLog': 'comment' },
                    body: JSON.stringify({ id, token: newKey(), icon, stamp, text: area ? area.value : '' }) });
                const out = await res.json().catch(() => ({}));
                if (!res.ok || !out.item) {
                    status.textContent = { busy: t('続けて書き込まれています。少し時間をおいてから送ってください。'), long: t('文章が長すぎます。{n}文字・数行までにしてください。', { n: data.max }), full: t('この記事のコメントはいっぱいです。'), empty: t('スタンプを選ぶか、文章を書いてください。') }[out.error] || t('送れませんでした。ページを読み込み直してから、もう一度お試しください。');
                    ready(); return;
                }
                me = out.me; who.textContent = t('あなたのID：{id}', { id: me });
                data.items.push(out.item); render();
                stamp = ''; f.querySelectorAll('.log-comment-stamps button').forEach(x => x.setAttribute('aria-pressed', 'false'));
                if (area) { area.value = ''; count.textContent = `0/${data.max}`; }
                status.textContent = out.item.wait ? t('送りました。管理者が確認すると表示されます。') : t('送りました。');
                list.querySelector('.log-comment:last-child')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch {
                status.textContent = t('送れませんでした。通信を確かめてから、もう一度お試しください。');
            }
            ready();
        });
        formSlot.replaceChildren(f);
    }
    async function load() {
        try {
            const res = await fetch(endpoint + '?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: key ? { 'X-NagiLog-Key': key } : {} });
            if (!res.ok) throw new Error(String(res.status));
            data = await res.json(); me = data.me || '';
            render(); form();
        } catch {
            list.replaceChildren(el('p', 'log-comments-note', t('コメントを読み込めませんでした。')));
        }
    }
    // Fetched when the block comes near the screen, so a reader who never scrolls there sends nothing.
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => { if (entries.some(e => e.isIntersecting)) { io.disconnect(); load(); } }, { rootMargin: '600px 0px' });
        io.observe(box);
    } else load();
})();
