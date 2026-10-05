/* LOG related posts in random order: the page holds a pool, this shows six of them. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const list = document.querySelector('[data-related-random] .log-related-list');
    if (!list) return;
    const items = [...list.children];
    for (let i = items.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [items[i], items[j]] = [items[j], items[i]];
    }
    items.forEach((item, i) => { item.hidden = i >= 6; list.append(item); });
})();
