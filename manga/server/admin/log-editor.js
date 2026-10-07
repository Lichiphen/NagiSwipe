/* Personal LOG editor; panel structure inspired by NagiMemo. MIT (c) 2026 Lichiphen. */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
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
        catch { throw new Error(t('ログインが切れた可能性があります。本文をコピーしてから、ログインし直してください。')); }
        if (!response.ok || result.error) throw new Error(result.error || t('保存できませんでした。もう一度お試しください。'));
        return result;
    }
    // Replace from the file picker or by dropping an image anywhere on the card.
    async function replaceImage(card, file) {
        const input = $('.log-replace', card);
        if (!file || card.dataset.busy) return;
        if (!/^image\/(jpeg|png|webp|gif|avif|bmp|svg\+xml)$/.test(file.type) && !/\.svg$/i.test(file.name)) { $('[data-replace-status]', card).textContent = t('JPEG・PNG・WebP・GIF・AVIF・BMP・SVGの画像を選んでください。'); return; }
        if (!window.confirm(t('この画像を使うすべての投稿が変わります。差し替えますか？'))) { input.value = ''; return; }
        const status = $('[data-replace-status]', card);
        input.disabled = true; card.dataset.busy = '1';
        status.textContent = t('画像を差し替えています…'); status.classList.add('log-busy');
        try {
            const data = new FormData();
            data.set('do', 'log_upload'); data.set('media', card.dataset.mediaCard); data.set('revision', card.dataset.revision); data.set('image', file);
            const { media } = await request(data);
            card.dataset.revision = String(media.revision);
            $$('input[name="revision"]', card).forEach(i => { i.value = String(media.revision); });
            $('img', card).src = media.thumb;
            $('a.imagelink', card).href = media.url;
            window.NagiSwipe?.init();
            status.textContent = t('差し替えました。本文のタグはそのまま使えます。');
        } catch (e) { status.textContent = e.message; }
        finally { input.disabled = false; input.value = ''; delete card.dataset.busy; status.classList.remove('log-busy'); }
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
            if (e.dataTransfer.files.length !== 1) { $('[data-replace-status]', card).textContent = t('差し替える画像を1枚だけドロップしてください。'); return; }
            replaceImage(card, e.dataTransfer.files[0]);
        });
    });
    // Outside the cards a dropped file must not open in the browser and leave the page (with the upload box, log-pages.js adds it instead).
    if ($('[data-media-card]') && !$('[data-media-upload]')) ['dragover', 'drop'].forEach(type => document.addEventListener(type, e => { if (!e.defaultPrevented && [...(e.dataTransfer?.types || [])].includes('Files')) e.preventDefault(); }));

    // Deleting a post from the public page asks first (admin.js, which asks on the admin pages, is not loaded there).
    $$('form[data-log-delete]').forEach(f => f.addEventListener('submit', e => {
        if (!window.confirm(t('この記事を削除しますか？この記事だけで使う画像も削除します。元に戻せません。'))) e.preventDefault();
    }));

    // 編集 on a public page remembers where it was; closing or saving the editor goes back to that post.
    const returnKey = 'nagilog-edit-return';
    const readReturn = () => { try { return JSON.parse(sessionStorage.getItem(returnKey) || 'null'); } catch { return null; } };
    const writeReturn = value => { try { if (value) sessionStorage.setItem(returnKey, JSON.stringify(value)); else sessionStorage.removeItem(returnKey); } catch {} };
    const editId = link => { try { return new URL(link.href).searchParams.get('id') || ''; } catch { return ''; } };
    document.addEventListener('click', e => {
        const link = e.target.closest?.('a.log-edit-link');
        if (!link || !/[?&]p=log_edit&/.test(link.getAttribute('href'))) return;
        const post = link.closest('.log-post');
        writeReturn({ id: editId(link), href: location.href.split('#')[0], y: scrollY, top: post ? post.getBoundingClientRect().top : 0 });
    });
    const back = readReturn();
    if (back && !form?.closest('.log-edit') && back.href === location.href.split('#')[0]) {
        writeReturn(null);
        // Back from the editor: put the post where it was on screen. Posts added by もっと見る are loaded again first.
        const find = () => $$('a.log-edit-link').find(a => editId(a) === back.id)?.closest('.log-post');
        let moved = false, tries = 0;
        const stop = () => { moved = true; };
        ['wheel', 'touchstart', 'keydown', 'mousedown'].forEach(type => addEventListener(type, stop, { once: true, passive: true }));
        const place = () => {
            if (moved) return;
            const post = find();
            if (post) { scrollTo(0, scrollY + post.getBoundingClientRect().top - back.top); return; }
            const more = $('[data-pager-more]');
            if (more && tries++ < 20 && !more.hasAttribute('aria-busy')) { more.click(); setTimeout(place, 600); return; }
            if (more && tries < 20) { setTimeout(place, 300); return; }
            scrollTo(0, back.y);
        };
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        place();
        // Embeds above the post change height while they load, so keep it in place for a moment.
        addEventListener('load', () => { place(); setTimeout(place, 800); setTimeout(place, 2000); });
    }
    addEventListener('pageshow', e => { if (e.persisted && readReturn()?.href === location.href.split('#')[0]) writeReturn(null); });

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
    // Pictures sit in rows under the text, not as tags in it. Each row is a group with a number; the text marks
    // where a group goes with a 〔画像N〕 line. Groups without a mark go after the text. Numbers stay as they are
    // while writing (an undone deletion finds its group again); a post opened again is numbered from the top.
    // Saving and the preview put the tags back in place of the marks, so the server only ever sees tags.
    const MARK_RE = /〔画像(\d+)〕/g;
    const markOf = n => '〔画像' + n + '〕';
    const TAG = /\[Image:([a-f0-9]{16})\]/g;
    let groups = [], active = null;
    const tagsOf = ids => ids.map(id => '[Image:' + id + ']').join('\n');
    function split(value) {
        const found = [...value.matchAll(TAG)], runs = [];
        found.forEach((m, i) => {
            if (i && value.slice(found[i - 1].index + found[i - 1][0].length, m.index).trim() === '') runs.at(-1).push(m);
            else runs.push([m]);
        });
        let text = '', from = 0;
        const list = runs.map((run, k) => {
            const start = run[0].index, end = run.at(-1).index + run.at(-1)[0].length;
            const last = k === runs.length - 1 && value.slice(end).trim() === '';
            text += value.slice(from, start) + (last ? '' : markOf(k + 1));
            from = last ? value.length : end;
            return { n: k + 1, ids: [...new Set(run.map(m => m[1]))] };
        });
        return { text: text + value.slice(from), groups: list };
    }
    // Where each group goes: the first mark of its number, top to bottom; the rest after the text, by number.
    function view(text = body.value) {
        const at = new Map();
        for (const m of text.matchAll(MARK_RE)) { const n = +m[1]; if (!at.has(n) && groups.some(g => g.n === n)) at.set(n, m.index); }
        return {
            marked: groups.filter(g => at.has(g.n)).sort((a, b) => at.get(a.n) - at.get(b.n)),
            end: groups.filter(g => !at.has(g.n)).sort((a, b) => a.n - b.n), at,
        };
    }
    const allImages = () => { const v = view(); return [...new Set([...v.marked, ...v.end].flatMap(g => g.ids))]; };
    const nextNumber = () => Math.max(0, ...groups.map(g => g.n), ...[...body.value.matchAll(MARK_RE)].map(m => +m[1])) + 1;
    function compose(text = body.value) {
        const used = new Set(), { end } = view(text);
        // A mark that brings nothing (an empty group, an unknown number) goes with its line break.
        const out = text.replace(/(\n)?〔画像(\d+)〕(\n)?/g, (m, lead = '', n, tail = '') => {
            const g = groups.find(x => x.n === +n), ids = g && !used.has(g) ? g.ids : [];
            if (g) used.add(g);
            return ids.length ? lead + tagsOf(ids) + tail : (lead && tail ? '\n' : '');
        });
        const rest = end.flatMap(g => g.ids);
        if (!rest.length) return out;
        if (out.trim() === '') return tagsOf(rest);
        return out + (out.endsWith('\n') ? '' : '\n') + tagsOf(rest);
    }
    function useBody(value) {
        const parts = split(value);
        groups = parts.groups; active = null;
        body.value = parts.text;
    }
    useBody(body.value);
    selection = [body.value.length, body.value.length];
    // Each mark drawn over the text as a band in the theme's accent with its pictures, which can be dragged to
    // another line. Only the bands are drawn (the rest is transparent), so typing, the IME's underlines and the
    // caret stay the textarea's own.
    const markLayer = document.createElement('div');
    markLayer.className = 'log-mark-layer'; markLayer.setAttribute('aria-hidden', 'true'); markLayer.hidden = true;
    body.after(markLayer);
    const COPIED = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing', 'wordSpacing', 'textIndent', 'tabSize', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft'];
    const grip = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6h.01M15 6h.01M9 12h.01M15 12h.01M9 18h.01M15 18h.01"/></svg>';
    function drawMark() {
        const { at } = view();
        markLayer.hidden = !at.size;
        if (!at.size) return;
        const cs = getComputedStyle(body);
        COPIED.forEach(name => { markLayer.style[name] = cs[name]; });
        markLayer.style.left = (body.offsetLeft + body.clientLeft) + 'px'; markLayer.style.top = (body.offsetTop + body.clientTop) + 'px';
        markLayer.style.width = body.clientWidth + 'px'; markLayer.style.height = body.clientHeight + 'px';
        const text = body.value, nodes = [];
        let from = 0;
        for (const m of text.matchAll(MARK_RE)) {
            const g = groups.find(x => x.n === +m[1]);
            if (!g || at.get(g.n) !== m.index) continue;
            nodes.push(text.slice(from, m.index));
            const mark = document.createElement('span'); mark.className = 'log-mark'; mark.dataset.len = String(m[0].length); mark.textContent = m[0];
            const band = document.createElement('span'); band.className = 'log-mark-band'; band.dataset.n = String(g.n);
            band.title = t('ドラッグで別の行へ動かす');
            band.innerHTML = grip;
            const label = document.createElement('b'); label.textContent = t('画像{n}', { n: g.n }); band.append(label);
            g.ids.slice(0, 4).forEach(id => {
                const thumb = mediaInfo[id]?.thumb;
                const img = document.createElement(thumb ? 'img' : 'i');
                if (thumb) { img.src = adminUrl(thumb); img.alt = ''; img.draggable = false; }
                band.append(img);
            });
            if (g.ids.length > 4) band.append('+' + (g.ids.length - 4));
            if (!g.ids.length) band.append(t('空'));
            mark.append(band); nodes.push(mark);
            from = m.index + m[0].length;
        }
        nodes.push(text.slice(from), '​');
        markLayer.replaceChildren(...nodes);
        markLayer.scrollTop = body.scrollTop;
    }
    body.addEventListener('scroll', () => { markLayer.scrollTop = body.scrollTop; });
    if ('ResizeObserver' in window) new ResizeObserver(() => drawMark()).observe(body);
    // Dragging a band: the line under the pointer is found from the layer, which is laid out like the text.
    const caretAt = (x, y) => {
        if (document.caretPositionFromPoint) { const p = document.caretPositionFromPoint(x, y); return p ? [p.offsetNode, p.offset] : null; }
        if (document.caretRangeFromPoint) { const r = document.caretRangeFromPoint(x, y); return r ? [r.startContainer, r.startOffset] : null; }
        return null;
    };
    const nodeLength = node => node.nodeType === 3 ? node.length : +(node.dataset?.len || 0);
    function offsetIn(node, offset) {
        let at = 0;
        for (const child of markLayer.childNodes) {
            if (child === node) return at + offset;
            if (child.contains(node)) return at + (node === child.firstChild ? offset : 0);
            at += nodeLength(child);
        }
        return null;
    }
    function pointIn(offset) {
        let at = 0;
        for (const child of markLayer.childNodes) {
            const length = nodeLength(child);
            if (offset < at + length || child === markLayer.lastChild) return child.nodeType === 3 ? [child, Math.min(offset - at, child.length)] : [child.firstChild, 0];
            at += length;
        }
        return null;
    }
    // The start of the line under y; the end of the text below the last line.
    function lineAt(y) {
        const box = markLayer.getBoundingClientRect(), x = box.left + parseFloat(markLayer.style.paddingLeft || '0') + 1;
        const text = body.value, last = document.createRange();
        last.selectNodeContents(markLayer.lastChild);
        if (y > last.getBoundingClientRect().bottom) return text.length;
        markLayer.classList.add('is-probing');
        const hit = caretAt(x, Math.max(box.top + 1, Math.min(box.bottom - 1, y)));
        markLayer.classList.remove('is-probing');
        const offset = hit ? offsetIn(...hit) : null;
        if (offset === null) return null;
        return Math.min(offset, text.length) === text.length && y > box.bottom - 2 ? text.length : text.lastIndexOf('\n', Math.min(offset, text.length) - 1) + 1;
    }
    const dropLine = document.createElement('i'); dropLine.className = 'log-mark-drop'; dropLine.hidden = true;
    function showDrop(start) {
        if (start === null) { dropLine.hidden = true; return; }
        const range = document.createRange(), text = body.value;
        const [node, local] = pointIn(Math.min(start, Math.max(0, text.length - 1)));
        range.setStart(node, local); range.setEnd(node, Math.min(local + 1, node.length ?? 0));
        const rect = range.getClientRects()[0] || range.getBoundingClientRect(), box = markLayer.getBoundingClientRect();
        // Beside the layer, not in it: the layer's nodes must stay the text alone.
        if (!dropLine.isConnected) markLayer.after(dropLine);
        dropLine.style.left = (markLayer.offsetLeft + 8) + 'px'; dropLine.style.width = (markLayer.offsetWidth - 16) + 'px';
        dropLine.style.top = (markLayer.offsetTop + (start >= text.length ? rect.bottom : rect.top) - box.top) + 'px'; dropLine.hidden = false;
    }
    function moveMark(n, start) {
        let text = body.value;
        const m = [...text.matchAll(MARK_RE)].find(x => +x[1] === n);
        if (!m || start === null) return;
        let a = m.index, b = a + m[0].length;
        if (text[b] === '\n') b++; else if (a > 0 && text[a - 1] === '\n') a--;
        if (start >= a && start <= b) return;
        text = text.slice(0, a) + text.slice(b);
        if (start > b) start -= b - a;
        start = Math.min(start, text.length);
        text = start >= text.length ? text + (text === '' || text.endsWith('\n') ? '' : '\n') + m[0] : text.slice(0, start) + m[0] + '\n' + text.slice(start);
        body.value = text; rememberSelection(); changed();
        say(t('「{mark}」の行を動かしました。', { mark: m[0] }));
    }
    let bandDrag = null;
    markLayer.addEventListener('pointerdown', e => {
        const band = e.target.closest('.log-mark-band');
        if (!band || e.button !== 0) return;
        e.preventDefault();
        bandDrag = { n: +band.dataset.n, band, x: e.clientX, y: e.clientY, pointer: e.pointerId, on: false, start: null };
        try { band.setPointerCapture(e.pointerId); } catch { /* already released */ }
    });
    markLayer.addEventListener('pointermove', e => {
        if (!bandDrag || e.pointerId !== bandDrag.pointer) return;
        if (!bandDrag.on) {
            if (Math.hypot(e.clientX - bandDrag.x, e.clientY - bandDrag.y) < 5) return;
            bandDrag.on = true; bandDrag.band.classList.add('is-dragging'); markLayer.classList.add('is-moving');
        }
        const box = body.getBoundingClientRect();
        if (e.clientY < box.top + 24) body.scrollTop -= 12; else if (e.clientY > box.bottom - 24) body.scrollTop += 12;
        markLayer.scrollTop = body.scrollTop;
        bandDrag.band.style.transform = 'translateY(calc(-50% + ' + (e.clientY - bandDrag.y) + 'px))';
        bandDrag.start = lineAt(e.clientY);
        showDrop(bandDrag.start);
    });
    function endBand(apply) {
        if (!bandDrag) return;
        const { n, on, start } = bandDrag;
        bandDrag = null; dropLine.hidden = true; markLayer.classList.remove('is-moving');
        if (on && apply) moveMark(n, start);
        drawMark();
    }
    markLayer.addEventListener('pointerup', e => { if (bandDrag && e.pointerId === bandDrag.pointer) endBand(true); });
    markLayer.addEventListener('pointercancel', () => endBand(false));
    const rating = () => ratingFields.find(i => i.checked)?.value || '';
    const current = () => ({ body: compose(), title: title.value, refs, categories: categoryFields.filter(i => i.checked).map(i => i.value), newCategories: newCategories.value, rating: rating(), warning: warning.value, mediaRatings });
    const initial = JSON.stringify(current());
    const snapshot = () => JSON.stringify(current());
    function restore(saved) {
        useBody(saved.body || ''); title.value = saved.title || ''; refs = saved.refs || {};
        if (Array.isArray(saved.categories)) categoryFields.forEach(i => { i.checked = saved.categories.includes(i.value); });
        newCategories.value = saved.newCategories || '';
        if (typeof saved.rating === 'string') ratingFields.forEach(i => { i.checked = i.value === saved.rating; });
        warning.value = saved.warning || '';
        mediaRatings = saved.mediaRatings && typeof saved.mediaRatings === 'object' ? saved.mediaRatings : {};
    }
    // A message still at work ends with "…": it gets the small wave (log.css .log-busy).
    function say(message, error = false) { status.textContent = message; status.classList.toggle('error', error); status.classList.toggle('log-busy', !error && message.endsWith('…')); }
    function changed(user = true) {
        if (user) touched = true;
        Object.keys(refs).forEach(tag => { if (!body.value.includes(tag)) delete refs[tag]; });
        refsField.value = JSON.stringify(refs);
        $('input[name="media_ratings"]', form).value = JSON.stringify(mediaRatings);
        // A group with no pictures lives only while its mark is in the text.
        const { at } = view(); groups = groups.filter(g => g.ids.length || at.has(g.n));
        renderAttachments(); renderChips(); drawMark();
        $('[data-preview-body]', form).hidden = true; $('[data-preview]', form).setAttribute('aria-pressed', 'false');
        $('[data-character-count]', form).textContent = t('{n}文字', { n: Array.from(body.value).length });
        dirty = snapshot() !== initial;
        fab?.classList.toggle('log-fab-draft', dirty);
        if (fab) fab.title = dirty ? t('書きかけがあります') : '';
        try { sessionStorage.setItem(storageKey, JSON.stringify({ ...current(), revision: initialRevision })); } catch { /* storage may be disabled */ }
    }
    try {
        const saved = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
        if (saved && saved.revision === initialRevision && (saved.body || saved.title)) {
            restore(saved);
            say(t('このタブで書いていた本文を戻しました。'));
            const discard = document.createElement('button');
            discard.type = 'button'; discard.className = 'btn'; discard.textContent = t('書きかけを消す');
            status.after(discard);
            discard.addEventListener('click', () => {
                restore(JSON.parse(initial)); changed(false); touched = false; discard.remove();
                try { sessionStorage.removeItem(storageKey); } catch { /* storage may be disabled */ }
                say(t('書きかけを消しました。'));
            });
        } else if (saved && saved.revision !== initialRevision) {
            const recover = document.createElement('button');
            recover.type = 'button'; recover.className = 'btn'; recover.textContent = t('このタブの未保存本文を戻す');
            status.after(recover);
            recover.addEventListener('click', () => {
                restore(saved); changed(); recover.remove();
                say(t('未保存の本文を戻しました。新しい投稿内容と比べてから保存してください。'));
            });
        }
    } catch { /* storage may be disabled */ }
    // Ratings, weakest first, as on the server (NL_RATINGS).
    const RANK = ['', 'sensitive', 'r18g', 'r18'];
    const LABEL = { '': t('なし'), sensitive: t('センシティブ'), r18: 'R-18', r18g: 'R-18G' };
    const bodyMedia = () => allImages();
    const mediaRating = id => mediaRatings[id] ?? mediaInfo[id]?.rating ?? '';
    const strongest = list => list.reduce((a, b) => RANK.indexOf(b) > RANK.indexOf(a) ? b : a, '');
    const veilIcon = '<svg class="log-veil-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M10.3 4.2a2 2 0 0 1 3.4 0l7.6 13.1a2 2 0 0 1-1.7 3H4.4a2 2 0 0 1-1.7-3Z"/><path d="M12 9.5v4.2M12 16.9v.1"/></svg>';
    // Pictures from tags typed or pasted by hand: ask the server once for their thumbnails and ratings.
    const asked = new Set();
    async function lookup(ids) {
        ids.forEach(id => asked.add(id));
        try {
            const response = await fetch(adminUrl('index.php?p=log_media_json&ids=' + ids.join(',')), { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            (await response.json()).items.forEach(item => { mediaInfo[item.id] = { thumb: item.thumb, alt: item.alt, rating: item.rating || '' }; });
            renderAttachments(); renderChips(); drawMark();
        } catch { /* the strip keeps its placeholders */ }
    }
    // Under the text: one row per group, in the order they appear (after the text last). Pictures move by
    // dragging (hold first on touch screens) within a row or to another, or with the arrow keys.
    function renderAttachments() {
        const ids = allImages();
        const unknown = ids.filter(id => !mediaInfo[id] && !asked.has(id));
        if (unknown.length) lookup(unknown);
        const { marked, end } = view(), rows = [...marked, ...end];
        const key = JSON.stringify([rows.map(g => [g.n, marked.includes(g), g === active, g.ids.map(id => id + ':' + mediaRating(id) + ':' + (mediaInfo[id]?.thumb || ''))])]);
        if (attachments.dataset.key === key) return;
        attachments.dataset.key = key;
        const button = (className, html, title, onClick) => {
            const b = document.createElement('button'); b.type = 'button'; b.className = className; b.innerHTML = html;
            if (title) b.title = title;
            b.addEventListener('click', onClick); return b;
        };
        const nodes = rows.map(g => {
            const inText = marked.includes(g);
            const group = document.createElement('section'); group.className = 'log-att-group' + (g === active ? ' is-active' : ''); group.dataset.n = String(g.n);
            const head = document.createElement('div'); head.className = 'log-att-head';
            const label = document.createElement('b'); label.className = 'log-att-label'; label.textContent = t('画像{n}', { n: g.n });
            const where = document.createElement('span'); where.className = 'log-att-where';
            where.textContent = inText ? t('本文の「{mark}」の行', { mark: markOf(g.n) }) : t('本文の最後');
            head.append(label, where);
            if (inText) head.append(button('log-att-action', g.ids.length ? t('本文の最後へ') : t('この場所を消す'), t(g.ids.length ? '「{mark}」の行を消して、本文の最後に並べます' : '「{mark}」の行を消します', { mark: markOf(g.n) }), () => {
                body.value = removeMarkOf(body.value, g.n); rememberSelection(); changed();
                say(g.ids.length ? t('画像{n}を本文の最後に並べます。', { n: g.n }) : t('「{mark}」を消しました。', { mark: markOf(g.n) }));
            }));
            else head.append(button('log-att-action', t('カーソルの位置へ'), t('本文のカーソル位置に「{mark}」の行を入れます', { mark: markOf(g.n) }), () => {
                insertMark(g.n); say(t('「{mark}」の行に画像{n}を並べます。本文の中の帯をドラッグすると、行を動かせます。', { mark: markOf(g.n), n: g.n }));
            }));
            const row = document.createElement('div'); row.className = 'log-att-row';
            g.ids.forEach((id, i) => {
                const info = mediaInfo[id] || {}, r = mediaRating(id);
                const item = document.createElement('div'); item.className = 'log-att'; item.dataset.mediaId = id;
                const thumb = document.createElement('button'); thumb.type = 'button'; thumb.className = 'log-att-thumb';
                thumb.title = t('ドラッグで並べ替え・別のまとまりへ移動'); thumb.setAttribute('aria-label', t('画像{n}の{i}枚目（矢印キーで移動）', { n: g.n, i: i + 1 }));
                // Never dragged by the browser itself: a dropped copy would be uploaded again.
                if (info.thumb) { const img = document.createElement('img'); img.src = adminUrl(info.thumb); img.alt = ''; img.draggable = false; thumb.append(img); }
                else thumb.append(t('画像'));
                const rate = document.createElement('button'); rate.type = 'button'; rate.className = 'log-att-rating'; rate.dataset.veil = r;
                rate.setAttribute('aria-haspopup', 'menu'); rate.setAttribute('aria-expanded', 'false');
                rate.setAttribute('aria-label', t('この画像の閲覧注意：{label}', { label: LABEL[r] })); rate.title = t('閲覧注意：{label}', { label: LABEL[r] });
                rate.innerHTML = veilIcon + (r ? '<span>' + LABEL[r] + '</span>' : '');
                rate.addEventListener('click', () => openMenu(rate, id));
                const remove = button('log-att-remove', '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>', t('この投稿から外す（画像一覧には残ります）'), () => {
                    g.ids = g.ids.filter(other => other !== id); changed();
                    ($$('.log-att-group[data-n="' + g.n + '"] .log-att-thumb', attachments)[Math.min(i, g.ids.length - 1)] || body).focus();
                    say(t('画像を外しました。画像そのものは画像一覧に残っています。'));
                });
                remove.setAttribute('aria-label', t('画像{n}の{i}枚目を外す', { n: g.n, i: i + 1 }));
                item.append(thumb, rate, remove);
                row.append(item);
            });
            const add = button('log-att-add', '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>', t('画像{n}に画像を追加', { n: g.n }), () => { active = g; uploadInput.click(); });
            add.setAttribute('aria-label', t('画像{n}に画像を追加', { n: g.n }));
            row.append(add);
            group.append(head, row);
            return group;
        });
        if (ids.length || rows.length) nodes.push(button('log-att-new', '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg><span>' + t('別の場所にも画像を置く') + '</span>', t('本文のカーソル位置に新しい「〔画像N〕」の行を入れます'), () => {
            const g = { n: nextNumber(), ids: [] };
            groups.push(g); active = g; insertMark(g.n);
            say(t('「{mark}」の行を入れました。画像{n}の＋から画像を追加してください。', { mark: markOf(g.n), n: g.n }));
        }));
        attachments.replaceChildren(...nodes);
        attachments.hidden = !rows.length;
    }
    // The mark on its own line goes with its line break; anywhere else, just the mark.
    const removeMarkOf = (text, n) => text.replace(new RegExp('(\\n)?〔画像' + n + '〕(\\n)?', 'g'), (m, lead, tail) => lead && tail ? '\n' : '');
    function insertMark(n) {
        const [start, end] = selection, before = body.value.slice(0, start);
        insert((before === '' || before.endsWith('\n') ? '' : '\n') + markOf(n) + (body.value.slice(end).startsWith('\n') ? '' : '\n'));
    }
    let drag = null;
    const rowsNow = () => $$('.log-att-group', attachments);
    function endDrag(apply) {
        if (!drag) return;
        clearTimeout(drag.timer);
        if (drag.on) {
            drag.item.classList.remove('is-dragging'); attachments.classList.remove('is-sorting');
            if (apply) {
                rowsNow().forEach(row => { const g = groups.find(x => x.n === +row.dataset.n); if (g) g.ids = $$('.log-att', row).map(n => n.dataset.mediaId); });
                active = groups.find(x => x.n === +drag.item.closest('.log-att-group').dataset.n) || active;
                changed();
            } else { attachments.dataset.key = ''; renderAttachments(); }
        }
        drag = null;
    }
    attachments.addEventListener('pointerdown', e => {
        const thumb = e.target.closest('.log-att-thumb');
        if (!thumb || e.button !== 0) return;
        drag = { item: thumb.closest('.log-att'), x: e.clientX, y: e.clientY, pointer: e.pointerId, on: false, timer: 0 };
        drag.start = () => { drag.on = true; drag.item.classList.add('is-dragging'); attachments.classList.add('is-sorting'); try { drag.item.setPointerCapture(drag.pointer); } catch { /* already released */ } };
        if (e.pointerType === 'touch') drag.timer = setTimeout(() => { if (drag && !drag.on) drag.start(); }, 350);
    });
    attachments.addEventListener('pointermove', e => {
        if (!drag || e.pointerId !== drag.pointer) return;
        if (!drag.on) {
            if (Math.hypot(e.clientX - drag.x, e.clientY - drag.y) < 6) return;
            // A quick swipe on a touch screen scrolls instead.
            if (e.pointerType === 'touch') { endDrag(false); return; }
            drag.start();
        }
        e.preventDefault();
        // The row under the pointer (or the nearest), then the place in it.
        const rows = rowsNow().map(group => [group, group.getBoundingClientRect()]);
        const [group] = rows.find(([, r]) => e.clientY >= r.top && e.clientY <= r.bottom) || rows.reduce((best, cur) => Math.abs(e.clientY - (cur[1].top + cur[1].bottom) / 2) < Math.abs(e.clientY - (best[1].top + best[1].bottom) / 2) ? cur : best);
        const row = $('.log-att-row', group);
        const next = $$('.log-att', row).filter(n => n !== drag.item).find(n => { const r = n.getBoundingClientRect(); return e.clientX < r.left + r.width / 2; }) || $('.log-att-add', row);
        if (drag.item.nextElementSibling !== next) row.insertBefore(drag.item, next);
        const box = row.getBoundingClientRect();
        if (e.clientX < box.left + 24) row.scrollLeft -= 12; else if (e.clientX > box.right - 24) row.scrollLeft += 12;
    });
    attachments.addEventListener('pointerup', e => { if (drag && e.pointerId === drag.pointer) endDrag(true); });
    attachments.addEventListener('pointercancel', () => endDrag(false));
    attachments.addEventListener('touchmove', e => { if (drag?.on) e.preventDefault(); }, { passive: false });
    attachments.addEventListener('contextmenu', e => { if (e.target.closest('.log-att-thumb')) e.preventDefault(); });
    attachments.addEventListener('keydown', e => {
        const thumb = e.target.closest('.log-att-thumb');
        if (!thumb || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(e.key)) return;
        e.preventDefault();
        const { marked, end } = view(), rows = [...marked, ...end];
        const id = thumb.closest('.log-att').dataset.mediaId, g = groups.find(x => x.n === +thumb.closest('.log-att-group').dataset.n);
        const i = g.ids.indexOf(id);
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
            const to = i + (e.key === 'ArrowLeft' ? -1 : 1);
            if (to < 0 || to >= g.ids.length) return;
            g.ids.splice(i, 1); g.ids.splice(to, 0, id); changed();
            say(t('画像{n}の{i}枚目に移しました。', { n: g.n, i: to + 1 }));
        } else {
            const other = rows[rows.indexOf(g) + (e.key === 'ArrowUp' ? -1 : 1)];
            if (!other) return;
            g.ids.splice(i, 1); other.ids.push(id); active = other; changed();
            say(t('画像{n}の最後に移しました。', { n: other.n }));
        }
        $('.log-att-group[data-n="' + groups.find(x => x.ids.includes(id))?.n + '"] .log-att[data-media-id="' + id + '"] .log-att-thumb', attachments)?.focus();
    });
    // New pictures go to the group last chosen (its +, a picture moved into it), otherwise after the text.
    function addImage(id) {
        if (allImages().includes(id)) { say(t('その画像はもう入っています。')); return; }
        let g = groups.includes(active) ? active : view().end[0];
        if (!g) { g = { n: nextNumber(), ids: [] }; groups.push(g); }
        g.ids.push(id); active = g; changed();
    }
    // Tags typed or pasted into the text move to the rows: a new group where text follows them, else after the text.
    function absorb() {
        if (body.value.search(/\[Image:[a-f0-9]{16}\]/) < 0) return;
        const caret = body.selectionStart, found = [];
        let first = -1;
        let text = body.value.replace(/[ \t]*\[Image:([a-f0-9]{16})\][ \t]*\r?\n?/g, (m, id, offset) => { if (!found.includes(id) && !allImages().includes(id)) found.push(id); if (first < 0) first = offset; return ''; });
        if (found.length) {
            if (text.slice(first).trim() !== '') {
                const g = { n: nextNumber(), ids: found }; groups.push(g); active = g;
                text = text.slice(0, first) + markOf(g.n) + '\n' + text.slice(first);
            } else {
                let g = view().end[0];
                if (!g) { g = { n: nextNumber(), ids: [] }; groups.push(g); }
                g.ids.push(...found); active = g;
            }
        }
        const at = Math.max(0, Math.min(text.length, caret - (body.value.length - text.length)));
        body.value = text; body.setSelectionRange(at, at); rememberSelection();
        say(t('画像のタグを、本文の下の画像の列に移しました。'));
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
            text.textContent = LABEL[shown] + (shown !== own ? t('（画像）') : '') + (warning.value.trim() ? '・' + warning.value.trim() : '');
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
        tool.dataset.veil = shown; tool.setAttribute('aria-label', shown ? t('閲覧注意：{label}', { label: LABEL[shown] }) : t('閲覧注意'));
        $('[data-panel="categories"]', form).classList.toggle('is-set', names.length > 0);
    }
    // Phones: a panel opening under the tool row would push the text up and down, so it opens as a sheet from the bottom.
    // The sheet is a dialog inside the form: the fields moved into it are still sent. Tapping outside or 閉じる closes it.
    const phone = matchMedia('(max-width:900px)');
    const sheet = document.createElement('dialog');
    sheet.className = 'log-panel-sheet';
    sheet.setAttribute('aria-labelledby', 'log-panel-sheet-title');
    sheet.innerHTML = '<div class="log-panel-sheet-head"><span class="log-panel-sheet-grip" aria-hidden="true"></span><h2 id="log-panel-sheet-title"></h2><button type="button" class="btn" data-sheet-close>' + t('閉じる') + '</button></div><div class="log-panel-sheet-body"></div>';
    form.append(sheet);
    let sheetPanel = null, sheetPlace = null;
    function sheetIn(panelBody, button) {
        if (sheetPanel === panelBody) return;
        sheetOut();
        sheetPlace = document.createComment('panel'); panelBody.before(sheetPlace);
        $('.log-panel-sheet-body', sheet).append(panelBody); sheetPanel = panelBody;
        $('#log-panel-sheet-title', sheet).textContent = button.getAttribute('aria-label');
        if (!sheet.open) sheet.showModal();
    }
    function sheetOut() {
        if (!sheetPanel) return;
        sheetPlace.replaceWith(sheetPanel); sheetPanel = sheetPlace = null;
    }
    sheet.addEventListener('close', () => {
        const name = sheetPanel?.dataset.panelBody;
        sheetOut();
        if (name) { togglePanel(name, false); $('[data-panel="' + name + '"]', form)?.focus({ preventScroll: true }); }
    });
    sheet.addEventListener('click', event => { if (event.target === sheet || event.target.closest('[data-sheet-close]')) sheet.close(); });
    phone.addEventListener('change', () => { if (sheet.open) sheet.close(); });
    // One panel at a time under the tool row (a sheet on phones).
    function togglePanel(name, open) {
        $$('[data-panel]', form).forEach(button => {
            const on = button.dataset.panel === name && (open ?? button.getAttribute('aria-expanded') !== 'true');
            button.setAttribute('aria-expanded', String(on));
            $('[data-panel-body="' + button.dataset.panel + '"]', form).hidden = !on;
        });
        const panelBody = $('[data-panel-body="' + name + '"]:not([hidden])', form);
        if (phone.matches && panelBody) sheetIn(panelBody, $('[data-panel="' + name + '"]', form));
        else if (sheet.open && !panelBody) sheet.close();
        // On phones never start in a text field: the keyboard would cover the sheet.
        if (panelBody && (open || phone.matches)) (panelBody.querySelector(phone.matches ? 'input:checked, input[type="checkbox"], input[type="radio"], button' : 'input:checked, input, button') || $('[data-sheet-close]', sheet)).focus({ preventScroll: true });
        if (!phone.matches) panelBody?.scrollIntoView({ block: 'nearest' });
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
    body.addEventListener('input', () => { absorb(); changed(); }); title.addEventListener('input', changed);
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
        const text = t('太字にする文字');
        insert('**' + text + '**');
        body.focus(); body.setSelectionRange(start + 2, start + 2 + text.length); rememberSelection();
    });
    let previousFocus = null;
    let inertNodes = [];
    function accessibility(open) {
        inertNodes.forEach(([el, was]) => { el.inert = was; }); inertNodes = [];
        if (open && (mobile.matches || slot)) {
            panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true');
            $$('header.top, footer.foot, .main > :not(#log-compose):not(.log-fab):not(.log-picker):not(.log-compose-slot), .log-site-header, .log-topmenu, .log-site-footer, .log-menu-toggle, .log-sidebar, .log-site-main > :not(.log-compose-slot)').forEach(el => { inertNodes.push([el, el.inert]); el.inert = true; });
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
    // The public page this edit was opened from, kept while the editor reloads for 続けて編集する.
    const origin = (() => {
        const id = $('input[name="post_id"]', form)?.value, from = document.referrer.split('#')[0];
        if (!panel.dataset.edit || !back || back.id !== id) return null;
        const again = (() => { try { const u = new URL(from); return u.pathname === location.pathname && u.searchParams.get('p') === 'log_edit' && u.searchParams.get('id') === id; } catch { return false; } })();
        if (from !== back.href && !(back.active && again)) { writeReturn(null); return null; }
        writeReturn({ ...back, active: true });
        return back.href;
    })();
    // Opened from a public page: a way back to it next to 投稿一覧に戻る (the admin list needs none).
    const crumb = $('.crumb-back');
    if (origin && crumb) {
        const toPublic = document.createElement('a');
        toPublic.className = 'crumb-return'; toPublic.href = origin;
        toPublic.textContent = new URL(origin).searchParams.has('id') ? t('記事ページに戻る') : t('公開ページに戻る');
        crumb.prepend(toPublic);
    }
    $('.log-close', panel).addEventListener('click', () => {
        if (panel.dataset.edit) { location.href = origin || 'index.php?p=log'; return; }
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
            setPanel(true); say(t('画像をアップロードしています…'));
            const errors = [];
            for (const file of list) {
                try {
                    const data = new FormData(); data.set('do', 'log_upload'); data.set('image', file);
                    const { media } = await request(data);
                    mediaInfo[media.id] = { thumb: media.thumb, alt: media.alt, rating: media.rating || '' };
                    addImage(media.id);
                } catch (e) { errors.push(t('{name}：{message}', { name: file.name, message: e.message })); }
            }
            say(errors.length ? errors.join(' / ') : t('画像を追加しました。サムネイルをドラッグすると、並べ替えや別のまとまりへの移動ができます。'), errors.length > 0);
        }).finally(() => { uploading--; });
        await uploadQueue;
    }
    let uploadQueue = Promise.resolve();
    uploadInput.addEventListener('change', () => { upload(uploadInput.files); uploadInput.value = ''; });
    // A picture dragged from this page (a thumbnail, an image in a post) is not a new file: never upload it again,
    // and keep the browser from writing its URL into the text.
    let pageDrag = false;
    document.addEventListener('dragstart', e => { pageDrag = !e.target.closest?.('textarea, input'); });
    document.addEventListener('dragend', () => { pageDrag = false; });
    document.addEventListener('dragover', e => {
        if (pageDrag) return;
        if (Array.from(e.dataTransfer?.types || []).includes('Files')) { e.preventDefault(); panel.classList.add('dragover'); }
    });
    document.addEventListener('dragleave', e => { if (!e.relatedTarget) panel.classList.remove('dragover'); });
    document.addEventListener('drop', e => {
        if (pageDrag) { if (form.contains(e.target)) e.preventDefault(); return; }
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
        more.disabled = true; pickerStatus.textContent = t('読み込み中…');
        try {
            const response = await fetch(adminUrl('index.php?p=log_' + kind + '_json&page=' + catalogPage + '&q=' + encodeURIComponent(search.value)), { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) throw new Error(t('一覧を読めませんでした。ログインを確認してください。'));
            const result = await response.json();
            if (currentGeneration !== generation) return;
            result.items.forEach(item => {
                const button = document.createElement('button'); button.type = 'button';
                const img = document.createElement('img'); img.src = adminUrl(item.thumb); img.alt = ''; img.loading = 'lazy';
                const label = document.createElement('span'); label.textContent = item.title || item.alt || t('画像');
                button.append(img, label);
                if (item.locked) { const badge = document.createElement('small'); badge.textContent = t('パスワード付き'); button.append(badge); }
                button.addEventListener('click', () => {
                    if (kind === 'manga') {
                        // An old post may already use this label for another work.
                        let tag = item.tag;
                        if (refs[tag] && refs[tag] !== item.id) tag = tag.slice(0, -1) + ' #' + item.id + ']';
                        refs[tag] = item.id; insert('\n' + tag + '\n');
                    } else { mediaInfo[item.id] = { thumb: item.thumb, alt: item.alt, rating: item.rating || '' }; addImage(item.id); }
                    picker.close(); body.focus();
                });
                grid.append(button);
            });
            more.hidden = !result.more; pickerStatus.textContent = grid.children.length ? t('選ぶと、本文のカーソル位置に入ります。') : t('見つかりませんでした。');
        } catch (e) { if (currentGeneration === generation) pickerStatus.textContent = e.message; }
        finally { if (currentGeneration === generation) more.disabled = false; }
    }
    $$('[data-picker]', form).forEach(button => button.addEventListener('click', () => {
        kind = button.dataset.picker; search.value = ''; $('#log-picker-title').textContent = kind === 'manga' ? t('漫画を選ぶ') : t('画像を選ぶ');
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
            const data = new FormData(form); data.set('do', 'log_preview'); data.set('body', compose());
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
        if (uploading) { say(t('画像の追加が終わってから保存してください。'), true); return; }
        const data = new FormData(form); data.set('status', e.submitter?.value || 'published'); data.set('body', compose());
        saving = true; $$('button[type="submit"],button[name="status"]', form).forEach(b => { b.disabled = true; });
        say(t('保存しています…'));
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
        const heading = el('h2', '', published ? t('保存しました。記事を見ますか？') : t('下書きを保存しました')); heading.id = 'log-saved-title';
        const lead = el('p', '', published ? t('公開ページで、編集した記事を確認できます。') : t('下書きは公開ページに出ないため、続けて編集するか一覧に戻ってください。'));
        const editUrl = adminUrl('index.php?p=log_edit&id=' + encodeURIComponent(result.id));
        const view = published ? link(t('記事を見る'), new URL('../?id=' + encodeURIComponent(result.id), endpoint).href, 'btn primary') : null;
        const actions = el('div', 'log-saved-dialog-actions');
        actions.append(link(t('続けて編集する'), editUrl, 'btn'), origin ? link(new URL(origin).searchParams.has('id') ? t('記事ページに戻る') : t('公開ページに戻る'), origin, published ? 'btn' : 'btn primary') : link(t('一覧に戻る'), adminUrl(result.redirect), published ? 'btn' : 'btn primary'));
        if (view) actions.append(view);
        saved.append(heading, lead, actions);
        // Escape closes the dialog; the form holds the old revision, so reopen the editor.
        saved.addEventListener('cancel', e => { e.preventDefault(); location.replace(editUrl); });
        document.body.append(saved);
        say(published ? t('保存しました。') : t('下書きを保存しました。'));
        saved.showModal();
        (view || actions.lastElementChild).focus();
    }
})();
