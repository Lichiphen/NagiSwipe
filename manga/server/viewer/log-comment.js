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
    let data = null, me = '', ticketAt = 0;
    // The comment being answered (null: a new thread), and the form, which moves under that thread while replying.
    let replyTo = null, f = null, setReply = () => {};

    function iconOf(key) { return data.icons.find(i => i.key === key) || data.icons[0]; }
    // The mark made from the reader's key: the same name from someone else still shows a different mark.
    const mark = uid => '◆' + uid.slice(0, 6);
    const who = c => c.own ? (data.owner?.name || t('管理人')) : (c.name ? c.name + ' ' : '') + mark(c.uid);
    // The owner's replies: their http(s) addresses become links (readers' comments never hold one).
    function ownerText(text) {
        const p = el('p', 'log-comment-text');
        let last = 0;
        for (const m of text.matchAll(/https?:\/\/[^\s<>"'「」『』（）()、。]+/g)) {
            const raw = m[0].replace(/[.,!?;:]+$/, '');
            let url;
            try { url = new URL(raw); } catch { continue; }
            if (url.protocol !== 'http:' && url.protocol !== 'https:') continue;
            const a = el('a', '', raw); a.href = url.href; a.target = '_blank'; a.rel = 'noopener';
            p.append(text.slice(last, m.index), a);
            last = m.index + raw.length;
        }
        p.append(text.slice(last));
        return p;
    }
    function item(c) {
        const li = el('li', 'log-comment' + (c.wait ? ' is-waiting' : '') + (c.own ? ' is-owner' : ''));
        li.id = 'comment-' + c.id;
        let face;
        if (c.own && !data.owner?.icon) face = el('span', 'log-comment-icon log-comment-owner-icon', [...(data.owner?.name || '?')][0]);
        else {
            face = el('img', 'log-comment-icon');
            const icon = c.own ? { url: data.owner.icon } : iconOf(c.icon);
            face.src = icon ? icon.url : ''; face.alt = ''; face.width = 40; face.height = 40; face.loading = 'lazy'; face.decoding = 'async';
        }
        const body = el('div', 'log-comment-body'), meta = el('p', 'log-comment-meta');
        if (c.own) meta.append(el('b', 'log-comment-name', data.owner?.name || ''), el('span', 'log-comment-owner', t('管理人')));
        else {
            if (c.name) meta.append(el('b', 'log-comment-name', c.name));
            meta.append(el('b', 'log-comment-id', mark(c.uid)));
            if (c.uid === me) meta.append(el('span', 'log-comment-me', t('あなた')));
        }
        const time = el('time', '', when(c.t)); time.dateTime = new Date(c.t * 1000).toISOString(); meta.append(time);
        body.append(meta);
        const to = c.re && data.items.find(x => x.id === c.re);
        if (to) body.append(el('p', 'log-comment-to', t('{who} さんへ', { who: who(to) })));
        if (c.stamp) body.append(el('p', 'log-comment-stamp', c.stamp));
        if (c.text) body.append(c.own ? ownerText(c.text) : el('p', 'log-comment-text', c.text));
        if (c.wait) body.append(el('p', 'log-comment-wait', t('承認待ち：管理者が確認すると、ほかの人にも表示されます（いまはあなたにだけ見えています）。')));
        else {
            const b = el('button', 'log-comment-reply-btn', t('返信')); b.type = 'button';
            b.setAttribute('aria-label', t('{who} さんに返信', { who: who(c) }));
            b.addEventListener('click', () => setReply(c));
            body.append(b);
        }
        li.append(face, body);
        return li;
    }
    function render() {
        const shown = data.items.filter(c => !c.wait).length;
        if (countEl) countEl.textContent = String(shown);
        // The button beside Share on this page shows the same count.
        document.querySelectorAll(`.log-comment-link[data-comments-for="${CSS.escape(id)}"] span`).forEach(n => { n.textContent = String(shown); });
        if (f && !replyTo && f.parentNode !== formSlot) formSlot.replaceChildren(f);
        if (!data.items.length) { list.replaceChildren(el('p', 'log-comments-note', t('まだコメントはありません。スタンプひとつから、どうぞ。'))); return; }
        // One level deep: each thread is a comment and the replies under it (a reply to a reply joins the same thread).
        const ids = new Set(data.items.map(c => c.id));
        const ul = el('ol', 'log-comment-list');
        const threads = new Map();
        data.items.forEach(c => {
            if (c.to && ids.has(c.to)) return;
            const li = el('li', 'log-comment-thread'), replies = el('ol', 'log-comment-replies');
            li.append(item(c), replies); ul.append(li);
            threads.set(c.id, { li, replies });
        });
        data.items.forEach(c => { if (c.to && threads.has(c.to)) threads.get(c.to).replies.append(item(c)); });
        threads.forEach(({ replies }) => { if (!replies.children.length) replies.remove(); });
        // While replying, the form sits under its thread.
        const at = replyTo && threads.get(replyTo.to && ids.has(replyTo.to) ? replyTo.to : replyTo.id);
        if (f && at) at.li.append(f);
        list.replaceChildren(ul);
    }
    function form() {
        f = el('form', 'log-comment-form'); f.noValidate = true;
        // Whom this answers, with a way back to a new comment.
        const replying = el('p', 'log-comment-replying'); replying.hidden = true;
        const replyLabel = el('span'), cancel = el('button', 'log-comment-cancel', t('やめる')); cancel.type = 'button';
        replying.append(replyLabel, cancel); f.append(replying);
        // Icons: a row of radio buttons drawn as pictures; the last choice is remembered.
        // Each group says in a few words what it is for, so icons and stamps are not taken for one another.
        const legend = (title, hint) => { const l = el('legend', 'log-comment-legend'); l.append(el('b', '', title), el('span', 'log-comment-hint', hint)); return l; };
        const icons = el('fieldset', 'log-comment-icons'); icons.append(legend(t('アイコン'), t('名前の横に表示します')));
        const saved = store.get('nl-comment-icon');
        data.icons.forEach((icon, i) => {
            const label = el('label', 'log-comment-icon-pick'), input = el('input'), img = el('img');
            input.type = 'radio'; input.name = 'icon'; input.value = icon.key; input.checked = saved ? icon.key === saved : i === 0;
            img.src = icon.url; img.alt = icon.name; img.width = 36; img.height = 36; img.title = icon.name;
            label.append(input, img); icons.append(label);
        });
        if (!icons.querySelector('input:checked')) icons.querySelector('input')?.click();
        f.append(icons);
        // An optional name, beside the mark; the first time, the owner approves it.
        const nameLabel = el('label', 'log-comment-name-label');
        const nameHead = el('span', 'log-comment-legend'); nameHead.append(el('b', '', t('名前')), el('span', 'log-comment-hint', t('任意・はじめは確認後に表示')));
        const nameInput = el('input'); nameInput.name = 'name'; nameInput.maxLength = data.name_max || 16; nameInput.autocomplete = 'nickname'; nameInput.value = store.get('nl-comment-name');
        nameLabel.append(nameHead, nameInput); f.append(nameLabel);
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
        let area = null, count = null, quiz = null, quizInput = null, quizSum = null;
        const showQuiz = () => { if (quiz) quiz.hidden = !area.value.trim(); };
        const setQuiz = q => { if (quizSum && Array.isArray(q)) { quizSum.textContent = `${q[0]} + ${q[1]} =`; quizInput.value = ''; } };
        if (data.text) {
            const label = el('label', 'log-comment-text-label');
            const head = el('span', 'log-comment-legend'); head.append(el('b', '', t('ひとこと')), el('span', 'log-comment-hint', t('任意・確認後に表示（{n}文字まで）', { n: data.max })));
            label.append(head);
            area = el('textarea'); area.rows = 2; area.maxLength = data.max; area.name = 'text';
            count = el('span', 'log-comment-counter', `0/${data.max}`);
            area.addEventListener('input', () => { count.textContent = `${[...area.value].length}/${data.max}`; showQuiz(); ready(); });
            label.append(area, count); f.append(label);
            // A small sum, only for a text (a stamp alone stays one tap). Digits, so it reads the same in every language.
            quiz = el('label', 'log-comment-quiz'); quiz.hidden = true;
            const quizHead = el('span', 'log-comment-legend'); quizHead.append(el('b', '', t('確認')), el('span', 'log-comment-hint', t('文章を送るときだけ、答えを数字で入れてください')));
            quizSum = el('span', 'log-comment-quiz-sum');
            quizInput = el('input'); quizInput.name = 'quiz'; quizInput.inputMode = 'numeric'; quizInput.autocomplete = 'off'; quizInput.maxLength = 3; quizInput.setAttribute('aria-label', t('答え'));
            quizInput.addEventListener('input', () => ready());
            const row = el('span', 'log-comment-quiz-row'); row.append(quizSum, quizInput);
            quiz.append(quizHead, row); f.append(quiz);
            setQuiz(data.quiz);
        }
        const foot = el('div', 'log-comment-foot');
        const whoEl = el('span', 'log-comment-who', me ? t('あなたの印：{mark}', { mark: mark(me) }) : t('最初の書き込みで、このブラウザー用の印（◆）を作ります。'));
        const send = el('button', 'log-comment-send', t('送る')); send.type = 'submit'; send.disabled = true;
        foot.append(whoEl, send);
        const status = el('p', 'log-comment-status'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        // Left empty by people (they never see it); a bot that fills every field gives itself away.
        const trap = el('div', 'log-comment-trap'); trap.setAttribute('aria-hidden', 'true');
        const hp = el('input'); hp.name = 'website'; hp.type = 'text'; hp.tabIndex = -1; hp.autocomplete = 'off';
        trap.append(hp);
        f.append(trap, foot, status);
        const ready = () => { send.disabled = !stamp && !(area && area.value.trim()) || !!(quiz && !quiz.hidden && !quizInput.value.trim()); };
        setReply = c => {
            replyTo = c;
            replying.hidden = !c;
            if (c) replyLabel.textContent = t('{who} さんへ返信', { who: who(c) });
            render();
            if (c) { f.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); (area || f.querySelector('.log-comment-stamp-btn'))?.focus({ preventScroll: true }); }
        };
        cancel.addEventListener('click', () => setReply(null));
        f.addEventListener('submit', async event => {
            event.preventDefault();
            if (send.disabled) return;
            const icon = f.querySelector('input[name="icon"]:checked')?.value || data.icons[0]?.key;
            store.set('nl-comment-icon', icon);
            const name = nameInput.value.trim();
            store.set('nl-comment-name', name);
            send.disabled = true; status.textContent = t('送っています…');
            // The ticket must be a moment old: someone very quick waits that moment here instead of meeting an error.
            const early = (data.wait || 2) * 1000 + 300 - (Date.now() - ticketAt);
            if (early > 0) await new Promise(r => setTimeout(r, early));
            try {
                const res = await fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-NagiLog': 'comment' },
                    body: JSON.stringify({ id, token: newKey(), icon, stamp, text: area ? area.value : '', name, re: replyTo ? replyTo.id : '', ticket: data.ticket, quiz: quizInput && area.value.trim() ? quizInput.value : '', website: hp.value }) });
                const out = await res.json().catch(() => ({}));
                if (out.ticket) { data.ticket = out.ticket; ticketAt = Date.now(); data.quiz = out.quiz; setQuiz(out.quiz); }
                if (!res.ok || !out.item) {
                    status.textContent = { busy: t('続けて書き込まれています。少し時間をおいてから送ってください。'), long: t('文章か名前が長すぎます。文章は{n}文字・数行、名前は{name}文字までにしてください。', { n: data.max, name: data.name_max || 16 }), full: t('この記事のコメントはいっぱいです。'), gone: t('返信先のコメントが見つかりませんでした。'), url: t('URLは書けません。'), held: t('承認待ちのコメントが多いため、管理者が確認するまで新しく送れません。'), many: t('この記事には、同じ回線からのスタンプがもう十分にあります。'), old: t('ページを開いてから時間がたったため、確認し直しました。もう一度送ってください。'), empty: t('スタンプを選ぶか、文章を書いてください。'), quiz: t('確認の答えが違います。足し算の答えを数字で入れてください。') }[out.error] || t('送れませんでした。ページを読み込み直してから、もう一度お試しください。');
                    if (out.error === 'quiz') quizInput.select();
                    ready(); return;
                }
                me = out.me; whoEl.textContent = t('あなたの印：{mark}', { mark: mark(me) });
                data.items.push(out.item);
                replyTo = null; replying.hidden = true; render();
                stamp = ''; f.querySelectorAll('.log-comment-stamps button').forEach(x => x.setAttribute('aria-pressed', 'false'));
                if (area) { area.value = ''; count.textContent = `0/${data.max}`; showQuiz(); }
                status.textContent = out.item.wait ? (out.item.text ? t('送りました。管理者が確認すると表示されます。') : t('送りました。名前を管理者が確認すると表示されます。')) : t('送りました。');
                document.getElementById('comment-' + out.item.id)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            } catch {
                status.textContent = t('送れませんでした。通信を確かめてから、もう一度お試しください。');
            }
            ready();
        });
        formSlot.replaceChildren(f);
    }
    let loading = null;
    function load() { return loading ??= fetchAll(); }
    async function fetchAll() {
        try {
            const res = await fetch(endpoint + '?id=' + encodeURIComponent(id), { credentials: 'same-origin', headers: key ? { 'X-NagiLog-Key': key } : {} });
            if (!res.ok) throw new Error(String(res.status));
            data = await res.json(); me = data.me || ''; ticketAt = Date.now();
            render(); form();
        } catch {
            list.replaceChildren(el('p', 'log-comments-note', t('コメントを読み込めませんでした。')));
        }
    }
    // "書き込む" beside the count: back to a new comment (not a reply) and down to the form, once it is there.
    box.querySelector('[data-comments-write]')?.addEventListener('click', event => {
        event.preventDefault();
        const go = () => { if (replyTo) setReply(null); (f || formSlot).scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }); };
        if (data) go();
        else { load().then(go); }
    });
    // Fetched when the block comes near the screen, so a reader who never scrolls there sends nothing.
    if ('IntersectionObserver' in window) {
        const io = new IntersectionObserver(entries => { if (entries.some(e => e.isIntersecting)) { io.disconnect(); load(); } }, { rootMargin: '600px 0px' });
        io.observe(box);
    } else load();
})();
