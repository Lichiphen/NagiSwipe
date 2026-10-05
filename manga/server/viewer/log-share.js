/* Post share row: phones use the OS share sheet, PCs a dialog (X / はてなブックマーク / LINE / Instagram); Copy copies "title\nURL". MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const ua = navigator.userAgent;
    const isMobile = /Android|iPhone|iPad|iPod|Mobile/i.test(ua) || (navigator.maxTouchPoints > 1 && /Macintosh/.test(ua));
    // X's in-app browser on iOS loops on the intent login page, so X there gets the copy guide instead.
    const isXInAppIOS = /Twitter for (iPhone|iPad)/i.test(ua);
    const COPIED_MS = 1800;
    // Services without a way to receive text: copy first, then the reader opens the site and pastes.
    const PASTE = {
        instagram: { url: 'https://www.instagram.com/', open: 'Instagramを開く', hint: '投稿やストーリーズに貼り付けてください。' },
        x: { url: 'https://x.com/', open: 'Xを開く', hint: '新しいポストに貼り付けてください。' },
    };
    const dialog = document.querySelector('.log-share-dialog');
    const preview = dialog?.querySelector('.log-share-preview');
    const status = dialog?.querySelector('.log-share-status');
    let current = '', opener = null;

    async function writeText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); return true; } catch { /* fall back below */ }
        }
        const field = document.createElement('textarea');
        field.value = text; field.setAttribute('readonly', ''); field.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
        (dialog?.open ? dialog : document.body).append(field); field.select();
        let ok = false; try { ok = document.execCommand('copy'); } catch { ok = false; }
        field.remove(); return ok;
    }
    function showCopyState(btn, ok) {
        const label = btn.querySelector('.log-share-label');
        clearTimeout(btn._timer);
        btn.classList.remove('is-copied', 'is-error');
        btn.classList.add(ok ? 'is-copied' : 'is-error');
        label.textContent = ok ? 'Copied' : 'Failed';
        btn._timer = setTimeout(() => { btn.classList.remove('is-copied', 'is-error'); label.textContent = 'Copy'; }, COPIED_MS);
    }
    function openWindow(url) {
        if (isMobile) { location.href = url; return; }
        // A 'noopener' feature would make window.open return null, so the opener is cut afterwards.
        const win = window.open(url, '_blank');
        if (win) win.opener = null; else location.href = url;
    }
    function openDialog(text, btn) {
        if (!dialog || typeof dialog.showModal !== 'function') { openWindow(btn.href); return; }
        current = text; opener = btn;
        preview.textContent = text; status.replaceChildren();
        dialog.showModal();
        dialog.querySelector('.log-share-service').focus();
    }
    async function pasteGuide(key) {
        const site = PASTE[key];
        const ok = await writeText(current);
        const link = document.createElement('a');
        link.href = site.url; link.target = '_blank'; link.rel = 'noopener noreferrer'; link.textContent = site.open;
        status.classList.toggle('is-error', !ok);
        status.replaceChildren(ok ? 'シェア文をコピーしました。' + site.hint : 'コピーできませんでした。上の文を選んでコピーしてください。', ' ', link);
        if (!ok) getSelection()?.selectAllChildren(preview);
    }
    function share(key) {
        const cut = current.lastIndexOf('\n');
        const title = current.slice(0, cut), url = current.slice(cut + 1);
        if (key === 'x' && !isXInAppIOS) openWindow('https://x.com/intent/tweet?text=' + encodeURIComponent(current));
        else if (key === 'hatena') openWindow('https://b.hatena.ne.jp/add?mode=confirm&url=' + encodeURIComponent(url) + '&title=' + encodeURIComponent(title));
        else if (key === 'line') openWindow(isMobile ? 'https://line.me/R/share?text=' + encodeURIComponent(current)
            : 'https://social-plugins.line.me/lineit/share?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(title));
        else pasteGuide(key);
    }

    if (dialog) {
        dialog.addEventListener('click', event => {
            if (event.target === dialog) { dialog.close(); return; }
            const service = event.target.closest('[data-share-service]');
            if (service) share(service.dataset.shareService);
            else if (event.target.closest('.log-share-close')) dialog.close();
        });
        dialog.addEventListener('close', () => opener?.focus());
    }
    // Delegated, so posts added by "もっと見る" work too; the copy button shows once script runs.
    document.documentElement.classList.add('log-share-ready');
    document.addEventListener('click', async event => {
        const btn = event.target.closest?.('.log-share-open, .log-share-copy');
        const text = btn?.closest('.log-share')?.dataset.shareText;
        if (!text) return;
        if (btn.classList.contains('log-share-copy')) { showCopyState(btn, await writeText(text)); return; }
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        if (isMobile && typeof navigator.share === 'function') {
            navigator.share({ text }).catch(err => { if (err.name !== 'AbortError') openDialog(text, btn); });
            return;
        }
        openDialog(text, btn);
    });
})();
