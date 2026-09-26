/*
 * NagiManga admin UI
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // --- Copy buttons -------------------------------------------------------
    $$('.js-copy').forEach(btn => {
        btn.addEventListener('click', async () => {
            const scope = btn.closest('p, .share, section') || document;
            const src = $('.copy-src', scope);
            if (!src) return;
            try {
                await navigator.clipboard.writeText(src.value);
            } catch (e) {
                src.select();
                document.execCommand('copy');
            }
            const label = btn.textContent;
            btn.textContent = 'コピーしました';
            setTimeout(() => { btn.textContent = label; }, 1500);
        });
    });

    // --- Confirm dangerous forms ------------------------------------------
    $$('form.js-confirm').forEach(form => {
        form.addEventListener('submit', e => {
            if (!window.confirm(form.dataset.confirm || 'よろしいですか？')) e.preventDefault();
        });
    });

    // --- data/ must not be reachable from the web --------------------------
    const probe = $('.js-probe');
    if (probe) {
        fetch(probe.dataset.probe, { cache: 'no-store', credentials: 'omit' })
            .then(r => (r.ok ? r.text() : ''))
            .then(t => { if (t.trim() === 'nm-probe') probe.hidden = false; })
            .catch(() => {});
    }

    // --- Share tag ---------------------------------------------------------
    const share = $('.js-share');
    if (share) {
        const esc = s => String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const dir = $('.js-share-dir', share);
        const view = $('.js-share-view', share);
        const cover = $('.js-share-cover', share);
        const vfit = $('.js-share-vfit', share);
        const label = $('.js-share-label', share);
        const out = $('.js-share-out', share);
        const preview = $('.js-preview', share);

        const update = () => {
            const vertical = dir.value === 'vertical';
            view.disabled = vertical;
            cover.disabled = vertical || view.value === 'single';
            // Only one of the two groups applies
            view.closest('label').hidden = vertical;
            cover.closest('label').hidden = vertical;
            vfit.closest('label').hidden = !vertical;
            let attrs = `data-nagimanga="${esc(share.dataset.id)}" data-endpoint="${esc(share.dataset.endpoint)}" data-direction="${esc(dir.value)}"`;
            if (vertical) {
                if (vfit.value === 'webtoon') attrs += ' data-vertical="webtoon"';
            } else {
                attrs += ` data-view="${esc(view.value)}"`;
                if (view.value !== 'single') attrs += ` data-cover="${esc(cover.value)}"`;
            }
            out.value = `<a href="#" ${attrs}>${esc(label.value || share.dataset.title)}</a>\n`
                + `<script src="${esc(share.dataset.script)}" defer></script>`;
            // Preview with the same options
            preview.dataset.direction = dir.value;
            preview.dataset.view = view.value;
            preview.dataset.cover = cover.value;
            preview.dataset.vertical = vfit.value;
        };
        [dir, view, cover, vfit, label].forEach(el => el.addEventListener('input', update));
        update();
    }

    // --- Upload ------------------------------------------------------------
    const drop = $('.js-drop');
    if (drop) {
        const input = $('.js-file', drop);
        const progress = $('.js-progress', drop);
        const status = $('.js-status', drop);
        const sortAfter = $('.js-sort-after', drop);
        let busy = false;

        const post = (fields) => {
            const fd = new FormData();
            Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
            fd.append('csrf', drop.dataset.csrf);
            return fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-NM-CSRF': drop.dataset.csrf } });
        };

        const submitForm = (fields) => {
            const f = document.createElement('form');
            f.method = 'post';
            f.action = 'index.php';
            Object.entries({ ...fields, csrf: drop.dataset.csrf }).forEach(([k, v]) => {
                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = k;
                i.value = v;
                f.appendChild(i);
            });
            document.body.appendChild(f);
            f.submit();
        };

        const upload = async (files) => {
            if (busy || !files.length) return;
            busy = true;
            // Send in file-name order (001, 002, … 010) so pages land in order
            files.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }));
            progress.hidden = false;
            progress.max = files.length;
            progress.value = 0;
            const errors = [];
            for (let i = 0; i < files.length; i++) {
                status.textContent = `アップロード中… ${i + 1} / ${files.length}（${files[i].name}）`;
                try {
                    const res = await post({ do: 'upload', id: drop.dataset.id, page: files[i] });
                    const data = await res.json().catch(() => ({ ok: false, error: `エラー（${res.status}）` }));
                    if (!data.ok) errors.push(`${files[i].name}: ${data.error || 'エラー'}`);
                } catch (e) {
                    errors.push(`${files[i].name}: 通信エラー`);
                }
                progress.value = i + 1;
            }
            busy = false;
            if (errors.length) {
                status.textContent = `完了（${errors.length} 件失敗）: ` + errors.join(' / ');
                return;
            }
            if (sortAfter.checked) submitForm({ do: 'sort_name', id: drop.dataset.id });
            else location.reload();
        };

        input.addEventListener('change', () => upload(Array.from(input.files)));
        ['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => {
            if (!e.dataTransfer || !Array.from(e.dataTransfer.types).includes('Files')) return;
            e.preventDefault();
            drop.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach(t => drop.addEventListener(t, () => drop.classList.remove('is-over')));
        drop.addEventListener('drop', e => {
            if (!e.dataTransfer || !e.dataTransfer.files.length) return;
            e.preventDefault();
            upload(Array.from(e.dataTransfer.files).filter(f => /^image\//.test(f.type)));
        });
    }

    // --- Reorder pages -----------------------------------------------------
    const list = $('.js-pages');
    const saveBtn = $('.js-save-order');
    if (list && saveBtn && drop) {
        let dragged = null;
        const renumber = () => $$('.page', list).forEach((li, i) => { $('.page-no', li).textContent = i + 1; });

        list.addEventListener('dragstart', e => {
            dragged = e.target.closest('.page');
            if (!dragged) return;
            dragged.classList.add('is-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', dragged.dataset.f);
        });
        list.addEventListener('dragend', () => {
            if (dragged) dragged.classList.remove('is-dragging');
            dragged = null;
        });
        list.addEventListener('dragover', e => {
            if (!dragged) return;
            e.preventDefault();
            const over = e.target.closest('.page');
            if (!over || over === dragged) return;
            const r = over.getBoundingClientRect();
            const after = (e.clientX - r.left) > r.width / 2;
            list.insertBefore(dragged, after ? over.nextSibling : over);
            renumber();
            saveBtn.hidden = false;
        });

        saveBtn.addEventListener('click', async () => {
            const order = $$('.page', list).map(li => li.dataset.f);
            saveBtn.disabled = true;
            const fd = new FormData();
            fd.append('do', 'order');
            fd.append('id', list.dataset.id);
            fd.append('order', JSON.stringify(order));
            fd.append('csrf', drop.dataset.csrf);
            try {
                const res = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                const data = await res.json();
                if (!data.ok) throw new Error(data.error || '');
                saveBtn.textContent = '保存しました';
                setTimeout(() => location.reload(), 600);
            } catch (e) {
                saveBtn.disabled = false;
                window.alert('保存できませんでした。' + (e.message || ''));
            }
        });
    }
})();
