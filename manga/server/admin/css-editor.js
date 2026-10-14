/* NagiLog additional CSS editor (admin page only). MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const i18nNode = document.getElementById('nm-i18n');
    let i18n = {};
    try { i18n = i18nNode ? JSON.parse(i18nNode.textContent) : {}; } catch (e) { i18n = {}; }
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);

    /*
     * The syntax check follows lib/log-css.php nl_css_errors() rule for rule; the server repeats it before saving.
     * Errors: unclosed comments, strings, url() and brackets, closing brackets without a partner, "{" without a selector,
     * an item ending in ";" that is neither an at-rule nor "property: value", a selector left without "{ }".
     * Warnings (this page only, never blocking): what this browser's CSS.supports() does not know, at-rules it does not
     * know, @import after other rules, and outside URLs the public page's CSP does not load.
     */
    const ERRORS_MAX = 50;
    const isWs = c => c === ' ' || c === '\t' || c === '\n' || c === '\f';
    const isDigit = c => c >= '0' && c <= '9';
    const isAlpha = c => (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z');
    const nameStart = c => c !== '' && (isAlpha(c) || c === '_' || c.charCodeAt(0) >= 0x80);
    const nameChar = c => c !== '' && (nameStart(c) || isDigit(c) || c === '-');

    function tokenize(css, add) {
        const n = css.length;
        const at = k => k < n ? css[k] : '';
        const name = k => {
            while (k < n) {
                if (css[k] === '\\' && k + 1 < n && css[k + 1] !== '\n') { k += 2; continue; }
                if (!nameChar(css[k])) break;
                k++;
            }
            return k;
        };
        const identStart = k => nameStart(at(k)) || (at(k) === '\\' && at(k + 1) !== '\n' && at(k + 1) !== '')
            || (at(k) === '-' && (nameStart(at(k + 1)) || at(k + 1) === '-' || (at(k + 1) === '\\' && at(k + 2) !== '\n' && at(k + 2) !== '')));
        const tokens = [];
        const push = (type, start, end, look) => tokens.push({ type, start, end, look: look || type });
        let i = 0;
        while (i < n) {
            const c = css[i];
            if (isWs(c)) { const s = i; while (i < n && isWs(css[i])) i++; push('ws', s, i); continue; }
            if (c === '/' && at(i + 1) === '*') {
                const end = css.indexOf('*/', i + 2);
                if (end < 0) { add(i, t('コメント（/*）が閉じていません')); push('comment', i, n); break; }
                push('comment', i, end + 2); i = end + 2; continue;
            }
            if (c === '"' || c === "'") {
                const s = i; i++;
                for (;;) {
                    if (i >= n) { add(s, t('文字列の引用符が閉じていません')); break; }
                    const d = css[i];
                    if (d === '\\') { i += 2; continue; }
                    if (d === '\n') { add(s, t('文字列の引用符が閉じていません')); break; }
                    i++;
                    if (d === c) break;
                }
                push('str', s, Math.min(i, n), 'string'); continue;
            }
            if (c === '@' && identStart(i + 1)) { const end = name(i + 1); push('at', i, end); i = end; continue; }
            if (identStart(i)) {
                const s = i; i = name(i);
                if (at(i) === '(') {
                    if (css.slice(s, i).toLowerCase() === 'url') {
                        let k = i + 1;
                        while (isWs(at(k))) k++;
                        if (at(k) !== '"' && at(k) !== "'") {
                            let bad = false;
                            for (;;) {
                                const d = at(k);
                                if (d === '') { add(s, t('url( ) が閉じていません')); i = n; break; }
                                if (d === ')') { i = k + 1; break; }
                                if (isWs(d)) { while (isWs(at(k))) k++; if (at(k) !== ')') bad = true; continue; }
                                if (d === '"' || d === "'" || d === '(') bad = true;
                                k += d === '\\' ? 2 : 1;
                            }
                            if (bad) add(s, t('url( ) の中身が正しくありません。空白や引用符を含むときは url("…") と書きます'));
                            push('other', s, i, 'url'); continue;
                        }
                    }
                    i++; push('(', s, i, 'func'); continue;
                }
                push('ident', s, i); continue;
            }
            if (isDigit(c) || ((c === '.' || c === '+' || c === '-') && (isDigit(at(i + 1)) || (at(i + 1) === '.' && isDigit(at(i + 2)))))) {
                const s = i; i++;
                while (i < n && (isDigit(css[i]) || css[i] === '.')) i++;
                if ((at(i) === 'e' || at(i) === 'E') && (isDigit(at(i + 1)) || ((at(i + 1) === '+' || at(i + 1) === '-') && isDigit(at(i + 2))))) { i += 2; while (i < n && isDigit(css[i])) i++; }
                if (at(i) === '%') i++; else if (identStart(i)) i = name(i);
                push('other', s, i, 'num'); continue;
            }
            if (c === '#') { const s = i; i = name(i + 1); push('other', s, i, 'hash'); continue; }
            if (c === '\\') { push('other', i, Math.min(i + 2, n)); i += 2; continue; }
            push('{}()[];:'.includes(c) ? c : 'other', i, i + 1, '{}()[];:,'.includes(c) ? 'punct' : (c === '!' ? 'bang' : 'other'));
            i++;
        }
        return tokens;
    }

    /** {errors, warnings, tokens}; offsets are string indexes. */
    function analyze(css, withWarnings) {
        const errors = [];
        const warnings = [];
        const add = (offset, message) => { if (errors.length < ERRORS_MAX) errors.push({ offset, message }); };
        const warn = (offset, end, message) => { if (warnings.length < 200) warnings.push({ offset, end, message }); };
        const tokens = tokenize(css, add);
        const close = { '}': '{', ')': '(', ']': '[' };
        // [type, offset, inValue, atName, isStyleRule]
        const stack = [['top', -1, false, '', false]];
        let item = null;
        let sawRule = false;
        const blockAt = () => { for (let k = stack.length - 1; k > 0; k--) if (stack[k][0] === '{' && !stack[k][2]) return stack[k][3]; return ''; };
        const DESCRIPTORS = /^(font-face|property|page|counter-style|font-palette-values|font-feature-values|position-try|view-transition|color-profile)$/;
        const endItem = (semicolon, end) => {
            if (item === null) return;
            const it = item; item = null;
            if (it.first === 'at') { if (withWarnings) atWarnings(it, end, false); return; }
            if (stack[stack.length - 1][0] === 'top') { add(it.start, semicolon ? t('ここには宣言を書けません。セレクター { } の中に書きます') : t('セレクターのあとに { } がありません')); return; }
            if (it.first === ':') add(it.start, t('プロパティ名がありません'));
            else if (!it.colon) add(it.start, t('宣言に「:」がありません（プロパティ名: 値; の形で書きます）'));
            else {
                tokens[it.firstIndex].look = 'prop';
                if (withWarnings) declWarnings(it, end);
            }
        };
        const KNOWN_AT = /^(media|supports|import|charset|namespace|font-face|keyframes|-webkit-keyframes|page|layer|container|property|counter-style|font-feature-values|font-palette-values|scope|starting-style|view-transition|position-try|document|-moz-document|color-profile)$/;
        const plain = (s, e) => css.slice(s, e).replace(/\/\*[\s\S]*?\*\//g, ' ').trim();
        const supports = typeof CSS !== 'undefined' && typeof CSS.supports === 'function';
        const atWarnings = (it, end, block) => {
            const nameEnd = tokens[it.firstIndex].end;
            const nm = css.slice(it.start + 1, nameEnd).toLowerCase();
            if (!KNOWN_AT.test(nm)) warn(it.start, nameEnd, t('「@{name}」は、このブラウザーが知らない@ルールです', { name: nm }));
            if (nm === 'import' && (sawRule || stack.length > 1)) warn(it.start, nameEnd, t('@import は、ほかのルールより前に書かないと無視されます'));
            if (!block && nm !== 'import' && nm !== 'charset' && nm !== 'layer' && nm !== 'namespace' && stack.length === 1) sawRule = true;
            if (block && stack.length === 1 && nm !== 'layer') sawRule = true;
            outsideUrls(it.start, end);
        };
        const declWarnings = (it, end) => {
            const prop = css.slice(it.start, tokens[it.firstIndex].end);
            const colon = tokens[it.colonIndex].end;
            const value = plain(colon, end).replace(/!\s*important\s*$/i, '').trim();
            outsideUrls(colon, end);
            if (!supports || prop.startsWith('--') || DESCRIPTORS.test(blockAt())) return;
            const p = prop.toLowerCase();
            if (!CSS.supports(p, 'initial')) {
                warn(it.start, tokens[it.firstIndex].end, /^-(webkit|moz|ms|o)-/.test(p)
                    ? t('接頭辞付きのプロパティ「{name}」は、このブラウザーでは使えません。ほかのブラウザー向けなら、このままで構いません', { name: prop })
                    : t('「{name}」は、このブラウザーが知らないプロパティです。綴りを確かめてください。新しいプロパティなら、このままで構いません', { name: prop }));
                return;
            }
            if (value === '') { warn(it.start, end, t('「{name}」の値がありません', { name: prop })); return; }
            if (!/\b(var|env|attr)\(/i.test(value) && !CSS.supports(p, value)) warn(colon, end, t('「{name}」の値「{value}」を、このブラウザーでは解釈できません', { name: prop, value: value.length > 60 ? value.slice(0, 60) + '…' : value }));
        };
        const ruleWarnings = (start, end, nested) => {
            if (!supports || /keyframes$/.test(blockAt()) || DESCRIPTORS.test(blockAt())) return;
            const text = plain(start, end);
            let depth = 0, from = 0;
            const parts = [];
            for (let k = 0; k < text.length; k++) {
                const c = text[k];
                if (c === '(' || c === '[') depth++;
                else if ((c === ')' || c === ']') && depth > 0) depth--;
                else if (c === ',' && depth === 0) { parts.push(text.slice(from, k)); from = k + 1; }
                else if ((c === '"' || c === "'")) { const q = text.indexOf(c, k + 1); if (q > 0) k = q; }
            }
            parts.push(text.slice(from));
            for (let part of parts) {
                part = part.trim();
                if (part === '') { warn(start, end, t('セレクターの並びに空の項目があります')); continue; }
                let test = part;
                if (nested) test = /&/.test(test) ? test.replace(/&/g, ':scope') : (/^[>+~]/.test(test) ? ':scope ' + test : ':scope ' + test);
                let ok = true;
                try { ok = CSS.supports('selector(' + test + ')'); } catch (e) { ok = true; }
                if (!ok) warn(start, end, /(^|[^a-z])-(webkit|moz|ms|o)-/i.test(part)
                    ? t('接頭辞付きのセレクター「{name}」は、このブラウザーでは使えません。ほかのブラウザー向けなら、このままで構いません', { name: part })
                    : t('セレクター「{name}」を、このブラウザーでは解釈できません', { name: part }));
            }
        };
        const outsideUrls = (s, e) => {
            const re = /url\(\s*(['"]?)\s*((?:https?:)?\/\/[^'")\s]+)|@import\s+(['"])((?:https?:)?\/\/[^'"]+)/gi;
            const text = css.slice(s, e);
            let m;
            while ((m = re.exec(text))) warn(s + m.index, s + m.index + m[0].length, t('ほかのサイトのファイル（{url}）は、公開ページのCSPにより読み込まれません', { url: m[2] || m[4] }));
        };
        tokens.forEach((tok, index) => {
            const type = tok.type;
            if (type === 'ws' || type === 'comment') return;
            const top = stack[stack.length - 1];
            const items = top[0] === 'top' || (top[0] === '{' && !top[2]);
            if (type in close) {
                let match = null;
                for (let k = stack.length - 1; k > 0; k--) if (stack[k][0] === close[type]) { match = k; break; }
                if (match !== null && type !== '}') for (let k = stack.length - 1; k > match; k--) if (stack[k][0] === '{') { match = null; break; }
                if (match === null) { add(tok.start, type === '}' ? t('対応する「{」がない「}」です') : t('対応する開きかっこがない「{c}」です', { c: type })); tok.look = 'bad'; return; }
                for (let k = stack.length - 1; k > match; k--) add(stack[k][1], t('「{c}」が閉じていません', { c: stack[k][0] }));
                stack.length = match + 1;
                if (type === '}' && !stack[match][2]) endItem(false, tok.start);
                stack.pop();
                return;
            }
            if (!items) {
                if (type === '{' || type === '(' || type === '[') stack.push([type, tok.start, true, '', false]);
                return;
            }
            if (type === ';') { endItem(true, tok.start); return; }
            if (type === '{') {
                if (item !== null && item.colon && item.custom) { stack.push(['{', tok.start, true, '', false]); return; }
                if (item === null) add(tok.start, t('「{」の前にセレクターがありません'));
                let atName = '';
                if (item !== null && item.first === 'at') {
                    atName = css.slice(item.start + 1, tokens[item.firstIndex].end).toLowerCase();
                    if (withWarnings) atWarnings(item, tok.start, true);
                } else if (item !== null) {
                    for (let k = item.firstIndex; k < index; k++) if (tokens[k].look === 'ident' || tokens[k].look === 'hash' || tokens[k].look === 'punct' || tokens[k].look === 'other') tokens[k].look = 'sel';
                    if (withWarnings) ruleWarnings(item.start, tok.start, stack.some(f => f[4]));
                    if (stack.length === 1) sawRule = true;
                }
                const style = item !== null && item.first !== 'at';
                item = null;
                stack.push(['{', tok.start, false, atName, style]);
                return;
            }
            if (item === null) {
                item = { start: tok.start, first: type === 'at' ? 'at' : (type === ':' ? ':' : type), colon: false, custom: type === 'ident' && css.startsWith('--', tok.start), count: 1, firstIndex: index, colonIndex: -1 };
                if (type === '(' || type === '[') stack.push([type, tok.start, true, '', false]);
                return;
            }
            item.count++;
            if (type === ':' && item.count === 2 && item.first === 'ident') { item.colon = true; item.colonIndex = index; }
            if (type === '(' || type === '[') stack.push([type, tok.start, true, '', false]);
        });
        if (item !== null && stack[stack.length - 1][0] === 'top') endItem(false, css.length);
        for (let k = stack.length - 1; k > 0; k--) add(stack[k][1], t('「{c}」が閉じていません', { c: stack[k][0] }));
        errors.sort((a, b) => a.offset - b.offset);
        return { errors, warnings, tokens };
    }
    /** Line, column and character offset (code points, as the server counts them). */
    function place(css, offset) {
        const before = css.slice(0, offset);
        const line = before.split('\n').length;
        const lineStart = before.lastIndexOf('\n') + 1;
        return { line, column: Array.from(before.slice(lineStart)).length + 1, offset: Array.from(before).length };
    }
    // For dev/log_css_browser_test.py, which compares this check with the server's.
    window.NLCssCheck = css => analyze(css, false).errors.map(e => Object.assign(place(css, e.offset), { message: e.message }));

    const root = document.querySelector('[data-css-editor]');
    if (!root) return;
    const form = root.querySelector('[data-css-form]');
    const text = root.querySelector('[data-css-text]');
    const code = root.querySelector('[data-css-highlight]');
    const pre = code.parentElement;
    const gutter = root.querySelector('[data-css-gutter]');
    const drop = root.querySelector('[data-css-drop]');
    const hint = root.querySelector('[data-css-drop-hint]');
    const status = root.querySelector('[data-css-status]');
    const panel = root.querySelector('[data-css-diagnostics]');
    const panelTitle = root.querySelector('[data-css-diagnostics-title]');
    const list = root.querySelector('[data-css-diagnostics-list]');
    const fileInput = root.querySelector('[data-css-file]');
    const saveButton = root.querySelector('[data-css-save]');
    const restoreButton = root.querySelector('[data-css-restore]');
    const restoreForm = document.getElementById('css-restore');
    const download = root.querySelector('[data-css-download]');
    const enabledForm = root.querySelector('[data-css-enabled-form]');
    const MAX = Number(root.dataset.max) || 262144;
    const encoder = new TextEncoder();
    let saved = root.hasAttribute('data-draft') ? null : text.value;
    let revision = Number(root.dataset.revision) || 0;
    let busy = false;
    let last = { errors: [], warnings: [] };
    root.classList.add('is-ready');

    const escapeHtml = s => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' })[c]);
    const dirty = () => saved === null || text.value !== saved;
    const setRevision = r => { revision = r; document.querySelectorAll('[data-css-revision]').forEach(input => { input.value = String(r); }); };
    const say = (message, kind) => { status.textContent = message; status.dataset.kind = kind || ''; };
    const sizeText = bytes => bytes < 1024 ? bytes + ' B' : (bytes / 1024).toFixed(1) + ' KiB';

    function paint(result) {
        const css = text.value;
        let html = '';
        let pos = 0;
        const bad = new Set(result.errors.map(e => e.offset));
        for (const tok of result.tokens) {
            if (tok.start > pos) html += escapeHtml(css.slice(pos, tok.start));
            const piece = escapeHtml(css.slice(tok.start, tok.end));
            html += tok.look === 'ws' || tok.look === 'other' ? piece : '<span class="tk-' + tok.look + (bad.has(tok.start) ? ' tk-error' : '') + '">' + piece + '</span>';
            pos = tok.end;
        }
        if (pos < css.length) html += escapeHtml(css.slice(pos));
        // A final line break needs a line of its own in the overlay.
        code.innerHTML = html + (css.endsWith('\n') || css === '' ? ' ' : '');
        const starts = [0];
        for (let k = css.indexOf('\n'); k >= 0; k = css.indexOf('\n', k + 1)) starts.push(k + 1);
        const lines = starts.length;
        const lineOf = offset => { let lo = 0, hi = starts.length - 1; while (lo < hi) { const mid = (lo + hi + 1) >> 1; if (starts[mid] <= offset) lo = mid; else hi = mid - 1; } return lo + 1; };
        const marks = new Map();
        result.errors.forEach(e => marks.set(lineOf(e.offset), 'error'));
        result.warnings.forEach(w => { const l = lineOf(w.offset); if (!marks.has(l)) marks.set(l, 'warning'); });
        let numbers = '';
        for (let l = 1; l <= lines; l++) numbers += (marks.has(l) ? '<span class="ln-' + marks.get(l) + '">' + l + '</span>' : l) + '\n';
        gutter.innerHTML = '<div class="css-gutter-lines">' + numbers + '</div>';
        gutter.style.width = (String(lines).length + 2) + 'ch';
        sync();
    }
    function report(result, serverErrors) {
        const css = text.value;
        const errors = serverErrors || result.errors.map(e => Object.assign(place(css, e.offset), { message: e.message, at: e.offset }));
        const warnings = serverErrors ? [] : result.warnings.map(w => Object.assign(place(css, w.offset), { message: w.message, at: w.offset, end: w.end }));
        list.textContent = '';
        const item = (d, kind) => {
            const li = document.createElement('li');
            li.className = 'css-diagnostic is-' + kind;
            const b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = '<span class="css-diagnostic-kind"></span> <span class="css-diagnostic-place"></span> <span class="css-diagnostic-message"></span>';
            b.querySelector('.css-diagnostic-kind').textContent = kind === 'error' ? t('エラー') : t('警告');
            b.querySelector('.css-diagnostic-place').textContent = t('{line}行{column}列', d);
            b.querySelector('.css-diagnostic-message').textContent = d.message;
            b.addEventListener('click', () => jump(d));
            li.append(b);
            list.append(li);
        };
        errors.forEach(d => item(d, 'error'));
        warnings.forEach(d => item(d, 'warning'));
        panel.hidden = errors.length + warnings.length === 0;
        panelTitle.textContent = errors.length ? t('構文エラー {e}件・警告 {w}件', { e: errors.length, w: warnings.length }) : t('警告 {w}件（保存できます）', { w: warnings.length });
        panel.dataset.state = errors.length ? 'error' : 'warning';
        return errors;
    }
    /** Select the place of a message and scroll it into view. */
    function jump(d) {
        const css = text.value;
        let at = d.at;
        if (at === undefined) {
            // A server message: from line and column (code points) back to a string index.
            const lines = css.split('\n');
            at = 0;
            for (let l = 1; l < d.line && l <= lines.length; l++) at += lines[l - 1].length + 1;
            const line = lines[d.line - 1] || '';
            at += Array.from(line).slice(0, d.column - 1).join('').length;
        }
        const end = d.end && d.end > at ? Math.min(d.end, at + 200) : Math.min(css.length, at + 1);
        text.focus({ preventScroll: true });
        text.setSelectionRange(at, end);
        const style = getComputedStyle(text);
        const lineHeight = parseFloat(style.lineHeight) || 20;
        const line = css.slice(0, at).split('\n').length;
        text.scrollTop = Math.max(0, (line - 3) * lineHeight);
        const column = at - css.lastIndexOf('\n', at - 1) - 1;
        const charWidth = parseFloat(style.fontSize) * 0.6 || 8;
        text.scrollLeft = Math.max(0, column * charWidth - text.clientWidth / 2);
        sync();
    }
    function sync() {
        pre.style.transform = 'translate(' + (-text.scrollLeft) + 'px,' + (-text.scrollTop) + 'px)';
        const lines = gutter.firstElementChild;
        if (lines) lines.style.transform = 'translateY(' + (-text.scrollTop) + 'px)';
    }
    function sizeCheck() {
        const bytes = encoder.encode(text.value).length;
        return { bytes, over: bytes > MAX };
    }
    function check() {
        const result = analyze(text.value, true);
        last = result;
        paint(result);
        report(result);
        const size = sizeCheck();
        if (size.over) say(t('{size}／256KiB：上限を超えているため保存できません', { size: sizeText(size.bytes) }), 'error');
        else if (dirty()) say(t('未保存の変更があります（{size}）', { size: sizeText(size.bytes) }), 'dirty');
        root.classList.remove('is-typing');
        return result;
    }
    let timer = 0;
    // Short documents repaint at once; long ones show plain text while typing and check after a short pause.
    text.addEventListener('input', () => {
        clearTimeout(timer);
        if (text.value.length > 20000) root.classList.add('is-typing');
        else paint(analyze(text.value, false));
        timer = setTimeout(check, 300);
    });
    text.addEventListener('scroll', sync, { passive: true });
    window.addEventListener('resize', sync);
    let escaped = false;
    text.addEventListener('keydown', event => {
        if (event.key === 'Escape') { escaped = true; return; }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); save(); return; }
        if (event.key !== 'Tab' || event.ctrlKey || event.altKey || event.metaKey) { escaped = false; return; }
        if (escaped) { escaped = false; return; }
        event.preventDefault();
        const { selectionStart: s, selectionEnd: e, value } = text;
        const lineStart = value.lastIndexOf('\n', s - 1) + 1;
        if (s === e && !event.shiftKey) { insert('  ', s, e); return; }
        // Several lines: indent or outdent each of them.
        const block = value.slice(lineStart, e);
        const changed = event.shiftKey ? block.replace(/^ {1,2}/gm, '') : block.replace(/^/gm, '  ');
        insert(changed, lineStart, e);
        text.setSelectionRange(lineStart, lineStart + changed.length);
    });
    function insert(value, s, e) {
        text.focus();
        text.setSelectionRange(s, e);
        // execCommand keeps the browser's undo history; setRangeText is the fallback.
        if (!document.execCommand || !document.execCommand('insertText', false, value)) {
            text.setRangeText(value, s, e, 'end');
            text.dispatchEvent(new Event('input'));
        }
    }

    async function readFile(file) {
        if (!file) return;
        if (!/\.css$/i.test(file.name) && file.type !== 'text/css') { say(t('.cssファイルを選んでください'), 'error'); return; }
        if (file.size > MAX) { say(t('{name}は{size}あります。256KiBまでのファイルを選んでください', { name: file.name, size: sizeText(file.size) }), 'error'); return; }
        let css;
        try { css = new TextDecoder('utf-8', { fatal: true }).decode(await file.arrayBuffer()); } catch (e) { say(t('{name}はUTF-8ではないため読み込めません。UTF-8で保存し直してください', { name: file.name }), 'error'); return; }
        if (css.charCodeAt(0) === 0xFEFF) css = css.slice(1);
        css = css.replace(/\r\n?/g, '\n');
        if (dirty() && text.value !== css && !window.confirm(t('編集中の内容は保存されていません。{name}の内容で置き換えますか？', { name: file.name }))) return;
        text.focus();
        text.select();
        if (!document.execCommand || !document.execCommand('insertText', false, css)) { text.value = css; }
        text.setSelectionRange(0, 0);
        text.scrollTop = 0;
        // Checked right here; the delayed check of the input event would only overwrite the next message.
        clearTimeout(timer);
        check();
        say(t('{name}を読み込みました。保存すると公開ページに反映します', { name: file.name }), 'dirty');
    }
    fileInput.addEventListener('change', () => { readFile(fileInput.files[0]); fileInput.value = ''; });
    let depth = 0;
    const hasFiles = event => Array.from(event.dataTransfer?.types || []).includes('Files');
    drop.addEventListener('dragenter', event => { if (!hasFiles(event)) return; event.preventDefault(); depth++; hint.hidden = false; drop.classList.add('is-over'); });
    drop.addEventListener('dragover', event => { if (hasFiles(event)) { event.preventDefault(); event.dataTransfer.dropEffect = 'copy'; } });
    drop.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; hint.hidden = true; drop.classList.remove('is-over'); } });
    drop.addEventListener('drop', event => {
        if (!hasFiles(event)) return;
        event.preventDefault();
        depth = 0; hint.hidden = true; drop.classList.remove('is-over');
        readFile(event.dataTransfer.files[0]);
    });
    // A file dropped beside the editor must not open in the tab and lose the text.
    window.addEventListener('dragover', event => { if (hasFiles(event)) event.preventDefault(); });
    window.addEventListener('drop', event => { if (hasFiles(event)) event.preventDefault(); });

    async function send(data) {
        data.set('csrf', form.querySelector('[name="csrf"]').value);
        const response = await fetch('index.php', { method: 'POST', body: data, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        let body = {};
        try { body = await response.json(); } catch (e) { body = { error: t('保存できませんでした。時間をおいて、もう一度お試しください') }; }
        return { response, body };
    }
    function applyState(body) {
        hasCss = Boolean(body.hash);
        setRevision(body.revision);
        if (download) download.hidden = !body.hash;
        if (restoreButton) restoreButton.hidden = !body.restorable;
        setEnabled(body.enabled);
    }
    async function save(force) {
        if (busy) return;
        const result = check();
        if (sizeCheck().over) return;
        if (result.errors.length) { say(t('構文エラーを直してから保存してください。公開中のCSSはそのままです'), 'error'); jump(Object.assign(place(text.value, result.errors[0].offset), { at: result.errors[0].offset })); return; }
        busy = true; saveButton.disabled = true;
        say(t('保存しています…'), '');
        const value = text.value;
        try {
            const data = new FormData();
            data.set('do', 'log_css_save'); data.set('css', value); data.set('revision', String(force ?? revision));
            const { response, body } = await send(data);
            if (response.ok && body.ok) {
                saved = value;
                applyState(body);
                conflictBox(null);
                say(t('保存しました（{time}・{size}）', { time: body.updated_text, size: sizeText(body.size) }), 'ok');
                if (text.value !== value) say(t('保存しました。保存後の変更はまだ保存していません'), 'dirty');
            } else if (response.status === 409 && body.conflict) {
                conflictBox(body.revision);
                say(body.error, 'error');
            } else {
                if (body.diagnostics) report(last, body.diagnostics);
                say(body.error || t('保存できませんでした'), 'error');
            }
        } catch (e) {
            say(t('通信できませんでした。編集中の内容は残しています'), 'error');
        } finally { busy = false; saveButton.disabled = false; }
    }
    /** Another screen saved first: reload its text, or save this one over it. */
    function conflictBox(serverRevision) {
        let box = root.querySelector('[data-css-conflict]');
        if (serverRevision === null) { if (box) box.remove(); return; }
        if (!box) {
            box = document.createElement('div');
            box.className = 'css-conflict';
            box.dataset.cssConflict = '';
            box.innerHTML = '<p></p><button type="button" class="btn" data-css-overwrite></button> <a class="btn" href="index.php?p=css" data-css-reload></a>';
            box.querySelector('p').textContent = t('別の画面（ほかのタブや端末）で、追加CSSが先に保存されました。どちらを残すか選んでください。');
            box.querySelector('[data-css-overwrite]').textContent = t('この画面の内容で上書き保存');
            box.querySelector('[data-css-reload]').textContent = t('保存された内容を開き直す');
            box.querySelector('[data-css-reload]').addEventListener('click', event => { if (dirty() && !window.confirm(t('この画面の未保存の内容は失われます。開き直しますか？'))) event.preventDefault(); else saved = text.value; });
            status.before(box);
        }
        box.querySelector('[data-css-overwrite]').onclick = () => save(serverRevision);
    }
    form.addEventListener('submit', event => { event.preventDefault(); save(); });

    let hasCss = root.dataset.savedHash !== '';
    function setEnabled(on) {
        const state = enabledForm.querySelector('[data-css-enabled-state]');
        state.textContent = !on ? t('適用を止めています') : (hasCss ? t('公開ページに適用中') : t('保存すると公開ページに適用します'));
        enabledForm.dataset.on = on && hasCss ? '1' : (on ? 'empty' : '0');
        enabledForm.querySelector('[data-css-enabled-value]').value = on ? '0' : '1';
        enabledForm.querySelector('[data-css-enabled-button]').textContent = on ? t('適用を止める') : t('公開ページに適用する');
    }
    setEnabled(enabledForm.querySelector('[data-css-enabled-value]').value === '0');
    enabledForm.addEventListener('submit', async event => {
        event.preventDefault();
        const button = enabledForm.querySelector('button');
        button.disabled = true;
        try {
            const { response, body } = await send(new FormData(enabledForm));
            if (response.ok && body.ok) { setEnabled(body.enabled); say(body.message, 'ok'); } else say(body.error || t('保存できませんでした'), 'error');
        } catch (e) { say(t('通信できませんでした。時間をおいて、もう一度お試しください'), 'error'); }
        button.disabled = false;
    });
    restoreForm.addEventListener('submit', async event => {
        // admin.js asked first (form.js-confirm); a "no" there stops here too.
        if (event.defaultPrevented) return;
        event.preventDefault();
        if (dirty() && !window.confirm(t('この画面の未保存の内容は失われます。続けますか？'))) return;
        try {
            const { response, body } = await send(new FormData(restoreForm));
            if (response.ok && body.ok) {
                saved = body.css;
                text.value = body.css;
                applyState(body);
                check();
                say(body.message, 'ok');
            } else {
                if (response.status === 409 && body.conflict) conflictBox(body.revision);
                say(body.error || t('戻せませんでした'), 'error');
            }
        } catch (e) { say(t('通信できませんでした。時間をおいて、もう一度お試しください'), 'error'); }
    });
    window.addEventListener('beforeunload', event => { if (dirty()) { event.preventDefault(); event.returnValue = ''; } });

    check();
    if (saved === null) say(t('保存できなかった内容を表示しています。直してから保存してください'), 'error');

    // Catalog: filter the rows; show the changes after the chosen version (every version in between included).
    const search = document.querySelector('[data-css-catalog-search]');
    if (search) search.addEventListener('input', () => {
        const words = search.value.toLowerCase().split(/\s+/).filter(Boolean);
        document.querySelectorAll('[data-css-catalog-row]').forEach(row => { row.hidden = !words.every(w => row.dataset.search.includes(w)); });
    });
    const since = document.querySelector('[data-css-since]');
    if (since) since.addEventListener('change', () => {
        const from = Number(since.value);
        document.querySelectorAll('[data-css-history]').forEach(li => { li.hidden = Number(li.dataset.cssHistory) <= from; });
    });
})();
