/*
 * A COPY button on each code block of a post or fixed page (``` in Markdown), on the frame's top right corner
 * like the documentation site's. Posts added later ("もっと見る", the editor's preview) get one too.
 * MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    // Icons come from viewer/icons/ui.svg (its address is on <html data-icons>).
    const icon = (id, cls) => '<svg class="' + cls + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + document.documentElement.dataset.icons + '#' + id + '"/></svg>';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = text => i18n[text] ?? text;
    const COPIED_MS = 1800;

    async function writeText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            try { await navigator.clipboard.writeText(text); return true; } catch { /* fall back below */ }
        }
        const field = document.createElement('textarea');
        field.value = text; field.setAttribute('readonly', ''); field.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
        // iOS Safari copies only an explicit selection range.
        document.body.append(field); field.select(); field.setSelectionRange(0, text.length);
        let ok = false; try { ok = document.execCommand('copy'); } catch { ok = false; }
        field.remove(); return ok;
    }
    // The frame holds the code and its button, so the button stays on the corner however long the code is.
    function decorate(pre) {
        if (pre.parentElement?.classList.contains('log-code-block')) return;
        const frame = document.createElement('div');
        frame.className = 'log-code-block';
        pre.before(frame); frame.append(pre);
        const btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'log-code-copy';
        btn.setAttribute('aria-label', t('コードをコピー'));
        btn.innerHTML = icon('ui-copy', 'log-code-icon-copy') + icon('link-check', 'log-code-icon-done') + '<span class="log-code-label" aria-live="polite">COPY</span>';
        frame.append(btn);
    }
    const scan = root => root.querySelectorAll?.('pre.log-md-code').forEach(decorate);
    scan(document);
    new MutationObserver(records => records.forEach(r => r.addedNodes.forEach(node => {
        if (node.nodeType !== 1) return;
        if (node.matches('pre.log-md-code')) decorate(node); else scan(node);
    }))).observe(document.body, { childList: true, subtree: true });

    document.addEventListener('click', async event => {
        const btn = event.target.closest?.('.log-code-copy');
        const code = btn?.parentElement.querySelector('pre.log-md-code');
        if (!code) return;
        const ok = await writeText(code.textContent);
        const label = btn.querySelector('.log-code-label');
        clearTimeout(btn._timer);
        btn.classList.remove('is-copied', 'is-error');
        btn.classList.add(ok ? 'is-copied' : 'is-error');
        label.textContent = ok ? 'COPIED' : 'FAILED';
        btn._timer = setTimeout(() => { btn.classList.remove('is-copied', 'is-error'); label.textContent = 'COPY'; }, COPIED_MS);
    });
})();
