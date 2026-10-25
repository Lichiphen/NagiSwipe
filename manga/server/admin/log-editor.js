/* Personal LOG editor; panel structure inspired by NagiMemo. MIT (c) 2026 Lichiphen. */
/*
 * Markdown marks for the post editor and the fixed page editor (log-pages.js). Bold, italic and links sit on the
 * tool row; headings, lists, quotes and the rest open in one dialog, so the row stays short on a phone.
 */
window.NagiLogFormat = (() => {
    'use strict';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = text => i18n[text] ?? text;
    // Replace [start, end) and select [from, to) of the result; insertText keeps the browser's undo history.
    // Both editors hear an input event, as if the text had been typed.
    function put(body, start, end, text, from = 0, to = text.length) {
        body.focus({ preventScroll: true });
        body.setSelectionRange(start, end);
        if (!document.execCommand?.('insertText', false, text)) {
            body.setRangeText(text, start, end, 'end');
            body.dispatchEvent(new Event('input', { bubbles: true }));
        }
        body.setSelectionRange(start + from, start + to);
    }
    const clamp = (body, range) => [Math.min(range[0], body.value.length), Math.min(range[1], body.value.length)];
    // The whole lines the selection (or the caret) touches; a selection ending at a line break leaves that line out.
    function lines(body, range) {
        const value = body.value, [s, e] = clamp(body, range);
        const start = value.lastIndexOf('\n', s - 1) + 1;
        const last = e > s && value[e - 1] === '\n' ? e - 1 : e;
        let end = value.indexOf('\n', last); if (end < 0) end = value.length;
        return { start, end, list: value.slice(start, end).split('\n') };
    }
    // Bold, italic, strike and code work within one line: each selected line is marked on its own,
    // spaces around the words stay outside, and the same mark again takes it off.
    function wrap(body, range, mark, sample) {
        const value = body.value, [start, end] = clamp(body, range);
        const picked = value.slice(start, end);
        const before = value.slice(0, start), after = value.slice(end);
        const single = mark === '*';
        const around = before.endsWith(mark) && after.startsWith(mark) && (!single || (!before.endsWith('**') || before.endsWith('***')) && (!after.startsWith('**') || after.startsWith('***')));
        if (picked !== '' && around) { put(body, start - mark.length, end + mark.length, picked); return; }
        if (picked.length > mark.length * 2 && picked.startsWith(mark) && picked.endsWith(mark) && !picked.includes('\n') && (!single || !picked.startsWith('**') || picked.startsWith('***'))) {
            const inner = picked.slice(mark.length, -mark.length); put(body, start, end, inner); return;
        }
        if (picked.trim() !== '') {
            const wrapped = picked.split('\n').map(line => {
                const [, lead, text, tail] = line.match(/^(\s*)(.*?)(\s*)$/s);
                return text === '' ? line : lead + mark + text + mark + tail;
            }).join('\n');
            const lead = picked.length - picked.trimStart().length, trail = picked.length - picked.trimEnd().length;
            put(body, start, end, wrapped, lead, wrapped.length - trail);
            return;
        }
        put(body, start, end, mark + sample + mark, mark.length, mark.length + sample.length);
    }
    // A link: the selected words become its text (or, when they are a URL, its address).
    function link(body, range) {
        const [start, end] = clamp(body, range);
        const picked = body.value.slice(start, end).trim();
        if (/^https?:\/\/\S+$/.test(picked)) { const label = t('リンクの文字'); put(body, start, end, '[' + label + '](' + picked + ')', 1, 1 + label.length); return; }
        const label = picked || t('リンクの文字');
        const text = '[' + label + '](https://)';
        put(body, start, end, text, label.length + 3, text.length - 1);
    }
    // Ruby: the selected words get |…《》 with the caret in the brackets for the reading; |words《reading》 again comes off.
    function ruby(body, range) {
        const [start, end] = clamp(body, range);
        const picked = body.value.slice(start, end);
        const marked = /^[|｜]([^|｜《》\n]+)《[^《》\n]*》$/.exec(picked);
        if (marked) { put(body, start, end, marked[1]); return; }
        const words = picked.trim();
        if (words === '' || picked.includes('\n')) {
            const sample = t('漢字'), reading = t('かんじ');
            put(body, start, end, '|' + sample + '《' + reading + '》', 1, 1 + sample.length);
            return;
        }
        const lead = picked.length - picked.trimStart().length, trail = picked.length - picked.trimEnd().length;
        const text = picked.slice(0, lead) + '|' + words + '《》' + picked.slice(picked.length - trail);
        const at = lead + 1 + words.length + 1;
        put(body, start, end, text, at, at);
    }
    // ## / ### / ####: the lines become headings of that level; the same level again turns them back.
    function heading(body, range, level, post) {
        const mark = '#'.repeat(level) + ' ', { start, end, list } = lines(body, range);
        if (list.every(line => line.trim() === '')) {
            const sample = t('見出し');
            put(body, start, end, mark + sample, mark.length, mark.length + sample.length);
            return;
        }
        // In a post a single # begins a hashtag, so only ## and more count as a heading mark there.
        const plain = line => line.replace(post ? /^#{2,6}[ \t]+/ : /^#{1,6}[ \t]+/, '');
        const same = list.every(line => line.trim() === '' || (line.startsWith(mark) && plain(line) === line.slice(mark.length)));
        const next = list.map(line => line.trim() === '' ? line : (same ? plain(line) : mark + plain(line).trimStart())).join('\n');
        put(body, start, end, next);
    }
    // - / 1. / > at the start of each line; when every line has it already, it comes off.
    function prefix(body, range, kind) {
        const { start, end, list } = lines(body, range);
        const marks = { list: /^(\s*)[-*+][ \t]+/, ordered: /^(\s*)[0-9]{1,9}[.)][ \t]+/, quote: /^(\s*)>[ \t]?/ };
        const mark = n => kind === 'list' ? '- ' : kind === 'quote' ? '> ' : n + '. ';
        if (list.every(line => line.trim() === '')) {
            const sample = kind === 'quote' ? t('引用する文') : t('項目');
            put(body, start, end, mark(1) + sample, mark(1).length, mark(1).length + sample.length);
            return;
        }
        const filled = list.filter(line => line.trim() !== '');
        const off = filled.every(line => marks[kind].test(line));
        let n = 0;
        const next = list.map(line => {
            if (line.trim() === '') return line;
            if (off) return line.replace(marks[kind], '$1');
            // A line with another list mark switches to this one.
            const bare = line.replace(marks.list, '$1').replace(marks.ordered, '$1');
            const lead = bare.match(/^\s*/)[0];
            return lead + mark(++n) + bare.slice(lead.length);
        }).join('\n');
        put(body, start, end, next);
    }
    // Blocks start on a line of their own and end with a line break (none added when one follows already).
    function block(body, range, kind) {
        const value = body.value, [start, end] = clamp(body, range);
        const lead = start === 0 || value[start - 1] === '\n' ? '' : '\n';
        const tail = end < value.length && value[end] === '\n' ? '' : '\n';
        const picked = value.slice(start, end);
        if (kind === 'rule') {
            // The rule goes after the selection; the blank line keeps the line above from becoming a heading elsewhere.
            const text = (end === 0 || value[end - 1] === '\n' ? '' : '\n') + '\n---' + tail;
            put(body, end, end, text, text.length, text.length);
            return;
        }
        if (kind === 'code') {
            const code = picked.replace(/\n$/, '') || t('コード');
            put(body, start, end, lead + '```\n' + code + '\n```' + tail, lead.length + 4, lead.length + 4 + code.length);
            return;
        }
        const a = t('項目'), b = t('内容'), cell = picked.trim() || 'a';
        const text = lead + '| ' + a + ' | ' + b + ' |\n| --- | --- |\n| ' + cell + ' |  |' + tail;
        const at = text.lastIndexOf('| ' + cell) + 2;
        put(body, start, end, text, at, at + cell.length);
    }
    const ACTIONS = {
        bold: (body, range) => wrap(body, range, '**', t('太字にする文字')),
        italic: (body, range) => wrap(body, range, '*', t('斜体にする文字')),
        strike: (body, range) => wrap(body, range, '~~', t('取り消す文字')),
        code: (body, range) => wrap(body, range, '`', t('コード')),
        link,
        ruby,
        list: (body, range) => prefix(body, range, 'list'),
        ordered: (body, range) => prefix(body, range, 'ordered'),
        quote: (body, range) => prefix(body, range, 'quote'),
        table: (body, range) => block(body, range, 'table'),
        rule: (body, range) => block(body, range, 'rule'),
        codeblock: (body, range) => block(body, range, 'code'),
    };
    function apply(name, body, range = [body.selectionStart, body.selectionEnd], options = {}) {
        const h = /^h([234])$/.exec(name);
        if (h) heading(body, range, +h[1], !!options.post);
        else ACTIONS[name]?.(body, range);
    }
    // The dialog: the headings the settings allow, then marks for words and for blocks.
    let dialog = null, target = null;
    function build() {
        dialog = document.createElement('dialog');
        dialog.className = 'log-format-dialog';
        dialog.setAttribute('aria-labelledby', 'log-format-title');
        const head = document.createElement('div'); head.className = 'log-format-head';
        const title = document.createElement('h2'); title.id = 'log-format-title'; title.textContent = t('装飾');
        const close = document.createElement('button'); close.type = 'button'; close.className = 'btn'; close.textContent = t('閉じる');
        close.addEventListener('click', () => dialog.close());
        head.append(title, close);
        const note = document.createElement('p'); note.className = 'note'; note.textContent = t('選んだ文字や行に付けます。何も選んでいなければ、見本を入れます。同じものをもう一度選ぶと外れます。');
        dialog.append(head, note);
        dialog.addEventListener('click', event => {
            if (event.target === dialog) { dialog.close(); return; }
            const choice = event.target.closest('[data-format]');
            if (!choice || !target) return;
            const { body, range, post, done } = target;
            dialog.close();
            apply(choice.dataset.format, body, range, { post });
            done?.();
        });
        document.body.append(dialog);
    }
    function group(label, items) {
        const box = document.createElement('section'); box.className = 'log-format-group';
        const name = document.createElement('h3'); name.textContent = label;
        const grid = document.createElement('div'); grid.className = 'log-format-choices';
        items.forEach(([format, text, code]) => {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'log-format-choice'; b.dataset.format = format;
            const big = document.createElement('b'); big.textContent = text;
            const small = document.createElement('code'); small.textContent = code;
            b.append(big, small); grid.append(b);
        });
        box.append(name, grid);
        return box;
    }
    // levels: the heading levels to offer ([2, 3, 4]); post: the text is a post (## and more are headings); done: called after a choice.
    function open(body, { levels = [2, 3, 4], post = false, range = [body.selectionStart, body.selectionEnd], done = null } = {}) {
        if (!dialog) build();
        target = { body, range: [...range], post, done };
        dialog.querySelectorAll('.log-format-group').forEach(g => g.remove());
        const names = { 2: t('見出し２'), 3: t('見出し３'), 4: t('見出し４') };
        if (levels.length) dialog.append(group(t('見出し'), levels.map(n => ['h' + n, names[n], '#'.repeat(n) + ' '])));
        dialog.append(group(t('文字'), [['strike', t('取り消し線'), '~~ ~~'], ['code', t('コード'), '` `'], ['ruby', t('ルビ（読み仮名）'), '|漢字《かんじ》']]));
        dialog.append(group(t('まとまり'), [['list', t('箇条書き'), '- '], ['ordered', t('番号付きリスト'), '1. '], ['quote', t('引用'), '> '], ['table', t('表'), '| |'], ['rule', t('区切り線'), '---'], ['codeblock', t('コードブロック'), '```']]));
        dialog.showModal();
        dialog.querySelector('.log-format-choice')?.focus();
    }
    return { apply, open };
})();
(() => {
    'use strict';
    // Icons come from viewer/icons/ui.svg (its address is on <html data-icons>).
    const icon = (id, cls = '') => '<svg' + (cls ? ' class="' + cls + '"' : '') + ' viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + document.documentElement.dataset.icons + '#' + id + '"/></svg>';
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
    // A PC or a device turned sideways: the tool row above the text, the preview as a tab, the media dock on the left.
    const wide = window.matchMedia('(orientation: landscape) and (min-width: 640px)');
    const dock = $('[data-media-dock]', panel);
    const toolsScroll = $('[data-tools-scroll]', form), tools = $('.log-compose-tools', form);
    let dockList = null, arranged = false;
    const toBody = $('[data-to-body]', panel);
    let bodySeen = true;
    // Phones held upright: the preview opens over the whole screen at the size readers see, with 閉じる kept at the
    // bottom. It sits outside the editor (a phone's full-screen editor moves with a transform) and is not modal, so
    // the image viewer opened from it still comes on top; Escape and Tab are handled here instead.
    const previewBox = $('[data-preview-body]', form);
    const previewSheet = document.createElement('dialog');
    previewSheet.className = 'log-preview-sheet';
    previewSheet.setAttribute('aria-labelledby', 'log-preview-sheet-title');
    previewSheet.innerHTML = '<div class="log-preview-sheet-head"><h2 id="log-preview-sheet-title">' + icon('tool-preview', 'log-tool-icon') + t('プレビュー') + '</h2><p>' + t('公開したときの大きさで表示しています') + '</p></div>'
        + '<div class="log-preview-sheet-body"></div><div class="log-preview-sheet-foot"><button type="button" class="btn primary" data-preview-close>' + icon('close', 'log-tool-icon') + t('閉じて編集に戻る') + '</button></div>';
    document.body.append(previewSheet);
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
    // where a group goes with a 〔メディアN〕 line. Groups without a mark go after the text. Numbers stay as they are
    // while writing (an undone deletion finds its group again); a post opened again is numbered from the top.
    // Saving and the preview put the tags back in place of the marks, so the server only ever sees tags.
    const MARK_RE = /〔(?:メディア|画像)(\d+)〕/g;
    const markOf = n => '〔メディア' + n + '〕';
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
        // Every group keeps its line, the one after the text too: the text shows where each picture is.
        const list = runs.map((run, k) => {
            const start = run[0].index, end = run.at(-1).index + run.at(-1)[0].length;
            text += value.slice(from, start) + markOf(k + 1);
            from = end;
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
        const out = text.replace(/(\n)?〔(?:メディア|画像)(\d+)〕(\n)?/g, (m, lead = '', n, tail = '') => {
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
    const grip = icon('grip');
    function drawMark() {
        const { at } = view();
        markLayer.hidden = !at.size;
        if (!at.size) return;
        const cs = getComputedStyle(body);
        COPIED.forEach(name => { markLayer.style[name] = cs[name]; });
        placeMarks();
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
            const label = document.createElement('b'); label.textContent = t('メディア{n}', { n: g.n }); band.append(label);
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
    // The layer lies exactly over the text box. Anything above it in the form (the tool row and its panels on a PC,
    // a chip, a message) moves the box without resizing it, so the form's own size changes place the layer again.
    function placeMarks() {
        if (markLayer.hidden) return;
        markLayer.style.left = (body.offsetLeft + body.clientLeft) + 'px'; markLayer.style.top = (body.offsetTop + body.clientTop) + 'px';
        markLayer.style.width = body.clientWidth + 'px'; markLayer.style.height = body.clientHeight + 'px';
    }
    body.addEventListener('scroll', () => { markLayer.scrollTop = body.scrollTop; });
    // (In the full-screen editor the form can be held at the dock's height, so the tool zone is watched as well.)
    if ('ResizeObserver' in window) {
        new ResizeObserver(() => drawMark()).observe(body);
        const moved = new ResizeObserver(placeMarks);
        [form, $('[data-toolzone]', form), $('[data-compose-tabs]', form)].forEach(el => el && moved.observe(el));
    }
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
    const current = () => ({ body: compose(), title: title.value, refs, categories: categoryFields.filter(i => i.checked).map(i => i.value), newCategories: newCategories.value, rating: rating(), warning: warning.value, mediaRatings, lock: !!$('[data-lock-toggle]', form)?.checked });
    const initial = JSON.stringify(current());
    const snapshot = () => JSON.stringify(current());
    function restore(saved) {
        useBody(saved.body || ''); title.value = saved.title || ''; refs = saved.refs || {};
        if (Array.isArray(saved.categories)) categoryFields.forEach(i => { i.checked = saved.categories.includes(i.value); });
        newCategories.value = saved.newCategories || '';
        if (typeof saved.rating === 'string') ratingFields.forEach(i => { i.checked = i.value === saved.rating; });
        warning.value = saved.warning || '';
        if (typeof saved.lock === 'boolean' && $('[data-lock-toggle]', form)) $('[data-lock-toggle]', form).checked = saved.lock;
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
        showPreview(false);
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
    // A video or audio file without a cover: its kind instead of a picture.
    const kindMark = kind => {
        const mark = document.createElement('span'); mark.className = 'log-kind-mark';
        mark.innerHTML = kind === 'audio' ? icon('audio')
            : kind === 'video' ? icon('video') : '';
        mark.append(kind === 'audio' ? t('音声') : kind === 'video' ? t('動画') : t('画像'));
        return mark;
    };
    const strongest = list => list.reduce((a, b) => RANK.indexOf(b) > RANK.indexOf(a) ? b : a, '');
    const veilIcon = icon('warning', 'log-veil-icon');
    // Pictures from tags typed or pasted by hand: ask the server once for their thumbnails and ratings.
    const asked = new Set();
    async function lookup(ids) {
        ids.forEach(id => asked.add(id));
        try {
            const response = await fetch(adminUrl('index.php?p=log_media_json&ids=' + ids.join(',')), { credentials: 'same-origin', cache: 'no-store' });
            if (!response.ok) return;
            (await response.json()).items.forEach(item => { mediaInfo[item.id] = { thumb: item.thumb, alt: item.alt, rating: item.rating || '', kind: item.kind || 'image' }; });
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
            const label = document.createElement('b'); label.className = 'log-att-label'; label.textContent = t('メディア{n}', { n: g.n });
            const where = document.createElement('span'); where.className = 'log-att-where';
            where.textContent = inText ? t('本文の「{mark}」の行', { mark: markOf(g.n) }) : t('本文の最後');
            head.append(label, where);
            if (inText) head.append(button('log-att-action', g.ids.length ? t('本文の最後へ') : t('この場所を消す'), t(g.ids.length ? '「{mark}」の行を消して、本文の最後に並べます' : '「{mark}」の行を消します', { mark: markOf(g.n) }), () => {
                body.value = removeMarkOf(body.value, g.n); rememberSelection(); changed();
                say(g.ids.length ? t('メディア{n}を本文の最後に並べます。', { n: g.n }) : t('「{mark}」を消しました。', { mark: markOf(g.n) }));
            }));
            else head.append(button('log-att-action', t('カーソルの位置へ'), t('本文のカーソル位置に「{mark}」の行を入れます', { mark: markOf(g.n) }), () => {
                insertMark(g.n); say(t('「{mark}」の行にメディア{n}を並べます。本文の中の帯をドラッグすると、行を動かせます。', { mark: markOf(g.n), n: g.n }));
            }));
            const row = document.createElement('div'); row.className = 'log-att-row';
            g.ids.forEach((id, i) => {
                const info = mediaInfo[id] || {}, r = mediaRating(id);
                const item = document.createElement('div'); item.className = 'log-att'; item.dataset.mediaId = id;
                const thumb = document.createElement('button'); thumb.type = 'button'; thumb.className = 'log-att-thumb';
                thumb.title = t('ドラッグで並べ替え・別のまとまりへ移動'); thumb.setAttribute('aria-label', t('メディア{n}の{i}つ目（矢印キーで移動）', { n: g.n, i: i + 1 }));
                // Never dragged by the browser itself: a dropped copy would be uploaded again.
                if (info.thumb) { const img = document.createElement('img'); img.src = adminUrl(info.thumb); img.alt = ''; img.draggable = false; thumb.append(img); }
                else thumb.append(kindMark(info.kind));
                const rate = document.createElement('button'); rate.type = 'button'; rate.className = 'log-att-rating'; rate.dataset.veil = r;
                rate.setAttribute('aria-haspopup', 'menu'); rate.setAttribute('aria-expanded', 'false');
                rate.setAttribute('aria-label', t('このメディアの閲覧注意：{label}', { label: LABEL[r] })); rate.title = t('閲覧注意：{label}', { label: LABEL[r] });
                rate.innerHTML = veilIcon + (r ? '<span>' + LABEL[r] + '</span>' : '');
                rate.addEventListener('click', () => openMenu(rate, id));
                const remove = button('log-att-remove', icon('close'), t('この投稿から外す（メディア一覧には残ります）'), () => {
                    g.ids = g.ids.filter(other => other !== id); changed();
                    ($$('.log-att-group[data-n="' + g.n + '"] .log-att-thumb', attachments)[Math.min(i, g.ids.length - 1)] || body).focus();
                    say(t('外しました。ファイルそのものはメディア一覧に残っています。'));
                });
                remove.setAttribute('aria-label', t('メディア{n}の{i}つ目を外す', { n: g.n, i: i + 1 }));
                item.append(thumb, rate, remove);
                // Video: how it plays. Audio: its cover and loop. Both open a dialog of log-av.js.
                if ((info.kind === 'video' || info.kind === 'audio') && window.NagiLogAV?.playDialog) {
                    const video = info.kind === 'video', label = video ? t('再生のしかた') : t('ジャケットとループ位置');
                    const av = button('log-att-av', video ? icon('sliders') : icon('loop'), label, () => openAV(id, video));
                    av.setAttribute('aria-label', label + ' ' + t('メディア{n}の{i}つ目', { n: g.n, i: i + 1 }));
                    item.append(av);
                }
                row.append(item);
            });
            const add = button('log-att-add', icon('plus'), t('メディア{n}に追加', { n: g.n }), () => { active = pinned = g; uploadInput.click(); });
            add.setAttribute('aria-label', t('メディア{n}に追加', { n: g.n }));
            row.append(add);
            group.append(head, row);
            return group;
        });
        if (ids.length || rows.length) nodes.push(button('log-att-new', icon('plus') + '<span>' + t('別の場所にもメディアを置く') + '</span>', t('本文のカーソル位置に新しい「〔メディアN〕」の行を入れます'), () => {
            const g = { n: nextNumber(), ids: [] };
            groups.push(g); active = g; insertMark(g.n);
            say(t('「{mark}」の行を入れました。メディア{n}の＋から追加してください。', { mark: markOf(g.n), n: g.n }));
        }));
        attachments.replaceChildren(...nodes);
        attachments.hidden = !rows.length;
        dockCount();
    }
    // The dialog for one video or audio file, with its full record read fresh (its revision may have moved on).
    async function openAV(id, video) {
        try {
            const response = await fetch(adminUrl('index.php?p=log_media_json&ids=' + id), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            const m = (await response.json()).items?.[0];
            if (!m) throw new Error(t('メディアが見つかりません。'));
            const media = { ...m, url: adminUrl(m.url), thumb: m.thumb ? adminUrl(m.thumb) : '' };
            const saved = await (video ? window.NagiLogAV.playDialog : window.NagiLogAV.tagDialog)(media, { endpoint: endpoint.href, csrf });
            if (!saved) return;
            mediaInfo[id] = { thumb: saved.thumb, alt: saved.alt, rating: saved.rating || '', kind: saved.kind || 'image' };
            attachments.dataset.key = ''; renderAttachments(); drawMark();
            say(video ? t('再生のしかたを保存しました。') : t('ジャケットとループ位置を書き込んで、ファイルを差し替えました。'));
        } catch (e) { say(e.message, true); }
    }
    // The mark on its own line goes with its line break; anywhere else, just the mark.
    const removeMarkOf = (text, n) => text.replace(new RegExp('(\\n)?〔(?:メディア|画像)' + n + '〕(\\n)?', 'g'), (m, lead, tail) => lead && tail ? '\n' : '');
    function insertMark(n, range = selection) {
        const [start, end] = range, before = body.value.slice(0, start);
        insert((before === '' || before.endsWith('\n') ? '' : '\n') + markOf(n) + (body.value.slice(end).startsWith('\n') ? '' : '\n'), range);
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
            say(t('メディア{n}の{i}つ目に移しました。', { n: g.n, i: to + 1 }));
        } else {
            const other = rows[rows.indexOf(g) + (e.key === 'ArrowUp' ? -1 : 1)];
            if (!other) return;
            g.ids.splice(i, 1); other.ids.push(id); active = other; changed();
            say(t('メディア{n}の最後に移しました。', { n: other.n }));
        }
        $('.log-att-group[data-n="' + groups.find(x => x.ids.includes(id))?.n + '"] .log-att[data-media-id="' + id + '"] .log-att-thumb', attachments)?.focus();
    });
    // Where new pictures go: the group whose + was pressed; the group whose 〔メディアN〕 line holds the cursor (or sits
    // just above it); else a 〔メディアN〕 line at the cursor, below its line when the cursor is mid-line so a sentence
    // is never split. Wherever they go, the group's line is in the text, so its band shows where they are.
    let pinned = null;
    function target() {
        const chosen = groups.includes(pinned) ? pinned : null;
        pinned = null;
        if (chosen) {
            if (!view().at.has(chosen.n)) insertMark(chosen.n, [body.value.length, body.value.length]);
            return { g: chosen, where: 'group' };
        }
        const text = body.value, caret = Math.min(selection[0], text.length), { at } = view();
        const lineStart = text.lastIndexOf('\n', caret - 1) + 1;
        let lineEnd = text.indexOf('\n', caret); if (lineEnd < 0) lineEnd = text.length;
        const atLineStart = text.slice(lineStart, caret).trim() === '';
        const lines = [text.slice(lineStart, lineEnd)];
        if (atLineStart && lineStart) lines.push(text.slice(text.lastIndexOf('\n', lineStart - 2) + 1, lineStart - 1));
        for (const line of lines) for (const m of line.matchAll(MARK_RE)) {
            const g = groups.find(x => x.n === +m[1]);
            if (g && at.has(g.n)) return { g, where: 'mark' };
        }
        const pos = atLineStart ? lineStart : lineEnd;
        // At the end of the text, the group already shown after it takes its line there.
        const last = text.slice(pos).trim() === '' ? view().end[0] : null;
        const g = last || { n: nextNumber(), ids: [] };
        if (!last) groups.push(g);
        insertMark(g.n, [pos, pos]);
        return { g, where: 'mark', created: !last };
    }
    // Where they went: the place is said in words, and its band in the text and its row under it light up.
    const placeText = ({ g, where }) => where === 'group' ? t('メディア{n}', { n: g.n }) : t('本文のカーソル位置（「{mark}」の行）', { mark: markOf(g.n) });
    function showPlace(g) {
        requestAnimationFrame(() => {
            const band = $('.log-mark-band[data-n="' + g.n + '"]', markLayer), row = $('.log-att-group[data-n="' + g.n + '"]', attachments);
            [band, row].forEach(el => { if (!el) return; el.classList.remove('is-placed'); void el.offsetWidth; el.classList.add('is-placed'); setTimeout(() => el.classList.remove('is-placed'), 3300); });
            if (band) {
                const top = band.parentElement.offsetTop;
                if (top < body.scrollTop || top > body.scrollTop + body.clientHeight - 24) { body.scrollTop = Math.max(0, top - body.clientHeight / 2); markLayer.scrollTop = body.scrollTop; }
            } else row?.scrollIntoView({ block: 'nearest' });
        });
    }
    function addImage(id, place = target()) {
        if (allImages().includes(id)) { say(t('そのメディアはもう入っています。')); return false; }
        place.g.ids.push(id); active = place.g; changed();
        return true;
    }
    // Tags typed or pasted into the text move to the rows: a new group, its line where the tags were.
    function absorb() {
        if (body.value.search(/\[Image:[a-f0-9]{16}\]/) < 0) return;
        const caret = body.selectionStart, found = [];
        let first = -1;
        let text = body.value.replace(/[ \t]*\[Image:([a-f0-9]{16})\][ \t]*\r?\n?/g, (m, id, offset) => { if (!found.includes(id) && !allImages().includes(id)) found.push(id); if (first < 0) first = offset; return ''; });
        if (found.length) {
            const g = { n: nextNumber(), ids: found }; groups.push(g); active = g;
            text = text.slice(0, first) + markOf(g.n) + (text.slice(first).trim() !== '' ? '\n' : '') + text.slice(first);
        }
        const at = Math.max(0, Math.min(text.length, caret - (body.value.length - text.length)));
        body.value = text; body.setSelectionRange(at, at); rememberSelection();
        say(t('メディアのタグを、本文の下のメディアの列に移しました。'));
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
        menu.hidden = false;
        // Under the button, kept inside the screen. When the screen ends first the page scrolls a little to make room
        // (a phone held upright), or, when that would hide the button, the menu goes above it (a phone turned sideways).
        let at;
        const short = () => at.bottom + 6 + menu.offsetHeight - (window.innerHeight - 8);
        const place = below => {
            const box = (menu.offsetParent || form).getBoundingClientRect();
            at = button.getBoundingClientRect();
            menu.style.left = Math.max(8 - box.left, Math.min(at.left - box.left, box.width - menu.offsetWidth, window.innerWidth - 8 - box.left - menu.offsetWidth)) + 'px';
            menu.style.top = (below ? at.bottom - box.top + 6 : at.top - box.top - 6 - menu.offsetHeight) + 'px';
        };
        place(true);
        if (short() > 0 && at.top - short() >= 8) { menu.scrollIntoView({ block: 'nearest' }); place(true); }
        if (short() > 9 && at.top - 6 - menu.offsetHeight >= 8) place(false);
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
            text.textContent = LABEL[shown] + (shown !== own ? t('（メディア）') : '') + (warning.value.trim() ? '・' + warning.value.trim() : '');
            b.append(text); out.push(b);
        }
        const names = categoryFields.filter(i => i.checked).map(i => i.nextElementSibling.textContent).concat(newCategories.value.split(/[,、\n]+/).map(s => s.trim()).filter(Boolean));
        names.forEach(name => {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'log-chip-category'; b.dataset.open = 'categories';
            b.innerHTML = icon('folder');
            const text = document.createElement('span'); text.textContent = name; b.append(text); out.push(b);
        });
        const lockOn = $('[data-lock-toggle]', form)?.checked;
        if (lockOn) {
            const b = document.createElement('button'); b.type = 'button'; b.className = 'log-chip-lock'; b.dataset.open = 'lock';
            b.innerHTML = icon('lock');
            const text = document.createElement('span'); text.textContent = t('パスワードあり'); b.append(text); out.push(b);
        }
        $('[data-panel="lock"]', form)?.classList.toggle('is-set', !!lockOn);
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
        awayCheck();
    }
    $$('[data-panel]', form).forEach(button => button.addEventListener('click', () => togglePanel(button.dataset.panel)));
    ratingFields.forEach(input => input.addEventListener('change', () => {
        // Choosing a rating for the post marks its pictures the same, unless the owner turned that off.
        if ($('[data-rate-all]', form).checked) bodyMedia().forEach(id => { mediaRatings[id] = input.value; });
        changed();
    }));
    warning.addEventListener('input', changed);
    // The lock: typing a password turns the switch on; the switch is part of the draft, the password is not kept in the tab.
    const lockToggle = $('[data-lock-toggle]', form), lockField = $('[data-lock-field]', form);
    lockToggle?.addEventListener('change', () => { if (lockToggle.checked && !lockField.value && !lockField.placeholder) lockField.focus(); changed(); });
    lockField?.addEventListener('input', () => { if (lockField.value.trim() && !lockToggle.checked) lockToggle.checked = true; changed(); });
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
    // B, I and link on the tool row; 装飾 opens the dialog with headings, lists and the rest (NagiLogFormat above).
    const format = (name, extra = {}) => { window.NagiLogFormat.apply(name, body, selection, { post: true, ...extra }); rememberSelection(); };
    $$('[data-format]', form).forEach(button => button.addEventListener('click', () => format(button.dataset.format)));
    const formatButton = $('[data-format-menu]', form);
    formatButton?.addEventListener('click', () => {
        const levels = (formatButton.dataset.headingLevels || '').split(',').filter(Boolean).map(level => +level.slice(1));
        window.NagiLogFormat.open(body, { levels, post: true, range: selection, done: rememberSelection });
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
        if (arranged) arrange();
        if (open) { toolsHint(); toolsNudge(); }
    }
    fab.addEventListener('click', () => setPanel(true));
    $('[data-compose-open]')?.addEventListener('click', () => setPanel(true));
    // A post's own page: ./?id=… or the clean ./20261014123456.
    const isPostPage = href => { try { const u = new URL(href); return u.searchParams.has('id') || /\/[0-9]{14}(?:-[0-9]{2,6})?$/.test(u.pathname); } catch { return false; } };
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
        toPublic.textContent = isPostPage(origin) ? t('記事ページに戻る') : t('公開ページに戻る');
        crumb.prepend(toPublic);
    }
    $('.log-close', panel).addEventListener('click', () => {
        if (panel.dataset.edit) { location.href = origin || 'index.php?p=log'; return; }
        setPanel(false);
    });
    mobile.addEventListener('change', () => accessibility(panel.classList.contains('active')));
    document.addEventListener('keydown', e => {
        // Escape in a dialog (装飾, a picker, the phone's sheet) closes only that dialog, not the editor.
        if (e.defaultPrevented || document.querySelector('dialog[open]') || window.NagiSwipe?.isOpen || document.querySelector('.nm-viewer:not([hidden])')) return;
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
            setPanel(true);
            // The dock shows where the new media go.
            if (panel.dataset.dock) dockTab('post');
            // The whole batch goes to one place, chosen now and shown (an empty band) while the files are sent.
            const place = target(), where = placeText(place);
            say(t('{where}に置きます。アップロードしています…', { where })); showPlace(place.g);
            const errors = [];
            let added = 0;
            for (const file of list) {
                try {
                    let media;
                    // Video and audio go in pieces with their measurements (log-av.js); pictures in one request.
                    if (window.NagiLogAV?.isAV(file)) {
                        media = await window.NagiLogAV.upload(file, { endpoint: endpoint.href, csrf, onProgress: (ratio, phase) => say(phase === 'measure' ? t('{name} を調べています…', { name: file.name }) : t('{name} を送っています…（{p}%）', { name: file.name, p: Math.floor(ratio * 100) })) });
                    } else {
                        const data = new FormData(); data.set('do', 'log_upload'); data.set('image', file);
                        ({ media } = await request(data));
                    }
                    mediaInfo[media.id] = { thumb: media.thumb, alt: media.alt, rating: media.rating || '', kind: media.kind || 'image' };
                    if (addImage(media.id, place)) added++;
                } catch (e) { errors.push(t('{name}：{message}', { name: file.name, message: e.message })); }
            }
            // Nothing arrived: the line made for this batch goes again.
            if (place.created && !place.g.ids.length) { body.value = removeMarkOf(body.value, place.g.n); rememberSelection(); changed(); }
            if (errors.length) say(errors.join(' / '), true);
            else if (added) { say(t('{where}に置きました。サムネイルや本文の帯をドラッグすると、場所を変えられます。', { where })); showPlace(place.g); }
            // New uploads join the top of the dock's list (unless it shows a search).
            if (added && dockList?.loaded && !dockList.search.value) dockList.load(true);
        }).finally(() => { uploading--; });
        await uploadQueue;
    }
    let uploadQueue = Promise.resolve();
    uploadInput.addEventListener('change', () => { upload(uploadInput.files); uploadInput.value = ''; });
    uploadInput.addEventListener('cancel', () => { pinned = null; });
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
        if (pageDrag) { if (panel.contains(e.target)) e.preventDefault(); return; }
        if (!e.dataTransfer?.files.length) return;
        e.preventDefault(); panel.classList.remove('dragover'); upload(e.dataTransfer.files);
    });
    body.addEventListener('paste', e => {
        const files = Array.from(e.clipboardData?.files || []);
        if (files.length) { e.preventDefault(); upload(files); }
    });

    // A picked work or medium goes into the text at the caret (a medium to the place target() chooses).
    function pick(item, kind) {
        if (kind === 'manga') {
            // An old post may already use this label for another work.
            let tag = item.tag;
            if (refs[tag] && refs[tag] !== item.id) tag = tag.slice(0, -1) + ' #' + item.id + ']';
            refs[tag] = item.id; insert('\n' + tag + '\n');
            return;
        }
        mediaInfo[item.id] = { thumb: item.thumb, alt: item.alt, rating: item.rating || '', kind: item.kind || 'image' };
        if (!allImages().includes(item.id)) { const place = target(); addImage(item.id, place); say(t('{where}に置きました。', { where: placeText(place) })); showPlace(place.g); }
        else say(t('そのメディアはもう入っています。'));
    }
    // A list of the media library or the works: in the dialog (both), and in the dock on a PC or sideways (media only).
    function catalog(box, picked) {
        const list = { kind: 'media', page: 1, generation: 0, loaded: false, search: $('[data-picker-search]', box) };
        const grid = $('[data-picker-grid]', box), more = $('[data-picker-more]', box), note = $('[data-picker-status]', box);
        let timer;
        list.load = async reset => {
            const current = ++list.generation;
            list.loaded = true;
            if (reset) { list.page = 1; grid.replaceChildren(); }
            more.disabled = true; note.textContent = t('読み込み中…');
            try {
                const response = await fetch(adminUrl('index.php?p=log_' + list.kind + '_json&page=' + list.page + '&q=' + encodeURIComponent(list.search.value)), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
                // A login page instead of the list (the session ended in another tab, or it expired): say so instead of a JSON error.
                if (response.redirected || !(response.headers.get('Content-Type') || '').includes('application/json')) throw Object.assign(new Error(t('ログインが切れた可能性があります。ログインし直してから、もう一度お試しください。')), { login: true });
                if (!response.ok) throw new Error(t('一覧を読めませんでした。ログインを確認してください。'));
                const result = await response.json();
                if (current !== list.generation) return;
                const kind = list.kind;
                result.items.forEach(item => {
                    const button = document.createElement('button'); button.type = 'button';
                    let img;
                    if (item.thumb) { img = document.createElement('img'); img.src = adminUrl(item.thumb); img.alt = ''; img.loading = 'lazy'; }
                    else { img = document.createElement('span'); img.className = 'log-picker-kind'; img.append(kindMark(item.kind)); }
                    const label = document.createElement('span'); label.textContent = item.title || item.alt || t('画像');
                    button.append(img, label);
                    button.title = label.textContent;
                    if (item.locked) { const badge = document.createElement('small'); badge.textContent = t('パスワード付き'); button.append(badge); }
                    button.addEventListener('click', () => { pick(item, kind); picked(); });
                    grid.append(button);
                });
                more.hidden = !result.more; note.textContent = grid.children.length ? t('選ぶと、本文のカーソル位置に入ります。') : t('見つかりませんでした。');
            } catch (e) {
                if (current !== list.generation) return;
                note.textContent = e.message;
                if (e.login && document.documentElement.dataset.publicLogin) { const a = document.createElement('a'); a.href = adminUrl('login.php'); a.target = '_blank'; a.rel = 'noopener'; a.textContent = t('ログイン画面を開く'); note.append(' ', a); }
            }
            finally { if (current === list.generation) more.disabled = false; }
        };
        list.search.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => list.load(true), 250); });
        more.addEventListener('click', () => { list.page++; list.load(false); });
        return list;
    }
    const pickerList = catalog(picker, () => { picker.close(); body.focus(); });
    // The dock stays open beside the editor, so a pick leaves it as it is and goes back to the text.
    dockList = catalog(dock, () => body.focus({ preventScroll: true }));
    $$('[data-picker]', form).forEach(button => button.addEventListener('click', () => {
        if (button.dataset.picker === 'media' && panel.dataset.dock) {
            dock.classList.remove('is-tucked'); dockTab('library'); dockList.search.focus({ preventScroll: true });
            dock.classList.remove('is-called'); void dock.offsetWidth; dock.classList.add('is-called');
            return;
        }
        pickerList.kind = button.dataset.picker; pickerList.search.value = ''; $('#log-picker-title').textContent = pickerList.kind === 'manga' ? t('漫画を選ぶ') : t('メディアを選ぶ');
        picker.showModal(); pickerList.load(true);
    }));
    $('[data-picker-close]', picker).addEventListener('click', () => picker.close());
    picker.addEventListener('close', () => { pickerList.generation++; });
    dock.addEventListener('animationend', () => dock.classList.remove('is-called'));
    async function openPreview() {
        const preview = previewBox;
        try {
            const data = new FormData(form); data.set('do', 'log_preview'); data.set('body', compose());
            const result = await request(data);
            // HTML comes only from the server's escaping/token renderer.
            preview.innerHTML = result.html;
            $$('[src],[href],[data-endpoint]', preview).forEach(el => ['src', 'href', 'data-endpoint'].forEach(attr => {
                // Links to a heading of the post (the table of contents) stay on this page.
                if (el.hasAttribute(attr) && !el.getAttribute(attr).startsWith('#')) el.setAttribute(attr, adminUrl(el.getAttribute(attr)));
            }));
            showPreview(true);
            window.NagiSwipe?.init();
            window.NagiLogEmbeds?.load(preview);
        } catch (e) { say(e.message, true); }
    }
    $('[data-preview]', form).addEventListener('click', () => { if (form.classList.contains('is-previewing')) showPreview(false); else openPreview(); });
    // 書く / プレビュー tabs (shown on a PC or sideways); the eye on the tool row switches them too.
    const tabs = $$('[data-compose-tab]', form);
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            if (tab.dataset.composeTab === 'preview') { if (!form.classList.contains('is-previewing')) openPreview(); }
            else { showPreview(false); body.focus(); }
        });
        tab.addEventListener('keydown', e => {
            if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
            e.preventDefault();
            const next = tabs[(tabs.indexOf(tab) + 1) % tabs.length];
            next.focus(); next.click();
        });
    });
    // On a PC or sideways, the tool row and its panels sit above the text and the preview takes the text's place;
    // on a phone held upright they go back under the text, where they are in the page, and the post's media follow
    // them so the tools stay right under the text.
    const toolzone = $('[data-toolzone]', form);
    const toolzoneHome = document.createComment('tools'), previewHome = document.createComment('preview'), mediaHome = document.createComment('media');
    toolzone.before(toolzoneHome); previewBox.before(previewHome); previewBox.after(mediaHome);
    // The dock: the post's media (its first tab, moved out of the form) and the media library, on the left on a PC
    // or sideways. Beside the full-screen editor and on the edit page it is a column ("grid"); beside the top page's
    // editor it floats in the margin ("float"), tucks away while the page scrolls down and comes back on the way up.
    const attachmentsHome = document.createComment('attachments'), menuHome = document.createComment('menu');
    attachments.before(attachmentsHome); menu.before(menuHome);
    function dockMode() {
        if (!wide.matches) return '';
        if (panel.classList.contains('active')) return 'grid';
        if (!slot || collapsed) return '';
        const card = panel.getBoundingClientRect();
        return card.width && card.left >= 236 ? 'float' : '';
    }
    function arrange() {
        arranged = true;
        form.classList.toggle('is-wide', wide.matches);
        if (wide.matches && previewSheet.open) previewSheet.close();
        if (wide.matches) { $('[data-compose-tabs]', form).before(toolzone); markLayer.after(previewBox); }
        else { toolzoneHome.after(toolzone); if (!previewSheet.open) previewHome.after(previewBox); }
        const mode = dockMode(), after = mode ? null : wide.matches ? attachmentsHome : mediaHome;
        if (panel.dataset.dock !== mode || (after && after.nextSibling !== attachments)) {
            closeMenu();
            panel.dataset.dock = mode;
            if (mode) { $('[data-dock-pane="post"]', dock).append(attachments); dock.append(menu); }
            else if (wide.matches) { attachmentsHome.after(attachments); menuHome.after(menu); }
            else mediaHome.after(attachments, menu);
            dock.classList.remove('is-tucked');
        }
        awayCheck();
        placeDock(); drawMark(); toolsHint(); dockCheck(); dockCount();
    }
    wide.addEventListener('change', arrange);
    let resizeTicking = false;
    window.addEventListener('resize', () => { if (!resizeTicking) { resizeTicking = true; requestAnimationFrame(() => { resizeTicking = false; arrange(); }); } });
    function placeDock() {
        if (panel.dataset.dock !== 'float') return;
        const card = panel.getBoundingClientRect(), width = Math.min(300, card.left - 40);
        dock.style.setProperty('--log-dock-width', width + 'px');
        dock.style.setProperty('--log-dock-left', (card.left - 24 - width) + 'px');
        dock.style.setProperty('--log-dock-top', Math.max(16, card.top) + 'px');
    }
    let lastScroll = window.scrollY, scrollTick = false;
    window.addEventListener('scroll', () => {
        if (panel.dataset.dock !== 'float' || scrollTick) return;
        scrollTick = true;
        requestAnimationFrame(() => {
            scrollTick = false;
            const y = window.scrollY, moved = y - lastScroll;
            if (Math.abs(moved) > 8) {
                const tuck = moved > 0 && y > 60;
                if (tuck && !dock.classList.contains('is-tucked')) closeMenu();
                dock.classList.toggle('is-tucked', tuck);
                lastScroll = y;
            }
            placeDock();
        });
    }, { passive: true });
    function dockTab(name) {
        $$('[role="tab"][data-dock-tab]', dock).forEach(tab => { const on = tab.dataset.dockTab === name; tab.setAttribute('aria-selected', String(on)); tab.tabIndex = on ? 0 : -1; });
        $$('[data-dock-pane]', dock).forEach(pane => { pane.hidden = pane.dataset.dockPane !== name; });
        dockCheck(); awayCheck();
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
    $('[data-dock-upload]', dock).addEventListener('click', () => uploadInput.click());
    arrange();
    // The preview opened from the eye or the tab. As a tab it keeps the text's height and scrolls inside.
    function showPreview(on) {
        const preview = previewBox;
        if (on && !form.classList.contains('is-previewing')) preview.style.setProperty('--log-preview-height', Math.max(body.offsetHeight, 320) + 'px');
        preview.hidden = !on; form.classList.toggle('is-previewing', on);
        $('[data-preview]', form).setAttribute('aria-pressed', String(on));
        $$('[data-compose-tab]', form).forEach(tab => {
            const selected = (tab.dataset.composeTab === 'preview') === on;
            tab.setAttribute('aria-selected', String(selected)); tab.tabIndex = selected ? 0 : -1;
        });
        if (on && !wide.matches && !previewSheet.open) {
            previewBox.classList.replace('log-preview', 'log-post'); $('.log-preview-sheet-body', previewSheet).append(previewBox);
            document.documentElement.classList.add('log-preview-open');
            previewSheet.show(); previewSheet.scrollTop = 0;
            $('[data-preview-close]', previewSheet).focus({ preventScroll: true });
        } else if (!on && previewSheet.open) previewSheet.close();
        awayCheck();
    }
    previewSheet.addEventListener('close', () => {
        previewBox.classList.replace('log-post', 'log-preview');
        document.documentElement.classList.remove('log-preview-open');
        // Turned sideways while it was open: the preview stays, as the tab beside 書く.
        if (wide.matches) { markLayer.after(previewBox); return; }
        previewHome.after(previewBox);
        if (form.classList.contains('is-previewing')) showPreview(false);
        $('[data-preview]', form).focus({ preventScroll: true });
    });
    $('[data-preview-close]', previewSheet).addEventListener('click', () => previewSheet.close());
    previewSheet.addEventListener('keydown', e => {
        // Escape over the image or manga viewer opened from the preview closes only that viewer.
        if (e.defaultPrevented || window.NagiSwipe?.isOpen || document.querySelector('.nm-viewer:not([hidden])')) return;
        if (e.key === 'Escape') { e.preventDefault(); previewSheet.close(); return; }
        if (e.key !== 'Tab') return;
        const focusable = $$('a[href],button:not(:disabled),input,summary,iframe,[tabindex]:not([tabindex="-1"])', previewSheet).filter(el => el.getClientRects().length);
        const first = focusable[0], last = focusable.at(-1);
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
    // 本文へ beside the heading (a PC or sideways): back to writing from the preview, an open panel, the media list
    // or a page scrolled away from the text, with the caret where it was. It stands out while one of those is so.
    function awayCheck() {
        if (!toBody) return;
        toBody.hidden = !wide.matches;
        const away = form.classList.contains('is-previewing') || !!$('[data-panel][aria-expanded="true"]', form)
            || (!!panel.dataset.dock && $('[data-dock-pane="post"]', dock).hidden) || !bodySeen;
        toBody.classList.toggle('is-away', away);
    }
    if (toBody && 'IntersectionObserver' in window) new IntersectionObserver(entries => { bodySeen = entries.at(-1).isIntersecting; awayCheck(); }, { threshold: 0.2 }).observe(body);
    toBody?.addEventListener('click', () => {
        showPreview(false); closeMenu();
        const open = $('[data-panel][aria-expanded="true"]', form);
        if (open) togglePanel(open.dataset.panel, false);
        if (panel.dataset.dock) { dockTab('post'); dock.classList.remove('is-tucked'); }
        // The row of tools and the top of the text in sight, under the heading when it stays at the top.
        const head = $('.log-compose-head', panel), at = body.getBoundingClientRect();
        const top = getComputedStyle(head).position === 'sticky' ? head.getBoundingClientRect().bottom : 0;
        if (at.top < top + 8 || at.top > window.innerHeight - 160) form.scrollIntoView({ block: 'start', behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
        body.focus({ preventScroll: true }); body.setSelectionRange(selection[0], selection[1]);
        awayCheck();
    });
    // The library is read the first time its tab shows.
    function dockCheck() { if (dockList && !dockList.loaded && panel.dataset.dock && !$('[data-dock-pane="library"]', dock).hidden) dockList.load(true); }
    // The first tab's count, with a small bump when it changes, and its note while the post has no media.
    function dockCount() {
        const count = $('[data-dock-count]', dock), n = String(allImages().length);
        if (count.textContent !== n) {
            const first = !dock.dataset.counted;
            count.textContent = n; dock.dataset.counted = '1';
            if (!first) { count.classList.remove('is-bump'); void count.offsetWidth; count.classList.add('is-bump'); }
        }
        $('[data-dock-empty]', dock).hidden = !attachments.hidden;
    }
    // The tool row scrolls sideways when it does not fit: a fade and an arrow show at the end that has more.
    function toolsHint() {
        if (!tools) return;
        const rest = tools.scrollWidth - tools.clientWidth;
        toolsScroll.classList.toggle('can-start', tools.scrollLeft > 2);
        toolsScroll.classList.toggle('can-end', rest > 2 && tools.scrollLeft < rest - 2);
    }
    // The first time in a tab, the row moves a little and back, so it shows that it scrolls.
    function toolsNudge() {
        if (!toolsScroll?.classList.contains('can-end') || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        try { if (sessionStorage.getItem('nagilog-tools-nudged')) return; sessionStorage.setItem('nagilog-tools-nudged', '1'); } catch { return; }
        setTimeout(() => { tools.scrollTo({ left: 48, behavior: 'smooth' }); setTimeout(() => tools.scrollTo({ left: 0, behavior: 'smooth' }), 650); }, 400);
    }
    tools.addEventListener('scroll', toolsHint, { passive: true });
    if ('ResizeObserver' in window) new ResizeObserver(toolsHint).observe(tools);
    $$('[data-tools-step]', toolsScroll).forEach(button => button.addEventListener('click', () => {
        tools.scrollBy({ left: +button.dataset.toolsStep * tools.clientWidth * 0.7, behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
    }));
    toolsHint();
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (saving) return;
        if (uploading) { say(t('メディアの追加が終わってから保存してください。'), true); return; }
        const data = new FormData(form); data.set('status', e.submitter?.value || 'published'); data.set('body', compose());
        saving = true; $$('button[type="submit"],button[name="status"]', form).forEach(b => { b.disabled = true; });
        say(t('保存しています…'));
        try {
            const result = await request(data);
            try { sessionStorage.removeItem(storageKey); } catch { /* storage may be disabled */ }
            dirty = false;
            // Editing an existing post in the admin: ask where to go instead of always returning to the list.
            if (panel.dataset.edit && !publicEditor) { savedDialog(result, data.get('status') === 'published'); return; }
            location.href = publicEditor && !result.id.startsWith('d') ? new URL(result.url, endpoint).href : adminUrl(result.redirect);
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
        const view = published ? link(t('記事を見る'), new URL(result.url, endpoint).href, 'btn primary') : null;
        const actions = el('div', 'log-saved-dialog-actions');
        actions.append(link(t('続けて編集する'), editUrl, 'btn'), origin ? link(isPostPage(origin) ? t('記事ページに戻る') : t('公開ページに戻る'), origin, published ? 'btn' : 'btn primary') : link(t('一覧に戻る'), adminUrl(result.redirect), published ? 'btn' : 'btn primary'));
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
