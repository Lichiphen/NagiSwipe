/* Mail link chooser: copy the address or open the default mail app. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const links = document.querySelectorAll('a[data-mail]');
    if (!links.length || typeof HTMLDialogElement !== 'function') return;
    const el = (tag, className, text) => { const node = document.createElement(tag); if (className) node.className = className; if (text) node.textContent = text; return node; };
    const dialog = el('dialog', 'log-mail-dialog');
    dialog.setAttribute('aria-labelledby', 'log-mail-title');
    const title = el('h2', '', 'メールアドレス'); title.id = 'log-mail-title';
    const address = el('p', 'log-mail-address');
    const status = el('p', 'log-mail-status'); status.setAttribute('role', 'status');
    const copy = el('button', 'log-mail-primary', 'アドレスをコピー'); copy.type = 'button';
    const send = el('a', 'log-mail-send', 'メールアプリで送信');
    const close = el('button', 'log-mail-close', '閉じる'); close.type = 'button';
    const actions = el('div', 'log-mail-actions'); actions.append(copy, send);
    const body = el('div', 'log-mail-body'); body.append(title, address, actions, status, close);
    dialog.append(body);
    document.body.append(dialog);
    let current = '', opener = null;

    async function writeText(text) {
        try { await navigator.clipboard.writeText(text); return true; } catch { /* fall back below */ }
        const field = el('textarea'); field.value = text; field.setAttribute('readonly', ''); field.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
        dialog.append(field); field.select();
        let ok = false; try { ok = document.execCommand('copy'); } catch { ok = false; }
        field.remove(); return ok;
    }
    links.forEach(link => link.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        current = link.dataset.mail; opener = link;
        address.textContent = current; send.href = 'mailto:' + current; status.textContent = '';
        dialog.showModal(); copy.focus();
    }));
    copy.addEventListener('click', async () => {
        if (await writeText(current)) status.textContent = 'コピーしました。';
        else { status.textContent = 'コピーできませんでした。アドレスを選んでコピーしてください。'; getSelection()?.selectAllChildren(address); }
    });
    send.addEventListener('click', () => { setTimeout(() => dialog.close(), 0); });
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('close', () => { if (opener?.isConnected) opener.focus(); });
})();
