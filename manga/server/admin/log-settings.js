/* LOG theme and icon previews. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const form = document.querySelector('[data-log-settings]');
    if (!form) return;
    const themes = new Set(['light-blue', 'light-sage', 'light-paper', 'dark-navy', 'dark-charcoal', 'dark-plum']);
    form.querySelectorAll('[name="theme"]').forEach(input => input.addEventListener('change', () => {
        if (input.checked && themes.has(input.value)) document.documentElement.dataset.logTheme = input.value;
    }));
    [['icon', '.log-icon-preview', 'log-settings-avatar', '選んだアイコン'], ['og_image', '.log-og-preview', '', '選んだ紹介画像']].forEach(([name, selector, className, alt]) => {
        const file = form.querySelector(`[name="${name}"]`), preview = form.querySelector(selector);
        let url = '';
        file.addEventListener('change', () => {
            if (url) URL.revokeObjectURL(url);
            const image = file.files[0];
            if (!image) return;
            url = URL.createObjectURL(image);
            const img = document.createElement('img'); img.className = className; img.alt = alt; img.src = url;
            preview.replaceChildren(img);
        });
    });
})();

/* Sidebar order and optional HTML blocks; changes are saved together. */
(() => {
    'use strict';
    const form = document.querySelector('[data-sidebar-manager]');
    if (!form) return;
    const group = form.querySelector('[data-sidebar-items]');
    const status = form.querySelector('[data-sidebar-status]');
    let busy = false, dragging = null, before = null, pointer = null;
    const rows = () => Array.from(group.children);
    const dirty = () => { status.textContent = '未保存の変更があります。「サイドバーを保存」で反映します。'; };
    const buttons = () => rows().forEach((row, i, all) => {
        row.querySelector('[data-sidebar-step="-1"]').disabled = busy || i === 0;
        row.querySelector('[data-sidebar-step="1"]').disabled = busy || i === all.length - 1;
    });
    const label = row => {
        if (!row || row.dataset.sidebarKind !== 'html') return;
        const text = row.querySelector('[data-sidebar-title]').value.trim() || 'HTML枠（見出しなし）';
        row.querySelector('[data-sidebar-label]').textContent = text;
        row.querySelector('.log-sort-handle').setAttribute('aria-label', `${text}をドラッグして移動`);
    };
    const end = cancel => {
        if (!dragging) return;
        dragging.classList.remove('log-sort-dragging');
        dragging = null; pointer = null;
        if (cancel) { before.forEach(row => group.append(row)); status.textContent = '今回の並び替えを取り消しました。'; }
        else dirty();
        before = null; buttons();
    };
    const wire = row => {
        const handle = row.querySelector('.log-sort-handle');
        handle.addEventListener('pointerdown', event => {
            if (event.button !== 0 || busy || dragging) return;
            event.preventDefault(); dragging = row; before = rows(); pointer = event.pointerId;
            row.classList.add('log-sort-dragging'); handle.setPointerCapture(pointer);
        });
        handle.addEventListener('pointermove', event => {
            if (pointer !== event.pointerId) return;
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-sidebar-item]');
            if (target && target !== row && target.parentElement === group) {
                const box = target.getBoundingClientRect();
                group.insertBefore(row, event.clientY < box.top + box.height / 2 ? target : target.nextSibling);
                handle.setPointerCapture(pointer);
            }
            if (event.clientY < 80) window.scrollBy(0, -16);
            else if (event.clientY > innerHeight - 80) window.scrollBy(0, 16);
        });
        handle.addEventListener('pointerup', event => { if (pointer === event.pointerId) end(false); });
        handle.addEventListener('pointercancel', event => { if (pointer === event.pointerId) end(true); });
        handle.addEventListener('lostpointercapture', () => { if (dragging && pointer !== null) end(true); });
    };
    rows().forEach(wire);
    form.addEventListener('input', event => { label(event.target.closest('[data-sidebar-item]')); dirty(); });
    form.addEventListener('keydown', event => { if (event.key === 'Escape' && dragging) { event.preventDefault(); end(true); } });
    form.addEventListener('click', event => {
        if (busy || dragging) return;
        const step = event.target.closest('[data-sidebar-step]');
        if (step) {
            const row = step.closest('[data-sidebar-item]');
            if (step.dataset.sidebarStep === '-1' && row.previousElementSibling) group.insertBefore(row, row.previousElementSibling);
            else if (step.dataset.sidebarStep === '1' && row.nextElementSibling) group.insertBefore(row.nextElementSibling, row);
            dirty(); buttons(); return;
        }
        const remove = event.target.closest('[data-sidebar-remove]');
        if (remove) {
            const row = remove.closest('[data-sidebar-item]'), next = row.nextElementSibling || row.previousElementSibling;
            row.remove(); next?.querySelector('.log-sort-handle').focus(); dirty(); buttons(); return;
        }
        if (event.target.closest('[data-sidebar-add]')) {
            const key = form.querySelector('[data-sidebar-template-choice]').value;
            const template = document.querySelector(`template[data-sidebar-template="${key}"]`);
            if (!template) return;
            const row = template.content.firstElementChild.cloneNode(true);
            const random = crypto.getRandomValues(new Uint8Array(8));
            row.dataset.sidebarId = 'html-' + Array.from(random, b => b.toString(16).padStart(2, '0')).join('');
            row.querySelector('details').open = true;
            group.append(row); wire(row); dirty(); buttons(); row.querySelector('[data-sidebar-title]').focus();
        }
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || dragging) return;
        const items = rows().map(row => {
            const item = {id: row.dataset.sidebarId, kind: row.dataset.sidebarKind, enabled: row.querySelector('[data-sidebar-enabled]').checked};
            if (item.kind === 'html') Object.assign(item, {title: row.querySelector('[data-sidebar-title]').value, html: row.querySelector('[data-sidebar-html]').value, framed: row.querySelector('[data-sidebar-framed]').checked});
            return item;
        });
        const data = new FormData(form);
        data.set('revision', form.dataset.revision); data.set('items', JSON.stringify(items));
        busy = true; const controls = Array.from(form.querySelectorAll('input, textarea, select, button'));
        controls.forEach(control => { control.disabled = true; }); status.textContent = 'サイドバーを保存しています…';
        try {
            const response = await fetch(form.action, {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok || !Number.isInteger(result.revision) || !Array.isArray(result.items)) throw new Error(result.error || '保存できませんでした。画面を開き直してください');
            form.dataset.revision = String(result.revision);
            result.items.forEach(item => {
                if (item.kind !== 'html') return;
                const row = rows().find(row => row.dataset.sidebarId === item.id);
                row.querySelector('[data-sidebar-html]').value = item.html;
                row.querySelector('[data-sidebar-title]').value = item.title; label(row);
            });
            status.textContent = 'サイドバーを保存しました。HTMLは使える要素と属性だけを残しています。';
        } catch (error) { status.textContent = error.message || '通信できませんでした。変更内容はこの画面に残しています。'; }
        finally { busy = false; controls.forEach(control => { control.disabled = false; }); buttons(); }
    });
    buttons();
})();
