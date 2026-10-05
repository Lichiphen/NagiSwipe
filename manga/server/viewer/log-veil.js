/* LOG content warnings for readers: open a veil, ask the age before R-18, and remember "do not ask again" per browser. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const LABEL = { sensitive: 'センシティブ', r18: 'R-18', r18g: 'R-18G' };
    // One LOG per folder: its choices never leak into another LOG on the same host.
    const KEY = 'nagilog-veil:' + location.pathname.replace(/[^/]*$/, '');
    const read = (store, key) => { try { return JSON.parse(store.getItem(key) || '{}') || {}; } catch { return {}; } };
    const write = (store, key, value) => { try { store.setItem(key, JSON.stringify(value)); } catch { /* storage may be disabled */ } };
    let prefs = read(localStorage, KEY);
    const session = read(sessionStorage, KEY);
    const main = document.querySelector('.log-site-main') || document.body;

    // A veiled picture keeps its full-size link aside, so the image viewer cannot page to it while it is covered.
    function reveal(details) {
        details.querySelectorAll('a[data-veil-href]').forEach(a => { a.href = a.dataset.veilHref; a.removeAttribute('data-veil-href'); });
        window.NagiSwipe?.init();
        window.NagiLogEmbeds?.load?.(details);
    }
    function apply(root = document) {
        root.querySelectorAll('details.log-veil:not([open])').forEach(d => { if (prefs[d.dataset.veil]) d.open = true; });
        root.querySelectorAll('.log-tile-thumb.is-veiled').forEach(t => t.classList.toggle('is-shown', !!prefs[t.dataset.veil]));
        resetLink();
    }
    function remember(level, on) {
        prefs = { ...prefs, [level]: on };
        if (!on) delete prefs[level];
        write(localStorage, KEY, prefs);
        apply();
    }

    // After the first veil of a kind opens: offer to stop asking in this browser (unless the age dialog just asked it).
    function offer(details) {
        const level = details.dataset.veil;
        if (prefs[level] || session['offered-' + level]) return;
        session['offered-' + level] = true; write(sessionStorage, KEY, session);
        const bar = document.createElement('p'); bar.className = 'log-veil-offer'; bar.setAttribute('role', 'status');
        const text = document.createElement('span'); text.textContent = 'このブラウザでは、次から' + LABEL[level] + 'を確認せずに表示しますか？';
        const yes = document.createElement('button'); yes.type = 'button'; yes.textContent = '確認せずに表示する';
        const no = document.createElement('button'); no.type = 'button'; no.textContent = '毎回確認する';
        yes.addEventListener('click', () => { remember(level, true); text.textContent = '次から' + LABEL[level] + 'を最初から表示します。'; yes.remove(); no.remove(); });
        no.addEventListener('click', () => bar.remove());
        bar.append(text, yes, no);
        details.after(bar);
    }

    document.addEventListener('toggle', e => {
        const d = e.target;
        if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('log-veil') || !d.open) return;
        reveal(d);
        if (d.dataset.opener === 'reader') { delete d.dataset.opener; offer(d); }
    }, true);

    // R-18 and R-18G are both for adults: ask the age once per tab (or never again for that kind, if the reader chose so) before opening.
    const ADULT = { r18: '性的な表現を含みます。', r18g: '強い流血・暴力・グロテスクな表現を含みます。' };
    let ageDialog = null, pending = null;
    function askAge(details) {
        pending = details;
        if (!ageDialog) {
            ageDialog = document.createElement('dialog'); ageDialog.className = 'log-age-dialog'; ageDialog.setAttribute('aria-labelledby', 'log-age-title');
            ageDialog.innerHTML = '<form method="dialog"><h2 id="log-age-title"></h2><p><span data-age-about></span><br>18歳未満の方は閲覧できません。<br>18歳以上ですか？</p>'
                + '<label><input type="checkbox" data-age-remember>このブラウザでは次から確認しない</label>'
                + '<div class="log-age-actions"><button value="yes" class="is-yes">18歳以上なので表示する</button><button value="no">表示しない</button></div></form>';
            document.body.append(ageDialog);
            ageDialog.addEventListener('close', () => {
                const d = pending; pending = null;
                if (ageDialog.returnValue !== 'yes' || !d) { d?.querySelector('summary')?.focus(); return; }
                session.age = true; write(sessionStorage, KEY, session);
                if (ageDialog.querySelector('[data-age-remember]').checked) remember(d.dataset.veil, true);
                d.open = true;
            });
        }
        const level = details.dataset.veil;
        ageDialog.querySelector('h2').textContent = LABEL[level] + 'の内容です';
        ageDialog.querySelector('[data-age-about]').textContent = ADULT[level];
        ageDialog.returnValue = '';
        ageDialog.querySelector('[data-age-remember]').checked = false;
        ageDialog.showModal();
        ageDialog.querySelector('.is-yes').focus();
    }
    document.addEventListener('click', e => {
        const summary = e.target.closest('details.log-veil > summary');
        if (!summary) return;
        const d = summary.parentElement;
        if (d.open) return;
        if (ADULT[d.dataset.veil] && !prefs[d.dataset.veil] && !session.age) { e.preventDefault(); askAge(d); return; }
        d.dataset.opener = 'reader';
    });

    // While the reader has chosen to see some kinds without asking, a small way back at the end of the page.
    function resetLink() {
        let row = document.querySelector('.log-veil-reset');
        const on = Object.keys(LABEL).filter(k => prefs[k]);
        if (!on.length) { row?.remove(); return; }
        if (!row) {
            row = document.createElement('p'); row.className = 'log-veil-reset';
            const button = document.createElement('button'); button.type = 'button'; button.textContent = '閲覧注意を毎回確認する';
            button.addEventListener('click', () => { prefs = {}; write(localStorage, KEY, prefs); location.reload(); });
            row.append(document.createElement('span'), ' ', button);
            main.append(row);
        }
        // Unchanged text is left alone, so the observer below is not woken by its own writes.
        const text = on.map(k => LABEL[k]).join('・') + 'を確認せずに表示しています。';
        if (row.firstChild.textContent !== text) row.firstChild.textContent = text;
    }

    apply();
    // Posts added by "もっと見る" follow the same choices.
    new MutationObserver(records => { if (records.some(r => r.addedNodes.length)) apply(main); }).observe(main, { childList: true, subtree: true });
})();
