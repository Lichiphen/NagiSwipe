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
    $$('.log-replace').forEach(input => input.addEventListener('change', async () => {
        const card = input.closest('[data-media-card]');
        const file = input.files[0];
        if (!file) return;
        if (!window.confirm('この画像を使うすべての投稿が変わります。差し替えますか？')) { input.value = ''; return; }
        const status = $('[data-replace-status]', card);
        input.disabled = true;
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
        finally { input.disabled = false; input.value = ''; }
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
    const storageKey = 'nagimanga-log:' + location.pathname + ':' + ($('input[name="post_id"]', form).value || 'new');
    const initialRevision = $('input[name="revision"]', form).value;
    const categoryFields = $$('input[name="categories[]"]', form);
    const newCategories = $('input[name="new_categories"]', form);
    const current = () => ({ body: body.value, title: title.value, refs, categories: categoryFields.filter(i => i.checked).map(i => i.value), newCategories: newCategories.value });
    const initial = JSON.stringify(current());
    const snapshot = () => JSON.stringify(current());
    function restore(saved) {
        body.value = saved.body; title.value = saved.title || ''; refs = saved.refs || {};
        if (Array.isArray(saved.categories)) categoryFields.forEach(i => { i.checked = saved.categories.includes(i.value); });
        newCategories.value = saved.newCategories || '';
    }
    function say(message, error = false) { status.textContent = message; status.classList.toggle('error', error); }
    function changed() {
        Object.keys(refs).forEach(tag => { if (!body.value.includes(tag)) delete refs[tag]; });
        refsField.value = JSON.stringify(refs);
        $('[data-preview-body]', form).hidden = true;
        $('[data-character-count]', form).textContent = Array.from(body.value).length + '文字';
        dirty = snapshot() !== initial;
        try { sessionStorage.setItem(storageKey, JSON.stringify({ ...current(), revision: initialRevision })); } catch { /* storage may be disabled */ }
    }
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (saved && saved.revision === initialRevision && (saved.body || saved.title)) {
            restore(saved);
            say('このタブで書いていた本文を戻しました。');
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
    changed();
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
            const rect = slot.getBoundingClientRect();
            if (rect.bottom > 0 && rect.top < window.innerHeight) { body.focus({ preventScroll: true }); return; }
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
        if (e.defaultPrevented || picker.open || window.NagiSwipe?.isOpen || document.querySelector('.nm-viewer:not([hidden])')) return;
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
        fab.classList.toggle('log-fab-visible', !panel.classList.contains('active') && (collapsed || (publicEditor && slot.getBoundingClientRect().bottom <= 0)));
    }
    function scrollFab() { if (!scrollTicking) { scrollTicking = true; requestAnimationFrame(checkFab); } }
    if (publicEditor) { window.addEventListener('scroll', scrollFab, { passive: true }); window.addEventListener('resize', scrollFab, { passive: true }); }
    checkFab();
    if (panel.dataset.edit || dirty) setPanel(true);
    window.addEventListener('beforeunload', e => { if ((dirty || uploading) && !saving) { e.preventDefault(); e.returnValue = ''; } });

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
                    insert('\n' + media.tag + '\n');
                    addAttachment(media);
                } catch (e) { errors.push(file.name + '：' + e.message); }
            }
            say(errors.length ? errors.join(' / ') : '画像を追加しました。タグを動かすと、表示する位置も変わります。', errors.length > 0);
        }).finally(() => { uploading--; });
        await uploadQueue;
    }
    let uploadQueue = Promise.resolve();
    function addAttachment(media) {
        const button = document.createElement('button'); button.type = 'button'; button.title = 'この画像のタグをもう一度入れる';
        const img = document.createElement('img'); img.src = adminUrl(media.thumb); img.alt = media.alt || 'アップロードした画像';
        button.append(img); button.addEventListener('click', () => insert('\n' + media.tag + '\n'));
        $('[data-attachments]', form).append(button);
    }
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
                    } else { insert('\n' + item.tag + '\n'); addAttachment(item); }
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
        const preview = $('[data-preview-body]', form);
        if (!preview.hidden) { preview.hidden = true; return; }
        try {
            const data = new FormData(form); data.set('do', 'log_preview');
            const result = await request(data);
            // HTML comes only from the server's escaping/token renderer.
            preview.innerHTML = result.html;
            $$('[src],[href],[data-endpoint]', preview).forEach(el => ['src', 'href', 'data-endpoint'].forEach(attr => {
                if (el.hasAttribute(attr)) el.setAttribute(attr, adminUrl(el.getAttribute(attr)));
            }));
            preview.hidden = false;
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
            dirty = false; location.href = publicEditor && !result.id.startsWith('d') ? new URL('../?id=' + encodeURIComponent(result.id), endpoint).href : adminUrl(result.redirect);
        } catch (err) {
            saving = false; say(err.message, true);
            $$('button[type="submit"],button[name="status"]', form).forEach(b => { b.disabled = false; });
        }
    });
})();
