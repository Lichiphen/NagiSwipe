/*
 * WCAG 2.2 AA contrast checks run inside the page by contrast_audit.py.
 * mode "page":  every visible text (4.5:1, large text 3:1), placeholder, form field boundary (3:1) and icon-only control (3:1).
 * mode "focus": the focused element shows an outline or ring of 3:1 against what is behind it.
 * Colours are read from the computed style and composited over every ancestor's background.
 * Text over a picture or gradient is measured against the colours behind it, assuming white where the picture is.
 */
mode => {
  const parse = s => {
    let m = s && s.match(/rgba?\(([^)]+)\)/);
    if (m) { const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number); return [p[0], p[1], p[2], p[3] ?? 1]; }
    m = s && s.match(/color\(srgb ([^)]+)\)/);
    if (m) { const p = m[1].split(/[ /]+/).filter(Boolean).map(Number); return [p[0] * 255, p[1] * 255, p[2] * 255, p[3] ?? 1]; }
    return null;
  };
  const over = (top, under) => { const a = top[3]; return [top[0] * a + under[0] * (1 - a), top[1] * a + under[1] * (1 - a), top[2] * a + under[2] * (1 - a), 1]; };
  const lum = c => { const f = v => { v /= 255; return v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const bgOf = el => {
    const layers = []; let image = false;
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const cs = getComputedStyle(n);
      if (cs.backgroundImage !== 'none') image = true;
      const c = parse(cs.backgroundColor);
      if (c && c[3] > 0) { layers.push(c); if (c[3] >= 1) break; }
    }
    let out = [255, 255, 255, 1];
    if (!layers.length || layers[layers.length - 1][3] < 1) { const root = parse(getComputedStyle(document.documentElement).backgroundColor); if (root && root[3] > 0) out = over(root, out); }
    for (let i = layers.length - 1; i >= 0; i--) out = over(layers[i], out);
    return { bg: out, image };
  };
  const desc = el => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (typeof el.className === 'string' && el.className.trim() ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');

  if (mode === 'focus') {
    const el = document.activeElement;
    if (!el || el === document.body || el === document.documentElement) return null;
    const cs = getComputedStyle(el);
    const bg = bgOf(el.parentElement || el).bg;
    const name = desc(el) + ' ' + (el.textContent || el.value || el.getAttribute('aria-label') || '').trim().slice(0, 16);
    if (cs.outlineStyle === 'auto') return 'ok';
    if (cs.outlineStyle !== 'none' && parseFloat(cs.outlineWidth) >= 1) { const c = parse(cs.outlineColor); const r = c ? ratio(over(c, bg), bg) : 0; return r >= 3 ? 'ok' : `FOCUS outline ${r.toFixed(2)}<3 ${name}`; }
    if (cs.boxShadow !== 'none') { const c = parse(cs.boxShadow); const r = c ? ratio(over(c, bg), bg) : 0; return r >= 3 ? 'ok' : `FOCUS ring ${r.toFixed(2)}<3 ${name}`; }
    return `FOCUS none ${name}`;
  }

  const visible = el => { const r = el.getBoundingClientRect(); if (!r.width || !r.height) return false; for (let n = el; n; n = n.parentElement) { const cs = getComputedStyle(n); if (cs.display === 'none' || cs.visibility === 'hidden' || Number(cs.opacity) === 0) return false; } return true; };
  const opacity = el => { let o = 1; for (let n = el; n; n = n.parentElement) o *= Number(getComputedStyle(n).opacity); return o; };
  const issues = new Set();
  document.querySelectorAll('body *').forEach(el => {
    if (['SCRIPT', 'STYLE', 'TEMPLATE', 'NOSCRIPT', 'OPTION'].includes(el.tagName)) return;
    const field = ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName);
    const text = Array.from(el.childNodes).some(n => n.nodeType === 3 && n.textContent.trim()) || (field && el.value && el.type !== 'hidden' && !['checkbox', 'radio', 'file', 'color', 'range'].includes(el.type));
    if (!visible(el) || el.disabled || el.closest('[aria-disabled="true"],:disabled')) return;
    const cs = getComputedStyle(el);
    const { bg, image } = bgOf(el);
    if (text) {
      let fg = parse(cs.color); if (!fg) return;
      fg = over([...fg.slice(0, 3), fg[3] * opacity(el)], bg);
      const size = parseFloat(cs.fontSize), bold = Number(cs.fontWeight) >= 700;
      const need = size >= 24 || (bold && size >= 18.66) ? 3 : 4.5;
      const r = ratio(fg, bg);
      const words = Array.from(el.childNodes).filter(n => n.nodeType === 3).map(n => n.textContent.trim()).join(' ') || el.value || '';
      if (r < need) issues.add(`${image ? 'TEXT(on picture)' : 'TEXT'} ${r.toFixed(2)}<${need} ${desc(el)} "${words.slice(0, 24)}"`);
    }
    if (['INPUT', 'TEXTAREA'].includes(el.tagName) && el.placeholder && !el.value) {
      const pc = parse(getComputedStyle(el, '::placeholder').color);
      if (pc) { const r = ratio(over(pc, bg), bg); if (r < 4.5) issues.add(`PLACEHOLDER ${r.toFixed(2)}<4.5 ${desc(el)}`); }
    }
    if (field && el.type !== 'hidden' && !el.readOnly) {
      const around = bgOf(el.parentElement).bg;
      const own = parse(cs.backgroundColor); const fill = own && own[3] > 0 ? over(own, around) : around;
      const bc = parse(cs.borderTopColor), bw = parseFloat(cs.borderTopWidth);
      const border = bc && bw > 0 && cs.borderTopStyle !== 'none' ? over(bc, around) : null;
      const native = cs.appearance !== 'none' && ['checkbox', 'radio'].includes(el.type);
      const best = Math.max(border ? Math.min(ratio(border, around), ratio(border, fill)) : 0, ratio(fill, around));
      if (!native && best < 3) issues.add(`FIELD ${best.toFixed(2)}<3 ${desc(el)} ${el.type || ''}`);
    }
    if (el.tagName === 'svg') {
      const control = el.closest('button,a');
      // Icon-only controls: the picture is all there is to see.
      if (control && !control.textContent.trim()) {
        const st = parse(cs.stroke) || parse(cs.fill) || parse(cs.color);
        if (st && st[3] > 0 && ratio(over(st, bg), bg) < 3) issues.add(`ICON ${ratio(over(st, bg), bg).toFixed(2)}<3 ${desc(control)}`);
      }
    }
  });
  return [...issues];
}
