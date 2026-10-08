/*
 * Fixed page editor (Markdown): the marks from the tool row, pictures by button, drop or paste,
 * a preview drawn by the server, and saving with fetch so a refused save keeps the text.
 * Also: the 画像一覧 page's "add pictures only" box and its copy buttons.
 * MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const $ = (s, root = document) => root.querySelector(s);
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
    const form = $('[data-page-editor]');
    if (!form) return;
    const body = $('[data-md-body]', form), status = $('[data-md-status]', form);
    const preview = $('[data-md-preview-body]', form), previewButton = $('[data-md-preview]', form);
    const say = (text, error = false) => { status.textContent = text; status.classList.toggle('is-error', error); status.classList.toggle('log-busy', !error && /…(?:（[^）]*）)?$/.test(text)); };
    let dirty = false, saving = false;
    form.addEventListener('input', () => { dirty = true; });
    window.addEventListener('beforeunload', e => { if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; } });
    // Put text at the caret (or around the selection) and keep the browser's undo history where it can.
    function insert(before, after = '', placeholder = '') {
        body.focus();
        const start = body.selectionStart, end = body.selectionEnd;
        const chosen = body.value.slice(start, end) || placeholder;
        const text = before + chosen + after;
        if (!document.execCommand?.('insertText', false, text)) body.setRangeText(text, start, end, 'end');
        body.setSelectionRange(start + before.length, start + before.length + chosen.length);
        dirty = true;
    }
    // Block marks start on a line of their own.
    const lineStart = () => { const v = body.value, at = body.selectionStart; return at === 0 || v[at - 1] === '\n' ? '' : '\n'; };
    const marks = {
        heading: () => insert(lineStart() + '## ', '', t('見出し')),
        bold: () => insert('**', '**', t('太字')),
        link: () => insert('[', '](https://)', t('リンクの文字')),
        list: () => insert(lineStart() + '- ', '', t('項目')),
        ordered: () => insert(lineStart() + '1. ', '', t('項目')),
        table: () => insert(lineStart() + '| ' + t('項目') + ' | ' + t('内容') + ' |\n| --- | --- |\n| ', ' |  |\n', 'a'),
        rule: () => insert(lineStart() + '\n---\n', '', ''),
    };
    form.addEventListener('click', event => {
        const tool = event.target.closest('[data-md]');
        if (tool && marks[tool.dataset.md]) marks[tool.dataset.md]();
    });
    // Pictures
    const picker = $('[data-md-upload-input]', form);
    $('[data-md-upload]', form).addEventListener('click', () => picker.click());
    async function pictures(files) {
        const list = Array.from(files).filter(isImage);
        if (!list.length) { say(t('JPEG・PNG・WebP・GIF・AVIF・BMP・SVGの画像を選んでください。'), true); return; }
        const lines = []; const errors = [];
        for (const [i, file] of list.entries()) {
            say(t('画像をアップロードしています…（{i} / {n}）', { i: i + 1, n: list.length }));
            try {
                const media = await uploadOne(file);
                known[media.id] = media;
                lines.push('![' + String(media.alt || '').replace(/[[\]]/g, '') + '](' + publicUrl(media) + ')');
            } catch (e) { errors.push(t('{name}：{message}', { name: file.name, message: e.message })); }
        }
        if (lines.length) { insert(lineStart() + lines.join('\n') + '\n'); drawStrip(); }
        say(errors.length ? errors.join(' / ') : t('画像を入れました。[ ] の中は画像の説明（alt）です。'), errors.length > 0);
    }
    picker.addEventListener('change', () => { pictures(picker.files); picker.value = ''; });
    // Drop anywhere on the editor card; paste into the text.
    const card = form.closest('.log-page-editor') || form;
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
    // --- The pictures in the text, as thumbnails under it (like the post editor) ---
    const strip = $('[data-md-images]', form);
    const imageLine = /!\[[^\]\n]*\]\(\s*<?[^\s)>]*[?&]media=([a-f0-9]{16})[^\s)>]*>?(?:\s+"[^"]*")?\s*\)/g;
    const tile = (cls, label, inner) => { const b = document.createElement('button'); b.type = 'button'; b.className = cls; b.setAttribute('aria-label', label); b.title = label; b.innerHTML = inner; return b; };
    let drawing = 0;
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
            show.addEventListener('click', () => { body.focus(); body.setSelectionRange(f.at, f.at + f.text.length); scrollToCaret(); });
            const remove = tile('log-page-image-remove', t('この画像を本文から外す'), '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>');
            remove.addEventListener('click', () => {
                const v = body.value, at = v.indexOf(f.text);
                if (at < 0) return;
                const end = at + f.text.length + (v[at + f.text.length] === '\n' ? 1 : 0);
                body.focus(); body.setSelectionRange(at, end);
                if (!document.execCommand?.('insertText', false, '')) body.setRangeText('', at, end, 'start');
                dirty = true; drawStrip(); say(t('画像を本文から外しました。画像そのものは画像一覧に残ります。'));
            });
            item.append(show, remove); strip.append(item);
        });
        const add = tile('log-page-image-add', t('画像を追加'), '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>');
        add.addEventListener('click', () => picker.click());
        const pick = tile('log-page-image-add', t('画像一覧から選ぶ'), '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="3.5" width="13.5" height="13.5" rx="2.2"/><path d="M4 7.5v10.3A2.7 2.7 0 0 0 6.7 20.5H17"/></svg>');
        pick.addEventListener('click', openPicker);
        strip.append(add, pick);
    }
    // Bring the chosen line into view inside the text box.
    function scrollToCaret() {
        const before = body.value.slice(0, body.selectionStart).split('\n').length - 1;
        const line = parseFloat(getComputedStyle(body).lineHeight) || 24;
        body.scrollTop = Math.max(0, before * line - body.clientHeight / 3);
    }
    let stripTimer = 0;
    body.addEventListener('input', () => { clearTimeout(stripTimer); stripTimer = setTimeout(drawStrip, 250); });

    // --- Choose from 画像一覧 in a dialog ---
    const dialog = $('[data-md-picker]');
    const grid = $('[data-pick-grid]', dialog), more = $('[data-pick-more]', dialog), search = $('[data-pick-search]', dialog), pickStatus = $('[data-pick-status]', dialog);
    let pickPage = 1, pickQuery = '', loading = false;
    async function loadPicks(reset) {
        if (loading) return;
        loading = true;
        if (reset) { pickPage = 1; grid.replaceChildren(); }
        pickStatus.textContent = t('読み込んでいます…'); pickStatus.classList.add('log-busy'); more.hidden = true;
        try {
            const url = new URL('index.php', location.href);
            url.search = new URLSearchParams({ p: 'log_media_json', page: String(pickPage), q: pickQuery, images: '1' }).toString();
            const r = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (r.redirected || !(r.headers.get('Content-Type') || '').includes('application/json')) throw new Error('login');
            const data = await r.json();
            data.items.forEach(m => {
                known[m.id] = m;
                const b = tile('log-page-pick', t('{name}を本文に入れる', { name: m.alt || t('画像') }), '');
                const img = document.createElement('img'); img.src = m.thumb; img.alt = ''; img.loading = 'lazy';
                const name = document.createElement('span'); name.textContent = m.alt || t('画像');
                b.append(img, name);
                b.addEventListener('click', () => {
                    dialog.close();
                    insert(lineStart() + '![' + String(m.alt || '').replace(/[[\]]/g, '') + '](' + publicUrl(m) + ')\n');
                    drawStrip(); say(t('画像を入れました。[ ] の中は画像の説明（alt）です。'));
                });
                grid.append(b);
            });
            more.hidden = !data.more; pickPage++;
            pickStatus.textContent = grid.children.length ? t('選ぶと、本文のカーソルの位置に入ります。') : (pickQuery ? t('見つかりませんでした。') : t('まだ画像がありません。「画像を追加」から追加できます。'));
        } catch { pickStatus.textContent = t('読み込めませんでした。ログインが切れた可能性があります。'); }
        finally { loading = false; pickStatus.classList.remove('log-busy'); }
    }
    function openPicker() { dialog.showModal(); if (!grid.children.length) loadPicks(true); search.focus(); }
    $('[data-md-pick]', form).addEventListener('click', openPicker);
    $('[data-pick-close]', dialog).addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', e => { if (e.target === dialog) dialog.close(); });
    more.addEventListener('click', () => loadPicks(false));
    let searchTimer = 0;
    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { pickQuery = search.value.trim(); loadPicks(true); }, 300); });
    drawStrip();
    // Preview
    previewButton.addEventListener('click', async () => {
        const on = previewButton.getAttribute('aria-pressed') !== 'true';
        previewButton.setAttribute('aria-pressed', String(on));
        if (!on) { preview.hidden = true; return; }
        const data = new FormData(); data.set('do', 'log_page_preview'); data.set('title', form.elements.title.value); data.set('body', body.value);
        say(t('プレビューを作っています…'));
        try {
            const { html } = await send(data);
            preview.innerHTML = html; preview.hidden = false;
            window.NagiSwipe?.init();
            say(t('プレビューです。公開ページでは、ページの幅の設定に合わせて表示します。'));
        } catch (e) { previewButton.setAttribute('aria-pressed', 'false'); say(e.message, true); }
    });
    body.addEventListener('input', () => { if (!preview.hidden) { preview.hidden = true; previewButton.setAttribute('aria-pressed', 'false'); } });
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
