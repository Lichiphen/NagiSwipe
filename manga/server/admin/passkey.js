/*
 * NagiManga passkeys: adding one in the admin (p=passkeys) and logging in with one (login page). WebAuthn, no library.
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 */
(function () {
    'use strict';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);

    // Binary fields travel as base64url (no padding).
    const toBytes = s => {
        const bin = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - s.length % 4) % 4));
        const out = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
        return out;
    };
    const toText = buf => {
        const bytes = new Uint8Array(buf);
        let bin = '';
        for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
        return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    };

    const supported = () => !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
    async function send(url, fields) {
        const body = new FormData();
        Object.entries(fields).forEach(([k, v]) => body.append(k, v));
        const res = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        let data = null;
        try { data = await res.json(); } catch { /* 404 page */ }
        if (!res.ok || !data || data.ok === false) throw new Error(data && data.error ? data.error : t('パスキーを確かめられませんでした。ページを開き直して、もう一度試してください'));
        return data;
    }
    // What the browser said, in the admin's words (the person closing the dialog is not an error to dwell on).
    const browserError = e => e && e.name === 'NotAllowedError' ? t('パスキーの操作が取り消されたか、時間切れになりました')
        : e && e.name === 'InvalidStateError' ? t('このパスキーはもう登録されています')
        : e && e.name === 'SecurityError' ? t('このアドレスではパスキーを使えません。HTTPS のアドレスで開いてください')
        : (e && e.message) || t('パスキーを確かめられませんでした。ページを開き直して、もう一度試してください');
    const say = (box, text, ok = false) => { if (!box) return; box.textContent = text; box.className = 'flash ' + (ok ? 'flash-ok' : 'flash-err'); box.hidden = false; };

    // --- Adding a passkey (admin, p=passkeys) ---------------------------------------------
    const form = document.querySelector('[data-passkey-register]');
    if (form) {
        const status = form.querySelector('[data-passkey-status]');
        if (!supported()) {
            say(status, t('このブラウザ、またはこのアドレス（HTTPS ではない）では、パスキーを使えません'));
            form.querySelector('button').disabled = true;
        }
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const button = form.querySelector('button');
            button.disabled = true;
            try {
                const csrf = form.querySelector('[name="csrf"]').value;
                const name = form.querySelector('[name="name"]').value;
                const o = (await send('index.php', { csrf, do: 'passkey_options', current: form.querySelector('[name="current"]').value })).options;
                o.challenge = toBytes(o.challenge);
                o.user.id = toBytes(o.user.id);
                o.excludeCredentials = (o.excludeCredentials || []).map(c => ({ ...c, id: toBytes(c.id) }));
                let cred;
                try { cred = await navigator.credentials.create({ publicKey: o }); } catch (err) { throw new Error(browserError(err)); }
                const r = cred.response;
                const done = await send('index.php', { csrf, do: 'passkey_add', name, clientDataJSON: toText(r.clientDataJSON), attestationObject: toText(r.attestationObject) });
                location.href = done.redirect || 'index.php?p=passkeys';
            } catch (err) {
                say(status, err.message);
                button.disabled = false;
            }
        });
    }

    // --- Logging in with a passkey (login page) ---------------------------------------------
    const login = document.querySelector('[data-passkey-login]');
    if (login) {
        const status = document.querySelector('[data-passkey-login-status]');
        if (!supported()) { login.hidden = true; return; }
        login.hidden = false;
        login.addEventListener('click', async () => {
            login.disabled = true;
            const fields = { csrf: login.dataset.csrf };
            if (login.dataset.key) fields.k = login.dataset.key;
            try {
                const o = (await send(login.dataset.action, { ...fields, passkey: 'options' })).options;
                o.challenge = toBytes(o.challenge);
                o.allowCredentials = (o.allowCredentials || []).map(c => ({ ...c, id: toBytes(c.id) }));
                let cred;
                try { cred = await navigator.credentials.get({ publicKey: o }); } catch (err) { throw new Error(browserError(err)); }
                const r = cred.response;
                const done = await send(login.dataset.action, {
                    ...fields, passkey: 'verify', id: toText(cred.rawId), clientDataJSON: toText(r.clientDataJSON),
                    authenticatorData: toText(r.authenticatorData), signature: toText(r.signature), userHandle: r.userHandle ? toText(r.userHandle) : ''
                });
                location.href = done.redirect;
            } catch (err) {
                say(status, err.message);
                login.disabled = false;
            }
        });
    }
})();
