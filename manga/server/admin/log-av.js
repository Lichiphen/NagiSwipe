/*
 * NagiLog: sending video and audio from the owner's browser. MIT License (c) 2026 Lichiphen.
 * The browser measures what the server cannot: length, size, the waveform (once, so readers never decode
 * the file), the cover picture inside MP3 / M4A / FLAC / Ogg / WAV files, a loop marked inside the song
 * (LOOPSTART tags, a WAV smpl chunk), and a video's poster frame. The file then
 * goes in pieces small enough for the host; a piece that fails is sent again.
 * Used by log-editor.js (the post editor) and log-pages.js (メディア一覧) as window.NagiLogAV.
 */
(() => {
    'use strict';
    const i18n = (() => { try { return JSON.parse(document.getElementById('nm-i18n')?.textContent || '{}'); } catch { return {}; } })();
    const t = (text, vars = {}) => (i18n[text] ?? text).replace(/\{(\w+)\}/g, (m, k) => k in vars ? String(vars[k]) : m);
    const PEAKS = 200;
    const AV_NAME = /\.(mp4|m4v|mov|webm|mp3|m4a|aac|ogg|oga|opus|wav|flac|weba)$/i;
    const accept = 'video/mp4,video/quicktime,video/webm,audio/mpeg,audio/mp4,audio/x-m4a,audio/aac,audio/ogg,audio/wav,audio/x-wav,audio/flac,audio/webm,.mp4,.m4v,.mov,.webm,.mp3,.m4a,.ogg,.oga,.opus,.wav,.flac';
    const isAV = file => /^(video|audio)\//.test(file.type || '') || AV_NAME.test(file.name || '');
    const isVideo = file => /^video\//.test(file.type || '') || /\.(mp4|m4v|mov|webm)$/i.test(file.name || '');
    const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

    /** Resolve on the event, or give up after a while (some files never report it). */
    const once = (target, type, ms) => new Promise((resolve, reject) => {
        const timer = setTimeout(() => { target.removeEventListener(type, ok); reject(new Error('timeout')); }, ms);
        const ok = () => { clearTimeout(timer); resolve(); };
        target.addEventListener(type, ok, { once: true });
        target.addEventListener('error', () => { clearTimeout(timer); reject(new Error('media')); }, { once: true });
    });

    async function lengthOf(file, tag) {
        const url = URL.createObjectURL(file);
        const media = document.createElement(tag); media.preload = 'metadata'; media.src = url;
        try { await once(media, 'loadedmetadata', 15000); return Number.isFinite(media.duration) ? media.duration : 0; }
        catch { return 0; }
        finally { URL.revokeObjectURL(url); }
    }

    /** Length and waveform. Very large files are not decoded (it needs memory several times their size). */
    async function measureAudio(file) {
        if (file.size > 150 * 1024 * 1024) return { duration: await lengthOf(file, 'audio'), peaks: [] };
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            const ctx = new Ctx();
            const buffer = await new Promise((resolve, reject) => file.arrayBuffer().then(data => {
                const p = ctx.decodeAudioData(data, resolve, reject);
                if (p && p.then) p.then(resolve, reject);
            }, reject));
            ctx.close?.();
            const channels = Array.from({ length: buffer.numberOfChannels }, (_, c) => buffer.getChannelData(c));
            const size = Math.max(1, Math.floor(buffer.length / PEAKS)), stride = Math.max(1, Math.floor(size / 600));
            const raw = [];
            for (let i = 0; i < PEAKS; i++) {
                let peak = 0;
                for (let j = i * size, end = Math.min(buffer.length, j + size); j < end; j += stride) for (const data of channels) { const v = Math.abs(data[j]); if (v > peak) peak = v; }
                raw.push(peak);
            }
            const max = Math.max(...raw, 0.0001);
            // A gentle curve keeps quiet passages visible next to loud ones.
            return { duration: buffer.duration, peaks: raw.map(v => Math.round(Math.pow(v / max, 0.75) * 100)) };
        } catch {
            return { duration: await lengthOf(file, 'audio'), peaks: [] };
        }
    }

    /** Length, size and a poster frame (about a second in). */
    async function measureVideo(file) {
        const url = URL.createObjectURL(file);
        const video = document.createElement('video'); video.muted = true; video.playsInline = true; video.preload = 'auto'; video.src = url;
        const out = { duration: 0, width: 0, height: 0, cover: null };
        try {
            await once(video, 'loadedmetadata', 15000);
            out.duration = Number.isFinite(video.duration) ? video.duration : 0;
            out.width = video.videoWidth; out.height = video.videoHeight;
            video.currentTime = Math.min(1, out.duration * 0.1 || 0.1);
            await once(video, 'seeked', 10000);
            const scale = Math.min(1, 1600 / Math.max(out.width, out.height, 1));
            const canvas = document.createElement('canvas');
            canvas.width = Math.max(1, Math.round(out.width * scale)); canvas.height = Math.max(1, Math.round(out.height * scale));
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            out.cover = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.86));
        } catch { /* no poster: the player shows a dark frame until it plays */ }
        finally { video.removeAttribute('src'); video.load(); URL.revokeObjectURL(url); }
        return out;
    }

    const text = (bytes, from, to) => String.fromCharCode(...bytes.subarray(from, to));
    const u32 = (b, i) => ((b[i] << 24) >>> 0) + (b[i + 1] << 16) + (b[i + 2] << 8) + b[i + 3];
    const le32 = (b, i) => (b[i] | (b[i + 1] << 8) | (b[i + 2] << 16) | (b[i + 3] << 24)) >>> 0;
    const utf8 = bytes => new TextDecoder().decode(bytes);
    const picture = (bytes, mime) => bytes.length > 100 ? new Blob([bytes], { type: /png/i.test(mime) ? 'image/png' : /webp/i.test(mime) ? 'image/webp' : 'image/jpeg' }) : null;
    const base64 = value => { try { return Uint8Array.from(atob(value.replace(/\s+/g, '')), c => c.charCodeAt(0)); } catch { return new Uint8Array(0); } };

    /** A FLAC picture block (FLAC files, and base64 in the METADATA_BLOCK_PICTURE comment of Ogg files). */
    function flacPicture(bytes, p = 0) {
        const mimeLen = u32(bytes, p + 4), mime = text(bytes, p + 8, p + 8 + mimeLen);
        p += 8 + mimeLen;
        p += 4 + u32(bytes, p) + 16;
        return picture(bytes.subarray(p + 4, p + 4 + u32(bytes, p)), mime);
    }
    /** Vorbis comments (Ogg Vorbis / Opus, FLAC): NAME=value, names in capitals. */
    function vorbisComments(bytes, p) {
        const tags = {};
        p += 4 + le32(bytes, p);
        const count = le32(bytes, p); p += 4;
        for (let i = 0; i < count && p + 4 <= bytes.length; i++) {
            const len = le32(bytes, p), entry = utf8(bytes.subarray(p + 4, p + 4 + len)); p += 4 + len;
            const eq = entry.indexOf('=');
            if (eq > 0) { const name = entry.slice(0, eq).toUpperCase(); if (!(name in tags)) tags[name] = entry.slice(eq + 1); }
        }
        return tags;
    }
    /** The cover (APIC) and the user texts (TXXX) of an ID3v2 tag that starts at tag[0]. */
    function id3(tag) {
        const out = { cover: null, tags: {} }, major = tag[3];
        const size = ((tag[6] & 127) << 21) | ((tag[7] & 127) << 14) | ((tag[8] & 127) << 7) | (tag[9] & 127);
        const decode = (enc, bytes) => {
            if (enc === 1 || enc === 2) {
                let le = enc === 1, from = 0;
                if (bytes[0] === 0xff && bytes[1] === 0xfe) { le = true; from = 2; } else if (bytes[0] === 0xfe && bytes[1] === 0xff) { le = false; from = 2; }
                return new TextDecoder(le ? 'utf-16le' : 'utf-16be').decode(bytes.subarray(from));
            }
            return enc === 3 ? utf8(bytes) : text(bytes, 0, bytes.length);
        };
        for (let i = 10; i + 10 < Math.min(tag.length, size + 10);) {
            const v22 = major === 2;
            const id = text(tag, i, i + (v22 ? 3 : 4));
            if (!/^[A-Z0-9]{3,4}$/.test(id)) break;
            const len = v22 ? (tag[i + 3] << 16) | (tag[i + 4] << 8) | tag[i + 5]
                : major === 4 ? ((tag[i + 4] & 127) << 21) | ((tag[i + 5] & 127) << 14) | ((tag[i + 6] & 127) << 7) | (tag[i + 7] & 127) : u32(tag, i + 4);
            const start = i + (v22 ? 6 : 10), end = Math.min(tag.length, start + len);
            const enc = tag[start];
            // Past one text in the frame's encoding (two zero bytes end it in UTF-16).
            const skipText = p => {
                if (enc === 1 || enc === 2) { while (p + 1 < end && !(tag[p] === 0 && tag[p + 1] === 0)) p += 2; return p + 2; }
                while (p < end && tag[p] !== 0) p++;
                return p + 1;
            };
            if (!out.cover && (id === 'APIC' || id === 'PIC')) {
                let p = start + 1, mime = 'image/jpeg';
                if (id === 'PIC') { mime = text(tag, p, p + 3); p += 3; }
                else { const z = tag.indexOf(0, p); mime = text(tag, p, z); p = z + 1; }
                out.cover = picture(tag.subarray(skipText(p + 1), end), mime); // p + 1: past the picture type
            } else if (id === 'TXXX' || id === 'TXX') {
                const valueAt = skipText(start + 1);
                const name = decode(enc, tag.subarray(start + 1, valueAt)).replace(/\0+$/, '').toUpperCase();
                out.tags[name] = decode(enc, tag.subarray(valueAt, end)).replace(/\0+$/, '');
            }
            i = start + len;
        }
        return out;
    }
    /** The first packets of an Ogg file (its first stream): the header, then the comments. */
    function oggPackets(bytes, max) {
        const packets = [];
        let parts = [];
        for (let p = 0; p + 27 <= bytes.length && packets.length < max;) {
            if (text(bytes, p, p + 4) !== 'OggS') break;
            const count = bytes[p + 26];
            let q = p + 27 + count;
            for (let k = 0; k < count && packets.length < max; k++) {
                const len = bytes[p + 27 + k];
                parts.push(bytes.subarray(q, q + len)); q += len;
                if (len < 255) {
                    const packet = new Uint8Array(parts.reduce((n, part) => n + part.length, 0));
                    parts.reduce((at, part) => { packet.set(part, at); return at + part.length; }, 0);
                    packets.push(packet); parts = [];
                }
            }
            p = q;
        }
        return packets;
    }
    /** The sample rate of the first MPEG audio frame after the ID3 tag. */
    function mp3Rate(bytes, from) {
        for (let i = from; i + 4 < bytes.length; i++) {
            if (bytes[i] !== 0xff || (bytes[i + 1] & 0xe0) !== 0xe0) continue;
            const version = (bytes[i + 1] >> 3) & 3, index = (bytes[i + 2] >> 2) & 3;
            if (version === 1 || index === 3) continue;
            return [[11025, 12000, 8000], null, [22050, 24000, 16000], [44100, 48000, 32000]][version][index];
        }
        return 0;
    }
    /**
     * The loop marked inside a song as games do: LOOPSTART with LOOPLENGTH or LOOPEND, in samples.
     * In seconds as [start, end]; end 0 means the end of the file.
     */
    function loopOf(tags, rate) {
        const num = name => { const m = String(tags[name] ?? '').match(/^\s*(\d+)/); return m ? +m[1] : null; };
        const start = num('LOOPSTART'), length = num('LOOPLENGTH'), end = num('LOOPEND');
        if (start === null || !rate) return null;
        const stop = length ? start + length : end !== null && end > start ? end : 0;
        return [start / rate, stop / rate];
    }

    /**
     * The cover picture and the loop inside an audio file: { cover: Blob|null, loop: [start, end]|null }.
     * Covers: ID3 (MP3, WAV), the covr atom (M4A), FLAC picture blocks, METADATA_BLOCK_PICTURE / COVERART (Ogg).
     * Loops: LOOPSTART tags (Ogg, FLAC, MP3 TXXX, M4A ----), or the first loop of a WAV smpl chunk.
     */
    async function readTags(file) {
        const out = { cover: null, loop: null, rate: 0 };
        try {
            const head = new Uint8Array(await file.slice(0, 16).arrayBuffer());
            if (text(head, 0, 3) === 'ID3') {
                const size = ((head[6] & 127) << 21) | ((head[7] & 127) << 14) | ((head[8] & 127) << 7) | (head[9] & 127);
                const bytes = new Uint8Array(await file.slice(0, Math.min(file.size, size + 10 + 8192, 32 * 1024 * 1024)).arrayBuffer());
                const tag = id3(bytes);
                out.cover = tag.cover; out.rate = mp3Rate(bytes, size + 10); out.loop = loopOf(tag.tags, out.rate);
            } else if (text(head, 4, 8) === 'ftyp') {
                // The metadata may sit at either end of the file.
                const parts = [[0, Math.min(file.size, 8 * 1024 * 1024)], [Math.max(0, file.size - 8 * 1024 * 1024), file.size]];
                const tags = {};
                let rate = 0;
                for (const [from, to] of parts) {
                    const bytes = new Uint8Array(await file.slice(from, to).arrayBuffer());
                    for (let i = 4; i + 24 < bytes.length; i++) {
                        if (bytes[i] === 0x63 && !out.cover && text(bytes, i, i + 4) === 'covr' && text(bytes, i + 8, i + 12) === 'data') {
                            const size = u32(bytes, i + 4), type = u32(bytes, i + 12) & 0xffffff;
                            out.cover = picture(bytes.subarray(i + 20, Math.min(bytes.length, i + 4 + size)), type === 14 ? 'image/png' : 'image/jpeg');
                        } else if (bytes[i] === 0x6d && !rate && text(bytes, i, i + 4) === 'mp4a') rate = u32(bytes, i + 28) >>> 16;
                        else if (bytes[i] === 0x6e && text(bytes, i, i + 4) === 'name' && text(bytes, i + 8, i + 12) === 'LOOP') {
                            // A freeform ---- item: its name atom, then its data atom.
                            const nameEnd = i - 4 + u32(bytes, i - 4), name = text(bytes, i + 8, nameEnd);
                            if (text(bytes, nameEnd + 4, nameEnd + 8) === 'data') tags[name.toUpperCase()] = utf8(bytes.subarray(nameEnd + 16, nameEnd + u32(bytes, nameEnd)));
                        }
                    }
                }
                out.rate = rate; out.loop = loopOf(tags, rate);
            } else if (text(head, 0, 4) === 'fLaC') {
                const bytes = new Uint8Array(await file.slice(0, Math.min(file.size, 16 * 1024 * 1024)).arrayBuffer());
                let rate = 0, tags = {};
                for (let i = 4; i + 4 < bytes.length;) {
                    const last = bytes[i] & 0x80, type = bytes[i] & 0x7f, len = (bytes[i + 1] << 16) | (bytes[i + 2] << 8) | bytes[i + 3];
                    if (type === 0) rate = (bytes[i + 14] << 12) | (bytes[i + 15] << 4) | (bytes[i + 16] >> 4);
                    else if (type === 4) tags = vorbisComments(bytes.subarray(0, i + 4 + len), i + 4);
                    else if (type === 6 && !out.cover) out.cover = flacPicture(bytes, i + 4);
                    if (last) break;
                    i += 4 + len;
                }
                out.rate = rate; out.loop = loopOf(tags, rate);
            } else if (text(head, 0, 4) === 'OggS') {
                // Vorbis or Opus: the comments are the second packet, the cover in them in base64.
                const bytes = new Uint8Array(await file.slice(0, Math.min(file.size, 32 * 1024 * 1024)).arrayBuffer());
                const [first, second] = oggPackets(bytes, 2);
                if (first && second) {
                    const vorbis = text(first, 1, 7) === 'vorbis', opus = text(first, 0, 8) === 'OpusHead';
                    const rate = vorbis ? le32(first, 12) : opus ? 48000 : 0;
                    const at = vorbis && text(second, 1, 7) === 'vorbis' ? 7 : opus && text(second, 0, 8) === 'OpusTags' ? 8 : -1;
                    if (at >= 0) {
                        const tags = vorbisComments(second, at);
                        if (tags.METADATA_BLOCK_PICTURE) out.cover = flacPicture(base64(tags.METADATA_BLOCK_PICTURE));
                        else if (tags.COVERART) out.cover = picture(base64(tags.COVERART), tags.COVERARTMIME || 'image/jpeg');
                        out.loop = loopOf(tags, rate);
                    }
                    out.rate = rate;
                }
            } else if (text(head, 0, 4) === 'RIFF' && text(head, 8, 12) === 'WAVE') {
                // WAV: the rate in fmt, a loop in smpl, an ID3 tag in its own chunk; smpl and id3 often follow the sound.
                const near = Math.min(file.size, 4 * 1024 * 1024);
                const bytes = new Uint8Array(await file.slice(0, near).arrayBuffer());
                let rate = 0, tags = {}, smpl = null;
                const walk = (data, from) => {
                    for (let i = from; i + 8 <= data.length;) {
                        const id = text(data, i, i + 4), len = le32(data, i + 4), body = data.subarray(i + 8, i + 8 + len);
                        if (i + 8 + len > data.length && id !== 'data') return -1;
                        if (id === 'fmt ') rate = le32(body, 4);
                        else if (id === 'smpl' && le32(body, 28) > 0) smpl = [le32(body, 36 + 8), le32(body, 36 + 12) + 1];
                        else if (/^id3 $/i.test(id) && text(body, 0, 3) === 'ID3') { const tag = id3(body); out.cover = tag.cover; tags = tag.tags; }
                        if (i + 8 + len > data.length) return i + 8 + len + (len & 1);
                        i += 8 + len + (len & 1);
                    }
                    return -1;
                };
                const after = walk(bytes, 12);
                if (after > 0 && after < file.size) walk(new Uint8Array(await file.slice(after, Math.min(file.size, after + 16 * 1024 * 1024)).arrayBuffer()), 0);
                out.rate = rate;
                out.loop = loopOf(tags, rate) || (smpl && rate && smpl[1] > smpl[0] ? [smpl[0] / rate, smpl[1] / rate] : null);
            }
        } catch { /* nothing found */ }
        return out;
    }
    const findCover = async file => (await readTags(file)).cover;

    /*
     * Writing the cover and the loop into the file itself, so they travel with it (a download, another
     * player, a game). Each format keeps everything else it holds. change.cover: a picture Blob to put in,
     * false to take the cover out, undefined to leave it. change.loop: [start, length] in samples,
     * null to take the loop out, undefined to leave it.
     */
    const ascii = value => Uint8Array.from(value, c => c.charCodeAt(0) & 255);
    const encoder = new TextEncoder();
    const be32 = n => Uint8Array.of(n >>> 24 & 255, n >>> 16 & 255, n >>> 8 & 255, n & 255);
    const le32b = n => Uint8Array.of(n & 255, n >>> 8 & 255, n >>> 16 & 255, n >>> 24 & 255);
    const syncsafe = n => Uint8Array.of(n >>> 21 & 127, n >>> 14 & 127, n >>> 7 & 127, n & 127);
    const unsafe = (b, i) => ((b[i] & 127) << 21) | ((b[i + 1] & 127) << 14) | ((b[i + 2] & 127) << 7) | (b[i + 3] & 127);
    function concat(parts) {
        const list = parts.map(p => p instanceof Uint8Array ? p : Uint8Array.from(p));
        const out = new Uint8Array(list.reduce((n, p) => n + p.length, 0));
        list.reduce((at, p) => { out.set(p, at); return at + p.length; }, 0);
        return out;
    }
    const LOOP_NAME = /^LOOP(START|LENGTH|END)$/i;
    const refuse = message => { throw new Error(message); };

    /** The cover as a JPEG (or the PNG as it is) at most 1200 px: a cover inside a song stays small. */
    async function coverBytes(blob) {
        const bitmap = await createImageBitmap(blob).catch(() => null);
        if (!bitmap) refuse(t('ジャケットの画像を読めませんでした。JPEG・PNG・WebPの画像を選んでください。'));
        const scale = Math.min(1, 1200 / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && /^image\/(jpeg|png)$/.test(blob.type) && blob.size < 2 * 1024 * 1024) return { bytes: new Uint8Array(await blob.arrayBuffer()), mime: blob.type, width: bitmap.width, height: bitmap.height };
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale)); canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const jpeg = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.9));
        return { bytes: new Uint8Array(await jpeg.arrayBuffer()), mime: 'image/jpeg', width: canvas.width, height: canvas.height };
    }
    /** A FLAC picture block: front cover. */
    const pictureBlock = c => concat([be32(3), be32(c.mime.length), ascii(c.mime), be32(0), be32(c.width), be32(c.height), be32(24), be32(0), be32(c.bytes.length), c.bytes]);

    // --- ID3v2 (MP3, and the id3 chunk of WAV) ---
    function id3Rebuild(old, change, cover) {
        let major = 3, frames = [];
        if (old && old.length >= 10 && text(old, 0, 3) === 'ID3' && (old[3] === 3 || old[3] === 4) && !(old[5] & 0x80)) {
            major = old[3];
            const end = Math.min(old.length, 10 + unsafe(old, 6));
            let i = 10;
            if (old[5] & 0x40) i += major === 4 ? unsafe(old, 10) : u32(old, 10) + 4;
            while (i + 10 <= end) {
                const id = text(old, i, i + 4);
                if (!/^[A-Z0-9]{4}$/.test(id)) break;
                const len = major === 4 ? unsafe(old, i + 4) : u32(old, i + 4);
                frames.push({ id, bytes: old.subarray(i, i + 10 + len), body: old.subarray(i + 10, i + 10 + len) });
                i += 10 + len;
            }
        }
        const txxxName = body => {
            const enc = body[0];
            if (enc === 1 || enc === 2) { let p = 1; while (p + 1 < body.length && !(body[p] === 0 && body[p + 1] === 0)) p += 2; return new TextDecoder(enc === 1 ? 'utf-16' : 'utf-16be').decode(body.subarray(1, p)); }
            const z = body.indexOf(0, 1); return utf8(body.subarray(1, z < 0 ? body.length : z));
        };
        const keep = frames.filter(f => !(change.cover !== undefined && f.id === 'APIC') && !(change.loop !== undefined && f.id === 'TXXX' && LOOP_NAME.test(txxxName(f.body))));
        const frame = (id, body) => concat([ascii(id), major === 4 ? syncsafe(body.length) : be32(body.length), [0, 0], body]);
        const text0 = (name, value) => frame('TXXX', concat([[0], ascii(name), [0], ascii(value)]));
        const added = [];
        if (cover) added.push(frame('APIC', concat([[0], ascii(cover.mime), [0, 3, 0], cover.bytes])));
        if (change.loop) added.push(text0('LOOPSTART', String(change.loop[0])), text0('LOOPLENGTH', String(change.loop[1])));
        const body = concat([...keep.map(f => f.bytes), ...added]);
        return body.length ? concat([ascii('ID3'), [major, 0, 0], syncsafe(body.length), body]) : new Uint8Array(0);
    }
    function writeMp3(bytes, change, cover) {
        let rest = bytes, old = null;
        if (text(bytes, 0, 3) === 'ID3') {
            const size = unsafe(bytes, 6), footer = bytes[3] === 4 && (bytes[5] & 0x10) ? 10 : 0;
            old = bytes.subarray(0, 10 + size); rest = bytes.subarray(10 + size + footer);
        }
        return [id3Rebuild(old, change, cover), rest];
    }

    // --- WAV: the loop in a smpl chunk (and as tags), the cover in an id3 chunk ---
    function writeWav(bytes, change, cover) {
        if (text(bytes, 0, 4) !== 'RIFF' || text(bytes, 8, 12) !== 'WAVE') refuse(t('WAVファイルとして読めませんでした。'));
        const chunks = [];
        let rate = 0, id3Old = null, smplOld = null;
        for (let i = 12; i + 8 <= bytes.length;) {
            const id = text(bytes, i, i + 4), len = le32(bytes, i + 4), body = bytes.subarray(i + 8, Math.min(bytes.length, i + 8 + len));
            if (id === 'fmt ') rate = le32(body, 4);
            if (/^id3 $/i.test(id)) id3Old = body;
            else if (id === 'smpl') smplOld = body;
            else chunks.push(concat([ascii(id), le32b(body.length), body, body.length & 1 ? [0] : []]));
            i += 8 + len + (len & 1);
        }
        const chunk = (id, body) => concat([ascii(id), le32b(body.length), body, body.length & 1 ? [0] : []]);
        if (change.loop) {
            const [start, length] = change.loop;
            chunks.push(chunk('smpl', concat([le32b(0), le32b(0), le32b(Math.round(1e9 / (rate || 44100))), le32b(60), le32b(0), le32b(0), le32b(0), le32b(1), le32b(0),
                le32b(0), le32b(0), le32b(start), le32b(start + length - 1), le32b(0), le32b(0)])));
        } else if (change.loop === undefined && smplOld) chunks.push(chunk('smpl', smplOld));
        const tag = id3Rebuild(id3Old, change, cover);
        if (tag.length) chunks.push(chunk('id3 ', tag));
        const body = concat(chunks);
        return [ascii('RIFF'), le32b(4 + body.length), ascii('WAVE'), body];
    }

    // --- Vorbis comments (FLAC, Ogg) ---
    function commentsOf(bytes, p) {
        const vendorLen = le32(bytes, p), vendor = bytes.subarray(p + 4, p + 4 + vendorLen);
        p += 4 + vendorLen;
        const count = le32(bytes, p), list = [];
        p += 4;
        for (let i = 0; i < count && p + 4 <= bytes.length; i++) { const len = le32(bytes, p); list.push(bytes.subarray(p + 4, p + 4 + len)); p += 4 + len; }
        return { vendor, list };
    }
    function commentsWith({ vendor, list }, change, cover) {
        const name = entry => { const s = utf8(entry.subarray(0, 64)); return s.slice(0, s.indexOf('=') >>> 0).toUpperCase(); };
        const keep = list.filter(e => !(change.loop !== undefined && LOOP_NAME.test(name(e))) && !(change.cover !== undefined && /^(METADATA_BLOCK_PICTURE|COVERART|COVERARTMIME)$/.test(name(e))));
        const add = [];
        if (change.loop) add.push(encoder.encode('LOOPSTART=' + change.loop[0]), encoder.encode('LOOPLENGTH=' + change.loop[1]));
        if (cover && cover.inComments) {
            const block = pictureBlock(cover);
            let b64 = '';
            for (let i = 0; i < block.length; i += 0x8000) b64 += String.fromCharCode(...block.subarray(i, i + 0x8000));
            add.push(encoder.encode('METADATA_BLOCK_PICTURE=' + btoa(b64)));
        }
        const all = [...keep, ...add];
        return concat([le32b(vendor.length), vendor, le32b(all.length), ...all.flatMap(e => [le32b(e.length), e])]);
    }

    function writeFlac(bytes, change, cover) {
        if (text(bytes, 0, 4) !== 'fLaC') refuse(t('FLACファイルとして読めませんでした。'));
        const blocks = [];
        let i = 4, comments = null;
        for (;;) {
            if (i + 4 > bytes.length) refuse(t('FLACファイルとして読めませんでした。'));
            const last = bytes[i] & 0x80, type = bytes[i] & 0x7f, len = (bytes[i + 1] << 16) | (bytes[i + 2] << 8) | bytes[i + 3];
            const body = bytes.subarray(i + 4, i + 4 + len);
            if (type === 4) comments = commentsOf(body, 0);
            else if (type !== 1 && !(type === 6 && change.cover !== undefined && u32(body, 0) === 3)) blocks.push({ type, body });
            i += 4 + len;
            if (last) break;
        }
        const vc = commentsWith(comments || { vendor: encoder.encode('NagiLog'), list: [] }, change, null);
        blocks.splice(1, 0, { type: 4, body: vc });
        if (cover) blocks.push({ type: 6, body: pictureBlock(cover) });
        if (blocks.some(b => b.body.length > 0xffffff)) refuse(t('ジャケットの画像が大きすぎます。'));
        return [ascii('fLaC'), ...blocks.map((b, k) => concat([[(k === blocks.length - 1 ? 0x80 : 0) | b.type, b.body.length >>> 16 & 255, b.body.length >>> 8 & 255, b.body.length & 255], b.body])), bytes.subarray(i)];
    }

    // --- Ogg Vorbis / Opus: the comment packet rewritten, the pages after it numbered again ---
    let crcTable = null;
    function oggCrc(page) {
        if (!crcTable) {
            crcTable = new Uint32Array(256);
            for (let n = 0; n < 256; n++) { let r = n << 24; for (let k = 0; k < 8; k++) r = r & 0x80000000 ? (r << 1) ^ 0x04c11db7 : r << 1; crcTable[n] = r >>> 0; }
        }
        let crc = 0;
        for (let k = 0; k < page.length; k++) crc = ((crc << 8) ^ crcTable[((crc >>> 24) ^ page[k]) & 255]) >>> 0;
        return crc;
    }
    function oggPage(flags, granule, serial, sequence, lacing, body) {
        const page = concat([ascii('OggS'), [0, flags], granule, serial, le32b(sequence), [0, 0, 0, 0], [lacing.length], lacing, body]);
        page.set(le32b(oggCrc(page)), 22);
        return page;
    }
    function writeOgg(bytes, change, cover) {
        const pages = [];
        for (let p = 0; p + 27 <= bytes.length;) {
            if (text(bytes, p, p + 4) !== 'OggS') refuse(t('OGGファイルとして読めませんでした。'));
            const count = bytes[p + 26], lacing = bytes.subarray(p + 27, p + 27 + count);
            const size = lacing.reduce((n, l) => n + l, 0), body = bytes.subarray(p + 27 + count, p + 27 + count + size);
            pages.push({ flags: bytes[p + 5], granule: bytes.subarray(p + 6, p + 14), serial: bytes.subarray(p + 14, p + 18), lacing, body });
            p += 27 + count + size;
        }
        if (!pages.length) refuse(t('OGGファイルとして読めませんでした。'));
        const serial = text(pages[0].serial, 0, 4);
        if (pages.some(pg => text(pg.serial, 0, 4) !== serial)) refuse(t('複数のストリームを含むOGGには書き込めません。'));
        // The header packets, and the page where they end (the sound must start on a page of its own).
        const packets = [];
        let parts = [], next = 0;
        const first = pages[0].body;
        const vorbis = text(first, 1, 7) === 'vorbis', opus = text(first, 0, 8) === 'OpusHead';
        if (!vorbis && !opus) refuse(t('VorbisかOpusのOGGにだけ書き込めます。'));
        const wanted = vorbis ? 3 : 2;
        for (let k = 0; k < pages.length && packets.length < wanted; k++) {
            let at = 0;
            for (const len of pages[k].lacing) {
                parts.push(pages[k].body.subarray(at, at + len)); at += len;
                if (len < 255) { packets.push(concat(parts)); parts = []; if (packets.length === wanted && at !== pages[k].body.length) refuse(t('このOGGの作りには書き込めません。')); }
            }
            next = k + 1;
        }
        if (packets.length < wanted) refuse(t('OGGファイルとして読めませんでした。'));
        const magic = vorbis ? 7 : 8;
        const comments = commentsWith(commentsOf(packets[1], magic), change, cover && { ...cover, inComments: true });
        packets[1] = concat([packets[1].subarray(0, magic), comments, vorbis ? [1] : []]);
        const lace = n => [...Array(Math.floor(n / 255)).fill(255), n % 255];
        const out = [];
        let sequence = 0;
        const zero = new Uint8Array(8);
        out.push(oggPage(2, zero, pages[0].serial, sequence++, Uint8Array.from(lace(packets[0].length)), packets[0]));
        // The rest of the headers on pages of at most 255 segments; a page that starts mid-packet says so.
        const segments = [];
        for (const packet of packets.slice(1)) { let at = 0; for (const len of lace(packet.length)) { segments.push(packet.subarray(at, at + len)); at += len; } }
        let continued = false;
        for (let k = 0; k < segments.length; k += 255) {
            const run = segments.slice(k, k + 255);
            out.push(oggPage(continued ? 1 : 0, zero, pages[0].serial, sequence++, Uint8Array.from(run.map(s => s.length)), concat(run)));
            continued = run[run.length - 1].length === 255;
        }
        for (const pg of pages.slice(next)) out.push(oggPage(pg.flags, pg.granule, pg.serial, sequence++, pg.lacing, pg.body));
        return out;
    }

    // --- M4A: iTunes items in moov/udta/meta/ilst; chunk offsets moved when moov sits before the sound ---
    function boxesIn(bytes, from, to) {
        const list = [];
        for (let i = from; i + 8 <= to;) {
            let size = u32(bytes, i), head = 8;
            if (size === 1) { size = u32(bytes, i + 8) * 4294967296 + u32(bytes, i + 12); head = 16; }
            else if (size === 0) size = to - i;
            if (size < head || i + size > to) break;
            list.push({ type: text(bytes, i + 4, i + 8), start: i, size, head, end: i + size });
            i += size;
        }
        return list;
    }
    const box = (type, ...payload) => { const body = concat(payload); return concat([be32(8 + body.length), ascii(type), body]); };
    function writeM4a(bytes, change, cover) {
        const top = boxesIn(bytes, 0, bytes.length);
        const moov = top.find(b => b.type === 'moov');
        if (!moov || moov.head !== 8) refuse(t('M4Aファイルとして読めませんでした。'));
        const children = (b, skip = 0) => boxesIn(bytes, b.start + b.head + skip, b.end);
        const raw = b => bytes.subarray(b.start, b.end);
        const moovKids = children(moov);
        const udta = moovKids.find(b => b.type === 'udta');
        const udtaKids = udta ? children(udta) : [];
        const meta = udtaKids.find(b => b.type === 'meta');
        // meta is a full box (4 bytes of version and flags) except in some QuickTime files.
        const full = meta ? text(bytes, meta.start + 12, meta.start + 16) !== 'hdlr' : true;
        const metaKids = meta ? children(meta, full ? 4 : 0) : [];
        const ilst = metaKids.find(b => b.type === 'ilst');
        const nameOf = item => { const n = children(item).find(b => b.type === 'name'); return n ? utf8(bytes.subarray(n.start + 12, n.end)) : ''; };
        const items = (ilst ? children(ilst) : []).filter(item => !(change.cover !== undefined && item.type === 'covr') && !(change.loop !== undefined && item.type === '----' && LOOP_NAME.test(nameOf(item)))).map(raw);
        if (cover) items.push(box('covr', box('data', [0, 0, 0, cover.mime === 'image/png' ? 14 : 13, 0, 0, 0, 0], cover.bytes)));
        const freeform = (name, value) => box('----', box('mean', [0, 0, 0, 0], ascii('com.apple.iTunes')), box('name', [0, 0, 0, 0], ascii(name)), box('data', [0, 0, 0, 1, 0, 0, 0, 0], encoder.encode(value)));
        if (change.loop) items.push(freeform('LOOPSTART', String(change.loop[0])), freeform('LOOPLENGTH', String(change.loop[1])));
        const hdlr = box('hdlr', [0, 0, 0, 0, 0, 0, 0, 0], ascii('mdirappl'), new Uint8Array(9));
        const newIlst = box('ilst', ...items);
        const newMeta = box('meta', full ? [0, 0, 0, 0] : [], ...(meta ? metaKids.filter(b => b.type !== 'ilst').map(raw) : [hdlr]), newIlst);
        const newUdta = box('udta', ...udtaKids.filter(b => b.type !== 'meta').map(raw), newMeta);
        const newMoov = box('moov', ...moovKids.filter(b => b.type !== 'udta').map(raw), newUdta);
        const delta = newMoov.length - moov.size;
        // Offsets into the file after the old moov move by the change in its size.
        const view = new DataView(newMoov.buffer, newMoov.byteOffset, newMoov.byteLength);
        const walk = (from, to) => {
            for (const b of boxesIn(newMoov, from, to)) {
                if (['trak', 'mdia', 'minf', 'stbl'].includes(b.type)) walk(b.start + b.head, b.end);
                else if (b.type === 'stco' || b.type === 'co64') {
                    const count = view.getUint32(b.start + 12), wide = b.type === 'co64';
                    for (let k = 0; k < count; k++) {
                        const at = b.start + 16 + k * (wide ? 8 : 4);
                        if (wide) {
                            const value = view.getUint32(at) * 4294967296 + view.getUint32(at + 4);
                            if (value >= moov.start) { const moved = value + delta; view.setUint32(at, Math.floor(moved / 4294967296)); view.setUint32(at + 4, moved >>> 0); }
                        } else {
                            const value = view.getUint32(at);
                            if (value >= moov.start) { if (value + delta > 0xffffffff) refuse(t('このM4Aには書き込めません（ファイルが大きすぎます）。')); view.setUint32(at, value + delta); }
                        }
                    }
                }
            }
        };
        walk(8, newMoov.length);
        return [bytes.subarray(0, moov.start), newMoov, bytes.subarray(moov.end)];
    }

    const WRITERS = { mp3: writeMp3, wav: writeWav, flac: writeFlac, ogg: writeOgg, oga: writeOgg, opus: writeOgg, m4a: writeM4a };
    const canWrite = ext => ext.toLowerCase() in WRITERS;
    /** The file with the cover and loop written in: a new Blob of the same type. */
    async function writeTags(file, ext, change) {
        const writer = WRITERS[String(ext).toLowerCase()];
        if (!writer) refuse(t('この形式にはジャケットやループ位置を書き込めません。MP3・M4A・FLAC・OGG・WAVに対応しています。'));
        const cover = change.cover ? await coverBytes(change.cover) : null;
        const bytes = new Uint8Array(await file.arrayBuffer());
        return new Blob(writer(bytes, change, cover), { type: file.type });
    }

    // --- Dialogs for one video or audio file (the post editor and メディア一覧) ---
    const make = (tag, className, textContent) => { const n = document.createElement(tag); if (className) n.className = className; if (textContent !== undefined) n.textContent = textContent; return n; };
    function dialogShell(title, lead) {
        const dialog = make('dialog', 'log-av-dialog');
        dialog.setAttribute('aria-labelledby', 'log-av-dialog-title');
        const form = make('form', 'log-av-dialog-body'); form.method = 'dialog';
        const head = make('div', 'log-av-dialog-head');
        const h = make('h2', '', title); h.id = 'log-av-dialog-title';
        const close = make('button', 'log-av-dialog-x'); close.type = 'button'; close.setAttribute('aria-label', t('閉じる'));
        close.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
        head.append(h, close);
        form.append(head, make('p', 'log-av-dialog-lead', lead));
        const status = make('p', 'log-av-dialog-status'); status.setAttribute('role', 'status');
        const foot = make('div', 'log-av-dialog-foot');
        const cancel = make('button', 'btn', t('キャンセル')); cancel.type = 'button';
        foot.append(cancel);
        dialog.append(form);
        document.body.append(dialog);
        const finish = new Set();
        const done = () => { finish.forEach(fn => fn()); dialog.close(); dialog.remove(); };
        close.addEventListener('click', done); cancel.addEventListener('click', done);
        dialog.addEventListener('cancel', event => { event.preventDefault(); done(); });
        dialog.addEventListener('click', event => { if (event.target === dialog) done(); });
        const say = (message, error = false) => { status.textContent = message; status.classList.toggle('is-error', error); };
        return { dialog, form, status, foot, say, done, onClose: fn => finish.add(fn), add: (...nodes) => form.append(...nodes), open: () => { form.append(status, foot); dialog.showModal(); } };
    }
    const post = async (endpoint, csrf, fields) => {
        const data = new FormData(); data.set('csrf', csrf);
        Object.entries(fields).forEach(([k, v]) => data.set(k, String(v)));
        const response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        let result;
        try { result = await response.json(); } catch { throw new Error(t('ログインが切れた可能性があります。ログインし直してから、もう一度お試しください。')); }
        if (!response.ok || result.error) throw new Error(result.error || t('保存できませんでした。もう一度お試しください。'));
        return result;
    };

    /**
     * How a video plays in posts: four ways to choose from, each shown at once in the preview.
     * Resolves with the saved media record, or null when closed without saving.
     */
    const WAYS = [
        { key: 'normal', auto: false, loop: false, name: t('ふつう'), note: t('再生ボタンを押すと、音ありで再生します。') },
        { key: 'gif', auto: true, loop: true, name: t('GIFのように'), note: t('見えたら音なしで自動再生し、くり返します。短い動画やアニメーションに。') },
        { key: 'auto', auto: true, loop: false, name: t('自動再生'), note: t('見えたら音なしで1回再生します。') },
        { key: 'loop', auto: false, loop: true, name: t('くり返し'), note: t('再生ボタンを押すと、音ありでくり返します。') },
    ];
    function playDialog(media, { endpoint, csrf }) {
        return new Promise(resolve => {
            const shell = dialogShell(t('再生のしかた'), t('「{name}」をどう見せるか選びます。この動画を使うすべての記事に効きます。', { name: media.alt || t('動画') }));
            const play = media.play || {};
            const current = WAYS.find(w => w.auto === !!play.auto && w.loop === !!play.loop) || WAYS[0];
            const layout = make('div', 'log-av-play');
            const choices = make('div', 'log-av-ways'); choices.setAttribute('role', 'radiogroup'); choices.setAttribute('aria-label', t('再生のしかた'));
            WAYS.forEach(way => {
                const label = make('label', 'log-av-way');
                const input = make('input'); input.type = 'radio'; input.name = 'way'; input.value = way.key; input.checked = way === current;
                const words = make('span', 'log-av-way-text');
                words.append(make('b', '', way.name), make('small', '', way.note));
                label.append(input, words);
                choices.append(label);
            });
            const muteRow = make('label', 'log-av-check');
            const mute = make('input'); mute.type = 'checkbox'; mute.checked = !!play.muted && !play.auto;
            muteRow.append(mute, make('span', '', t('最初は音を消しておく（読者が音のボタンで出せます）')));
            const autoNote = make('p', 'log-av-note', t('自動再生は、ブラウザの決まりで音なしで始まります。読者には「音を出す」ボタンが出ます。動きを減らす設定にしている読者には、自動で再生しません。'));
            const preview = make('div', 'log-av-preview');
            const video = make('video'); video.src = media.url; video.playsInline = true; video.preload = 'metadata'; video.controls = true;
            if (media.thumb) video.poster = media.thumb;
            preview.append(video, make('small', '', t('プレビュー')));
            const side = make('div', 'log-av-play-side'); side.append(choices, muteRow, autoNote);
            layout.append(side, preview);
            shell.add(layout);
            const save = make('button', 'btn primary', t('保存')); save.type = 'button';
            shell.foot.append(save);
            const chosen = () => WAYS.find(w => w.key === choices.querySelector('input:checked').value);
            const apply = () => {
                const way = chosen();
                mute.disabled = way.auto; muteRow.classList.toggle('is-off', way.auto);
                if (way.auto) mute.checked = true;
                autoNote.hidden = !way.auto;
                video.loop = way.loop; video.muted = way.auto || mute.checked;
                if (way.auto) video.play().catch(() => {}); else { video.pause(); video.currentTime = 0; }
            };
            choices.addEventListener('change', apply); mute.addEventListener('change', apply);
            shell.onClose(() => { video.pause(); video.removeAttribute('src'); video.load(); resolve(null); });
            save.addEventListener('click', async () => {
                const way = chosen();
                save.disabled = true; shell.say(t('保存しています…'));
                try {
                    const result = await post(endpoint, csrf, { do: 'log_media_play', media: media.id, revision: media.revision, auto: way.auto ? 1 : 0, muted: way.auto || mute.checked ? 1 : 0, loop: way.loop ? 1 : 0 });
                    resolve(result.media); resolve = () => {};
                    shell.done();
                } catch (e) { shell.say(e.message, true); save.disabled = false; }
            });
            shell.open(); apply();
            choices.querySelector('input:checked').focus();
        });
    }

    // Times as people read them: 1:23.456 (and back; a plain number of seconds works too).
    const clock = s => { s = Math.max(0, s); const m = Math.floor(s / 60); return m + ':' + (s - m * 60).toFixed(3).padStart(6, '0'); };
    const seconds = value => {
        const m = String(value).trim().match(/^(?:(\d+):)?(\d+(?:\.\d+)?)$/);
        return m ? (m[1] ? +m[1] * 60 : 0) + +m[2] : NaN;
    };

    /**
     * The cover and the loop of a song, written into the file, which then replaces the one on the server.
     * The loop can be heard at its seam (the end running into the start) before saving.
     */
    function tagDialog(media, { endpoint, csrf }) {
        return new Promise(resolve => {
            const ext = String(media.f || '').split('.').pop().toLowerCase();
            const shell = dialogShell(t('ジャケットとループ位置'), t('「{name}」のファイルの中に書き込みます。書き込んだファイルに差し替えるので、この曲を使うすべての記事に効きます。', { name: media.alt || t('音声') }));
            if (!canWrite(ext)) {
                shell.add(make('p', 'log-av-note', t('この形式にはジャケットやループ位置を書き込めません。MP3・M4A・FLAC・OGG・WAVに対応しています。')));
                shell.onClose(() => resolve(null)); shell.open(); return;
            }
            // Cover
            const coverBox = make('fieldset', 'log-av-section');
            coverBox.append(make('legend', '', t('ジャケット')));
            const coverRow = make('div', 'log-av-cover');
            const img = make('img'); img.alt = ''; img.hidden = !media.thumb; if (media.thumb) img.src = media.thumb;
            const none = make('span', 'log-av-cover-none', t('なし')); none.hidden = !!media.thumb;
            const pick = make('label', 'btn small', t('画像を選ぶ'));
            const input = make('input'); input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp'; input.hidden = true; pick.append(input);
            const drop = make('button', 'btn small', t('ジャケットを外す')); drop.type = 'button'; drop.hidden = !media.thumb;
            const coverButtons = make('div', 'log-av-cover-buttons'); coverButtons.append(pick, drop);
            coverRow.append(img, none, coverButtons);
            coverBox.append(coverRow, make('p', 'log-av-note', t('1200px より大きい画像は、縮めてJPEGで入れます。')));
            let cover; // undefined: as it is, Blob: a new one, false: none
            let preview = null;
            input.addEventListener('change', () => {
                const file = input.files[0]; if (!file) return;
                cover = file; if (preview) URL.revokeObjectURL(preview); preview = URL.createObjectURL(file);
                img.src = preview; img.hidden = false; none.hidden = true; drop.hidden = false;
            });
            drop.addEventListener('click', () => { cover = false; img.hidden = true; none.hidden = false; drop.hidden = true; input.value = ''; });
            // Loop
            const loopBox = make('fieldset', 'log-av-section');
            loopBox.append(make('legend', '', t('ループ位置')));
            const radio = (value, label) => { const l = make('label', 'log-av-check'); const r = make('input'); r.type = 'radio'; r.name = 'loop'; r.value = value; l.append(r, make('span', '', label)); return [l, r]; };
            const [offRow, off] = radio('off', t('入れない（くり返すときは曲全体）'));
            const [onRow, on] = radio('on', t('ループ位置を入れる（イントロのあと、この間をくり返します）'));
            const has = Array.isArray(media.loop);
            (has ? on : off).checked = true;
            const times = make('div', 'log-av-times');
            const field = (label, value, hint) => {
                const wrap = make('label', 'log-av-time'); wrap.append(make('span', '', label));
                const box = make('input'); box.type = 'text'; box.inputMode = 'decimal'; box.value = value; box.placeholder = hint; box.autocomplete = 'off';
                const here = make('button', 'btn small', t('今の位置')); here.type = 'button';
                const row = make('span', 'log-av-time-row'); row.append(box, here);
                wrap.append(row); times.append(wrap);
                return [box, here];
            };
            const [startBox, startHere] = field(t('開始'), has ? clock(media.loop[0]) : '0:00.000', '0:12.345');
            const [endBox, endHere] = field(t('終わり'), has && media.loop[1] > 0 ? clock(media.loop[1]) : '', t('空なら曲の最後'));
            // Listening: the song decoded once; the seam is heard by starting a little before the end.
            const listen = make('div', 'log-av-listen');
            const playButton = make('button', 'btn small', t('再生')); playButton.type = 'button';
            const seam = make('button', 'btn small', t('つなぎ目を聞く')); seam.type = 'button';
            const bar = make('input', 'log-av-seek'); bar.type = 'range'; bar.min = '0'; bar.step = '0.01'; bar.value = '0'; bar.setAttribute('aria-label', t('再生位置'));
            const now = make('span', 'log-av-now', '0:00.000');
            listen.append(playButton, seam, bar, now);
            loopBox.append(offRow, onRow, times, listen, make('p', 'log-av-note', t('「今の位置」は、下の再生位置をその欄に入れます。時間は「分:秒.ミリ秒」か秒で書きます。ファイルにはサンプル数（LOOPSTART・LOOPLENGTH）で入れます。')));
            shell.add(coverBox, loopBox);
            const download = make('button', 'btn', t('書き込んだファイルを保存')); download.type = 'button';
            const save = make('button', 'btn primary', t('書き込んで差し替える')); save.type = 'button';
            shell.foot.append(download, save);
            const sync = () => { times.classList.toggle('is-off', !on.checked); [startBox, endBox, startHere, endHere, seam].forEach(n => { n.disabled = !on.checked; }); };
            [on, off].forEach(r => r.addEventListener('change', sync));
            // The file, its rate and the decoded sound.
            let file = null, rate = 0, audio = null, ctx = null, source = null, startedAt = 0, from = 0, frame = 0;
            const ready = (async () => {
                shell.say(t('ファイルを読み込んでいます…'));
                const response = await fetch(media.url, { credentials: 'same-origin' });
                if (!response.ok) throw new Error(t('ファイルを読み込めませんでした。'));
                const blob = await response.blob();
                file = new File([blob], (media.alt || 'audio').replace(/[\\/:*?"<>|]+/g, '_') + '.' + ext, { type: blob.type || 'audio/' + ext });
                rate = (await readTags(file)).rate;
                const Ctx = window.AudioContext || window.webkitAudioContext;
                ctx = new Ctx();
                const data = await file.arrayBuffer();
                audio = await new Promise((ok, ng) => { const p = ctx.decodeAudioData(data, ok, ng); if (p?.then) p.then(ok, ng); });
                bar.max = String(audio.duration);
                shell.say(rate ? '' : t('サンプリング周波数を読めませんでした。ループ位置は書き込めません。'), !rate);
            })().catch(e => { shell.say(e.message || t('ファイルを読み込めませんでした。'), true); throw e; });
            ready.catch(() => {});
            const range = () => {
                const a = seconds(startBox.value), b = endBox.value.trim() === '' ? audio.duration : seconds(endBox.value);
                if (!(a >= 0) || !(b > a) || b > audio.duration + 0.001) return null;
                return [a, Math.min(b, audio.duration)];
            };
            // The sound plays the loop only once it is inside it: started after the loop's end (the bar moved
            // past it), it plays the rest of the song first and then goes to the loop's start. Web Audio would
            // otherwise wrap a start past the loop back into it at once, and the bar would show another place.
            let looped = null, tail = false, scrubbing = false;
            const position = () => {
                if (!source) return from;
                const at = from + ctx.currentTime - startedAt;
                if (looped && !tail && at >= looped[1]) return looped[0] + (at - looped[1]) % (looped[1] - looped[0]);
                return Math.min(at, audio.duration);
            };
            // The bar follows the sound, except while a finger or the mouse holds it.
            const tick = () => { now.textContent = clock(position()); if (!scrubbing) bar.value = String(position()); frame = source ? requestAnimationFrame(tick) : 0; };
            const stopSound = () => { if (source) { source.onended = null; try { source.stop(); } catch { /* not started */ } source = null; } cancelAnimationFrame(frame); frame = 0; playButton.textContent = t('再生'); };
            const startSound = at => {
                stopSound();
                const node = ctx.createBufferSource(); node.buffer = audio; node.connect(ctx.destination);
                looped = on.checked ? range() : null;
                tail = !!looped && at >= looped[1];
                if (looped && !tail) { node.loop = true; node.loopStart = looped[0]; node.loopEnd = looped[1]; }
                node.onended = () => { if (source !== node) return; if (tail) startSound(looped[0]); else { from = 0; stopSound(); tick(); } };
                source = node;
                ctx.resume?.(); node.start(0, at); startedAt = ctx.currentTime; from = at;
                playButton.textContent = t('一時停止'); tick();
            };
            playButton.addEventListener('click', async () => { await ready; if (source) { from = position(); stopSound(); } else startSound(from >= audio.duration ? 0 : from); });
            seam.addEventListener('click', async () => {
                await ready;
                const r = range();
                if (!r) { shell.say(t('ループの開始と終わりを確かめてください。'), true); return; }
                shell.say('');
                startSound(Math.max(r[0], r[1] - 3));
            });
            // Moving the bar shows the time; the sound moves there when it is let go (no stutter while dragging).
            bar.addEventListener('pointerdown', () => { scrubbing = true; });
            bar.addEventListener('input', () => { now.textContent = clock(+bar.value); });
            bar.addEventListener('change', async () => { scrubbing = false; await ready; const at = +bar.value; if (source) startSound(at); else { from = at; tick(); } });
            startHere.addEventListener('click', () => { if (audio) startBox.value = clock(position()); });
            endHere.addEventListener('click', () => { if (audio) endBox.value = clock(position()); });
            [startBox, endBox].forEach(box => box.addEventListener('change', () => { if (source && source.loop) startSound(position()); }));
            // The new file.
            const build = async () => {
                await ready;
                const change = {};
                if (cover !== undefined) change.cover = cover;
                if (on.checked) {
                    const r = range();
                    if (!r) refuse(t('ループの開始と終わりを確かめてください。終わりは開始より後で、曲の長さ以内です。'));
                    if (!rate) refuse(t('サンプリング周波数を読めませんでした。ループ位置は書き込めません。'));
                    const start = Math.round(r[0] * rate), stop = Math.round(r[1] * rate);
                    change.loop = [start, stop - start];
                } else change.loop = null;
                const blob = await writeTags(file, ext, change);
                const out = new File([blob], file.name, { type: file.type });
                // Read back what was written before it goes anywhere.
                const check = await readTags(out);
                if (change.loop && !check.loop) refuse(t('ループ位置を書き込めませんでした。'));
                if (change.cover && !check.cover) refuse(t('ジャケットを書き込めませんでした。'));
                return out;
            };
            download.addEventListener('click', async () => {
                download.disabled = true;
                try {
                    shell.say(t('書き込んでいます…'));
                    const out = await build();
                    const a = make('a'); a.href = URL.createObjectURL(out); a.download = out.name; document.body.append(a); a.click(); a.remove();
                    setTimeout(() => URL.revokeObjectURL(a.href), 60000);
                    shell.say(t('書き込んだファイルを保存しました。'));
                } catch (e) { shell.say(e.message, true); }
                download.disabled = false;
            });
            save.addEventListener('click', async () => {
                save.disabled = download.disabled = true;
                try {
                    shell.say(t('書き込んでいます…'));
                    const out = await build();
                    stopSound();
                    const result = await upload(out, { endpoint, csrf, replace: media.id, revision: media.revision, dropCover: cover === false, onProgress: (ratio, phase) => shell.say(phase === 'measure' ? t('調べています…') : t('送っています…（{p}%）', { p: Math.floor(ratio * 100) })) });
                    resolve(result); resolve = () => {};
                    shell.done();
                } catch (e) { shell.say(e.message, true); save.disabled = download.disabled = false; }
            });
            shell.onClose(() => { stopSound(); ctx?.close?.(); if (preview) URL.revokeObjectURL(preview); resolve(null); });
            sync(); shell.open();
        });
    }

    /**
     * Send one video or audio file. onProgress(ratio, phase) reports 'measure' and then 'send' with 0–1.
     * Resolves with the media record (as the image upload does).
     */
    async function upload(file, { endpoint, csrf, onProgress = () => {}, replace = '', revision = 0, dropCover = false } = {}) {
        onProgress(0, 'measure');
        const video = isVideo(file);
        const type = file.type || (video ? 'video/mp4' : 'audio/mpeg');
        const meta = video ? await measureVideo(file) : await measureAudio(file);
        const tags = video ? { cover: meta.cover, loop: null } : await readTags(file), cover = tags.cover;
        const post = async fields => {
            const data = new FormData();
            data.set('csrf', csrf);
            for (const [key, value] of Object.entries(fields)) {
                if (value instanceof Blob) data.set(key, value, key === 'cover' ? 'cover.' + (value.type === 'image/png' ? 'png' : 'jpg') : 'part');
                else data.set(key, String(value));
            }
            const response = await fetch(endpoint, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' } });
            let result;
            try { result = await response.json(); }
            catch { throw new Error(t('ログインが切れた可能性があります。ログインし直してから、もう一度お試しください。')); }
            if (!response.ok || result.error) throw Object.assign(new Error(result.error || t('保存できませんでした。もう一度お試しください。')), { final: response.status === 422 });
            return result;
        };
        const begin = await post({ do: 'log_av_begin', name: file.name, size: file.size, type, media: replace, revision });
        const token = begin.token, piece = begin.chunk;
        let offset = 0;
        onProgress(0, 'send');
        try {
            while (offset < file.size) {
                let result;
                for (let tries = 1; ; tries++) {
                    try { result = await post({ do: 'log_av_chunk', token, offset, chunk: file.slice(offset, offset + piece) }); break; }
                    catch (e) { if (e.final || tries >= 4) throw e; await sleep(1500 * tries); }
                }
                offset = result.received;
                onProgress(offset / file.size, 'send');
            }
            const fields = { do: 'log_av_finish', token, meta: JSON.stringify({ duration: meta.duration || 0, width: meta.width || 0, height: meta.height || 0, peaks: meta.peaks || [], loop: tags.loop, drop_cover: dropCover && !cover }) };
            if (cover) fields.cover = cover;
            return (await post(fields)).media;
        } catch (e) {
            post({ do: 'log_av_cancel', token }).catch(() => {});
            throw e;
        }
    }

    window.NagiLogAV = { isAV, accept, upload, findCover, readTags, writeTags, canWrite, playDialog, tagDialog };
})();
