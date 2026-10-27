/*
 * Diagrams in posts and fixed pages, written in Mermaid's language (```mermaid in Markdown) and drawn here without any
 * library: flowcharts (graph / flowchart, TD・TB・BT・LR・RL) and sequence diagrams. Each diagram is an SVG fitted to the
 * column and colored by the LOG theme (classes in log.css, no inline styles). The 拡大 button opens it over the whole
 * screen to zoom and scroll, which helps most on a phone. Other kinds of diagrams, and mistakes, keep the source as text.
 * Diagrams added later ("もっと見る", the editors' previews) are drawn too.
 * MIT License (c) 2026 Lichiphen.
 */
(() => {
    'use strict';
    const NS = 'http://www.w3.org/2000/svg';
    // Icons come from viewer/icons/ui.svg (its address is on <html data-icons>).
    const icon = (id, cls) => '<svg class="' + cls + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><use href="' + document.documentElement.dataset.icons + '#' + id + '"/></svg>';
    const line = (d, cls) => '<svg class="' + cls + '" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' + d + '"/></svg>';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const el = (name, attrs, parent) => {
        const e = document.createElementNS(NS, name);
        for (const [k, v] of Object.entries(attrs || {})) e.setAttribute(k, String(v));
        parent?.append(e);
        return e;
    };
    const round = n => Math.round(n * 10) / 10;
    let count = 0;
    // A mistake in the source: its line number, for the note under the diagram.
    const mistake = n => Object.assign(new Error(t('{n}行目が読めません', { n })), { source: true });

    // --- Text: measured with the page's font, written as lines (Mermaid's <br> is a new line) ---
    const pen = document.createElement('canvas').getContext('2d');
    const LH = 20, FONT = 14, SMALL = 13;
    function measurer(box) {
        const family = getComputedStyle(box).fontFamily;
        return (text, size = FONT, weight = 500) => { pen.font = weight + ' ' + size + 'px ' + family; return pen.measureText(text).width; };
    }
    const ENTITIES = { quot: '"', amp: '&', lt: '<', gt: '>', nbsp: ' ', apos: "'" };
    function words(raw) {
        let s = String(raw ?? '').trim();
        if (/^"[\s\S]*"$/.test(s)) s = s.slice(1, -1);
        s = s.replace(/<br\s*\/?>/gi, '\n').replace(/<[^>]*>/g, '').replace(/#(\w+);/g, (m, n) => ENTITIES[n] ?? m)
            .replace(/\*\*(.+?)\*\*/g, '$1').replace(/`/g, '');
        return s.split('\n').map(l => l.trim());
    }
    const widest = (lines, measure, size, weight) => Math.max(0, ...lines.map(l => measure(l, size, weight)));
    function write(parent, lines, x, y, cls, anchor = 'middle') {
        const text = el('text', { class: cls, x: round(x), y: round(y - (lines.length - 1) * LH / 2), 'text-anchor': anchor }, parent);
        // Safari does not pass dominant-baseline on to the tspans, so the first line is lowered by hand
        lines.forEach((l, i) => { el('tspan', { x: round(x), dy: i ? LH : '0.36em' }, text).textContent = l || ' '; });
        return text;
    }
    // The statements of a diagram: comments (%%) and blank lines left out, each with its line number.
    function statements(source) {
        const out = [];
        source.split('\n').forEach((raw, i) => {
            const s = raw.replace(/%%.*$/, '').trim();
            if (s) out.push({ s, n: i + 1 });
        });
        return out;
    }

    // ======================= Flowcharts =======================
    // Node shapes in Mermaid's brackets, longer marks first.
    const SHAPES = [['(((', ')))', 'circle'], ['((', '))', 'circle'], ['([', '])', 'stadium'], ['[[', ']]', 'subroutine'], ['[(', ')]', 'cylinder'],
        ['[/', '/]', 'lean'], ['[\\', '\\]', 'lean'], ['[/', '\\]', 'trap'], ['[\\', '/]', 'trap'], ['{{', '}}', 'hexagon'], ['{', '}', 'diamond'],
        ['(', ')', 'round'], ['>', ']', 'flag'], ['[', ']', 'rect']];
    const ID = /^[\p{L}\p{N}_]+(?:-[\p{L}\p{N}_]+)*/u;
    // A link: --> --- ==> === -.-> -.- ~~~ <--> --o --x, then an optional |label|.
    const LINK = /^\s*(<)?(-{2,}|={2,}|-\.+-|~{3,})(?:(>)|([ox])(?=[\s|]|$))?\s*(?:\|([^|]*)\|)?\s*/;
    // -- text --> (and == text ==>, -. text .->) becomes -->|text|.
    const INLINE = /(<)?(--|==|-\.)\s+([^|>\n]+?)\s+(-{2,}>|-{3,}|={2,}>|={3,}|\.+->|\.+-)(?=\s|$|[\p{L}\p{N}_"])/gu;

    function parseFlow(list, head) {
        const dir = (head.match(/^(?:graph|flowchart)\s+(TD|TB|BT|LR|RL)\b/i)?.[1] || 'TD').toUpperCase().replace('TB', 'TD');
        const nodes = new Map(), edges = [];
        const node = (id, lines, shape) => {
            let n = nodes.get(id);
            if (!n) { n = { id, lines: [id], shape: 'rect', order: nodes.size }; nodes.set(id, n); }
            if (lines) { n.lines = lines; n.shape = shape; }
            return n;
        };
        for (const { s: raw, n: lineNo } of list) {
            if (/^(classDef|class|style|linkStyle|click|subgraph|end|direction|accTitle|accDescr)\b/.test(raw)) continue;
            for (let s of raw.split(/;(?=(?:[^"]*"[^"]*")*[^"]*$)/)) {
                s = s.replace(/:::[\w-]+/g, '').replace(/@\{[^}]*\}/g, '').trim()
                    .replace(INLINE, (m, back, from, text, to) => (back || '') + (from === '-.' ? '-.' + (to.endsWith('>') ? '->' : '-') : to) + '|' + text + '|');
                if (!s) continue;
                let at = 0;
                // One node, with its shape and words when they are written here.
                const one = () => {
                    const rest = s.slice(at), id = rest.match(ID)?.[0];
                    if (!id) throw mistake(lineNo);
                    at += id.length;
                    for (const [open, close, shape] of SHAPES) {
                        if (!s.startsWith(open, at)) continue;
                        const start = at + open.length, last = close.at(-1);
                        let quoted = false;
                        for (let i = start; i < s.length; i++) {
                            if (s[i] === '"') quoted = !quoted;
                            if (!quoted && s[i] === last && s.slice(i - close.length + 1, i + 1) === close && i - close.length + 1 >= start) {
                                at = i + 1;
                                return node(id, words(s.slice(start, i - close.length + 1)), shape);
                            }
                        }
                    }
                    return node(id);
                };
                // A group: A & B & C.
                const group = () => {
                    const g = [one()];
                    for (let m; (m = s.slice(at).match(/^\s*&\s*/)); ) { at += m[0].length; g.push(one()); }
                    return g;
                };
                at = s.match(/^\s*/)[0].length;
                let from = group();
                while (at < s.length) {
                    const m = s.slice(at).match(LINK);
                    if (!m || !m[0].trim()) throw mistake(lineNo);
                    at += m[0].length;
                    const to = group(), op = m[2];
                    const style = op[0] === '=' ? 'thick' : op[0] === '~' ? 'hidden' : op.includes('.') ? 'dotted' : 'solid';
                    const head = m[3] ? 'arrow' : m[4] === 'o' ? 'circle' : m[4] === 'x' ? 'cross' : 'none';
                    for (const a of from) for (const b of to) edges.push({ from: a.id, to: b.id, style, head, tail: m[1] ? 'arrow' : 'none', lines: m[5] != null && m[5].trim() !== '' ? words(m[5]) : null });
                    from = to;
                    at += s.slice(at).match(/^\s*/)[0].length;
                }
            }
        }
        if (!nodes.size) throw mistake(list[0]?.n || 1);
        return { dir, nodes: [...nodes.values()], edges };
    }

    // Each node's box for its words and shape (w, h: the drawn size).
    function sizeNode(n, measure) {
        const tw = widest(n.lines, measure), th = n.lines.length * LH;
        let w = tw + 32, h = th + 20;
        if (n.shape === 'diamond') { w = (tw + 16) * 1.9; h = (th + 10) * 1.9; }
        else if (n.shape === 'circle') w = h = Math.max(tw + 24, th + 24);
        else if (n.shape === 'stadium') w += h / 2;
        else if (n.shape === 'hexagon' || n.shape === 'lean' || n.shape === 'trap' || n.shape === 'flag') w += 28;
        else if (n.shape === 'subroutine') w += 16;
        else if (n.shape === 'cylinder') h += 14;
        n.w = Math.max(w, 48); n.h = Math.max(h, 40);
    }

    // Layered layout (ranks along the flow, then order and place within each rank). b = across the flow, d = along it.
    function layoutFlow(g, measure) {
        const across = g.dir === 'LR' || g.dir === 'RL';
        const N = new Map(g.nodes.map(n => [n.id, n]));
        g.nodes.forEach(n => { sizeNode(n, measure); n.bs = across ? n.h : n.w; n.ds = across ? n.w : n.h; });
        // Cycles: an edge back to a node still on the walk is laid out reversed.
        const state = new Map(), drawn = g.edges.filter(e => e.from !== e.to);
        const out = new Map(g.nodes.map(n => [n.id, []]));
        drawn.forEach(e => out.get(e.from).push(e));
        const walk = id => {
            state.set(id, 1);
            for (const e of out.get(id)) {
                if (state.get(e.to) === 1) e.back = true;
                else if (!state.get(e.to)) walk(e.to);
            }
            state.set(id, 2);
        };
        g.nodes.forEach(n => { if (!state.get(n.id)) walk(n.id); });
        const dag = drawn.map(e => e.back ? { e, u: e.to, v: e.from } : { e, u: e.from, v: e.to });
        // Ranks two apart, so a label or a bend fits between two ranks of nodes.
        const rank = new Map(g.nodes.map(n => [n.id, 0])), into = new Map(g.nodes.map(n => [n.id, 0]));
        dag.forEach(x => into.set(x.v, into.get(x.v) + 1));
        const queue = g.nodes.filter(n => !into.get(n.id)).map(n => n.id);
        while (queue.length) {
            const id = queue.shift();
            for (const x of dag) if (x.u === id) {
                rank.set(x.v, Math.max(rank.get(x.v), rank.get(id) + 2));
                into.set(x.v, into.get(x.v) - 1);
                if (!into.get(x.v)) queue.push(x.v);
            }
        }
        // Long edges pass through small hidden points, one per rank; a label rides on the first one.
        const items = g.nodes.map(n => ({ id: n.id, node: n, rank: rank.get(n.id), bs: n.bs, ds: n.ds, order: n.order }));
        const byId = new Map(items.map(i => [i.id, i]));
        const links = [];
        dag.forEach((x, k) => {
            const chain = [byId.get(x.u)];
            const r0 = rank.get(x.u), r1 = rank.get(x.v);
            for (let r = r0 + 1; r < r1; r++) {
                const label = r === r0 + 1 && x.e.lines;
                const lw = label ? widest(x.e.lines, measure, SMALL) + 14 : 0, lh = label ? x.e.lines.length * LH + 6 : 0;
                const dummy = { id: '\u0000' + k + ':' + r, rank: r, bs: label ? (across ? lh : lw) : 8, ds: label ? (across ? lw : lh) : 0, order: 1e6 + k, edge: x.e, label: !!label };
                items.push(dummy); byId.set(dummy.id, dummy); chain.push(dummy);
            }
            chain.push(byId.get(x.v));
            x.chain = chain;
            for (let i = 0; i + 1 < chain.length; i++) links.push([chain[i], chain[i + 1]]);
        });
        const maxRank = Math.max(0, ...items.map(i => i.rank));
        const layers = Array.from({ length: maxRank + 1 }, () => []);
        items.forEach(i => layers[i.rank].push(i));
        const up = new Map(items.map(i => [i, []])), down = new Map(items.map(i => [i, []]));
        links.forEach(([a, b]) => { down.get(a).push(b); up.get(b).push(a); });
        // Order: as written, then sweeps that move each item toward the middle of its neighbors.
        layers.forEach(l => l.sort((a, b) => a.order - b.order).forEach((it, i) => { it.pos = i; }));
        const sweep = (list, near) => list.forEach(layer => {
            layer.forEach(it => { const n = near.get(it); it.key = n.length ? n.reduce((s, o) => s + o.pos, 0) / n.length : it.pos; });
            layer.sort((a, b) => a.key - b.key || a.pos - b.pos).forEach((it, i) => { it.pos = i; });
        });
        for (let k = 0; k < 6; k++) { sweep(layers.slice(1), up); sweep(layers.slice(0, -1).reverse(), down); }
        // Places across: packed in order, then pulled toward neighbors, keeping gaps (pushed both ways and averaged).
        const gap = (a, b) => (a.bs + b.bs) / 2 + (a.node && b.node ? 30 : 14);
        layers.forEach(l => { let p = 0; l.forEach((it, i) => { if (i) p += gap(l[i - 1], it); it.b = p; }); });
        const place = (layer, near) => {
            if (!layer.length) return;
            const want = layer.map(it => { const n = near(it); return n.length ? n.reduce((s, o) => s + o.b, 0) / n.length : it.b; });
            const a = want.slice(), z = want.slice();
            for (let i = 1; i < layer.length; i++) a[i] = Math.max(a[i], a[i - 1] + gap(layer[i - 1], layer[i]));
            for (let i = layer.length - 2; i >= 0; i--) z[i] = Math.min(z[i], z[i + 1] - gap(layer[i], layer[i + 1]));
            layer.forEach((it, i) => { it.b = (a[i] + z[i]) / 2; });
        };
        for (let k = 0; k < 8; k++) {
            layers.forEach(l => place(l, it => [...up.get(it), ...down.get(it)]));
            [...layers].reverse().forEach(l => place(l, it => [...up.get(it), ...down.get(it)]));
        }
        // Along the flow: each rank as deep as its deepest item, half a gap per rank step.
        let d = 0;
        layers.forEach((l, r) => {
            const depth = Math.max(0, ...l.map(i => i.ds));
            if (r) d += 24;
            l.forEach(i => { i.d = d + depth / 2; });
            d += depth;
        });
        const minB = Math.min(...items.map(i => i.b - i.bs / 2)), total = d;
        const flip = g.dir === 'BT' || g.dir === 'RL';
        const map = (b, dd) => { const db = b - minB, ddd = flip ? total - dd : dd; return across ? [ddd, db] : [db, ddd]; };
        items.forEach(i => { [i.x, i.y] = map(i.b, i.d); });
        return { items, dag, map, across };
    }

    // Where a line from the node's center toward (x, y) leaves the node.
    function edgeOf(n, x, y) {
        const dx = x - n.x, dy = y - n.y, hw = n.w / 2, hh = n.h / 2;
        if (!dx && !dy) return [n.x, n.y];
        let k;
        if (n.shape === 'diamond') k = 1 / (Math.abs(dx) / hw + Math.abs(dy) / hh);
        else if (n.shape === 'circle') k = hw / Math.hypot(dx, dy);
        else k = Math.min(dx ? hw / Math.abs(dx) : Infinity, dy ? hh / Math.abs(dy) : Infinity);
        return [n.x + dx * k, n.y + dy * k];
    }
    function shape(parent, n) {
        const x = n.x - n.w / 2, y = n.y - n.h / 2, w = n.w, h = n.h, p = pts => el('polygon', { points: pts.map(q => q.map(round).join(',')).join(' ') }, parent);
        switch (n.shape) {
            case 'diamond': return p([[n.x, y], [x + w, n.y], [n.x, y + h], [x, n.y]]);
            case 'circle': return el('circle', { cx: round(n.x), cy: round(n.y), r: round(w / 2) }, parent);
            case 'hexagon': return p([[x + 14, y], [x + w - 14, y], [x + w, n.y], [x + w - 14, y + h], [x + 14, y + h], [x, n.y]]);
            case 'lean': return p([[x + 14, y], [x + w, y], [x + w - 14, y + h], [x, y + h]]);
            case 'trap': return p([[x + 14, y], [x + w - 14, y], [x + w, y + h], [x, y + h]]);
            case 'flag': return p([[x, y], [x + w, y], [x + w, y + h], [x, y + h], [x + 14, n.y]]);
            case 'cylinder': {
                const ry = 7;
                const body = el('path', { d: `M${round(x)},${round(y + ry)} a${round(w / 2)},${ry} 0 0 1 ${round(w)},0 v${round(h - 2 * ry)} a${round(w / 2)},${ry} 0 0 1 ${round(-w)},0 Z` }, parent);
                el('path', { class: 'log-dg-line', d: `M${round(x)},${round(y + ry)} a${round(w / 2)},${ry} 0 0 0 ${round(w)},0` }, parent);
                return body;
            }
            default: {
                const r = n.shape === 'round' ? 10 : n.shape === 'stadium' ? h / 2 : 5;
                const box = el('rect', { x: round(x), y: round(y), width: round(w), height: round(h), rx: round(r) }, parent);
                if (n.shape === 'subroutine') {
                    el('path', { class: 'log-dg-line', d: `M${round(x + 8)},${round(y)} v${round(h)} M${round(x + w - 8)},${round(y)} v${round(h)}` }, parent);
                }
                return box;
            }
        }
    }
    function markers(svg, id) {
        const defs = el('defs', {}, svg);
        const make = (name, build) => {
            const m = el('marker', { id: id + '-' + name, viewBox: '0 0 12 12', refX: 11, refY: 6, markerWidth: 9, markerHeight: 9, orient: 'auto-start-reverse', markerUnits: 'userSpaceOnUse' }, defs);
            build(m);
        };
        make('arrow', m => el('path', { class: 'log-dg-head', d: 'M1,1 L11,6 L1,11 Z' }, m));
        make('strong', m => el('path', { class: 'log-dg-head is-strong', d: 'M1,1 L11,6 L1,11 Z' }, m));
        make('open', m => el('path', { class: 'log-dg-head-open', d: 'M1,1 L11,6 L1,11' }, m));
        make('circle', m => el('circle', { class: 'log-dg-head-ring', cx: 6, cy: 6, r: 4.5 }, m));
        make('cross', m => el('path', { class: 'log-dg-head-open', d: 'M2,2 L10,10 M10,2 L2,10' }, m));
    }
    function drawFlow(g, measure) {
        const id = 'log-dg-' + (++count);
        const { items, dag, map, across } = layoutFlow(g, measure);
        const N = new Map(g.nodes.map(n => [n.id, n]));
        items.forEach(i => { if (i.node) { i.node.x = i.x; i.node.y = i.y; } });
        const svg = el('svg', { class: 'log-dg log-dg-flow', role: 'img' });
        markers(svg, id);
        const edgeLayer = el('g', { class: 'log-dg-edges' }, svg), nodeLayer = el('g', { class: 'log-dg-nodes' }, svg), labelLayer = el('g', { class: 'log-dg-labels' }, svg);
        const box = { x0: Infinity, y0: Infinity, x1: -Infinity, y1: -Infinity };
        const grow = (x, y, w = 0, h = 0) => { box.x0 = Math.min(box.x0, x - w / 2); box.y0 = Math.min(box.y0, y - h / 2); box.x1 = Math.max(box.x1, x + w / 2); box.y1 = Math.max(box.y1, y + h / 2); };
        // A thick line (==>) ends in an arrow of its own color.
        const head = (path, end, kind, style) => { if (kind !== 'none') path.setAttribute(end, `url(#${id}-${kind === 'arrow' && style === 'thick' ? 'strong' : kind})`); };
        dag.forEach(x => {
            const e = x.e;
            if (e.style === 'hidden') return;
            const chain = x.back ? [...x.chain].reverse() : x.chain;
            const pts = chain.map(c => [c.x, c.y]);
            const a = N.get(e.from), b = N.get(e.to);
            pts[0] = edgeOf(a, ...pts[1]); pts[pts.length - 1] = edgeOf(b, ...pts[pts.length - 2]);
            // Smooth bends: each step curves along the flow's direction.
            let d = `M${round(pts[0][0])},${round(pts[0][1])}`;
            for (let i = 0; i + 1 < pts.length; i++) {
                const [x0, y0] = pts[i], [x1, y1] = pts[i + 1];
                d += across ? ` C${round((x0 + x1) / 2)},${round(y0)} ${round((x0 + x1) / 2)},${round(y1)} ${round(x1)},${round(y1)}`
                    : ` C${round(x0)},${round((y0 + y1) / 2)} ${round(x1)},${round((y0 + y1) / 2)} ${round(x1)},${round(y1)}`;
            }
            const path = el('path', { class: 'log-dg-edge is-' + e.style, d }, edgeLayer);
            head(path, 'marker-end', e.head, e.style); head(path, 'marker-start', e.tail, e.style);
            pts.forEach(p => grow(p[0], p[1], 12, 12));
            const spot = chain.find(c => c.label);
            if (spot) {
                const w = widest(e.lines, measure, SMALL) + 12, h = e.lines.length * LH + 4;
                const lab = el('g', { class: 'log-dg-edge-label' }, labelLayer);
                el('rect', { x: round(spot.x - w / 2), y: round(spot.y - h / 2), width: round(w), height: round(h), rx: 4 }, lab);
                write(lab, e.lines, spot.x, spot.y, 'log-dg-text is-small');
                grow(spot.x, spot.y, w, h);
            }
        });
        // An edge to itself: a small loop on the node's side.
        g.edges.filter(e => e.from === e.to && e.style !== 'hidden').forEach(e => {
            const n = N.get(e.from), x = n.x + n.w / 2, y = n.y;
            const path = el('path', { class: 'log-dg-edge is-' + e.style, d: `M${round(x)},${round(y - 8)} C${round(x + 34)},${round(y - 30)} ${round(x + 34)},${round(y + 30)} ${round(x)},${round(y + 8)}` }, edgeLayer);
            head(path, 'marker-end', e.head, e.style);
            grow(x + 30, y, 12, 64);
            if (e.lines) { write(labelLayer, e.lines, x + 40, y, 'log-dg-text is-small', 'start'); grow(x + 40 + widest(e.lines, measure, SMALL), y, 8, e.lines.length * LH); }
        });
        g.nodes.forEach(n => {
            const gNode = el('g', { class: 'log-dg-node is-' + n.shape }, nodeLayer);
            shape(gNode, n);
            write(gNode, n.lines, n.x, n.y + (n.shape === 'cylinder' ? 4 : 0), 'log-dg-text');
            grow(n.x, n.y, n.w + 4, n.h + 4);
        });
        return frame(svg, box);
    }

    // ======================= Sequence diagrams =======================
    const MSG = /^(.+?)\s*(-->>|->>|-->|->|--x|-x|--\)|-\))\s*([+-]?)\s*([^:]+?)\s*(?::(.*))?$/;
    function parseSequence(list) {
        const people = [], who = new Map(), events = [];
        let numbered = false;
        const person = (id, lines, actor = false) => {
            id = id.trim();
            let p = who.get(id);
            if (!p) { p = { id, lines: [id], actor, i: people.length }; people.push(p); who.set(id, p); }
            if (lines) { p.lines = lines; p.actor = actor; }
            return p;
        };
        for (const { s: raw, n } of list) {
            const s = raw.replace(/^create\s+/, '');
            let m;
            if ((m = s.match(/^(participant|actor)\s+(.+?)(?:\s+as\s+(.+))?$/))) person(m[2], words(m[3] ?? m[2]), m[1] === 'actor');
            else if (/^(autonumber)\b/.test(s)) numbered = true;
            else if (/^(activate|deactivate|destroy|title|accTitle|accDescr|links?|properties|details)\b/.test(s)) continue;
            else if ((m = s.match(/^note\s+(left of|right of|over)\s+([^:]+?)\s*:\s*(.*)$/i))) {
                events.push({ type: 'note', where: m[1].toLowerCase().split(' ')[0], people: m[2].split(',').map(x => person(x)), lines: words(m[3]) });
            }
            else if ((m = s.match(/^(loop|alt|opt|par|critical|break|rect|box)\b\s*(.*)$/))) events.push({ type: 'start', kind: m[1], lines: m[1] === 'rect' || m[1] === 'box' ? [] : words(m[2]) });
            else if ((m = s.match(/^(else|and|option)\b\s*(.*)$/))) events.push({ type: 'else', lines: words(m[2]) });
            else if (/^end$/.test(s)) events.push({ type: 'end' });
            else if ((m = s.match(MSG))) {
                const from = person(m[1]), to = person(m[4]), op = m[2];
                events.push({ type: 'msg', from, to, lines: words(m[5] ?? ''), dashed: op.startsWith('--'), head: op.endsWith('>>') ? 'arrow' : op.endsWith('x') ? 'cross' : op.endsWith(')') ? 'open' : 'none' });
            }
            else throw mistake(n);
        }
        if (!people.length) throw mistake(list[0]?.n || 1);
        return { people, events, numbered };
    }
    function drawSequence(q, measure) {
        const id = 'log-dg-' + (++count);
        const { people, events } = q;
        people.forEach(p => { p.w = Math.max(96, widest(p.lines, measure) + 32); p.h = p.lines.length * LH + 22; });
        // Gaps between neighbors: wide enough for the boxes, then for every message and note that crosses them.
        const gaps = people.slice(1).map((p, i) => Math.max(120, (people[i].w + p.w) / 2 + 30));
        const need = (a, b, width) => {
            if (a === b) return;
            const [lo, hi] = a < b ? [a, b] : [b, a];
            const have = gaps.slice(lo, hi).reduce((s, g) => s + g, 0);
            if (have < width) for (let i = lo; i < hi; i++) gaps[i] += (width - have) / (hi - lo);
        };
        events.forEach(e => {
            if (e.type === 'msg') {
                const w = widest(e.lines, measure, SMALL) + (q.numbered ? 52 : 32);
                if (e.from === e.to) need(e.from.i, e.from.i + 1 < people.length ? e.from.i + 1 : e.from.i, w + 30);
                else need(e.from.i, e.to.i, w);
            } else if (e.type === 'note') {
                e.w = widest(e.lines, measure, SMALL) + 24;
                const [a, b] = [e.people[0].i, e.people.at(-1).i];
                if (e.where === 'right' && a + 1 < people.length) need(a, a + 1, e.w + 24);
                if (e.where === 'left' && a > 0) need(a - 1, a, e.w + 24);
                if (e.where === 'over' && a !== b) need(a, b, e.w - 40);
            }
        });
        const left = Math.max(people[0].w / 2, ...events.filter(e => e.type === 'note' && e.where === 'left' && e.people[0].i === 0).map(e => e.w + 16)) + 8;
        people.forEach((p, i) => { p.x = i ? people[i - 1].x + gaps[i - 1] : left; });
        const svg = el('svg', { class: 'log-dg log-dg-sequence', role: 'img' });
        markers(svg, id);
        const back = el('g', { class: 'log-dg-frames' }, svg), lives = el('g', { class: 'log-dg-lives' }, svg), front = el('g', {}, svg);
        const box = { x0: 0, y0: 0, x1: -Infinity, y1: -Infinity };
        const grow = (x0, x1, y) => { box.x0 = Math.min(box.x0, x0); box.x1 = Math.max(box.x1, x1); box.y1 = Math.max(box.y1, y); };
        const top = Math.max(...people.map(p => p.h));
        const actors = y => people.forEach(p => {
            const g = el('g', { class: 'log-dg-actor' + (p.actor ? ' is-actor' : '') }, front);
            el('rect', { x: round(p.x - p.w / 2), y: round(y + top - p.h), width: round(p.w), height: round(p.h), rx: p.actor ? 18 : 6 }, g);
            write(g, p.lines, p.x, y + top - p.h / 2, 'log-dg-text');
            grow(p.x - p.w / 2, p.x + p.w / 2, y + top);
        });
        actors(0);
        let y = top + 22, number = 0;
        const stack = [];
        const span = list => { const is = list.map(p => p.i); return is.length ? [Math.min(...is), Math.max(...is)] : [0, people.length - 1]; };
        events.forEach(e => {
            for (const f of stack) if (e.people || e.from) f.people.push(...(e.people || [e.from, e.to]));
            if (e.type === 'msg') {
                const a = e.from, b = e.to, labelH = e.lines.filter(Boolean).length * LH;
                const g = el('g', { class: 'log-dg-message' + (e.dashed ? ' is-dashed' : '') }, front);
                const mark = e.head === 'arrow' ? 'arrow' : e.head === 'cross' ? 'cross' : e.head === 'open' ? 'open' : null;
                let at;
                if (a === b) {
                    if (labelH) write(g, e.lines, a.x + 14, y + labelH / 2, 'log-dg-text is-small', 'start');
                    y += labelH + 4; at = y;
                    const path = el('path', { class: 'log-dg-line', d: `M${round(a.x)},${round(y)} h40 v28 h-38` }, g);
                    if (mark) path.setAttribute('marker-end', `url(#${id}-${mark})`);
                    grow(a.x, a.x + 54 + widest(e.lines, measure, SMALL), y);
                    y += 46;
                } else {
                    if (labelH) write(g, e.lines, (a.x + b.x) / 2, y + labelH / 2, 'log-dg-text is-small');
                    y += labelH + 6; at = y;
                    const dir = Math.sign(b.x - a.x);
                    const path = el('path', { class: 'log-dg-line', d: `M${round(a.x)},${round(y)} L${round(b.x - dir * 2)},${round(y)}` }, g);
                    if (mark) path.setAttribute('marker-end', `url(#${id}-${mark})`);
                    y += 26;
                }
                if (q.numbered) {
                    // The number sits where the message starts.
                    el('circle', { class: 'log-dg-number', cx: round(a.x), cy: round(at), r: 10 }, g);
                    write(g, [String(++number)], a.x, at, 'log-dg-text is-number');
                }
            } else if (e.type === 'note') {
                const [lo, hi] = span(e.people), h = e.lines.length * LH + 14;
                let x0, x1;
                if (e.where === 'right') { x0 = people[lo].x + 14; x1 = x0 + e.w; }
                else if (e.where === 'left') { x1 = people[lo].x - 14; x0 = x1 - e.w; }
                else { x0 = Math.min(people[lo].x - 40, (people[lo].x + people[hi].x - e.w) / 2); x1 = Math.max(people[hi].x + 40, x0 + e.w); }
                const g = el('g', { class: 'log-dg-note' }, front);
                el('rect', { x: round(x0), y: round(y), width: round(x1 - x0), height: round(h), rx: 4 }, g);
                write(g, e.lines, (x0 + x1) / 2, y + h / 2, 'log-dg-text is-small');
                grow(x0, x1, y + h);
                y += h + 16;
            } else if (e.type === 'start') {
                stack.push({ kind: e.kind, lines: e.lines, top: y, splits: [], people: [], depth: stack.length });
                y += e.kind === 'rect' || e.kind === 'box' ? 10 : 32;
            } else if (e.type === 'else' && stack.length) {
                stack.at(-1).splits.push({ y, lines: e.lines });
                y += 30;
            } else if (e.type === 'end' && stack.length) {
                const f = stack.pop();
                y += 6;
                const [lo, hi] = span(f.people), inset = f.depth * 8;
                const x0 = people[lo].x - Math.max(people[lo].w / 2, 50) - 6 + inset, x1 = people[hi].x + Math.max(people[hi].w / 2, 50) + 6 - inset;
                const g = el('g', { class: 'log-dg-frame is-' + f.kind }, back);
                el('rect', { x: round(x0), y: round(f.top), width: round(x1 - x0), height: round(y - f.top), rx: 6 }, g);
                if (f.kind !== 'rect' && f.kind !== 'box') {
                    const tw = measure(f.kind, 12, 700) + 18;
                    el('path', { class: 'log-dg-frame-tag', d: `M${round(x0)},${round(f.top)} h${round(tw)} v14 l-8,8 h${round(-tw + 8)} Z` }, g);
                    write(g, [f.kind], x0 + (tw - 4) / 2, f.top + 11, 'log-dg-text is-tag');
                    if (f.lines.length && f.lines.join('')) write(g, ['[' + f.lines.join(' ') + ']'], x0 + tw + 10, f.top + 12, 'log-dg-text is-small is-muted', 'start');
                    f.splits.forEach(sp => {
                        el('path', { class: 'log-dg-frame-split', d: `M${round(x0)},${round(sp.y)} H${round(x1)}` }, g);
                        if (sp.lines.join('')) write(g, ['[' + sp.lines.join(' ') + ']'], x0 + 12, sp.y + 14, 'log-dg-text is-small is-muted', 'start');
                    });
                }
                grow(x0, x1, y);
                y += 12;
                if (stack.length) stack.at(-1).people.push(...f.people);
            }
        });
        y += 6;
        people.forEach(p => el('path', { class: 'log-dg-life', d: `M${round(p.x)},${round(top)} V${round(y)}` }, lives));
        actors(y);
        return frame(svg, box);
    }

    // The finished SVG: a margin round the drawing, its own size as width (the column narrows it), a text alternative.
    function frame(svg, box) {
        const m = 12, x = box.x0 - m, y = box.y0 - m, w = box.x1 - box.x0 + m * 2, h = box.y1 - box.y0 + m * 2;
        svg.setAttribute('viewBox', [x, y, w, h].map(round).join(' '));
        svg.setAttribute('width', round(w));
        svg.setAttribute('height', round(h));
        return svg;
    }
    function render(source, box) {
        const list = statements(source);
        const head = list.shift();
        if (!head) throw mistake(1);
        const measure = measurer(box);
        if (/^(graph|flowchart)\b/i.test(head.s)) return drawFlow(parseFlow(list, head.s), measure);
        if (/^sequenceDiagram\b/.test(head.s)) return drawSequence(parseSequence(list), measure);
        throw Object.assign(new Error(head.s.split(/\s/)[0]), { unsupported: true });
    }

    function draw(box) {
        if (box.dataset.mermaidState) return;
        const source = box.querySelector('.log-mermaid-source')?.textContent ?? '';
        try {
            const svg = render(source, box);
            svg.setAttribute('aria-label', t('図'));
            const view = document.createElement('div');
            view.className = 'log-mermaid-view';
            view.append(svg);
            const zoom = document.createElement('button');
            zoom.type = 'button'; zoom.className = 'log-mermaid-zoom';
            zoom.setAttribute('aria-label', t('図を拡大して見る'));
            zoom.innerHTML = icon('p-full', 'log-mermaid-icon') + '<span>' + t('拡大') + '</span>';
            // The source stays (hidden): readers using a screen reader and copying the page still get it.
            box.querySelector('.log-mermaid-source').hidden = true;
            box.prepend(view, zoom);
            box.dataset.mermaidState = 'drawn';
        } catch (e) {
            box.dataset.mermaidState = 'error';
            const note = document.createElement('p');
            note.className = 'log-mermaid-error';
            // Not a fault of the page: the kind is not drawn here, or the source has a mistake. The source stays.
            note.textContent = e.unsupported ? t('この種類の図にはまだ対応していないため、元の文を表示しています。') : t('図の書き方に誤りがあるため、元の文を表示しています。');
            const why = document.createElement('small');
            why.textContent = e.unsupported ? t('描けるのはフローチャート（graph・flowchart）とシーケンス図（sequenceDiagram）です。') : e.source ? e.message : '';
            if (why.textContent) note.append(why);
            box.prepend(note);
        }
    }
    // Words are measured with the page's font, so drawing waits for the fonts.
    const scan = root => root.querySelectorAll?.('.log-mermaid').forEach(draw);
    (document.fonts?.ready ?? Promise.resolve()).then(() => {
        scan(document);
        new MutationObserver(records => records.forEach(r => r.addedNodes.forEach(node => {
            if (node.nodeType !== 1) return;
            if (node.matches('.log-mermaid')) draw(node); else scan(node);
        }))).observe(document.body, { childList: true, subtree: true });
    });

    // --- 拡大: the diagram over the whole screen at its own size, zoomed with the buttons, a pinch or Ctrl + wheel ---
    let sheet = null, stage = null, figure = null, level = null, natural = 0, scale = 1, opener = null;
    function build() {
        sheet = document.createElement('dialog');
        sheet.className = 'log-mermaid-sheet';
        sheet.setAttribute('aria-label', t('図'));
        sheet.innerHTML = '<div class="log-mermaid-sheet-bar"><div class="log-mermaid-sheet-tools">'
            + '<button type="button" data-zoom="out" aria-label="' + t('縮小（-）') + '">' + line('M6 12h12', 'log-mermaid-icon') + '</button>'
            + '<button type="button" data-zoom="fit" class="log-mermaid-level" aria-label="' + t('画面に合わせる') + '"></button>'
            + '<button type="button" data-zoom="in" aria-label="' + t('拡大（+）') + '">' + line('M6 12h12M12 6v12', 'log-mermaid-icon') + '</button></div>'
            + '<button type="button" class="log-mermaid-sheet-close" data-zoom-close>' + icon('close', 'log-mermaid-icon') + '<span>' + t('閉じる') + '</span></button></div>'
            + '<div class="log-mermaid-stage" tabindex="0"><div class="log-mermaid-figure"></div></div>';
        document.body.append(sheet);
        stage = sheet.querySelector('.log-mermaid-stage'); figure = sheet.querySelector('.log-mermaid-figure'); level = sheet.querySelector('.log-mermaid-level');
        sheet.addEventListener('click', e => {
            const b = e.target.closest('[data-zoom],[data-zoom-close]');
            if (!b) return;
            if (b.hasAttribute('data-zoom-close')) { sheet.close(); return; }
            setScale(b.dataset.zoom === 'fit' ? fitScale() : scale * (b.dataset.zoom === 'in' ? 1.25 : 0.8));
        });
        sheet.addEventListener('keydown', e => {
            if (e.key === '+' || e.key === '=') { e.preventDefault(); setScale(scale * 1.25); }
            else if (e.key === '-') { e.preventDefault(); setScale(scale * 0.8); }
            else if (e.key === '0') { e.preventDefault(); setScale(fitScale()); }
        });
        stage.addEventListener('wheel', e => { if (!e.ctrlKey) return; e.preventDefault(); setScale(scale * (e.deltaY < 0 ? 1.1 : 0.9)); }, { passive: false });
        sheet.addEventListener('close', () => {
            figure.replaceChildren();
            document.documentElement.classList.remove('log-mermaid-open');
            opener?.focus({ preventScroll: true });
        });
    }
    // The whole diagram on the screen (larger or smaller than its own size).
    function fitScale() {
        const box = figure.firstElementChild?.viewBox?.baseVal;
        const height = box?.width ? natural * box.height / box.width : natural;
        return Math.min((stage.clientWidth - 32) / natural, (stage.clientHeight - 32) / height);
    }
    function setScale(next) {
        scale = Math.min(4, Math.max(0.2, next));
        const svg = figure.firstElementChild;
        if (svg) svg.style.width = Math.round(natural * scale) + 'px';
        level.textContent = Math.round(scale * 100) + '%';
    }
    document.addEventListener('click', event => {
        const zoom = event.target.closest?.('.log-mermaid-zoom');
        const svg = zoom?.closest('.log-mermaid')?.querySelector('.log-mermaid-view svg');
        if (!svg) return;
        if (!sheet) build();
        opener = zoom;
        // A copy free of the column's limit; its markers get their own names.
        const copy = svg.cloneNode(true);
        const suffix = '-z';
        copy.querySelectorAll('marker[id]').forEach(m => { m.id += suffix; });
        copy.querySelectorAll('[marker-end],[marker-start]').forEach(p => ['marker-end', 'marker-start'].forEach(a => { const v = p.getAttribute(a); if (v) p.setAttribute(a, v.replace(/\)$/, suffix + ')')); }));
        natural = svg.viewBox.baseVal.width || svg.getBoundingClientRect().width;
        copy.removeAttribute('width'); copy.removeAttribute('height');
        figure.replaceChildren(copy);
        document.documentElement.classList.add('log-mermaid-open');
        sheet.showModal();
        // At its own size, where the words are easy to read; the percentage button fits it to the screen.
        setScale(1);
        stage.scrollTo(0, 0);
        stage.focus({ preventScroll: true });
    });
})();
