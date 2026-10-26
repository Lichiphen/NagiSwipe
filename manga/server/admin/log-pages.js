/*
 * Fixed page editor (Markdown): the marks from the tool row, pictures by button, drop or paste,
 * a preview drawn by the server, and saving with fetch so a refused save keeps the text.
 * On a PC it is laid out like the post editor: tools above the text, 書く / プレビュー tabs and a picture dock.
 * Also: the 画像一覧 page's "add pictures only" box and its copy buttons.
 * MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    // Icons come from viewer/icons/ui.svg (its address is on <html data-icons>).
    const icon = (id, cls = '') => '<svg' + (cls ? ' class="' + cls + '"' : '') + ' viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + document.documentElement.dataset.icons + '#' + id + '"/></svg>';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const $ = (s, root = document) => root.querySelector(s);
    const $$ = (s, root = document) => [...root.querySelectorAll(s)];
    const csrf = $('input[name="csrf"]')?.value;
    const endpoint = new URL('index.php', location.href);
    const types = /^image\/(jpeg|png|webp|gif|avif|bmp|svg\+xml)$/;
    const isImage = file => types.test(file.type) || /\.svg$/i.test(file.name);
    async function send(data, json = true) {
        data.set('csrf', csrf);
        const response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        let result;
        try { result = await response.json(); }
        catch { throw new Error(t('ログインが切れた可能性があります。本文をコピーしてから、ログインし直してください。')); }
        if (!response.ok || result.error) throw new Error(result.error || t('保存できませんでした。もう一度お試しください。'));
        return json ? result : null;
    }
    async function uploadOne(file) {
        const data = new FormData(); data.set('do', 'log_upload'); data.set('image', file);
        const { media } = await send(data);
        return media;
    }
    // The public address of a LOG picture; pages and the sidebar use it as is.
    const publicUrl = media => './?media=' + media.id + '&format=image.' + String(media.f).split('.').pop();

    // --- Copy buttons and the upload box on 画像一覧 ---
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-copy-text]');
        if (!button) return;
        const label = button.querySelector('span'), before = label?.textContent;
        try { await navigator.clipboard.writeText(button.dataset.copyText); if (label) label.textContent = t('コピーしました'); }
        catch { window.prompt(t('コピーしてください'), button.dataset.copyText); }
        if (label) setTimeout(() => { label.textContent = before; }, 1600);
    });
    // メディア一覧: how a video plays, a song's cover and loop (dialogs of log-av.js); the list is read again after saving.
    document.addEventListener('click', async event => {
        const button = event.target.closest('[data-av-edit]');
        if (!button || !window.NagiLogAV?.playDialog) return;
        const m = JSON.parse(button.dataset.av);
        const media = { ...m, url: new URL(m.url, endpoint).href, thumb: m.thumb ? new URL(m.thumb, endpoint).href : '' };
        const open = button.dataset.avEdit === 'video' ? window.NagiLogAV.playDialog : window.NagiLogAV.tagDialog;
        if (await open(media, { endpoint: endpoint.href, csrf })) location.reload();
    });
    const box = $('[data-media-upload]');
    if (box) {
        const input = $('[data-media-upload-input]', box), status = $('[data-media-upload-status]', box);
        let busy = false;
        async function add(files) {
            const list = Array.from(files);
            if (!list.length || busy) return;
            const av = f => !!window.NagiLogAV?.isAV(f);
            const bad = list.filter(f => !isImage(f) && !av(f));
            const good = list.filter(f => isImage(f) || av(f));
            if (!good.length) { status.textContent = t('画像・動画・音声のファイルを選んでください。'); return; }
            busy = true; box.classList.add('is-busy');
            const errors = bad.map(f => t('{name}：対応していない形式です', { name: f.name }));
            let done = 0;
            for (const file of good) {
                const step = t('追加しています…（{i} / {n}）', { i: done + 1, n: good.length });
                status.textContent = step; status.classList.add('log-busy');
                try {
                    if (av(file)) {
                        const csrf = $('input[name="csrf"]').value;
                        await window.NagiLogAV.upload(file, { endpoint: new URL('index.php', location.href).href, csrf, onProgress: (ratio, phase) => {
                            status.textContent = step + ' ' + (phase === 'measure' ? t('{name} を調べています…', { name: file.name }) : t('{name} を送っています…（{p}%）', { name: file.name, p: Math.floor(ratio * 100) }));
                        } });
                    } else await uploadOne(file);
                    done++;
                }
                catch (e) { errors.push(t('{name}：{message}', { name: file.name, message: e.message })); }
            }
            busy = false; box.classList.remove('is-busy'); input.value = ''; status.classList.remove('log-busy');
            if (done && !errors.length) { status.textContent = t('{n}件追加しました。一覧を読み込み直しています…', { n: done }); location.href = 'index.php?p=log_media'; return; }
            status.textContent = (done ? t('{n}件追加しました。', { n: done }) : '') + errors.join(' / ') + (done ? t('（一覧は読み込み直すと出ます）') : '');
        }
        input.addEventListener('change', () => add(input.files));
        const files = e => [...(e.dataTransfer?.types || [])].includes('Files');
        let depth = 0;
        // Anywhere on the page except a card (dropping on a card replaces that picture).
        document.addEventListener('dragenter', e => { if (files(e) && !e.target.closest?.('[data-media-card]')) { depth++; box.classList.add('is-over'); } });
        document.addEventListener('dragleave', e => { if (files(e) && --depth <= 0) { depth = 0; box.classList.remove('is-over'); } });
        document.addEventListener('dragover', e => { if (files(e) && !e.target.closest?.('[data-media-card]')) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
        document.addEventListener('drop', e => {
            depth = 0; box.classList.remove('is-over');
            if (!files(e) || e.target.closest?.('[data-media-card]')) return;
            e.preventDefault(); add(e.dataTransfer.files);
        });
    }

    // --- The fixed page editor ---
    // Laid out like the post editor (log-editor.js): on a PC or sideways the tool row sits above the text, the preview is a
    // 書く / プレビュー tab in the text's place, the page's pictures and the picture list stay open in a dock on the left,
    // and 本文へ beside the heading goes back to writing. On a phone held upright the row comes right under the text and
    // the preview opens over the whole screen at the size readers see.
    const form = $('[data-page-editor]');
    if (!form) return;
    const card = form.closest('.log-page-editor') || form;
    const body = $('[data-md-body]', form), status = $('[data-md-status]', form);
    const preview = $('[data-md-preview-body]', form), previewButton = $('[data-md-preview]', form);
    const wide = window.matchMedia('(orientation: landscape) and (min-width: 640px)');
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)');
    const dock = $('[data-media-dock]', card), toBody = $('[data-to-body]', card);
    const toolzone = $('[data-toolzone]', form), tabsRow = $('[data-compose-tabs]', form);
    const toolsScroll = $('[data-tools-scroll]', form), tools = $('.log-compose-tools', form);
    const say = (text, error = false) => { status.textContent = text; status.classList.toggle('is-error', error); status.classList.toggle('log-busy', !error && /…(?:（[^）]*）)?$/.test(text)); };
    let dirty = false, saving = false;
    form.addEventListener('input', () => { dirty = true; });
    window.addEventListener('beforeunload', e => { if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; } });
    const previewing = () => form.classList.contains('is-previewing');
    // Put text at the caret (or around the selection) and keep the browser's undo history where it can.
    // With the dock open the page stays where it is; the preview tab gives the text back first.
    function insert(before, after = '', placeholder = '') {
        if (previewing()) showPreview(false);
        body.focus({ preventScroll: !!card.dataset.dock });
        const start = body.selectionStart, end = body.selectionEnd;
        const chosen = body.value.slice(start, end) || placeholder;
        const text = before + chosen + after;
        if (document.activeElement !== body || !document.execCommand?.('insertText', false, text)) body.setRangeText(text, start, end, 'end');
        body.setSelectionRange(start + before.length, start + before.length + chosen.length);
        dirty = true;
    }
    // Pictures start on a line of their own.
    const lineStart = () => { const v = body.value, at = body.selectionStart; return at === 0 || v[at - 1] === '\n' ? '' : '\n'; };
    // B, I and link on the row; 装飾 opens the dialog with headings, lists and the rest (NagiLogFormat in log-editor.js).
    form.addEventListener('click', event => {
        const tool = event.target.closest('[data-md]');
        if (tool) { if (previewing()) showPreview(false); window.NagiLogFormat?.apply(tool.dataset.md, body); }
        if (event.target.closest('[data-md-menu]')) { if (previewing()) showPreview(false); window.NagiLogFormat?.open(body, { levels: [2, 3, 4] }); }
    });
    // ? opens the Markdown table under the text.
    const help = $('#log-page-help', form), helpButton = $('[data-md-help]', form);
    helpButton?.addEventListener('click', () => { help.open = !help.open; if (help.open) help.scrollIntoView({ block: 'nearest', behavior: reduce.matches ? 'auto' : 'smooth' }); });
    help?.addEventListener('toggle', () => helpButton?.setAttribute('aria-expanded', String(help.open)));
    // Pictures
    const picker = $('[data-md-upload-input]', form);
    $('[data-md-upload]', form).addEventListener('click', () => picker.click());
    $('[data-dock-upload]', card)?.addEventListener('click', () => picker.click());
    async function pictures(files) {
        const list = Array.from(files).filter(isImage);
        if (!list.length) { say(t('JPEG・PNG・WebP・GIF・AVIF・BMP・SVGの画像を選んでください。'), true); return; }
        const lines = []; const errors = [];
        if (card.dataset.dock) dockTab('page');
        for (const [i, file] of list.entries()) {
            say(t('画像をアップロードしています…（{i} / {n}）', { i: i + 1, n: list.length }));
            try {
                const media = await uploadOne(file);
                known[media.id] = media;
                lines.push('![' + String(media.alt || '').replace(/[[\]]/g, '') + '](' + publicUrl(media) + ')');
            } catch (e) { errors.push(t('{name}：{message}', { name: file.name, message: e.message })); }
        }
        if (lines.length) {
            insert(lineStart() + lines.join('\n') + '\n'); drawStrip();
            // New pictures join the top of the dock's list (unless it shows a search).
            if (dockList.loaded && !dockList.search.value) dockList.load(true);
        }
        say(errors.length ? errors.join(' / ') : t('画像を入れました。[ ] の中は画像の説明（alt）です。'), errors.length > 0);
    }
    picker.addEventListener('change', () => { pictures(picker.files); picker.value = ''; });
    // Drop anywhere on the editor card (the dock too); paste into the text.
    const hasFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
    let depth = 0;
    card.addEventListener('dragenter', e => { if (hasFiles(e)) { depth++; card.classList.add('is-over'); } });
    card.addEventListener('dragleave', e => { if (hasFiles(e) && --depth <= 0) { depth = 0; card.classList.remove('is-over'); } });
    card.addEventListener('dragover', e => { if (hasFiles(e)) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; } });
    card.addEventListener('drop', e => {
        depth = 0; card.classList.remove('is-over');
        if (!e.dataTransfer?.files.length) return;
        e.preventDefault(); pictures(e.dataTransfer.files);
    });
    body.addEventListener('paste', e => {
        const files = Array.from(e.clipboardData?.files || []);
        if (files.length) { e.preventDefault(); pictures(files); }
    });
    // Outside the editor a dropped file must not open in the browser and leave the page.
    ['dragover', 'drop'].forEach(type => document.addEventListener(type, e => { if (!e.defaultPrevented && hasFiles(e)) e.preventDefault(); }));

    const known = {};
    // --- The pictures in the text, as thumbnails under it (like the post editor), or in the dock's first tab ---
    const strip = $('[data-md-images]', form);
    const imageLine = /!\[[^\]\n]*\]\(\s*<?[^\s)>]*[?&]media=([a-f0-9]{16})[^\s)>]*>?(?:\s+"[^"]*")?\s*\)/g;
    const tile = (cls, label, inner) => { const b = document.createElement('button'); b.type = 'button'; b.className = cls; b.setAttribute('aria-label', label); b.title = label; b.innerHTML = inner; return b; };
    let drawing = 0, pictureCount = 0;
    async function drawStrip() {
        const found = [...body.value.matchAll(imageLine)].map(m => ({ id: m[1], at: m.index, text: m[0] }));
        const missing = [...new Set(found.map(f => f.id))].filter(id => !known[id]);
        const ticket = ++drawing;
        if (missing.length) {
            try {
                const r = await fetch(new URL('index.php?p=log_media_json&ids=' + missing.join(','), location.href), { credentials: 'same-origin' });
                (await r.json()).items.forEach(m => { known[m.id] = m; });
            } catch { /* the tile shows without a picture */ }
            if (ticket !== drawing) return;
        }
        strip.replaceChildren();
        found.forEach(f => {
            const m = known[f.id];
            const item = document.createElement('div'); item.className = 'log-page-image';
            const show = tile('log-page-image-thumb', t('本文のこの画像の行を選ぶ'), '');
            if (m) { const img = document.createElement('img'); img.src = m.thumb; img.alt = ''; show.append(img); } else show.textContent = '?';
            show.addEventListener('click', () => {
                if (previewing()) showPreview(false);
                body.focus({ preventScroll: !!card.dataset.dock }); body.setSelectionRange(f.at, f.at + f.text.length); scrollToCaret();
            });
            const remove = tile('log-page-image-remove', t('この画像を本文から外す'), icon('close'));
            remove.addEventListener('click', () => {
                const v = body.value, at = v.indexOf(f.text);
                if (at < 0) return;
                if (previewing()) showPreview(false);
                const end = at + f.text.length + (v[at + f.text.length] === '\n' ? 1 : 0);
                body.focus({ preventScroll: !!card.dataset.dock }); body.setSelectionRange(at, end);
                if (!document.execCommand?.('insertText', false, '')) body.setRangeText('', at, end, 'start');
                dirty = true; drawStrip(); say(t('画像を本文から外しました。画像そのものは画像一覧に残ります。'));
            });
            item.append(show, remove); strip.append(item);
        });
        const add = tile('log-page-image-add', t('画像を追加'), icon('plus'));
        add.addEventListener('click', () => picker.click());
        const pick = tile('log-page-image-add', t('画像一覧から選ぶ'), icon('stack'));
        pick.addEventListener('click', choose);
        strip.append(add, pick);
        pictureCount = found.length;
        dockCount();
    }
    // Bring the chosen line into view inside the text box.
    function scrollToCaret() {
        const before = body.value.slice(0, body.selectionStart).split('\n').length - 1;
        const line = parseFloat(getComputedStyle(body).lineHeight) || 24;
        body.scrollTop = Math.max(0, before * line - body.clientHeight / 3);
    }
    let stripTimer = 0;
    body.addEventListener('input', () => { clearTimeout(stripTimer); stripTimer = setTimeout(drawStrip, 250); });

    // --- 画像一覧: in a dialog (a phone held upright) and in the dock (a PC or sideways) ---
    function catalog(box, picked) {
        const list = { page: 1, generation: 0, loaded: false, search: $('[data-pick-search]', box) };
        const grid = $('[data-pick-grid]', box), more = $('[data-pick-more]', box), note = $('[data-pick-status]', box);
        list.load = async reset => {
            const ticket = ++list.generation;
            list.loaded = true;
            if (reset) { list.page = 1; grid.replaceChildren(); }
            const query = list.search.value.trim();
            note.textContent = t('読み込んでいます…'); note.classList.add('log-busy'); more.hidden = true;
            try {
                const url = new URL('index.php', location.href);
                url.search = new URLSearchParams({ p: 'log_media_json', page: String(list.page), q: query, images: '1' }).toString();
                const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
                if (r.redirected || !(r.headers.get('Content-Type') || '').includes('application/json')) throw new Error('login');
                const data = await r.json();
                if (ticket !== list.generation) return;
                data.items.forEach(m => {
                    known[m.id] = m;
                    const b = tile('log-page-pick', t('{name}を本文に入れる', { name: m.alt || t('画像') }), '');
                    const img = document.createElement('img'); img.src = m.thumb; img.alt = ''; img.loading = 'lazy';
                    const name = document.createElement('span'); name.textContent = m.alt || t('画像');
                    b.append(img, name);
                    b.addEventListener('click', () => {
                        picked();
                        insert(lineStart() + '![' + String(m.alt || '').replace(/[[\]]/g, '') + '](' + publicUrl(m) + ')\n');
                        drawStrip(); say(t('画像を入れました。[ ] の中は画像の説明（alt）です。'));
                    });
                    grid.append(b);
                });
                more.hidden = !data.more; list.page++;
                note.textContent = grid.children.length ? t('選ぶと、本文のカーソルの位置に入ります。') : (query ? t('見つかりませんでした。') : t('まだ画像がありません。「画像を追加」から追加できます。'));
            } catch { if (ticket === list.generation) note.textContent = t('読み込めませんでした。ログインが切れた可能性があります。'); }
            finally { if (ticket === list.generation) note.classList.remove('log-busy'); }
        };
        let timer = 0;
        list.search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => list.load(true), 300); });
        more.addEventListener('click', () => list.load(false));
        return list;
    }
    const dialog = $('[data-md-picker]');
    const pickerList = catalog(dialog, () => dialog.close());
    // The dock stays open beside the editor, so a pick leaves it as it is.
    const dockList = catalog(dock, () => {});
    function openPicker() { dialog.showModal(); if (!pickerList.loaded) pickerList.load(true); pickerList.search.focus(); }
    // 画像一覧から選ぶ: the dock's list when the dock is there, the dialog otherwise.
    function choose() {
        if (!card.dataset.dock) { openPicker(); return; }
        dockTab('library'); dockList.search.focus({ preventScroll: true });
        dock.classList.remove('is-called'); void dock.offsetWidth; dock.classList.add('is-called');
    }
    $('[data-md-pick]', form).addEventListener('click', choose);
    $('[data-pick-close]', dialog).addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
    dock.addEventListener('animationend', () => dock.classList.remove('is-called'));

    // --- The dock: the page's pictures (the strip, moved in) and 画像一覧 ---
    function dockTab(name) {
        $$('[role="tab"][data-dock-tab]', dock).forEach(tab => { const on = tab.dataset.dockTab === name; tab.setAttribute('aria-selected', String(on)); tab.tabIndex = on ? 0 : -1; });
        $$('[data-dock-pane]', dock).forEach(pane => { pane.hidden = pane.dataset.dockPane !== name; });
        if (name === 'library' && !dockList.loaded) dockList.load(true);
        awayCheck();
    }
    $$('[data-dock-tab]', dock).forEach(button => button.addEventListener('click', () => {
        dockTab(button.dataset.dockTab);
        if (button.dataset.dockTab === 'library' && !button.matches('[role="tab"]')) dockList.search.focus({ preventScroll: true });
    }));
    const dockTabs = $$('[role="tab"][data-dock-tab]', dock);
    dockTabs.forEach(tab => tab.addEventListener('keydown', e => {
        if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
        e.preventDefault();
        const next = dockTabs[(dockTabs.indexOf(tab) + 1) % dockTabs.length];
        next.focus(); next.click();
    }));
    // The first tab's count, with a small bump when it changes, and its note while the page has no pictures.
    function dockCount() {
        const count = $('[data-dock-count]', dock), n = String(pictureCount);
        if (count.textContent !== n) {
            const first = !dock.dataset.counted;
            count.textContent = n; dock.dataset.counted = '1';
            if (!first) { count.classList.remove('is-bump'); void count.offsetWidth; count.classList.add('is-bump'); }
        }
        $('[data-dock-empty]', dock).hidden = pictureCount > 0;
        strip.hidden = !!card.dataset.dock && !pictureCount;
    }

    // --- Where things sit: a PC or sideways, or a phone held upright ---
    const toolzoneHome = document.createComment('tools'), stripHome = document.createComment('images'), previewHome = document.createComment('preview');
    toolzone.before(toolzoneHome); strip.before(stripHome); preview.before(previewHome);
    function arrange() {
        form.classList.toggle('is-wide', wide.matches);
        if (wide.matches && sheet.open) sheet.close();
        if (wide.matches) tabsRow.before(toolzone); else toolzoneHome.after(toolzone);
        const mode = wide.matches ? 'grid' : '';
        if ((card.dataset.dock || '') !== mode) {
            if (mode) { card.dataset.dock = mode; $('[data-dock-pane="page"]', dock).append(strip); }
            else { delete card.dataset.dock; stripHome.after(strip); }
        }
        dockCount(); toolsHint(); awayCheck();
    }

    // --- Preview ---
    // Phones held upright: over the whole screen at the size readers see, with 閉じる kept at the bottom. It is not
    // modal, so the image viewer opened from it still comes on top; Escape and Tab are handled here instead.
    const sheet = document.createElement('dialog');
    sheet.className = 'log-preview-sheet';
    sheet.setAttribute('aria-labelledby', 'log-preview-sheet-title');
    sheet.innerHTML = '<div class="log-preview-sheet-head"><h2 id="log-preview-sheet-title">' + icon('tool-preview', 'log-tool-icon') + t('プレビュー') + '</h2><p>' + t('公開したときの大きさで表示しています') + '</p></div>'
        + '<div class="log-preview-sheet-body"></div><div class="log-preview-sheet-foot"><button type="button" class="btn primary" data-preview-close>' + icon('close', 'log-tool-icon') + t('閉じて編集に戻る') + '</button></div>';
    document.body.append(sheet);
    const tabs = $$('[data-compose-tab]', form);
    // The server sends the title and the page as <h1> + body; they are laid out as on the public page.
    function fill(html) {
        const box = document.createElement('div'); box.innerHTML = html;
        const title = box.querySelector(':scope > h1');
        title?.remove();
        const text = document.createElement('div'); text.className = 'log-page-body';
        text.append(...box.childNodes);
        preview.replaceChildren(...(title ? [title] : []), text);
    }
    async function openPreview() {
        const data = new FormData(); data.set('do', 'log_page_preview'); data.set('title', form.elements.title.value); data.set('body', body.value);
        say(t('プレビューを作っています…'));
        try {
            const { html } = await send(data);
            fill(html); showPreview(true);
            window.NagiSwipe?.init();
            window.NagiLogEmbeds?.load?.(preview);
            say(t('プレビューです。公開ページでは、ページの幅の設定に合わせて表示します。'));
        } catch (e) { say(e.message, true); }
    }
    // As a tab it keeps the text's height and scrolls inside.
    function showPreview(on) {
        if (on && !previewing()) preview.style.setProperty('--log-preview-height', Math.max(body.offsetHeight, 320) + 'px');
        preview.hidden = !on; form.classList.toggle('is-previewing', on);
        previewButton.setAttribute('aria-pressed', String(on));
        tabs.forEach(tab => {
            const selected = (tab.dataset.composeTab === 'preview') === on;
            tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
        });
        if (on && !wide.matches && !sheet.open) {
            preview.classList.replace('log-page-preview', 'log-post'); preview.classList.add('log-page');
            $('.log-preview-sheet-body', sheet).append(preview);
            document.documentElement.classList.add('log-preview-open');
            sheet.show(); sheet.scrollTop = 0;
            $('[data-preview-close]', sheet).focus({ preventScroll: true });
        } else if (!on && sheet.open) sheet.close();
        awayCheck();
    }
    sheet.addEventListener('close', () => {
        preview.classList.replace('log-post', 'log-page-preview'); preview.classList.remove('log-page');
        document.documentElement.classList.remove('log-preview-open');
        previewHome.after(preview);
        // Turned sideways while it was open: the preview stays, as the tab beside 書く.
        if (wide.matches) return;
        if (previewing()) showPreview(false);
        previewButton.focus({ preventScroll: true });
    });
    $('[data-preview-close]', sheet).addEventListener('click', () => sheet.close());
    sheet.addEventListener('keydown', e => {
        // Escape over the image viewer opened from the preview closes only that viewer.
        if (e.defaultPrevented || window.NagiSwipe?.isOpen || document.querySelector('.nm-viewer:not([hidden])')) return;
        if (e.key === 'Escape') { e.preventDefault(); sheet.close(); return; }
        if (e.key !== 'Tab') return;
        const focusable = $$('a[href],button:not(:disabled),input,summary,iframe,[tabindex]:not([tabindex="-1"])', sheet).filter(el => el.getClientRects().length);
        const first = focusable[0], last = focusable.at(-1);
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    previewButton.addEventListener('click', () => { if (previewing()) showPreview(false); else openPreview(); });
    // 書く / プレビュー tabs (shown on a PC or sideways); the eye on the tool row switches them too.
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            if (tab.dataset.composeTab === 'preview') { if (!previewing()) openPreview(); }
            else { showPreview(false); body.focus(); }
        });
        tab.addEventListener('keydown', e => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            e.preventDefault();
            const next = tabs[(tabs.indexOf(tab) + 1) % tabs.length];
            next.focus(); next.click();
        });
    });

    // --- 本文へ beside the heading (a PC or sideways) ---
    // Back to writing from the preview, the picture list or a page scrolled away from the text, with the caret where
    // it was. It stands out while one of those is so.
    let bodySeen = true;
    function awayCheck() {
        if (!toBody) return;
        toBody.hidden = !wide.matches;
        const away = previewing() || (!!card.dataset.dock && $('[data-dock-pane="page"]', dock).hidden) || !bodySeen;
        toBody.classList.toggle('is-away', away);
    }
    if (toBody && 'IntersectionObserver' in window) new IntersectionObserver(entries => { bodySeen = entries.at(-1).isIntersecting; awayCheck(); }, { threshold: 0.2 }).observe(body);
    toBody?.addEventListener('click', () => {
        const [from, to] = [body.selectionStart, body.selectionEnd];
        showPreview(false);
        if (card.dataset.dock) dockTab('page');
        // The row of tools and the top of the text in sight.
        const at = body.getBoundingClientRect();
        if (at.top < 8 || at.top > window.innerHeight - 160) toolzone.scrollIntoView({ block: 'start', behavior: reduce.matches ? 'auto' : 'smooth' });
        body.focus({ preventScroll: true }); body.setSelectionRange(from, to);
        awayCheck();
    });

    // --- The tool row scrolls sideways when it does not fit: a fade and an arrow show at the end that has more ---
    function toolsHint() {
        const rest = tools.scrollWidth - tools.clientWidth;
        toolsScroll.classList.toggle('can-start', tools.scrollLeft > 2);
        toolsScroll.classList.toggle('can-end', rest > 2 && tools.scrollLeft < rest - 2);
    }
    tools.addEventListener('scroll', toolsHint, { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(toolsHint).observe(tools);
    $$('[data-tools-step]', toolsScroll).forEach(button => button.addEventListener('click', () => {
        tools.scrollBy({ left: +button.dataset.toolsStep * tools.clientWidth * 0.7, behavior: reduce.matches ? 'auto' : 'smooth' });
    }));

    wide.addEventListener('change', arrange);
    arrange();
    drawStrip();
    // Save
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving) return;
        if (!form.reportValidity()) return;
        const data = new FormData(form);
        data.set('status', event.submitter?.value || 'draft');
        saving = true; form.inert = true; say(t('保存しています…'));
        try {
            const { redirect } = await send(data);
            dirty = false; location.href = redirect;
        } catch (e) { saving = false; say(e.message + ' ' + t('入力した内容はこの画面に残っています。'), true); }
        finally { form.inert = false; }
    });
})();
