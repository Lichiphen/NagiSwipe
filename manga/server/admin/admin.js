/*
 * NagiManga admin UI
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */
(function () {
    'use strict';
    // Icons come from viewer/icons/ui.svg (its address is on <html data-icons>).
    const icon = (id, cls = '') => '<svg' + (cls ? ' class="' + cls + '"' : '') + ' viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + document.documentElement.dataset.icons + '#' + id + '"/></svg>';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    // An error that must not be missed (a block the server refused to save): a toast at the bottom until closed.
    function toast(text, opts = {}) {
        let box = $('.nm-toasts');
        if (!box) { box = document.createElement('div'); box.className = 'nm-toasts'; document.body.append(box); }
        const item = document.createElement('div');
        item.className = 'nm-toast' + (opts.big ? ' nm-toast-big' : '') + (opts.shake ? ' nm-toast-shake' : '');
        item.setAttribute('role', 'alert');
        item.innerHTML = '<p></p><button type="button" class="nm-toast-close" aria-label="' + t('閉じる') + '">' + icon('close') + '</button>';
        item.firstChild.textContent = text;
        item.lastChild.addEventListener('click', () => item.remove());
        box.append(item);
        setTimeout(() => item.remove(), 15000);
    }

    // Save the same refused analytics block again and again and the warning grows, each time louder than the last,
    // before the message settles back into the toast at the bottom. Centered; skipped when the viewer asks for less motion.
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
    function escalate(strike, text) {
        if (reduceMotion || strike < 2) { toast(text, { big: strike >= 2, shake: strike >= 2 }); return; }
        if (strike === 2) { toast(text, { big: true, shake: true }); return; }
        const stage = document.createElement('div');
        stage.className = 'nm-punish'; stage.setAttribute('aria-hidden', 'true');
        document.body.append(stage);
        const done = () => { stage.remove(); toast(text, { big: true }); };
        const word = (chars, cls) => {
            const w = document.createElement('div');
            w.className = 'nm-punish-word ' + cls;
            for (const ch of chars) { const s = document.createElement('span'); s.textContent = ch; w.append(s); }
            stage.append(w);
            return w;
        };
        // Random fly-out direction for each piece of a word that breaks apart.
        const scatter = w => $$('span', w).forEach(s => {
            s.style.setProperty('--dx', (Math.random() * 2 - 1) * 55 + 'vw');
            s.style.setProperty('--dy', (Math.random() * 2 - 1) * 45 + 'vh');
            s.style.setProperty('--rot', (Math.random() * 2 - 1) * 540 + 'deg');
        });
        const after = (el, fn) => el.addEventListener('animationend', fn, { once: true });

        // After the painted word leaves, the screen itself cracks and crumbles.
        const crack = () => screenCrack(stage, done);

        if (strike === 3) {
            // Painted STOP pulled down by gravity, then bursts apart, then the screen cracks.
            const w = word('STOP', 'nm-punish-stop is-falling');
            after(w, () => { scatter(w); w.classList.add('is-burst'); after($('span', w), () => setTimeout(crack, 150)); });
        } else if (strike === 4) {
            // STOP shivers, bounces four times, bounces off the bottom, then the screen cracks.
            const w = word('STOP', 'nm-punish-stop is-wobble');
            after(w, () => { w.classList.remove('is-wobble'); w.classList.add('is-bounce');
                after(w, () => { w.classList.remove('is-bounce'); w.classList.add('is-exit'); after(w, crack); }); });
        } else {
            // "Enough already" stands out in relief; then the screen cracks and crumbles from the cracks, bit by bit.
            const w = word(t('くどいよ'), 'nm-punish-enough');
            setTimeout(() => {
                const box = w.getBoundingClientRect();
                for (let i = 0; i < 64; i++) {
                    const d = document.createElement('i');
                    d.className = 'nm-dust';
                    d.style.left = (box.left + Math.random() * box.width) + 'px';
                    d.style.top = (box.top + Math.random() * box.height) + 'px';
                    d.style.setProperty('--dx', (Math.random() * 2 - 1) * 12 + 'vw');
                    d.style.setProperty('--dy', (25 + Math.random() * 40) + 'vh');
                    d.style.animationDelay = (0.5 + Math.random() * 0.6) + 's';
                    stage.append(d);
                }
                w.classList.add('is-crumble');
                screenCrack(stage, done);
            }, 800);
        }
    }

    // A full-screen glass crack: lines spread from the middle, then the shards between them fall away one by one.
    function screenCrack(stage, done) {
        const NS = 'http://www.w3.org/2000/svg';
        const W = innerWidth, H = innerHeight, cx = W / 2, cy = H / 2, R = Math.hypot(W, H);
        const n = 11, rim = [];
        for (let i = 0; i < n; i++) {
            const a = (i / n) * Math.PI * 2 + (Math.random() * 0.3 - 0.15);
            rim.push([cx + Math.cos(a) * R, cy + Math.sin(a) * R]);
        }
        const svg = document.createElementNS(NS, 'svg');
        svg.setAttribute('class', 'nm-crack'); svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('aria-hidden', 'true');
        const line = (x1, y1, x2, y2, delay) => {
            const l = document.createElementNS(NS, 'line');
            l.setAttribute('x1', x1); l.setAttribute('y1', y1); l.setAttribute('x2', x2); l.setAttribute('y2', y2);
            l.style.setProperty('--len', Math.hypot(x2 - x1, y2 - y1));
            l.style.setProperty('--cd', delay + 's');
            svg.append(l);
        };
        rim.forEach(([x, y], i) => {
            line(cx, cy, x, y, 0);
            // A branch cracking off partway along each radial line.
            const t = 0.35 + Math.random() * 0.3, bx = cx + (x - cx) * t, by = cy + (y - cy) * t;
            const nb = rim[(i + 1) % n];
            line(bx, by, bx + (nb[0] - bx) * 0.4, by + (nb[1] - by) * 0.4, 0.22);
        });
        stage.append(svg);
        rim.forEach(([x1, y1], i) => {
            const [x2, y2] = rim[(i + 1) % n];
            const sh = document.createElement('div');
            sh.className = 'nm-shard';
            sh.style.clipPath = 'polygon(' + cx + 'px ' + cy + 'px, ' + x1 + 'px ' + y1 + 'px, ' + x2 + 'px ' + y2 + 'px)';
            sh.style.setProperty('--dx', (((x1 + x2) / 2 - cx) * 0.12) + 'px');
            sh.style.setProperty('--rot', ((Math.random() * 2 - 1) * 40) + 'deg');
            sh.style.setProperty('--sd', (0.5 + i * 0.07 + Math.random() * 0.1) + 's');
            stage.append(sh);
        });
        setTimeout(done, 1900);
    }

    // Dialogs the page asks to show at once (the two-step reminder after a login).
    $$('dialog[data-open]').forEach(d => d.showModal());

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

        // --- Under each block's heading, labels that jump to its parts (fieldsets, h3, folded areas) ---
        // The theme swatches and the sidebar's repeated rows are not parts of their own; folded blocks show their parts once opened.
        $$('[data-settings-panel] > .card').forEach((card, c) => {
            const head = $(':scope > h2', card);
            if (!head) return;
            const parts = $$('h3, fieldset > legend, details > summary', card).filter(el => el !== head
                && !el.closest('.log-theme-group, .log-sidebar-list') && !el.parentElement.parentElement.closest('fieldset'));
            if (!parts.length) return;
            const nav = document.createElement('nav');
            nav.className = 'settings-parts'; nav.setAttribute('aria-label', t('{section}の項目', { section: head.textContent.trim() }));
            parts.forEach((el, i) => {
                const target = el.tagName === 'H3' ? el : el.parentElement;
                if (!target.id) target.id = (card.id || 'settings-card-' + (c + 1)) + '-part-' + (i + 1);
                const a = document.createElement('a');
                a.href = '#' + target.id; a.textContent = el.textContent.trim();
                a.addEventListener('click', e => {
                    if (e.ctrlKey || e.metaKey || e.shiftKey) return;
                    e.preventDefault();
                    for (let d = target.parentElement.closest('details'); d && card.contains(d); d = d.parentElement.closest('details')) d.open = true;
                    if (target.tagName === 'DETAILS') target.open = true;
                    history.replaceState(null, '', '#' + target.id);
                    target.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start'});
                });
                nav.append(a);
            });
            head.after(nav);
        });

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
            // Ways out without scrolling back up: the LOG admin and the public page (a new tab, so edits stay here).
            const exits = () => {
                const box = document.createElement('div');
                box.className = 'settings-toc-exits';
                box.innerHTML = '<a class="settings-toc-exit" href="index.php?p=log">' + icon('back') + '<span>' + t('管理画面に戻る') + '</span></a>'
                    + '<a class="settings-toc-exit" href="../" target="_blank" rel="noopener">' + icon('globe') + '<span>' + t('公開ページを見る') + '</span></a>';
                return box;
            };
            toc.append(exits(), list()); toc.hidden = false;

            // Phones: a button floating at the bottom right opens the same list in a dialog.
            const fab = document.createElement('button');
            fab.type = 'button'; fab.className = 'settings-toc-fab'; fab.setAttribute('aria-haspopup', 'dialog');
            fab.innerHTML = icon('list-dots') + '<span>' + t('目次') + '</span>';
            const dialog = document.createElement('dialog');
            dialog.className = 'settings-toc-dialog'; dialog.setAttribute('aria-labelledby', 'settings-toc-title');
            dialog.innerHTML = '<div class="settings-toc-dialog-head"><h2 id="settings-toc-title">' + t('設定の目次') + '</h2><button type="button" class="btn" data-toc-close>' + t('閉じる') + '</button></div>';
            dialog.append(exits(), list());
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

        // --- One save button for every tab; a question before leaving with unsaved changes ---
        // After the deferred scripts, so the sidebar editor (log-settings.js) is ready.
        const settingsForm = $('[data-settings-form]');
        if (settingsForm) document.addEventListener('DOMContentLoaded', () => settingsSave(settingsForm));
        function settingsSave(form) {
            const sections = $$('[data-settings-section]', form);
            const status = $('[data-settings-status]', form);
            const button = $('[data-settings-save]', form);
            const idle = status.textContent;
            const name = section => $(':scope > h2, :scope > details > summary', section.closest('.card'))?.textContent.trim() || '';
            // A block's current values; the sidebar and the top menu have no named fields and give their JSON.
            const items = section => section.nlItems || section.nlSidebarItems;
            const snap = section => (items(section) ? items(section)() : '') + JSON.stringify($$('input, select, textarea', section).filter(c => c.name && c.form === form && c.type !== 'hidden')
                    .map(c => c.type === 'checkbox' || c.type === 'radio' ? c.checked : c.type === 'file' ? Array.from(c.files, f => f.name + ':' + f.size).join('|') : c.value));
            const saved = new Map(sections.map(s => [s, snap(s)]));
            let changed = [], busy = false, leaving = false, armed = false, reloadTo = '', analyticsStrikes = 0;
            const say = (text, error = false) => { if (status.textContent !== text) status.textContent = text; status.classList.toggle('is-error', error); };
            // The back gesture (or button) first returns to this extra entry, so the page can ask before leaving.
            const arm = () => { history.pushState({settingsGuard: true}, '', location.href); armed = true; };
            const update = () => {
                changed = sections.filter(s => snap(s) !== saved.get(s));
                sections.forEach(s => s.closest('.card').classList.toggle('is-changed', changed.includes(s)));
                $$('[data-toc-target]').forEach(a => a.classList.toggle('is-changed', changed.some(s => s.closest('.card').id === a.dataset.tocTarget)));
                form.classList.toggle('has-changes', changed.length > 0);
                if (!busy) say(changed.length ? t('保存していない変更：{list}', { list: changed.map(name).join(t('、')) }) : idle);
                if (changed.length && !armed) arm();
            };
            ['input', 'change', 'settings:change'].forEach(type => form.addEventListener(type, update));
            // Show a block on any tab: select its tab and open the <details> around it.
            const reveal = el => {
                const panel = el.closest('[data-settings-panel]');
                const tab = panel && tabs.find(t => t.dataset.settingsTab === panel.dataset.settingsPanel);
                if (tab && tab.getAttribute('aria-selected') !== 'true') tab.click();
                for (let d = el.closest('details'); d; d = d.parentElement.closest('details')) d.open = true;
            };
            // true: saved, false: not saved (the message says why), null: nothing to save.
            async function save() {
                if (busy) return false;
                update();
                if (!changed.length) { say(t('変更はありません。')); return null; }
                const invalid = changed.flatMap(s => $$('input, select, textarea', s)).find(c => c.form === form && !c.disabled && !c.checkValidity());
                if (invalid) {
                    reveal(invalid); invalid.reportValidity();
                    say(t('「{section}」の入力を確認してください。どの設定もまだ保存していません。', { section: name(invalid.closest('[data-settings-section]')) }), true);
                    return false;
                }
                const data = new FormData(form);
                data.delete('sections[]');
                changed.forEach(s => data.append('sections[]', s.dataset.settingsSection));
                changed.filter(items).forEach(s => {
                    data.set(s.dataset.itemsField || 'sidebar_items', items(s)());
                    data.set(s.dataset.revisionField || 'sidebar_revision', s.dataset.revision);
                });
                busy = true; form.inert = true; button.disabled = true; say(t('保存しています…'));
                let rejected = null;
                try {
                    const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                    let result;
                    try { result = await response.json(); }
                    catch { throw new Error(t('保存できませんでした。ログインが切れた可能性があります。入力した内容はこの画面に残っています。')); }
                    if (!response.ok || !result.ok) {
                        rejected = result.section;
                        const section = sections.find(s => s.dataset.settingsSection === result.section);
                        if (section) { reveal(section); section.closest('.card').scrollIntoView({ block: 'start' }); }
                        throw new Error(t('{message}。入力した内容はこの画面に残っています。', { message: result.error || t('保存できませんでした') }));
                    }
                    sections.forEach(s => saved.set(s, snap(s))); changed = []; analyticsStrikes = 0;
                    return true;
                } catch (e) {
                    const message = e.message || t('通信できませんでした。入力した内容はこの画面に残っています。');
                    say(message, true);
                    // The analytics block refused a second, third… time: the warning grows each time. Anything else is one toast.
                    if (rejected === 'analytics') escalate(++analyticsStrikes, message);
                    else toast(message);
                    return false;
                } finally { busy = false; form.inert = false; button.disabled = false; }
            }
            // Saved: reload so every block shows what the server kept (and the message), without a leftover history entry.
            const reload = () => {
                leaving = true; say(t('保存しました。画面を読み込み直しています…'));
                // The extra entry still has the tab chosen after it was made; open that tab again.
                if (armed) { reloadTo = location.href; history.back(); } else location.reload();
            };
            form.addEventListener('submit', async e => { e.preventDefault(); if (await save()) reload(); });

            const dialog = document.createElement('dialog');
            dialog.className = 'settings-leave-dialog'; dialog.setAttribute('aria-labelledby', 'settings-leave-title');
            dialog.innerHTML = '<h2 id="settings-leave-title">' + t('保存していない変更があります') + '</h2><p>' + t('このまま移動すると、次の設定の変更が消えます。') + '</p><ul data-leave-list></ul>'
                + '<div class="settings-leave-actions"><button type="button" class="btn" data-leave="stay">' + t('編集に戻る') + '</button><button type="button" class="btn danger" data-leave="discard">' + t('保存せずに移動') + '</button><button type="button" class="btn primary" data-leave="save">' + t('保存して移動') + '</button></div>';
            document.body.append(dialog);
            let pending = null;
            const ask = (go, stay = () => {}) => {
                $('[data-leave-list]', dialog).replaceChildren(...changed.map(s => { const li = document.createElement('li'); li.textContent = name(s); return li; }));
                pending = { go, stay };
                dialog.showModal(); $('[data-leave="stay"]', dialog).focus();
            };
            dialog.addEventListener('click', async e => {
                const choice = e.target.closest('[data-leave]')?.dataset.leave;
                if (!choice || !pending) return;
                const { go, stay } = pending;
                let ok = choice === 'discard';
                if (choice === 'save') {
                    $$('button', dialog).forEach(b => { b.disabled = true; });
                    ok = await save() !== false;
                    $$('button', dialog).forEach(b => { b.disabled = false; });
                }
                pending = null; dialog.close();
                if (!ok) { stay(); return; }
                leaving = true; go();
            });
            dialog.addEventListener('cancel', e => { e.preventDefault(); $('[data-leave="stay"]', dialog).click(); });

            // Links to other pages, other forms (logout, password), closing or reloading the tab, and going back.
            document.addEventListener('click', e => {
                if (e.defaultPrevented || !changed.length || leaving || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
                const a = e.target.closest('a[href]');
                if (!a || a.target === '_blank' || a.hasAttribute('download') || a.getAttribute('href').startsWith('#')) return;
                e.preventDefault();
                ask(() => { location.href = a.href; });
            });
            document.addEventListener('submit', e => {
                const other = e.target;
                if (other === form || e.defaultPrevented || !changed.length || leaving) return;
                e.preventDefault();
                ask(() => other.submit());
            });
            window.addEventListener('beforeunload', e => { if (changed.length && !leaving) { e.preventDefault(); e.returnValue = ''; } });
            window.addEventListener('popstate', () => {
                if (!armed) return;
                armed = false;
                if (reloadTo) { history.replaceState(null, '', reloadTo); location.reload(); return; }
                if (leaving) return;
                if (changed.length) ask(() => history.back(), arm);
                else history.back();
            });
        }
    }
    $$('.nav-more').forEach(menu => {
        document.addEventListener('click', e => { if (!menu.contains(e.target)) menu.open = false; });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && menu.open) { menu.open = false; $('summary', menu).focus(); } });
    });

    // --- Verification code boxes: half-width digits only -------------------------
    // A Japanese keyboard types full-width digits (０-９): turn them into 0-9 and drop anything else, up to 6.
    // A letter or sign typed directly never gets in. On a PC the input method (日本語入力 on) would hold its text
    // unconverted until Enter: the box is cleaned while it composes, which ends the composition, so only
    // half-width digits ever show. Phones get the number pad and are cleaned once the word is done.
    const halfDigits = value => value.replace(/[０-９]/g, d => String.fromCharCode(d.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, '').slice(0, 6);
    const finePointer = window.matchMedia('(pointer: fine)');
    $$('[data-otp-digits]').forEach(box => {
        const clean = () => {
            const value = halfDigits(box.value);
            if (value === box.value) return;
            const at = halfDigits(box.value.slice(0, box.selectionStart ?? box.value.length)).length;
            box.value = value; box.setSelectionRange(at, at);
        };
        box.addEventListener('beforeinput', e => {
            if (!e.isComposing && e.inputType === 'insertText' && e.data?.length === 1 && !/[0-9０-９]/.test(e.data)) e.preventDefault();
        });
        box.addEventListener('input', e => { if (!e.isComposing || finePointer.matches) clean(); });
        box.addEventListener('compositionend', () => setTimeout(clean));
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
            btn.textContent = t('コピーしました');
            setTimeout(() => { btn.textContent = label; }, 1500);
        });
    });

    // --- Show / hide the current reader password ---------------------------
    $$('.js-pw-toggle').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = $('.js-pw', btn.parentNode);
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? t('隠す') : t('表示');
        });
    });

    // --- Confirm dangerous forms ------------------------------------------
    $$('form.js-confirm').forEach(form => {
        form.addEventListener('submit', e => {
            if (!window.confirm(form.dataset.confirm || t('よろしいですか？'))) e.preventDefault();
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
                status.textContent = t('アップロード中… {i} / {n}（{name}）', { i: i + 1, n: files.length, name: files[i].name });
                try {
                    const res = await post({ do: 'upload', id: drop.dataset.id, page: files[i] });
                    const data = await res.json().catch(() => ({ ok: false, error: t('エラー（{status}）', { status: res.status }) }));
                    if (!data.ok) errors.push(`${files[i].name}: ${data.error || t('エラー')}`);
                } catch (e) {
                    errors.push(`${files[i].name}: ${t('通信エラー')}`);
                }
                progress.value = i + 1;
            }
            busy = false;
            if (errors.length) {
                status.textContent = t('完了（{n} 件失敗）:', { n: errors.length }) + ' ' + errors.join(' / ');
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
                !window.confirm(t('画像は {files} 枚、ページは {pages} ページです。1 ページ目から順に {n} ページ分を割り当てますか？', { files: files.length, pages: pages.length, n }))) {
                return;
            }
            busyL = true;
            progressL.hidden = false;
            progressL.max = n;
            progressL.value = 0;
            const errors = [];
            for (let i = 0; i < n; i++) {
                statusL.textContent = t('アップロード中… {i} / {n}（{name} → {i} ページ目）', { i: i + 1, n, name: files[i].name });
                const fd = new FormData();
                fd.append('do', 'upload_light');
                fd.append('id', dropL.dataset.id);
                fd.append('f', pages[i]);
                fd.append('page', files[i]);
                fd.append('csrf', dropL.dataset.csrf);
                try {
                    const res = await fetch('index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
                    const data = await res.json().catch(() => ({ ok: false, error: t('エラー（{status}）', { status: res.status }) }));
                    if (!data.ok) errors.push(`${files[i].name}: ${data.error || t('エラー')}`);
                } catch (e) {
                    errors.push(`${files[i].name}: ${t('通信エラー')}`);
                }
                progressL.value = i + 1;
            }
            busyL = false;
            if (errors.length) {
                statusL.textContent = t('完了（{n} 件失敗）:', { n: errors.length }) + ' ' + errors.join(' / ');
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
                saveBtn.textContent = t('保存しました');
                setTimeout(() => location.reload(), 600);
            } catch (e) {
                saveBtn.disabled = false;
                window.alert(t('保存できませんでした。{message}', { message: e.message || '' }));
            }
        });
    }
})();

/*
 * LOG restore with a progress screen. The ZIP goes up once, then the server converts images
 * a few seconds at a time, so no request outlasts the host's time limit; the wave meter shows
 * the step, the share done and the time left. Without JavaScript the form restores in one request.
 */
(() => {
    'use strict';
    // Texts in the admin's language (#nm-i18n from the page); Japanese, the key, when there is none.
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const form = document.querySelector('[data-log-restore]');
    if (!form || !window.fetch || !window.FormData) return;
    const csrf = form.querySelector('[name="csrf"]').value;
    // Shares of the meter: sending the ZIP, then converting images; the rest is the swap.
    const UPLOAD = 25, IMAGES = 70;
    const dialog = document.createElement('dialog');
    dialog.className = 'log-restore-dialog'; dialog.setAttribute('aria-labelledby', 'log-restore-title');
    dialog.innerHTML = '<h2 id="log-restore-title">' + t('LOGを復元しています') + '</h2>'
        + '<ol class="log-restore-steps"><li data-step="upload">' + t('ZIPを送る') + '</li><li data-step="images">' + t('画像を復元') + '</li><li data-step="finish">' + t('投稿と設定を反映') + '</li></ol>'
        + '<div class="log-restore-meter" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-labelledby="log-restore-title"><div class="log-restore-fill"><span class="log-restore-wave is-back"></span><span class="log-restore-wave"></span></div></div>'
        + '<p class="log-restore-numbers"><strong data-restore-percent>0%</strong><span data-restore-eta></span></p>'
        + '<p class="log-restore-detail" data-restore-detail aria-live="polite"></p>'
        + '<p class="note" data-restore-note></p>'
        + '<div class="log-restore-actions"><button type="button" class="btn" data-restore-cancel>' + t('中止する') + '</button></div>';
    document.body.append(dialog);
    const $ = selector => dialog.querySelector(selector);
    const meter = $('.log-restore-meter'), fill = $('.log-restore-fill'), title = $('#log-restore-title'), note = $('[data-restore-note]'), cancelButton = $('[data-restore-cancel]');
    let running = false, stopped = false, xhr = null, token = '';

    const timeLeft = seconds => seconds < 60 ? t('残り約{n}秒', { n: Math.max(5, Math.ceil(seconds / 5) * 5) }) : t('残り約{n}分', { n: Math.ceil(seconds / 60) });
    const show = (percent, eta, detail) => {
        const value = Math.max(0, Math.min(100, Math.round(percent)));
        fill.style.width = `${value}%`;
        meter.setAttribute('aria-valuenow', String(value));
        $('[data-restore-percent]').textContent = `${value}%`;
        if (eta !== undefined) $('[data-restore-eta]').textContent = eta;
        if (detail !== undefined) $('[data-restore-detail]').textContent = detail;
    };
    const step = name => {
        let reached = false;
        dialog.querySelectorAll('[data-step]').forEach(li => {
            const current = li.dataset.step === name;
            if (current) reached = true;
            li.classList.toggle('is-current', current);
            li.classList.toggle('is-done', !reached);
            if (current) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
        });
    };
    const fail = (message, maybeDone) => {
        running = false; xhr = null;
        dialog.classList.add('is-error');
        title.textContent = t('復元できませんでした');
        $('[data-restore-eta]').textContent = '';
        $('[data-restore-detail]').textContent = message;
        if (maybeDone) note.innerHTML = t('<a href="index.php?p=log">LOGの一覧を開いて、復元されたか確認する</a>');
        else {
            note.textContent = t('LOGは書き換えていません。内容を確かめて、もう一度お試しください。');
            // Drop the uploaded ZIP and the converted images now rather than after a day.
            if (token) post({do: 'log_restore_cancel', token});
        }
        cancelButton.textContent = t('閉じる'); cancelButton.disabled = false; cancelButton.focus();
    };
    // A busy host answers with its own HTML page; tell that apart from the app's messages.
    const reason = (status, data) => data?.error || ([0, 502, 503, 504].includes(status)
        ? t('サーバーが混み合っていて応答がありませんでした。少し待ってから、もう一度お試しください。')
        : t('サーバーから予期しない応答がありました（{status}）。', { status }));
    const post = async fields => {
        const body = new FormData();
        body.set('csrf', csrf);
        Object.entries(fields).forEach(([key, value]) => body.set(key, value));
        try {
            const response = await fetch('index.php', {method: 'POST', body, credentials: 'same-origin', headers: {'X-NM-CSRF': csrf}});
            let data = null;
            try { data = await response.json(); } catch { data = null; }
            return {status: response.status, data};
        } catch { return {status: 0, data: null}; }
    };
    // XMLHttpRequest, because fetch cannot report how much of the ZIP has been sent.
    const upload = data => new Promise(resolve => {
        xhr = new XMLHttpRequest();
        const started = performance.now();
        const mb = n => (n / 1048576).toFixed(1);
        xhr.upload.addEventListener('progress', event => {
            if (!event.lengthComputable || !event.total) return;
            const rate = event.loaded / Math.max(.5, (performance.now() - started) / 1000);
            show(UPLOAD * event.loaded / event.total, event.loaded < event.total && rate ? t('送信 {eta}', { eta: timeLeft((event.total - event.loaded) / rate) }) : '',
                t('{sent} / {total} MB を送りました', { sent: mb(event.loaded), total: mb(event.total) }));
        });
        xhr.upload.addEventListener('load', () => show(UPLOAD, '', t('ZIPの中身に壊れたところがないか確認しています')));
        xhr.addEventListener('load', () => { let parsed = null; try { parsed = JSON.parse(xhr.responseText); } catch { parsed = null; } resolve({status: xhr.status, data: parsed}); });
        xhr.addEventListener('error', () => resolve({status: 0, data: null}));
        xhr.addEventListener('abort', () => resolve({status: 0, data: null}));
        xhr.open('POST', 'index.php');
        xhr.setRequestHeader('X-NM-CSRF', csrf);
        xhr.send(data);
    });

    const run = async () => {
        running = true; stopped = false; token = '';
        dialog.classList.remove('is-error');
        title.textContent = t('LOGを復元しています');
        note.textContent = t('終わるまで、この画面を閉じずにお待ちください。');
        cancelButton.textContent = t('中止する'); cancelButton.disabled = false; cancelButton.hidden = false;
        step('upload'); show(0, t('残り時間を計算しています'), t('ZIPを送っています'));
        dialog.showModal();

        const data = new FormData(form);
        data.set('do', 'log_restore_begin');
        const begun = await upload(data);
        xhr = null;
        if (stopped) return;
        if (!begun.data?.ok) return fail(reason(begun.status, begun.data));
        token = begun.data.token;
        const total = begun.data.total;

        step('images');
        const started = performance.now();
        let done = 0, retries = 0;
        show(UPLOAD, total ? t('残り時間を計算しています') : '', total ? t('画像 {done} / {total} 枚', { done: 0, total }) : t('復元する画像はありません'));
        while (done < total) {
            const r = await post({do: 'log_restore_step', token});
            if (stopped) return;
            if (!r.data?.ok) {
                // The job survives a busy host or a dropped line: wait, then ask again.
                if (!r.data && retries < 3) {
                    retries++;
                    $('[data-restore-eta]').textContent = t('サーバーの応答を待っています（{n}/3）', { n: retries });
                    await new Promise(resolve => setTimeout(resolve, 3000 * retries));
                    if (stopped) return;
                    continue;
                }
                return fail(reason(r.status, r.data));
            }
            retries = 0; done = r.data.done;
            const seconds = (performance.now() - started) / 1000;
            show(UPLOAD + IMAGES * done / total, done < total ? timeLeft(seconds / done * (total - done) + 2) : t('まもなく完了します'), t('画像 {done} / {total} 枚', { done, total }));
        }

        step('finish');
        cancelButton.disabled = true;
        show(UPLOAD + IMAGES, t('まもなく完了します'), t('投稿 {n}件と設定を書き込んでいます', { n: begun.data.posts }));
        const r = await post({do: 'log_restore_finish', token});
        // Without a reply, the swap may still have finished on the server.
        if (!r.data?.ok) return fail(r.data ? reason(r.status, r.data) : reason(r.status, null) + ' ' + t('復元は終わっている場合があります。'), !r.data);
        running = false;
        step('');
        title.textContent = t('復元が完了しました');
        note.textContent = ''; cancelButton.hidden = true;
        show(100, '', t('投稿 {posts}件・画像 {media}件を復元しました。LOGの一覧へ移動します。', { posts: r.data.posts, media: r.data.media }));
        setTimeout(() => { location.href = r.data.redirect; }, 1200);
    };

    form.addEventListener('submit', event => {
        event.preventDefault();
        if (!running) run();
    });
    cancelButton.addEventListener('click', () => {
        if (running) {
            running = false; stopped = true;
            if (xhr) xhr.abort();
            if (token) post({do: 'log_restore_cancel', token});
        }
        dialog.close();
    });
    // Escape must not hide a restore that is still running.
    dialog.addEventListener('cancel', event => { if (running) event.preventDefault(); });
    window.addEventListener('beforeunload', event => { if (running) { event.preventDefault(); event.returnValue = ''; } });
})();
