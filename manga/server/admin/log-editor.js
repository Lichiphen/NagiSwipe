/* Personal LOG editor; panel structure inspired by NagiMemo. MIT (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const $ = (s, root = document) => root.querySelector(s);
    const $$ = (s, root = document) => Array.from(root.querySelectorAll(s));
    const csrf = $('input[name="csrf"]')?.value;
    const form = $('[data-log-editor]');
    const endpoint = new URL(form?.getAttribute('action') || 'index.php', location.href);
    const adminUrl = value => new URL(value, endpoint).href;
    async function request(data) {
        data.set('csrf', csrf);
        const response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        let result;
        try { result = await response.json(); }
        catch { throw new Error('ログインが切れた可能性があります。本文をコピーしてから、ログインし直してください。'); }
        if (!response.ok || result.error) throw new Error(result.error || '保存できませんでした。もう一度お試しください。');
        return result;
    }
    // Replace from the file picker or by dropping an image anywhere on the card.
    async function replaceImage(card, file) {
        const input = $('.log-replace', card);
        if (!file || card.dataset.busy) return;
        if (!/^image\/(jpeg|png|webp|gif|avif|bmp)$/.test(file.type)) { $('[data-replace-status]', card).textContent = 'JPEG・PNG・WebP・GIF・AVIF・BMPの画像を選んでください。'; return; }
        if (!window.confirm('この画像を使うすべての投稿が変わります。差し替えますか？')) { input.value = ''; return; }
        const status = $('[data-replace-status]', card);
        input.disabled = true; card.dataset.busy = '1';
        status.textContent = '画像を差し替えています…';
        try {
            const data = new FormData();
            data.set('do', 'log_upload'); data.set('media', card.dataset.mediaCard); data.set('revision', card.dataset.revision); data.set('image', file);
            const { media } = await request(data);
            card.dataset.revision = String(media.revision);
            $$('input[name="revision"]', card).forEach(i => { i.value = String(media.revision); });
            $('img', card).src = media.thumb;
            $('a.imagelink', card).href = media.url;
            window.NagiSwipe?.init();
            status.textContent = '差し替えました。本文のタグはそのまま使えます。';
        } catch (e) { status.textContent = e.message; }
        finally { input.disabled = false; input.value = ''; delete card.dataset.busy; }
    }
    $$('.log-replace').forEach(input => input.addEventListener('change', () => replaceImage(input.closest('[data-media-card]'), input.files[0])));
    $$('[data-media-card]').forEach(card => {
        let depth = 0;
        const files = e => [...(e.dataTransfer?.types || [])].includes('Files');
        card.addEventListener('dragenter', e => { if (!files(e)) return; e.preventDefault(); depth++; card.classList.add('log-drop-over'); });
        card.addEventListener('dragover', e => { if (!files(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
        card.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; card.classList.remove('log-drop-over'); } });
        card.addEventListener('drop', e => {
            if (!files(e)) return;
            e.preventDefault(); depth = 0; card.classList.remove('log-drop-over');
            if (e.dataTransfer.files.length !== 1) { $('[data-replace-status]', card).textContent = '差し替える画像を1枚だけドロップしてください。'; return; }
            replaceImage(card, e.dataTransfer.files[0]);
        });
    });
    // Outside the cards a dropped file must not open in the browser and leave the page.
    if ($('[data-media-card]')) ['dragover', 'drop'].forEach(type => document.addEventListener(type, e => { if (!e.defaultPrevented && [...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); }));

    // Deleting a post from the public page asks first (admin.js, which asks on the admin pages, is not loaded there).
    $$('form[data-log-delete]').forEach(f => f.addEventListener('submit', e => {
        if (!window.confirm('この記事を削除しますか？この記事だけで使う画像も削除します。元に戻せません。')) e.preventDefault();
    }));

    if (!form) return;
    const panel = $('#log-compose');
    const body = $('textarea[name="body"]', form);
    const title = $('input[name="title"]', form);
    const refsField = $('input[name="manga"]', form);
    const status = $('.log-status', form);
    const fab = $('.log-fab');
    const picker = $('.log-picker');
    const mobile = window.matchMedia('(max-width: 900px)');
    const publicEditor = form.dataset.public === '1';
    const slot = $('[data-compose-slot]');
    const collapsed = slot?.classList.contains('log-compose-collapsed');
    const modalPanel = () => panel.classList.contains('active') && (mobile.matches || !!slot);
    let refs = JSON.parse(refsField.value || '{}');
    let selection = [body.value.length, body.value.length];
    let uploading = 0;
    let saving = false;
    let dirty = false;
    // Edits made on this page. A draft brought back from sessionStorage stays there when the page is left,
    // so it alone must not ask before leaving: the editor on every public page shares the same draft.
    let touched = false;
    const storageKey = 'nagimanga-log:' + location.pathname + ':' + ($('input[name="post_id"]', form).value || 'new');
    const initialRevision = $('input[name="revision"]', form).value;
    const categoryFields = $$('input[name="categories[]"]', form);
    const newCategories = $('input[name="new_categories"]', form);
    const ratingFields = $$('input[name="rating"]', form);
    const warning = $('input[name="warning"]', form);
    const attachments = $('[data-attachments]', form);
    // Pictures known to the editor: id -> { thumb, alt, rating }. Ratings changed here are sent with the post.
    const mediaInfo = JSON.parse(attachments.dataset.media || '{}');
    let mediaRatings = {};
    const rating = () => ratingFields.find(i => i.checked)?.value || '';
    const current = () => ({ body: body.value, title: title.value, refs, categories: categoryFields.filter(i => i.checked).map(i => i.value), newCategories: newCategories.value, rating: rating(), warning: warning.value, mediaRatings });
    const initial = JSON.stringify(current());
    const snapshot = () => JSON.stringify(current());
    function restore(saved) {
        body.value = saved.body; title.value = saved.title || ''; refs = saved.refs || {};
        if (Array.isArray(saved.categories)) categoryFields.forEach(i => { i.checked = saved.categories.includes(i.value); });
        newCategories.value = saved.newCategories || '';
        if (typeof saved.rating === 'string') ratingFields.forEach(i => { i.checked = i.value === saved.rating; });
        warning.value = saved.warning || '';
        mediaRatings = saved.mediaRatings && typeof saved.mediaRatings === 'object' ? saved.mediaRatings : {};
    }
    function say(message, error = false) { status.textContent = message; status.classList.toggle('error', error); }
    function changed(user = true) {
        if (user) touched = true;
        Object.keys(refs).forEach(tag => { if (!body.value.includes(tag)) delete refs[tag]; });
        refsField.value = JSON.stringify(refs);
        $('input[name="media_ratings"]', form).value = JSON.stringify(mediaRatings);
        renderAttachments(); renderChips();
        $('[data-preview-body]', form).hidden = true; $('[data-preview]', form).setAttribute('aria-pressed', 'false');
        $('[data-character-count]', form).textContent = Array.from(body.value).length + '文字';
        dirty = snapshot() !== initial;
        fab?.classList.toggle('log-fab-draft', dirty);
        if (fab) fab.title = dirty ? '書きかけがあります' : '';
        try { sessionStorage.setItem(storageKey, JSON.stringify({ ...current(), revision: initialRevision })); } catch { /* storage may be disabled */ }
    }
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (saved && saved.revision === initialRevision && (saved.body || saved.title)) {
            restore(saved);
            say('このタブで書いていた本文を戻しました。');
            const discard = document.createElement('button');
            discard.type = 'button'; discard.className = 'btn'; discard.textContent = '書きかけを消す';
            status.after(discard);
            discard.addEventListener('click', () => {
                restore(JSON.parse(initial)); changed(false); touched = false; discard.remove();
                try { sessionStorage.removeItem(storageKey); } catch { /* storage may be disabled */ }
                say('書きかけを消しました。');
            });
        } else if (saved && saved.revision !== initialRevision) {
            const recover = document.createElement('button');
            recover.type = 'button'; recover.className = 'btn'; recover.textContent = 'このタブの未保存本文を戻す';
            status.after(recover);
            recover.addEventListener('click', () => {
                restore(saved); changed(); recover.remove();
                say('未保存の本文を戻しました。新しい投稿内容と比べてから保存してください。');
            });
        }
    } catch { /* storage may be disabled */ }
    // Ratings, weakest first, as on the server (NL_RATINGS).
    const RANK = ['', 'sensitive', 'r18g', 'r18'];
    const LABEL = { '': 'なし', sensitive: 'センシティブ', r18: 'R-18', r18g: 'R-18G' };
    const bodyMedia = () => [...new Set([...body.value.matchAll(/\[Image:([a-f0-9]{16})\]/g)].map(m => m[1]))];
    const mediaRating = id => mediaRatings[id] ?? mediaInfo[id]?.rating ?? '';
    const strongest = list => list.reduce((a, b) => RANK.indexOf(b) > RANK.indexOf(a) ? b : a, '');
    const veilIcon = '<svg class="log-veil-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.3 4.2a2 2 0 0 1 3.4 0l7.6 13.1a2 2 0 0 1-1.7 3H4.4a2 2 0 0 1-1.7-3Z"/><path d="M12 9.5v4.2M12 16.9v.1"/></svg>';
    // The strip under the text: every picture tagged in the body, in order, each with its own rating button.
    // Tags typed or pasted by hand: ask the server once for their thumbnails and ratings.
    const asked = new Set();
    async function lookup(ids) {
        ids.forEach(id => asked.add(id));
        try {
            const response = await fetch(adminUrl('index.php?p=log_media_json&ids=' + ids.join(',')), { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            (await response.json()).items.forEach(item => { mediaInfo[item.id] = { thumb: item.thumb, alt: item.alt, rating: item.rating || '' }; });
            renderAttachments(); renderChips();
        } catch { /* the strip keeps its placeholders */ }
    }
    function renderAttachments() {
        const ids = bodyMedia();
        const unknown = ids.filter(id => !mediaInfo[id] && !asked.has(id));
        if (unknown.length) lookup(unknown);
        const key = ids.map(id => id + ':' + mediaRating(id) + ':' + (mediaInfo[id]?.thumb || '')).join(',');
        if (attachments.dataset.key === key) return;
        attachments.dataset.key = key;
        attachments.replaceChildren(...ids.map(id => {
            const info = mediaInfo[id] || {}, r = mediaRating(id);
            const item = document.createElement('div'); item.className = 'log-att'; item.dataset.mediaId = id;
            const thumb = document.createElement('button'); thumb.type = 'button'; thumb.className = 'log-att-thumb'; thumb.title = 'この画像のタグをもう一度入れる';
            if (info.thumb) { const img = document.createElement('img'); img.src = adminUrl(info.thumb); img.alt = info.alt || '本文の画像'; thumb.append(img); }
            else thumb.textContent = '画像';
            thumb.addEventListener('click', () => insert('\n[Image:' + id + ']\n'));
            const rate = document.createElement('button'); rate.type = 'button'; rate.className = 'log-att-rating'; rate.dataset.veil = r;
            rate.setAttribute('aria-haspopup', 'menu'); rate.setAttribute('aria-expanded', 'false');
            rate.setAttribute('aria-label', 'この画像の閲覧注意：' + LABEL[r]); rate.title = '閲覧注意：' + LABEL[r];
            rate.innerHTML = veilIcon + (r ? '<span>' + LABEL[r] + '</span>' : '');
            rate.addEventListener('click', () => openMenu(rate, id));
            item.append(thumb, rate);
            return item;
        }));
        attachments.hidden = !ids.length;
    }
    const menu = $('[data-att-menu]', form);
    let menuFor = null;
    function closeMenu(focus = false) {
        if (menu.hidden) return;
        menu.hidden = true;
        const opener = $('.log-att[data-media-id="' + menuFor + '"] .log-att-rating', attachments);
        opener?.setAttribute('aria-expanded', 'false');
        if (focus) opener?.focus();
        menuFor = null;
    }
    function openMenu(button, id) {
        if (menuFor === id) { closeMenu(); return; }
        closeMenu(); menuFor = id;
        $$('[data-rate]', menu).forEach(b => b.setAttribute('aria-checked', String(b.dataset.rate === mediaRating(id))));
        const box = form.getBoundingClientRect(), at = button.getBoundingClientRect();
        menu.hidden = false;
        menu.style.left = Math.max(0, Math.min(at.left - box.left, box.width - menu.offsetWidth)) + 'px';
        menu.style.top = (at.bottom - box.top + 6) + 'px';
        button.setAttribute('aria-expanded', 'true');
        $('[data-rate][aria-checked="true"]', menu)?.focus();
    }
    $$('[data-rate]', menu).forEach(b => b.addEventListener('click', () => {
        const id = menuFor; closeMenu(true);
        if (id) { mediaRatings[id] = b.dataset.rate; changed(); $('.log-att[data-media-id="' + id + '"] .log-att-rating', attachments)?.focus(); }
    }));
    menu.addEventListener('keydown', e => {
        const items = $$('[data-rate]', menu), i = items.indexOf(document.activeElement);
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(true); }
        else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); items[(i + (e.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length].focus(); }
    });
    document.addEventListener('click', e => { if (!menu.hidden && !e.target.closest('[data-att-menu], .log-att-rating')) closeMenu(); });
    // Chips under the pictures: what is set now; each opens the panel where it is changed.
    function renderChips() {
        const chips = $('[data-chips]', form), out = [];
        const own = rating(), shown = strongest([own, ...bodyMedia().map(mediaRating)]);
        if (shown) {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'log-chip-rating'; b.dataset.open = 'rating'; b.dataset.veil = shown;
            b.innerHTML = veilIcon; const text = document.createElement('span');
            text.textContent = LABEL[shown] + (shown !== own ? '（画像）' : '') + (warning.value.trim() ? '・' + warning.value.trim() : '');
            b.append(text); out.push(b);
        }
        const names = categoryFields.filter(i => i.checked).map(i => i.nextElementSibling.textContent).concat(newCategories.value.split(/[,、\n]+/).map(s => s.trim()).filter(Boolean));
        names.forEach(name => {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'log-chip-category'; b.dataset.open = 'categories';
            b.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>';
            const text = document.createElement('span'); text.textContent = name; b.append(text); out.push(b);
        });
        out.forEach(b => b.addEventListener('click', () => togglePanel(b.dataset.open, true)));
        chips.replaceChildren(...out); chips.hidden = !out.length;
        const tool = $('[data-panel="rating"]', form);
        tool.dataset.veil = shown; tool.setAttribute('aria-label', '閲覧注意' + (shown ? '：' + LABEL[shown] : ''));
        $('[data-panel="categories"]', form).classList.toggle('is-set', names.length > 0);
    }
    // One panel at a time under the tool row.
    function togglePanel(name, open) {
        $$('[data-panel]', form).forEach(button => {
            const on = button.dataset.panel === name && (open ?? button.getAttribute('aria-expanded') !== 'true');
            button.setAttribute('aria-expanded', String(on));
            $('[data-panel-body="' + button.dataset.panel + '"]', form).hidden = !on;
        });
        const panelBody = $('[data-panel-body="' + name + '"]:not([hidden])', form);
        if (panelBody && open) (panelBody.querySelector('input:checked, input, button') || panelBody).focus({ preventScroll: true });
        panelBody?.scrollIntoView({ block: 'nearest' });
    }
    $$('[data-panel]', form).forEach(button => button.addEventListener('click', () => togglePanel(button.dataset.panel)));
    ratingFields.forEach(input => input.addEventListener('change', () => {
        // Choosing a rating for the post marks its pictures the same, unless the owner turned that off.
        if ($('[data-rate-all]', form).checked) bodyMedia().forEach(id => { mediaRatings[id] = input.value; });
        changed();
    }));
    warning.addEventListener('input', changed);
    // A preset fills the note and leaves the caret at its end, ready to edit.
    $$('[data-warning-preset]', form).forEach(b => b.addEventListener('click', () => {
        warning.value = b.dataset.warningPreset; changed();
        warning.focus(); warning.setSelectionRange(warning.value.length, warning.value.length);
    }));
    changed(false);
    body.addEventListener('input', changed); title.addEventListener('input', changed);
    categoryFields.forEach(input => input.addEventListener('change', changed));
    newCategories.addEventListener('input', changed);
    function rememberSelection() { selection = [body.selectionStart, body.selectionEnd]; }
    ['select', 'keyup', 'click', 'blur', 'input'].forEach(name => body.addEventListener(name, rememberSelection));
    function insert(text, range = selection) {
        const start = Math.min(range[0], body.value.length), end = Math.min(range[1], body.value.length);
        body.setRangeText(text, start, end, 'end');
        rememberSelection(); changed();
    }
    $$('[data-hashtag]', form).forEach(button => button.addEventListener('click', () => {
        const start = Math.min(selection[0], body.value.length);
        const prefix = start > 0 && !/\s/.test(body.value[start - 1]) ? ' ' : '';
        insert(prefix + '#' + button.dataset.hashtag + ' '); body.focus();
    }));
    $('[data-bold]', form).addEventListener('click', () => {
        const [start, end] = selection;
        const picked = body.value.slice(start, end);
        // Triple-click also selects the line break: keep surrounding spaces/line breaks outside the markers,
        // and mark each line separately because bold works within one line.
        if (picked.trim() !== '') {
            const wrapped = picked.split('\n').map(line => {
                const [, lead, text, tail] = line.match(/^(\s*)(.*?)(\s*)$/s);
                return text === '' ? line : lead + '**' + text + '**' + tail;
            }).join('\n');
            insert(wrapped);
            const lead = picked.length - picked.trimStart().length, trail = picked.length - picked.trimEnd().length;
            body.focus(); body.setSelectionRange(start + lead, start + wrapped.length - trail); rememberSelection();
            return;
        }
        const text = '太字にする文字';
        insert('**' + text + '**');
        body.focus(); body.setSelectionRange(start + 2, start + 2 + text.length); rememberSelection();
    });
    let previousFocus = null;
    let inertNodes = [];
    function accessibility(open) {
        inertNodes.forEach(([el, was]) => { el.inert = was; }); inertNodes = [];
        if (open && (mobile.matches || slot)) {
            panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true');
            $$('header.top, footer.foot, .main > :not(#log-compose):not(.log-fab):not(.log-picker):not(.log-compose-slot), .log-site-header, .log-site-footer, .log-menu-toggle, .log-sidebar, .log-site-main > :not(.log-compose-slot)').forEach(el => { inertNodes.push([el, el.inert]); el.inert = true; });
        } else { panel.removeAttribute('role'); panel.removeAttribute('aria-modal'); }
        document.body.classList.toggle('log-composing', open && (mobile.matches || !!slot));
    }
    function setPanel(open) {
        if (open && publicEditor && !panel.classList.contains('active')) {
            // The top page's editor in view only needs the focus. Elsewhere the editor is hidden in an empty
            // slot that can sit inside the screen, so it always opens as a panel.
            const rect = slot.getBoundingClientRect();
            if (!collapsed && rect.bottom > 0 && rect.top < window.innerHeight) { body.focus({ preventScroll: true }); return; }
            slot.style.minHeight = panel.offsetHeight + 'px';
        }
        if (open) previousFocus = document.activeElement;
        panel.classList.toggle('active', open); fab.setAttribute('aria-expanded', String(open));
        if (!open && slot) slot.style.minHeight = '';
        accessibility(open);
        if (open) body.focus(); else previousFocus?.focus();
        checkFab();
    }
    fab.addEventListener('click', () => setPanel(true));
    $('[data-compose-open]')?.addEventListener('click', () => setPanel(true));
    $('.log-close', panel).addEventListener('click', () => {
        if (panel.dataset.edit) { location.href = 'index.php?p=log'; return; }
        setPanel(false);
    });
    mobile.addEventListener('change', () => accessibility(panel.classList.contains('active')));
    document.addEventListener('keydown', e => {
        if (e.defaultPrevented || picker.open || saved?.open || window.NagiSwipe?.isOpen || document.querySelector('.nm-viewer:not([hidden])')) return;
        if (e.key === 'Escape' && !menu.hidden) { closeMenu(true); return; }
        if (e.key === 'Escape' && !panel.dataset.edit) setPanel(false);
        if (e.key === 'Tab' && modalPanel()) {
            const focusable = $$('button:not(:disabled),textarea,input:not([type="hidden"]),summary,a[href]', panel).filter(el => el.getClientRects().length);
            const first = focusable[0], last = focusable.at(-1);
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last?.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first?.focus(); }
        }
    });
    function viewport() {
        const height = window.visualViewport?.height || window.innerHeight;
        panel.style.setProperty('--log-viewport-height', height + 'px');
    }
    window.visualViewport?.addEventListener('resize', viewport);
    window.addEventListener('resize', viewport); viewport();
    requestAnimationFrame(() => document.body.classList.add('log-ready'));
    let scrollTicking = false;
    function checkFab() {
        scrollTicking = false;
        const show = !panel.classList.contains('active') && (collapsed || (publicEditor && slot.getBoundingClientRect().bottom <= 0));
        fab.classList.toggle('log-fab-visible', show);
        $('.log-fab-admin')?.classList.toggle('log-fab-visible', show);
    }
    function scrollFab() { if (!scrollTicking) { scrollTicking = true; requestAnimationFrame(checkFab); } }
    if (publicEditor) { window.addEventListener('scroll', scrollFab, { passive: true }); window.addEventListener('resize', scrollFab, { passive: true }); }
    // Without the open editor (pages other than the top, the admin list) the button floats in shortly after load.
    if (collapsed) setTimeout(checkFab, 220); else checkFab();
    // A draft brought back opens the editor only where it is part of the page (the top page);
    // elsewhere the dot on the write button tells about it, so moving between posts never pops the panel up.
    if (panel.dataset.edit || (dirty && !collapsed)) setPanel(true);
    window.addEventListener('beforeunload', e => { if (((dirty && touched) || uploading) && !saving) { e.preventDefault(); e.returnValue = ''; } });

    const uploadInput = $('[data-upload-input]', form);
    $('[data-upload]', form).addEventListener('click', () => uploadInput.click());
    async function upload(files) {
        const list = Array.from(files);
        if (!list.length || saving) return;
        // One queue preserves the order across separate drops and selection changes.
        uploading++;
        uploadQueue = uploadQueue.then(async () => {
            setPanel(true); say('画像をアップロードしています…');
            const errors = [];
            for (const file of list) {
                try {
                    const data = new FormData(); data.set('do', 'log_upload'); data.set('image', file);
                    const { media } = await request(data);
                    mediaInfo[media.id] = { thumb: media.thumb, alt: media.alt, rating: media.rating || '' };
                    insert('\n' + media.tag + '\n');
                } catch (e) { errors.push(file.name + '：' + e.message); }
            }
            say(errors.length ? errors.join(' / ') : '画像を追加しました。タグを動かすと、表示する位置も変わります。', errors.length > 0);
        }).finally(() => { uploading--; });
        await uploadQueue;
    }
    let uploadQueue = Promise.resolve();
    uploadInput.addEventListener('change', () => { upload(uploadInput.files); uploadInput.value = ''; });
    document.addEventListener('dragover', e => {
        if (Array.from(e.dataTransfer?.types || []).includes('Files')) { e.preventDefault(); panel.classList.add('dragover'); }
    });
    document.addEventListener('dragleave', e => { if (!e.relatedTarget) panel.classList.remove('dragover'); });
    document.addEventListener('drop', e => {
        if (!e.dataTransfer?.files.length) return;
        e.preventDefault(); panel.classList.remove('dragover'); upload(e.dataTransfer.files);
    });
    body.addEventListener('paste', e => {
        const files = Array.from(e.clipboardData?.files || []);
        if (files.length) { e.preventDefault(); upload(files); }
    });

    let kind = 'media', catalogPage = 1, generation = 0, searchTimer;
    const grid = $('.log-picker-grid', picker), search = $('[data-picker-search]', picker), more = $('[data-picker-more]', picker), pickerStatus = $('[data-picker-status]', picker);
    async function loadCatalog(reset) {
        const currentGeneration = ++generation;
        if (reset) { catalogPage = 1; grid.replaceChildren(); }
        more.disabled = true; pickerStatus.textContent = '読み込み中…';
        try {
            const response = await fetch(adminUrl('index.php?p=log_' + kind + '_json&page=' + catalogPage + '&q=' + encodeURIComponent(search.value)), { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error('一覧を読めませんでした。ログインを確認してください。');
            const result = await response.json();
            if (currentGeneration !== generation) return;
            result.items.forEach(item => {
                const button = document.createElement('button'); button.type = 'button';
                const img = document.createElement('img'); img.src = adminUrl(item.thumb); img.alt = ''; img.loading = 'lazy';
                const label = document.createElement('span'); label.textContent = item.title || item.alt || '画像';
                button.append(img, label);
                if (item.locked) { const badge = document.createElement('small'); badge.textContent = 'パスワード付き'; button.append(badge); }
                button.addEventListener('click', () => {
                    if (kind === 'manga') {
                        // An old post may already use this label for another work.
                        let tag = item.tag;
                        if (refs[tag] && refs[tag] !== item.id) tag = tag.slice(0, -1) + ' #' + item.id + ']';
                        refs[tag] = item.id; insert('\n' + tag + '\n');
                    } else { mediaInfo[item.id] = { thumb: item.thumb, alt: item.alt, rating: item.rating || '' }; insert('\n' + item.tag + '\n'); }
                    picker.close(); body.focus();
                });
                grid.append(button);
            });
            more.hidden = !result.more; pickerStatus.textContent = grid.children.length ? '選ぶと、本文のカーソル位置に入ります。' : '見つかりませんでした。';
        } catch (e) { if (currentGeneration === generation) pickerStatus.textContent = e.message; }
        finally { if (currentGeneration === generation) more.disabled = false; }
    }
    $$('[data-picker]', form).forEach(button => button.addEventListener('click', () => {
        kind = button.dataset.picker; search.value = ''; $('#log-picker-title').textContent = kind === 'manga' ? '漫画を選ぶ' : '画像を選ぶ';
        picker.showModal(); loadCatalog(true);
    }));
    $('[data-picker-close]', picker).addEventListener('click', () => picker.close());
    picker.addEventListener('close', () => { generation++; });
    search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadCatalog(true), 250); });
    more.addEventListener('click', () => { catalogPage++; loadCatalog(false); });
    $('[data-preview]', form).addEventListener('click', async () => {
        const preview = $('[data-preview-body]', form), toggle = $('[data-preview]', form);
        if (!preview.hidden) { preview.hidden = true; toggle.setAttribute('aria-pressed', 'false'); return; }
        try {
            const data = new FormData(form); data.set('do', 'log_preview');
            const result = await request(data);
            // HTML comes only from the server's escaping/token renderer.
            preview.innerHTML = result.html;
            $$('[src],[href],[data-endpoint]', preview).forEach(el => ['src', 'href', 'data-endpoint'].forEach(attr => {
                if (el.hasAttribute(attr)) el.setAttribute(attr, adminUrl(el.getAttribute(attr)));
            }));
            preview.hidden = false; toggle.setAttribute('aria-pressed', 'true');
            window.NagiSwipe?.init();
            window.NagiLogEmbeds?.load(preview);
        } catch (e) { say(e.message, true); }
    });
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (saving) return;
        if (uploading) { say('画像の追加が終わってから保存してください。', true); return; }
        const data = new FormData(form); data.set('status', e.submitter?.value || 'published');
        saving = true; $$('button[type="submit"],button[name="status"]', form).forEach(b => { b.disabled = true; });
        say('保存しています…');
        try {
            const result = await request(data);
            try { sessionStorage.removeItem(storageKey); } catch { /* storage may be disabled */ }
            dirty = false;
            // Editing an existing post in the admin: ask where to go instead of always returning to the list.
            if (panel.dataset.edit && !publicEditor) { savedDialog(result, data.get('status') === 'published'); return; }
            location.href = publicEditor && !result.id.startsWith('d') ? new URL('../?id=' + encodeURIComponent(result.id), endpoint).href : adminUrl(result.redirect);
        } catch (err) {
            saving = false; say(err.message, true);
            $$('button[type="submit"],button[name="status"]', form).forEach(b => { b.disabled = false; });
        }
    });
    // After an edit is saved: view the post, go back to the list, or keep editing (reloaded for the new revision).
    let saved = null;
    function savedDialog(result, published) {
        const el = (tag, className, text) => { const n = document.createElement(tag); if (className) n.className = className; if (text) n.textContent = text; return n; };
        const link = (text, href, className) => { const a = el('a', className, text); a.href = href; return a; };
        saved = el('dialog', 'log-saved-dialog');
        saved.setAttribute('aria-labelledby', 'log-saved-title');
        const heading = el('h2', '', published ? '保存しました。記事を見ますか？' : '下書きを保存しました'); heading.id = 'log-saved-title';
        const lead = el('p', '', published ? '公開ページで、編集した記事を確認できます。' : '下書きは公開ページに出ないため、続けて編集するか一覧に戻ってください。');
        const editUrl = adminUrl('index.php?p=log_edit&id=' + encodeURIComponent(result.id));
        const view = published ? link('記事を見る', new URL('../?id=' + encodeURIComponent(result.id), endpoint).href, 'btn primary') : null;
        const actions = el('div', 'log-saved-dialog-actions');
        actions.append(link('続けて編集する', editUrl, 'btn'), link('一覧に戻る', adminUrl(result.redirect), published ? 'btn' : 'btn primary'));
        if (view) actions.append(view);
        saved.append(heading, lead, actions);
        // Escape closes the dialog; the form holds the old revision, so reopen the editor.
        saved.addEventListener('cancel', e => { e.preventDefault(); location.replace(editUrl); });
        document.body.append(saved);
        say(published ? '保存しました。' : '下書きを保存しました。');
        saved.showModal();
        (view || actions.lastElementChild).focus();
    }
})();
