/* LOG list selection and taxonomy ordering. MIT (c) 2026 Lichiphen. */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    // "/" jumps to the post search, unless the user is already typing somewhere.
    document.addEventListener('keydown', event => {
        const search = document.querySelector('[data-log-search]');
        if (!search || event.key !== '/' || event.ctrlKey || event.metaKey || event.altKey || event.isComposing) return;
        if (event.target.closest?.('input, textarea, select, [contenteditable="true"], dialog[open]')) return;
        event.preventDefault(); search.focus(); search.select();
    });
    const deleteDialog = document.createElement('dialog');
    deleteDialog.className = 'log-delete-dialog';
    deleteDialog.setAttribute('aria-labelledby', 'log-delete-title');
    deleteDialog.setAttribute('aria-describedby', 'log-delete-message');
    deleteDialog.innerHTML = '<h2 id="log-delete-title">' + t('削除の確認') + '</h2><p id="log-delete-message"></p><p class="note">' + t('共有画像は残します。取り消しにはバックアップが必要です。') + '</p><div class="log-delete-actions"><button type="button" class="btn" data-delete-cancel autofocus>' + t('キャンセル') + '</button><button type="button" class="btn danger" data-delete-confirm>' + t('削除する') + '</button></div>';
    document.body.append(deleteDialog);
    let deleteAction = null;
    const confirmDelete = (message, action) => {
        deleteDialog.querySelector('#log-delete-message').textContent = message;
        deleteAction = action; deleteDialog.showModal();
    };
    deleteDialog.querySelector('[data-delete-cancel]').addEventListener('click', () => { deleteAction = null; deleteDialog.close(); });
    deleteDialog.addEventListener('cancel', () => { deleteAction = null; });
    deleteDialog.querySelector('[data-delete-confirm]').addEventListener('click', () => {
        const action = deleteAction; deleteAction = null; deleteDialog.close(); if (action) action();
    });
    document.querySelectorAll('form.js-confirm').forEach(form => {
        if (!['log_delete', 'log_media_delete'].includes(form.querySelector('[name="do"]')?.value)) return;
        form.addEventListener('submit', event => {
            event.preventDefault(); event.stopImmediatePropagation();
            confirmDelete(form.dataset.confirm || t('削除しますか？'), () => HTMLFormElement.prototype.submit.call(form));
        }, true);
    });
    const bulk = document.querySelector('[data-bulk-form]');
    if (bulk) {
        const toggle = document.querySelector('[data-bulk-toggle]');
        const list = document.querySelector('.log-post-list');
        const items = Array.from(document.querySelectorAll('[data-bulk-item]'));
        const all = bulk.querySelector('[data-bulk-all]');
        const submit = bulk.querySelector('[data-bulk-submit]');
        const update = () => {
            const selected = items.filter(input => input.checked);
            bulk.elements.posts.value = JSON.stringify(selected.map(input => ({id: input.value, revision: Number(input.dataset.revision)})));
            bulk.querySelector('[data-bulk-count]').textContent = t('{n}件を選択', { n: selected.length });
            submit.disabled = selected.length === 0;
            all.checked = items.length > 0 && selected.length === items.length;
            all.indeterminate = selected.length > 0 && selected.length < items.length;
            items.forEach(input => input.closest('.log-list-row').classList.toggle('log-row-selected', input.checked));
        };
        toggle.addEventListener('click', () => {
            const active = bulk.hidden;
            bulk.hidden = !active;
            toggle.setAttribute('aria-expanded', String(active));
            // While choosing, the same button ends the mode: say so and look different from the delete button.
            toggle.classList.toggle('log-bulk-on', active);
            toggle.querySelector('[data-bulk-label]').textContent = active ? t('選ぶのをやめる') : t('まとめて削除');
            list.classList.toggle('log-bulk-active', active);
            document.body.classList.toggle('log-bulk-mode', active);
            items.forEach(input => { input.closest('.log-bulk-choice').hidden = !active; if (!active) input.checked = false; });
            update();
            if (active) all.focus();
        });
        items.forEach(input => input.addEventListener('change', update));
        all.addEventListener('change', () => { items.forEach(input => { input.checked = all.checked; }); update(); });
        bulk.addEventListener('submit', event => {
            event.preventDefault();
            update();
            const count = items.filter(input => input.checked).length;
            if (count) confirmDelete(t('{n}件の記事と、その記事だけで使っていた画像を削除します。削除しますか？', { n: count }), () => { submit.disabled = true; HTMLFormElement.prototype.submit.call(bulk); });
        });
    }

    const manager = document.querySelector('[data-taxonomy-manager]');
    if (!manager) return;
    const status = manager.querySelector('[data-taxonomy-status]');
    const groups = Array.from(manager.querySelectorAll('[data-sort-kind]'));
    let busy = false, dragging = null, origin = null, before = null, pointer = null;
    const rows = group => Array.from(group.children);
    const buttons = () => groups.forEach(group => rows(group).forEach((row, i, all) => {
        row.querySelector('[data-sort-step="-1"]').disabled = busy || i === 0;
        row.querySelector('[data-sort-step="1"]').disabled = busy || i === all.length - 1;
        row.querySelector('.log-sort-handle').disabled = busy;
    }));
    const save = async (group, previous) => {
        if (rows(group).every((row, i) => row === previous[i])) { status.textContent = t('順番は変わっていません'); return; }
        busy = true; buttons(); status.textContent = t('順番を保存しています…');
        const data = new FormData();
        data.set('do', 'log_taxonomy_order');
        data.set('csrf', manager.querySelector('[name="csrf"]').value);
        data.set('revision', manager.dataset.revision);
        data.set('kind', group.dataset.sortKind);
        data.set('order', JSON.stringify(rows(group).map(row => row.dataset.sortId)));
        try {
            const response = await fetch(new URL('index.php', location.href), {method: 'POST', body: data, credentials: 'same-origin'});
            const result = await response.json();
            if (!response.ok || !result.ok || !Number.isInteger(result.revision)) throw new Error(result.error || t('保存できませんでした。画面を開き直してください'));
            manager.dataset.revision = String(result.revision);
            manager.querySelectorAll('[name="revision"]').forEach(input => { input.value = result.revision; });
            status.textContent = t('順番を保存しました');
        } catch (error) {
            previous.forEach(row => group.append(row));
            status.textContent = error.message || t('通信できませんでした。画面を開き直してください');
        } finally { busy = false; buttons(); }
    };
    const place = (target, y) => {
        if (!dragging || !target || target === dragging || target.parentElement !== origin) return;
        const box = target.getBoundingClientRect();
        origin.insertBefore(dragging, y < box.top + box.height / 2 ? target : target.nextSibling);
        if (pointer !== null) dragging.querySelector('.log-sort-handle').setPointerCapture(pointer);
    };
    const begin = row => {
        dragging = row; origin = row.parentElement; before = rows(origin);
        row.classList.add('log-sort-dragging');
        status.textContent = t('移動先へドラッグしてください');
    };
    const end = cancel => {
        if (!dragging) return;
        const group = origin, previous = before;
        dragging.classList.remove('log-sort-dragging');
        dragging = origin = before = null; pointer = null;
        if (cancel) { previous.forEach(row => group.append(row)); status.textContent = t('並び替えを取り消しました'); }
        else void save(group, previous);
    };
    manager.addEventListener('submit', event => { if (busy || dragging) { event.preventDefault(); status.textContent = t('並び替えの保存が終わってから、名前を保存してください'); } });
    manager.addEventListener('click', event => {
        const step = event.target.closest('[data-sort-step]');
        if (!step || busy || dragging) return;
        const row = step.closest('[data-sort-id]'), group = row.parentElement, previous = rows(group);
        if (step.dataset.sortStep === '-1' && row.previousElementSibling) group.insertBefore(row, row.previousElementSibling);
        else if (step.dataset.sortStep === '1' && row.nextElementSibling) group.insertBefore(row.nextElementSibling, row);
        void save(group, previous);
    });
    manager.querySelectorAll('.log-sort-handle').forEach(handle => {
        handle.draggable = false;
        handle.addEventListener('pointerdown', event => {
            if (event.button !== 0 || busy || dragging) return;
            event.preventDefault(); begin(handle.closest('[data-sort-id]')); pointer = event.pointerId; handle.setPointerCapture(pointer);
        });
        handle.addEventListener('pointermove', event => {
            if (pointer !== event.pointerId) return;
            place(document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-sort-id]'), event.clientY);
            if (event.clientY < 80) window.scrollBy(0, -16);
            else if (event.clientY > innerHeight - 80) window.scrollBy(0, 16);
        });
        handle.addEventListener('pointerup', event => { if (pointer === event.pointerId) end(false); });
        handle.addEventListener('pointercancel', event => { if (pointer === event.pointerId) end(true); });
        handle.addEventListener('lostpointercapture', () => { if (dragging && pointer !== null) end(true); });
    });
    manager.addEventListener('keydown', event => { if (event.key === 'Escape' && dragging) { event.preventDefault(); end(true); } });
    buttons();
})();
