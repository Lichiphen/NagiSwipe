/*
 * NagiScope screens: the visitors graph (drawn from the left when a tab is chosen), the counting-up number,
 * the IP dialog with the privacy policy samples, and the goal form. No libraries; the graph is plain SVG.
 * MIT License (c) 2026 Lichiphen.
 */
(function () {
    'use strict';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const still = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const NS = 'http://www.w3.org/2000/svg';
    const svgEl = (name, attrs = {}) => { const e = document.createElementNS(NS, name); for (const k in attrs) e.setAttribute(k, attrs[k]); return e; };
    const fmt = n => Number(n).toLocaleString();

    // --- Dashboard ------------------------------------------------------------------------------------------
    const dataEl = $('#ns-data');
    if (dataEl) {
        let data = {};
        try { data = JSON.parse(dataEl.textContent || '{}'); } catch { /* empty */ }
        const chart = $('[data-ns-chart]');
        const big = $('[data-ns-big]');
        let counter = 0;
        const countUp = to => {
            cancelAnimationFrame(counter);
            if (still || to === 0) { big.textContent = fmt(to); return; }
            const start = performance.now(), dur = 900;
            const step = now => {
                const p = Math.min(1, (now - start) / dur);
                big.textContent = fmt(Math.round(to * (1 - Math.pow(1 - p, 3))));
                if (p < 1) counter = requestAnimationFrame(step);
            };
            counter = requestAnimationFrame(step);
        };
        // Drawn at the box's own width, so the labels keep their size on a phone.
        const draw = (d, animate = true) => {
            const W = Math.max(280, Math.round(chart.clientWidth || 640)), H = W < 520 ? 190 : 230, L = 36, R = 10, T = 12, B = 28;
            const pts = d.points || [], cmp = d.compare || [];
            const n = Math.max(pts.length, 2);
            const max = Math.max(1, ...pts, ...cmp);
            const top = max <= 4 ? 4 : Math.ceil(max / 4) * 4;
            const x = i => L + (W - L - R) * i / (n - 1);
            const y = v => T + (H - T - B) * (1 - v / top);
            const svg = svgEl('svg', { viewBox: `0 0 ${W} ${H}`, class: 'ns-chart-svg', 'aria-hidden': 'true' });
            for (let g = 0; g <= 4; g++) {
                const v = top * g / 4, yy = y(v);
                svg.append(svgEl('line', { x1: L, x2: W - R, y1: yy, y2: yy, class: 'ns-gridline' }));
                const label = svgEl('text', { x: L - 6, y: yy, dy: '0.36em', class: 'ns-axis', 'text-anchor': 'end' });
                label.textContent = fmt(Math.round(v));
                svg.append(label);
            }
            const every = Math.ceil(n / Math.max(3, Math.floor(W / 95)));
            (d.labels || []).forEach((text, i) => {
                // Every few points, and the last one; a label too close to the last one is left out.
                if (i !== n - 1 && (i % every || n - 1 - i < every * 0.6)) return;
                const label = svgEl('text', { x: x(i), y: H - 8, class: 'ns-axis', 'text-anchor': i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle') });
                label.textContent = text;
                svg.append(label);
            });
            const path = list => list.map((v, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(v).toFixed(1)).join(' ');
            if (cmp.length) svg.append(svgEl('path', { d: path(cmp), class: 'ns-line-before' }));
            if (pts.length) {
                svg.append(svgEl('path', { d: path(pts) + ` L${x(pts.length - 1).toFixed(1)} ${y(0)} L${x(0)} ${y(0)} Z`, class: 'ns-area' }));
                const line = svgEl('path', { d: path(pts), class: 'ns-line' });
                svg.append(line);
                pts.forEach((v, i) => {
                    const dot = svgEl('circle', { cx: x(i), cy: y(v), r: 9, class: 'ns-hit' });
                    const title = svgEl('title');
                    title.textContent = (d.labels[i] || '') + ' ' + t('{n} 人', { n: fmt(v) }) + (i < cmp.length ? ' (' + d.compareLabel + ' ' + fmt(cmp[i]) + ')' : '');
                    dot.append(title);
                    svg.append(dot);
                });
                chart.replaceChildren(svg);
                // The line grows from the left.
                if (!still && animate) {
                    const len = line.getTotalLength();
                    line.style.strokeDasharray = len;
                    line.style.strokeDashoffset = len;
                    const area = svg.querySelector('.ns-area');
                    area.style.opacity = '0';
                    line.getBoundingClientRect();
                    line.style.transition = 'stroke-dashoffset 1.1s cubic-bezier(.22,1,.36,1)';
                    area.style.transition = 'opacity .6s ease .6s';
                    line.style.strokeDashoffset = '0';
                    area.style.opacity = '1';
                }
            } else {
                chart.replaceChildren(svg);
            }
        };
        let current = 'month', resizeTimer = 0;
        addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => draw(data[current], false), 150); });
        const loading = {};
        const show = async tab => {
            const before = current;
            current = tab;
            if (!data[tab]) {
                // The 1-year tab is fetched only when chosen (it reads two years of days).
                $$('[data-ns-tab]').forEach(b => b.setAttribute('aria-selected', b.dataset.nsTab === tab ? 'true' : 'false'));
                try {
                    loading[tab] ??= fetch('index.php?p=scope&view=period&site=' + encodeURIComponent(dataEl.dataset.site || 'm') + '&tab=' + tab,
                        { credentials: 'same-origin', headers: { Accept: 'application/json' } }).then(r => { if (!r.ok) throw new Error(String(r.status)); return r.json(); });
                    const got = await loading[tab];
                    if (!data[tab]) {
                        data[tab] = got.data;
                        // The lists come from the server, built with every reader-sent text escaped (lib/scope-admin.php).
                        $$('[data-ns-period]').pop().insertAdjacentHTML('afterend', got.html);
                    }
                } catch (e) {
                    // Pressing the tab again tries again; meanwhile the tab shown before stays selected.
                    console.error('NagiScope:', e);
                    delete loading[tab];
                    if (current === tab) { current = before; $$('[data-ns-tab]').forEach(b => b.setAttribute('aria-selected', b.dataset.nsTab === before ? 'true' : 'false')); }
                    return;
                }
                if (current !== tab) return;
            }
            const d = data[tab];
            $$('[data-ns-tab]').forEach(b => b.setAttribute('aria-selected', b.dataset.nsTab === tab ? 'true' : 'false'));
            $$('[data-ns-period]').forEach(p => { p.hidden = p.dataset.nsPeriod !== tab; });
            $('[data-ns-label]').textContent = d.label;
            const vs = $('[data-ns-vs]');
            vs.textContent = d.vs; vs.dataset.dir = d.dir;
            $('[data-ns-compare-label]').textContent = d.compareLabel;
            $('[data-ns-stat="pages"]').textContent = fmt(d.pages);
            $('[data-ns-stat="rate"]').textContent = d.rate;
            $('[data-ns-stat="likes"]').textContent = fmt(d.likes);
            countUp(d.visitors);
            draw(d);
        };
        $$('[data-ns-tab]').forEach(b => b.addEventListener('click', () => show(b.dataset.nsTab)));
        // Arrow keys move between the tabs.
        $('.ns-periods')?.addEventListener('keydown', e => {
            if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
            const tabs = $$('[data-ns-tab]');
            const i = tabs.findIndex(b => b.getAttribute('aria-selected') === 'true');
            const next = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
            next.focus(); show(next.dataset.nsTab);
        });
        show('month');
    }

    // --- Keeping IP addresses: the dialog with the privacy policy samples -----------------------------------
    const dialog = $('[data-ns-dialog]');
    $$('[data-ns-ip-form]').forEach(form => {
        const picked = () => (form.querySelector('[data-ns-ip]:checked') || {}).value || 'none';
        const onBox = form.querySelector('input[name="on"]');
        const first = { ip: picked(), on: onBox ? onBox.checked : false };
        let confirmed = false;
        form.addEventListener('submit', e => {
            const ip = picked();
            const turningOn = !onBox || (onBox.checked && !first.on);
            if (confirmed || !dialog || ip === 'none' || (onBox && !onBox.checked) || (!turningOn && ip === first.ip)) return;
            e.preventDefault();
            $('[data-ns-sample-full]', dialog).hidden = ip !== 'full';
            $('[data-ns-sample-partial]', dialog).hidden = ip !== 'partial';
            dialog.showModal();
            $('[data-ns-confirm]', dialog).onclick = () => { confirmed = true; dialog.close(); form.requestSubmit ? form.requestSubmit() : form.submit(); };
            $('[data-ns-cancel]', dialog).onclick = () => dialog.close();
        });
    });

    // --- Forms that ask first ------------------------------------------------------------------------------
    $$('form[data-ns-confirm-text]').forEach(form => form.addEventListener('submit', e => {
        if (!confirm(form.dataset.nsConfirmText)) e.preventDefault();
    }));

    // --- Goal form: only the fields of the chosen kind -----------------------------------------------------
    const kind = $('[data-ns-kind]');
    if (kind) {
        const sync = () => $$('[data-ns-for]').forEach(el => {
            const on = el.dataset.nsFor.split(' ').includes(kind.value);
            el.hidden = !on;
            $$('select, input', el).forEach(f => { f.disabled = !on; });
        });
        kind.addEventListener('change', sync);
        sync();
    }
})();
