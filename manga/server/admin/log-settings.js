/* LOG theme and icon previews. MIT License (c) 2026 Lichiphen. */
(() => {
    'use strict';
    const form = document.querySelector('[data-log-settings]');
    if (!form) return;
    const themes = new Set(['light-blue', 'light-sage', 'light-paper', 'dark-navy', 'dark-charcoal', 'dark-plum']);
    form.querySelectorAll('[name="theme"]').forEach(input => input.addEventListener('change', () => {
        if (input.checked && themes.has(input.value)) document.documentElement.dataset.logTheme = input.value;
    }));
    [['icon', '.log-icon-preview', 'log-settings-avatar', '選んだアイコン'], ['og_image', '.log-og-preview', '', '選んだ紹介画像']].forEach(([name, selector, className, alt]) => {
        const file = form.querySelector(`[name="${name}"]`), preview = form.querySelector(selector);
        let url = '';
        file.addEventListener('change', () => {
            if (url) URL.revokeObjectURL(url);
            const image = file.files[0];
            if (!image) return;
            url = URL.createObjectURL(image);
            const img = document.createElement('img'); img.className = className; img.alt = alt; img.src = url;
            preview.replaceChildren(img);
        });
    });
})();
