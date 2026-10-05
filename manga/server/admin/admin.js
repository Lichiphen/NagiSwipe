/*
 * NagiManga admin UI
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // Keep edits in each settings tab while switching between modules.
    const settingsTabs = $('[data-settings-tabs]');
    if (settingsTabs) {
        const tabs = $$('[data-settings-tab]', settingsTabs);
        settingsTabs.setAttribute('role', 'tablist');
        function select(tab, focus = false) {
            tabs.forEach(t => {
                const active = t === tab;
                t.setAttribute('role', 'tab'); t.setAttribute('aria-selected', String(active));
                t.id = 'settings-tab-' + t.dataset.settingsTab;
                t.setAttribute('aria-controls', 'settings-' + t.dataset.settingsTab);
                t.tabIndex = active ? 0 : -1;
                if (active) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
            });
            $$('[data-settings-panel]').forEach(p => { p.hidden = p.dataset.settingsPanel !== tab.dataset.settingsTab; p.setAttribute('role', 'tabpanel'); p.setAttribute('aria-labelledby', 'settings-tab-' + p.dataset.settingsPanel); });
            if (focus) tab.focus();
        }
        tabs.forEach((tab, i) => {
            tab.addEventListener('click', e => {
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
                e.preventDefault(); select(tab); history.replaceState(null, '', tab.href);
            });
            tab.addEventListener('keydown', e => {
                let next = i;
                if (e.key === 'ArrowRight') next = (i + 1) % tabs.length;
                else if (e.key === 'ArrowLeft') next = (i + tabs.length - 1) % tabs.length;
                else if (e.key === 'Home') next = 0;
                else if (e.key === 'End') next = tabs.length - 1;
                else return;
                e.preventDefault(); select(tabs[next], true); history.replaceState(null, '', tabs[next].href);
            });
        });
        select(tabs.find(t => t.hasAttribute('aria-current')) || tabs[0]);

        // --- Table of contents: a column beside the settings on PCs, a floating button and a dialog on phones ---
        const toc = $('[data-settings-toc]');
        if (toc) {
            const reduce = matchMedia('(prefers-reduced-motion: reduce)');
            const groups = tabs.map(tab => {
                const key = tab.dataset.settingsTab, panel = $('#settings-' + key);
                const items = panel ? $$(':scope > .card', panel).map((card, i) => {
                    const head = $(':scope > h2', card) || $(':scope > details > summary', card);
                    if (!head) return null;
                    if (!card.id) card.id = 'settings-' + key + '-' + (i + 1);
                    return {card, label: head.textContent.trim()};
                }).filter(Boolean) : [];
                return {tab, items};
            });
            const list = () => {
                const root = document.createElement('div');
                root.className = 'settings-toc-list';
                groups.forEach(({tab, items}) => {
                    const group = document.createElement('section');
                    group.className = 'settings-toc-group'; group.dataset.tocTab = tab.dataset.settingsTab;
                    const title = document.createElement('p');
                    title.className = 'settings-toc-tab'; title.textContent = tab.textContent.trim();
                    const ol = document.createElement('ol');
                    items.forEach(({card, label}) => {
                        const a = document.createElement('a');
                        a.href = '#' + card.id; a.textContent = label; a.dataset.tocTarget = card.id;
                        const li = document.createElement('li'); li.append(a); ol.append(li);
                    });
                    group.append(title, ol); root.append(group);
                });
                return root;
            };
            const sync = () => {
                const active = tabs.find(t => t.getAttribute('aria-selected') === 'true')?.dataset.settingsTab;
                $$('.settings-toc-group').forEach(g => g.classList.toggle('is-active', g.dataset.tocTab === active));
            };
            const go = id => {
                const card = document.getElementById(id);
                const group = groups.find(g => g.items.some(item => item.card === card));
                if (!card || !group) return;
                if (group.tab.getAttribute('aria-selected') !== 'true') select(group.tab);
                sync();
                history.replaceState(null, '', group.tab.href.split('#')[0] + '#' + id);
                card.scrollIntoView({behavior: reduce.matches ? 'auto' : 'smooth', block: 'start'});
                card.tabIndex = -1; card.focus({preventScroll: true});
            };
            toc.append(list()); toc.hidden = false;

            // Phones: a button floating at the bottom right opens the same list in a dialog.
            const fab = document.createElement('button');
            fab.type = 'button'; fab.className = 'settings-toc-fab'; fab.setAttribute('aria-haspopup', 'dialog');
            fab.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01"/></svg><span>目次</span>';
            const dialog = document.createElement('dialog');
            dialog.className = 'settings-toc-dialog'; dialog.setAttribute('aria-labelledby', 'settings-toc-title');
            dialog.innerHTML = '<div class="settings-toc-dialog-head"><h2 id="settings-toc-title">設定の目次</h2><button type="button" class="btn" data-toc-close>閉じる</button></div>';
            dialog.append(list());
            document.body.append(fab, dialog);
            fab.addEventListener('click', () => { sync(); dialog.showModal(); });
            dialog.addEventListener('click', e => {
                if (e.target === dialog || e.target.closest('[data-toc-close]')) { dialog.close(); return; }
                const link = e.target.closest('[data-toc-target]');
                if (!link) return;
                e.preventDefault(); dialog.close(); go(link.dataset.tocTarget);
            });
            toc.addEventListener('click', e => {
                const link = e.target.closest('[data-toc-target]');
                if (!link || e.ctrlKey || e.metaKey || e.shiftKey) return;
                e.preventDefault(); go(link.dataset.tocTarget);
            });
            tabs.forEach(tab => tab.addEventListener('click', () => setTimeout(sync)));
            sync();

            // The side list marks the block being read.
            if ('IntersectionObserver' in window) {
                const inView = new Set();
                const order = groups.flatMap(g => g.items.map(({card}) => card));
                const io = new IntersectionObserver(entries => {
                    entries.forEach(en => { if (en.isIntersecting) inView.add(en.target); else inView.delete(en.target); });
                    const best = order.find(card => inView.has(card) && card.offsetParent)?.id ?? null;
                    $$('[data-toc-target]', toc).forEach(a => { if (a.dataset.tocTarget === best) a.setAttribute('aria-current', 'true'); else a.removeAttribute('aria-current'); });
                }, {rootMargin: '0px 0px -55% 0px'});
                groups.forEach(g => g.items.forEach(({card}) => io.observe(card)));
            }
        }
    }
    $$('.nav-more').forEach(menu => {
        document.addEventListener('click', e => { if (!menu.contains(e.target)) menu.open = false; });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && menu.open) { menu.open = false; $('summary', menu).focus(); } });
    });

    // --- Copy buttons -------------------------------------------------------
    $$('.js-copy').forEach(btn => {
        btn.addEventListener('click', async () => {
            // The nearest box that holds a copy source (the button may sit in its own <p>)
            let scope = btn.parentElement;
            while (scope && !$('.copy-src', scope)) scope = scope.parentElement;
            const src = scope && $('.copy-src', scope);
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

    // --- Show / hide the current reader password ---------------------------
    $$('.js-pw-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = $('.js-pw', btn.parentNode);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? '隠す' : '表示';
        });
    });

    // --- Confirm dangerous forms ------------------------------------------
    $$('form.js-confirm').forEach(form => {
        form.addEventListener('submit', e => {
            if (!window.confirm(form.dataset.confirm || 'よろしいですか？')) e.preventDefault();
        });
    });
    // One dangerous button in a form that also saves (e.g. "いいねを削除" beside "数を保存").
    $$('[data-confirm-click]').forEach(btn => {
        btn.addEventListener('click', e => {
            if (!window.confirm(btn.dataset.confirmClick)) e.preventDefault();
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
        const urlOut = $('.js-share-url', share);
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
            // Same options as a plain URL (read.php?nagimanga=ID&...)
            const q = new URLSearchParams({ nagimanga: share.dataset.id, dir: dir.value });
            if (vertical) {
                if (vfit.value === 'webtoon') q.set('vertical', 'webtoon');
            } else {
                if (view.value === 'single') q.set('view', 'single');
                else if (cover.value === '0') q.set('cover', '0');
            }
            urlOut.value = share.dataset.endpoint + '?' + q.toString();
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

    // --- Light (small) versions: file i goes to page i -------------------------
    const dropL = $('.js-drop-light');
    if (dropL) {
        const inputL = $('.js-file-light', dropL);
        const progressL = $('.js-progress', dropL);
        const statusL = $('.js-status', dropL);
        let busyL = false;

        const uploadLight = async (files) => {
            if (busyL || !files.length) return;
            files.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true, sensitivity: 'base' }));
            const pages = $$('.js-pages .page').map(li => li.dataset.f);
            const n = Math.min(files.length, pages.length);
            if (files.length !== pages.length &&
                !window.confirm(`画像は ${files.length} 枚、ページは ${pages.length} ページです。1 ページ目から順に ${n} ページ分を割り当てますか？`)) {
                return;
            }
            busyL = true;
            progressL.hidden = false;
            progressL.max = n;
            progressL.value = 0;
            const errors = [];
            for (let i = 0; i < n; i++) {
                statusL.textContent = `アップロード中… ${i + 1} / ${n}（${files[i].name} → ${i + 1} ページ目）`;
                const fd = new FormData();
                fd.append('do', 'upload_light');
                fd.append('id', dropL.dataset.id);
                fd.append('f', pages[i]);
                fd.append('page', files[i]);
                fd.append('csrf', dropL.dataset.csrf);
                try {
                    const res = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json().catch(() => ({ ok: false, error: `エラー（${res.status}）` }));
                    if (!data.ok) errors.push(`${files[i].name}: ${data.error || 'エラー'}`);
                } catch (e) {
                    errors.push(`${files[i].name}: 通信エラー`);
                }
                progressL.value = i + 1;
            }
            busyL = false;
            if (errors.length) {
                statusL.textContent = `完了（${errors.length} 件失敗）: ` + errors.join(' / ');
                return;
            }
            location.reload();
        };

        inputL.addEventListener('change', () => uploadLight(Array.from(inputL.files)));
        ['dragenter', 'dragover'].forEach(t => dropL.addEventListener(t, e => {
            if (!e.dataTransfer || !Array.from(e.dataTransfer.types).includes('Files')) return;
            e.preventDefault();
            dropL.classList.add('is-over');
        }));
        ['dragleave', 'drop'].forEach(t => dropL.addEventListener(t, () => dropL.classList.remove('is-over')));
        dropL.addEventListener('drop', e => {
            if (!e.dataTransfer || !e.dataTransfer.files.length) return;
            e.preventDefault();
            uploadLight(Array.from(e.dataTransfer.files).filter(f => /^image\//.test(f.type)));
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
