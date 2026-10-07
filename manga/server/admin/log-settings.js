/* LOG theme and icon previews. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const form = document.querySelector('[data-log-settings]');
    if (!form) return;
    const themes = new Set(['light-blue', 'light-sage', 'light-paper', 'dark-navy', 'dark-charcoal', 'dark-plum']);
    form.querySelectorAll('[name="theme"]').forEach(input => input.addEventListener('change', () => {
        if (input.checked && themes.has(input.value)) document.documentElement.dataset.logTheme = input.value;
    }));
    [['icon', '.log-icon-preview', 'log-settings-avatar', t('選んだアイコン')], ['og_image', '.log-og-preview', '', t('選んだ紹介画像')]].forEach(([name, selector, className, alt]) => {
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

/*
 * Sidebar order and optional HTML blocks. The settings screen's one save button sends them:
 * form.nlSidebarItems() builds the JSON, and a "settings:change" event reports moves that fire no input event.
 */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const form = document.querySelector('[data-sidebar-manager]');
    if (!form) return;
    const group = form.querySelector('[data-sidebar-items]');
    const status = form.querySelector('[data-sidebar-status]');
    let dragging = null, before = null, pointer = null;
    const rows = () => Array.from(group.children);
    const dirty = () => { status.textContent = t('未保存の変更があります。画面の下の「設定を保存」で反映します。'); form.dispatchEvent(new Event('settings:change', {bubbles: true})); };
    const count = editor => { editor.closest('[data-sidebar-item]').querySelector('[data-sidebar-chip]').textContent = t('{n}件のリンク', { n: editor.querySelector('[data-link-items]').children.length }); };
    const preview = select => {
        const source = select.closest('.log-links-editor').querySelector('[data-link-icon-set]').content.querySelector(`[data-link-icon-source="${select.value}"] svg`);
        if (source) select.closest('[data-link-item]').querySelector('[data-link-preview]').replaceChildren(source.cloneNode(true));
    };
    const buttons = () => {
        rows().forEach((row, i, all) => {
            row.querySelector('[data-sidebar-step="-1"]').disabled = i === 0;
            row.querySelector('[data-sidebar-step="1"]').disabled = i === all.length - 1;
        });
        form.querySelectorAll('[data-link-items]').forEach(list => Array.from(list.children).forEach((row, i, all) => {
            row.querySelector('[data-link-step="-1"]').disabled = i === 0;
            row.querySelector('[data-link-step="1"]').disabled = i === all.length - 1;
        }));
    };
    const label = row => {
        if (!row || row.dataset.sidebarKind !== 'html') return;
        const text = row.querySelector('[data-sidebar-title]').value.trim() || t('HTML枠（見出しなし）');
        row.querySelector('[data-sidebar-label]').textContent = text;
        row.querySelector('.log-sort-handle').setAttribute('aria-label', t('{name}をドラッグして移動', { name: text }));
    };
    const end = cancel => {
        if (!dragging) return;
        dragging.classList.remove('log-sort-dragging');
        dragging = null; pointer = null;
        if (cancel) { before.forEach(row => group.append(row)); status.textContent = t('今回の並び替えを取り消しました。'); }
        else dirty();
        before = null; buttons();
    };
    const wire = row => {
        const handle = row.querySelector('.log-sort-handle');
        handle.addEventListener('pointerdown', event => {
            if (event.button !== 0 || dragging) return;
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
    form.addEventListener('invalid', event => {
        const editor = event.target.closest('.log-links-editor');
        if (editor) editor.open = true;
    }, true);
    form.addEventListener('input', event => { label(event.target.closest('[data-sidebar-item]')); dirty(); });
    // Hiding the login link: only after the owner confirms the admin URL is bookmarked, so nobody locks themselves out.
    const loginUrl = form.dataset.loginUrl;
    const loginSwitch = form.querySelector('[data-sidebar-id="login"] [data-sidebar-enabled]');
    if (loginUrl && loginSwitch && typeof HTMLDialogElement === 'function') {
        const el = (tag, className, text) => { const n = document.createElement(tag); if (className) n.className = className; if (text) n.textContent = text; return n; };
        const dialog = el('dialog', 'log-login-confirm');
        dialog.setAttribute('aria-labelledby', 'log-login-confirm-title');
        const title = el('h2', '', t('管理用URLをブックマークしましたか？')); title.id = 'log-login-confirm-title';
        const lead = el('p', '', t('「ログイン」のリンクを隠すと、公開ページから管理画面に入れなくなります。次のURLから入れるように、ブックマークしてから隠してください。'));
        const url = el('input', 'log-login-confirm-url'); url.readOnly = true; url.value = loginUrl; url.setAttribute('aria-label', t('管理用URL'));
        const copy = el('button', 'btn', t('URLをコピー')); copy.type = 'button';
        const open = el('a', 'btn', t('新しいタブで開く')); open.href = loginUrl; open.target = '_blank'; open.rel = 'noopener';
        const tools = el('div', 'log-login-confirm-tools'); tools.append(copy, open);
        const note = el('p', 'note', t('このURLは「設定 → 共通・安全 → ログイン URL」でも確認できます。')); note.setAttribute('role', 'status');
        const yes = el('button', 'btn danger', t('ブックマークした（隠す）')); yes.type = 'button';
        const no = el('button', 'btn primary', t('まだ（表示したままにする）')); no.type = 'button';
        const actions = el('div', 'log-login-confirm-actions'); actions.append(no, yes);
        dialog.append(title, lead, url, tools, note, actions);
        document.body.append(dialog);
        copy.addEventListener('click', async () => {
            try { await navigator.clipboard.writeText(loginUrl); note.textContent = t('コピーしました。ブックマークやメモに保存してください。'); }
            catch { url.select(); note.textContent = t('URLを選びました。Ctrl+C（スマホは長押し）でコピーしてください。'); }
        });
        yes.addEventListener('click', () => {
            loginSwitch.checked = false; dirty();
            status.textContent = t('「ログイン・管理ページ」を隠します。画面の下の「設定を保存」で反映します。');
            dialog.close();
        });
        no.addEventListener('click', () => dialog.close('keep'));
        dialog.addEventListener('cancel', () => { dialog.returnValue = 'keep'; });
        dialog.addEventListener('close', () => {
            if (dialog.returnValue === 'keep') status.textContent = t('「ログイン・管理ページ」は表示したままにしました。ブックマークしてから、もう一度オフにしてください。');
            dialog.returnValue = ''; loginSwitch.focus();
        });
        // Intercept before the switch changes; turning it back on needs no question.
        loginSwitch.addEventListener('click', event => {
            // During the click the box already shows its new state: checked means it is being turned on.
            if (loginSwitch.checked) return;
            event.preventDefault();
            note.textContent = t('このURLは「設定 → 共通・安全 → ログイン URL」でも確認できます。');
            dialog.showModal(); no.focus();
        });
    }
    form.addEventListener('change', event => { if (event.target.matches('[data-link-icon]')) preview(event.target); });
    form.addEventListener('keydown', event => { if (event.key === 'Escape' && dragging) { event.preventDefault(); end(true); } });
    form.addEventListener('click', event => {
        if (dragging) return;
        const linkAdd = event.target.closest('[data-link-add]');
        if (linkAdd) {
            const editor = linkAdd.closest('.log-links-editor');
            const row = editor.querySelector('[data-link-template]').content.firstElementChild.cloneNode(true);
            editor.querySelector('[data-link-items]').append(row);
            count(editor); dirty(); buttons(); row.querySelector('[data-link-label]').focus(); return;
        }
        const linkRemove = event.target.closest('[data-link-remove]');
        if (linkRemove) {
            const row = linkRemove.closest('[data-link-item]'), next = row.nextElementSibling || row.previousElementSibling;
            const editor = row.closest('.log-links-editor'), add = editor.querySelector('[data-link-add]');
            row.remove(); (next?.querySelector('[data-link-label]') || add).focus(); count(editor); dirty(); buttons(); return;
        }
        const linkStep = event.target.closest('[data-link-step]');
        if (linkStep) {
            const row = linkStep.closest('[data-link-item]'), list = row.parentElement;
            if (linkStep.dataset.linkStep === '-1' && row.previousElementSibling) list.insertBefore(row, row.previousElementSibling);
            else if (linkStep.dataset.linkStep === '1' && row.nextElementSibling) list.insertBefore(row.nextElementSibling, row);
            linkStep.focus(); dirty(); buttons(); return;
        }
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
    form.nlSidebarItems = () => JSON.stringify(rows().map(row => {
        const item = {id: row.dataset.sidebarId, kind: row.dataset.sidebarKind, enabled: row.querySelector('[data-sidebar-enabled]').checked};
        if (item.kind === 'html') Object.assign(item, {title: row.querySelector('[data-sidebar-title]').value, html: row.querySelector('[data-sidebar-html]').value, framed: row.querySelector('[data-sidebar-framed]').checked});
        if (item.kind === 'links') Object.assign(item, {icon_frame: row.querySelector('[data-links-frame]').checked, message: row.querySelector('[data-links-message]').value});
        if (item.kind === 'links') item.links = Array.from(row.querySelector('[data-link-items]').children).map(link => ({label: link.querySelector('[data-link-label]').value, url: link.querySelector('[data-link-url]').value, icon: link.querySelector('[data-link-icon]').value}));
        return item;
    }));
    buttons();
})();

/* RSS: the chosen range fills the URL field; the Copy button beside it copies it. */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const box = document.querySelector('[data-rss-builder]');
    if (!box) return;
    const select = box.querySelector('[data-rss-select]'), field = box.querySelector('[data-rss-url]');
    select.addEventListener('change', () => { field.value = select.value; });
})();

/*
 * Top menu: rows sorted like the sidebar (handle or arrow buttons), each with a name and a link.
 * Typing a name lists the fixed pages, categories and hashtags whose names contain it; choosing one fills in the link.
 * form.nlItems gives the JSON the settings screen's save button sends.
 */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    // The top menu and the footer links share this editor.
    document.querySelectorAll('[data-topmenu-manager]').forEach(setup);
    function setup(form) {
    const group = form.querySelector('[data-topmenu-items]');
    const status = form.querySelector('[data-topmenu-status]');
    const empty = form.querySelector('[data-topmenu-empty]');
    const template = form.closest('section').querySelector('template[data-topmenu-template]');
    let candidates = [];
    try { candidates = JSON.parse(form.dataset.candidates || '[]'); } catch { candidates = []; }
    // Folding for the search: width, case, and katakana to hiragana.
    const fold = text => text.normalize('NFKC').toLowerCase().replace(/[ァ-ヶ]/g, ch => String.fromCharCode(ch.charCodeAt(0) - 0x60));
    let dragging = null, before = null, pointer = null;
    const rows = () => Array.from(group.children);
    const dirty = () => { status.textContent = t('未保存の変更があります。画面の下の「設定を保存」で反映します。'); form.dispatchEvent(new Event('settings:change', {bubbles: true})); };
    const buttons = () => {
        rows().forEach((row, i, all) => {
            row.querySelector('[data-topmenu-step="-1"]').disabled = i === 0;
            row.querySelector('[data-topmenu-step="1"]').disabled = i === all.length - 1;
        });
        empty.hidden = rows().length > 0;
        form.querySelector('[data-topmenu-add]').disabled = rows().length >= 30;
    };
    const name = row => row.querySelector('[data-topmenu-label]').value.trim() || t('新しい項目');
    const relabel = row => {
        row.querySelector('.log-sort-handle').setAttribute('aria-label', t('{name}をドラッグして移動', { name: name(row) }));
        row.querySelector('[data-topmenu-enabled]').setAttribute('aria-label', t('{name}を表示する', { name: name(row) }));
        row.querySelector('[data-topmenu-remove]').setAttribute('aria-label', t('{name}を外す', { name: name(row) }));
    };
    const end = cancel => {
        if (!dragging) return;
        dragging.classList.remove('log-sort-dragging');
        dragging = null; pointer = null;
        if (cancel) { before.forEach(row => group.append(row)); status.textContent = t('今回の並び替えを取り消しました。'); }
        else dirty();
        before = null; buttons();
    };
    const wire = row => {
        const handle = row.querySelector('.log-sort-handle');
        handle.addEventListener('pointerdown', event => {
            if (event.button !== 0 || dragging) return;
            event.preventDefault(); dragging = row; before = rows(); pointer = event.pointerId;
            row.classList.add('log-sort-dragging'); handle.setPointerCapture(pointer);
        });
        handle.addEventListener('pointermove', event => {
            if (pointer !== event.pointerId) return;
            const target = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-topmenu-item]');
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
    // --- Suggestions under the name field ---
    let active = -1;
    const box = row => row.querySelector('[data-topmenu-suggest]');
    const close = row => {
        const list = box(row), input = row.querySelector('[data-topmenu-label]');
        list.hidden = true; list.replaceChildren(); active = -1;
        input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant');
    };
    const choose = (row, item) => {
        row.querySelector('[data-topmenu-label]').value = item.t;
        row.querySelector('[data-topmenu-url]').value = item.u;
        close(row); relabel(row); dirty();
        status.textContent = t('「{name}」（{kind}）のリンク先を入れました：{url}', { name: item.t, kind: item.k, url: item.u });
    };
    const suggest = row => {
        const input = row.querySelector('[data-topmenu-label]'), list = box(row);
        const query = fold(input.value.trim());
        const found = candidates.filter(c => !query || fold(c.t).includes(query)).slice(0, 8);
        list.replaceChildren(); active = -1;
        if (!found.length || document.activeElement !== input) { close(row); return; }
        found.forEach((item, i) => {
            const option = document.createElement('button');
            option.type = 'button'; option.className = 'log-topmenu-option'; option.tabIndex = -1;
            option.setAttribute('role', 'option'); option.id = row.dataset.topmenuId + '-option-' + i;
            const title = document.createElement('span'); title.textContent = item.t;
            const kind = document.createElement('small'); kind.textContent = item.k + ' · ' + item.u;
            option.append(title, kind);
            // mousedown keeps the focus in the field until the choice is made.
            option.addEventListener('mousedown', event => event.preventDefault());
            option.addEventListener('click', () => choose(row, item));
            list.append(option);
        });
        list.hidden = false; input.setAttribute('aria-expanded', 'true');
    };
    const mark = (row, step) => {
        const options = Array.from(box(row).children);
        if (!options.length) return;
        active = (active + step + options.length) % options.length;
        options.forEach((o, i) => o.setAttribute('aria-selected', String(i === active)));
        row.querySelector('[data-topmenu-label]').setAttribute('aria-activedescendant', options[active].id);
        options[active].scrollIntoView({block: 'nearest'});
    };
    rows().forEach(wire);
    form.addEventListener('input', event => {
        const row = event.target.closest('[data-topmenu-item]');
        if (!row) return;
        if (event.target.matches('[data-topmenu-label]')) { suggest(row); relabel(row); }
        dirty();
    });
    form.addEventListener('focusin', event => { if (event.target.matches('[data-topmenu-label]')) suggest(event.target.closest('[data-topmenu-item]')); });
    form.addEventListener('focusout', event => {
        if (!event.target.matches('[data-topmenu-label]')) return;
        const row = event.target.closest('[data-topmenu-item]');
        setTimeout(() => { if (document.activeElement !== row.querySelector('[data-topmenu-label]')) close(row); }, 0);
    });
    form.addEventListener('keydown', event => {
        if (event.key === 'Escape' && dragging) { event.preventDefault(); end(true); return; }
        if (!event.target.matches('[data-topmenu-label]') || event.isComposing) return;
        const row = event.target.closest('[data-topmenu-item]'), list = box(row);
        if (event.key === 'ArrowDown') { event.preventDefault(); if (list.hidden) suggest(row); mark(row, 1); }
        else if (event.key === 'ArrowUp' && !list.hidden) { event.preventDefault(); mark(row, -1); }
        else if (event.key === 'Enter' && !list.hidden && active >= 0) { event.preventDefault(); list.children[active].click(); }
        else if (event.key === 'Escape' && !list.hidden) { event.preventDefault(); close(row); }
    });
    form.addEventListener('click', event => {
        if (dragging) return;
        const step = event.target.closest('[data-topmenu-step]');
        if (step) {
            const row = step.closest('[data-topmenu-item]');
            if (step.dataset.topmenuStep === '-1' && row.previousElementSibling) group.insertBefore(row, row.previousElementSibling);
            else if (step.dataset.topmenuStep === '1' && row.nextElementSibling) group.insertBefore(row.nextElementSibling, row);
            buttons(); (step.disabled ? row.querySelector('[data-topmenu-step]:not(:disabled)') : step)?.focus(); dirty(); return;
        }
        const remove = event.target.closest('[data-topmenu-remove]');
        if (remove) {
            const row = remove.closest('[data-topmenu-item]'), next = row.nextElementSibling || row.previousElementSibling;
            row.remove(); buttons();
            (next?.querySelector('[data-topmenu-label]') || form.querySelector('[data-topmenu-add]')).focus();
            dirty(); status.textContent = t('「{name}」を外しました。保存するまでは、画面を読み込み直すと元に戻せます。', { name: name(row) });
            return;
        }
        if (event.target.closest('[data-topmenu-add]') && template) {
            const row = template.content.firstElementChild.cloneNode(true);
            const random = crypto.getRandomValues(new Uint8Array(6));
            row.dataset.topmenuId = 'tm-' + Array.from(random, b => b.toString(16).padStart(2, '0')).join('');
            group.append(row); wire(row); dirty(); buttons(); row.querySelector('[data-topmenu-label]').focus();
        }
    });
    form.nlItems = () => JSON.stringify(rows().map(row => ({
        id: row.dataset.topmenuId,
        label: row.querySelector('[data-topmenu-label]').value,
        url: row.querySelector('[data-topmenu-url]').value,
        enabled: row.querySelector('[data-topmenu-enabled]').checked,
    })));
    buttons();
    }
})();
