#!/usr/bin/env python3
"""LOG video and audio: uploads in pieces, type checks, players on the page, seeking (Range), visibility and backups."""
import io
import json
import math
import secrets
import struct
import sys
import wave
import zipfile

import attack_test as helper
from log_test import HERE, RESULTS, install, payload, start

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
ROOT = HERE.parents[1]
checks = []


def check(name, ok):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def wav_bytes(seconds=1.0, rate=8000):
    buf = io.BytesIO()
    with wave.open(buf, 'wb') as w:
        w.setnchannels(1); w.setsampwidth(2); w.setframerate(rate)
        w.writeframes(b''.join(struct.pack('<h', int(12000 * math.sin(i / 8))) for i in range(int(seconds * rate))))
    return buf.getvalue()


def mp4_bytes(size):
    head = b'\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2'
    return head + secrets.token_bytes(size - len(head))


def run(c, csrf, port):
    def post(data, files=None):
        return c.post("/admin/index.php", {"csrf": csrf, **data}, files=files)

    def send(data, name, kind, piece=1024 * 1024, meta=None, cover=None, resend=False):
        r = post({"do": "log_av_begin", "name": name, "size": str(len(data)), "type": kind})
        begin = json.loads(r.text)
        if not begin.get("ok"):
            return begin
        token = begin["token"]
        offset = 0
        while offset < len(data):
            part = data[offset:offset + piece]
            got = json.loads(post({"do": "log_av_chunk", "token": token, "offset": str(offset)}, files={"chunk": ("blob", part, "application/octet-stream")}).text)
            if resend:
                again = json.loads(post({"do": "log_av_chunk", "token": token, "offset": str(offset)}, files={"chunk": ("blob", part, "application/octet-stream")}).text)
                resend = again.get("received") == got.get("received")
                if not resend:
                    return {"error": "resend"}
            offset = got["received"]
        files = {"cover": ("cover.png", cover, "image/png")} if cover else None
        return json.loads(post({"do": "log_av_finish", "token": token, "meta": json.dumps(meta or {})}, files=files).text)

    public = helper.Client(port)
    cover = (ROOT / "img" / "Shimeshime.png").read_bytes()
    audio = wav_bytes()
    r = send(audio, "進捗デモ.wav", "audio/wav", piece=4000, meta={"duration": 1.0, "peaks": [10, 50, 100, 300, -5]}, cover=cover, resend=True)
    a = r.get("media", {})
    check("音声を分割して送れる（送り直しも受け付ける）", r.get("ok") and a.get("kind") == "audio" and a.get("f", "").endswith(".wav"))
    check("曲名はファイル名から（拡張子を除く）", a.get("alt") == "進捗デモ")
    check("波形は0〜100に収める", a.get("peaks") == [10, 50, 100, 100, 0])
    check("ジャケットを画像として保存", a.get("cover", "").endswith((".webp", ".jpg")) and a.get("thumb"))

    begin = json.loads(post({"do": "log_av_begin", "name": "x.wav", "size": str(len(audio)), "type": "audio/wav"}).text)
    skip = json.loads(post({"do": "log_av_chunk", "token": begin["token"], "offset": "100"}, files={"chunk": ("blob", audio[100:200], "application/octet-stream")}).text)
    check("途中を飛ばした一部は受け取らず、続きの位置を返す", skip.get("resend") and skip.get("received") == 0)
    early = json.loads(post({"do": "log_av_finish", "token": begin["token"], "meta": "{}"}).text)
    check("最後まで届く前の完了は断る", "error" in early)
    post({"do": "log_av_cancel", "token": begin["token"]})

    fake = send(b"<?php echo 1; ?>" + b"x" * 200, "evil.mp3", "audio/mpeg")
    check("音声に見せかけたファイルは断る", "error" in fake and "対応していません" in fake["error"])
    check("動画・音声以外の種類は始めから断る", "error" in json.loads(post({"do": "log_av_begin", "name": "a.txt", "size": "10", "type": "text/plain"}).text))
    check("ファイル名の外し方（トークン）を検査", "error" in json.loads(post({"do": "log_av_chunk", "token": "../../x", "offset": "0"}, files={"chunk": ("b", b"x", "application/octet-stream")}).text))

    video = mp4_bytes(2_500_000)
    v = send(video, "clip.MOV", "video/quicktime", meta={"duration": 12.5, "width": 1920, "height": 1080}).get("media", {})
    check("動画（MOV）を MP4 として保存", v.get("kind") == "video" and v.get("f", "").endswith(".mp4") and v.get("vw") == 1920)
    check("表紙のない動画はサムネイルなし", v.get("thumb") == "")

    r = post(payload(f"作業の記録\n今日の進捗です。\n[Image:{a['id']}]\n動画も。\n[Image:{v['id']}]"))
    pid = json.loads(r.text).get("id")
    page = public.get("/?id=" + pid).text
    check("記事に音楽プレーヤー（波形つき）", 'data-player="audio"' in page and 'data-peaks="10,50,100,100,0"' in page and 'log-player-cover' in page)
    check("記事に動画プレーヤー（縦横比つき）", 'data-player="video"' in page and '--player-w:1920;--player-h:1080' in page)
    check("プレーヤーのスクリプトを読み込む", 'log-player.js' in page)

    src = f"/?media={a['id']}&v={a['revision']}&format=media.wav"
    full = public.get(src)
    check("音声を配信（Range 対応を知らせる）", full.status == 200 and full.body == audio and full.getheader("Accept-Ranges") == "bytes" and full.getheader("Content-Type") == "audio/wav")
    part = public.get(src, headers={"Range": "bytes=100-199"})
    check("一部だけ返す（206）", part.status == 206 and part.body == audio[100:200] and part.getheader("Content-Range") == f"bytes 100-199/{len(audio)}")
    tail = public.get(src, headers={"Range": "bytes=-50"})
    check("末尾だけ返す", tail.status == 206 and tail.body == audio[-50:])
    open_end = public.get(f"/?media={v['id']}&v=1&format=media.mp4", headers={"Range": "bytes=2000000-"})
    check("途中から最後まで返す（動画のシーク）", open_end.status == 206 and open_end.body == video[2000000:])
    check("範囲の外は 416", public.get(src, headers={"Range": f"bytes={len(audio) + 10}-"}).status == 416)
    check("ジャケットのサムネイルは画像", public.get(f"/?media={a['id']}&thumb=1").getheader("Content-Type", "").startswith("image/"))
    check("表紙のない動画のサムネイルは 404", public.get(f"/?media={v['id']}&thumb=1").status == 404)

    post({"do": "log_media_alt", "media": a["id"], "revision": str(a["revision"]), "alt": "進捗デモ", "rating": "sensitive"})
    veiled = public.get("/?id=" + pid).text
    check("閲覧注意の音声は折りたたむ", 'log-veil-media" data-veil="sensitive"' in veiled and 'data-player="audio"' in veiled)
    post({"do": "log_media_alt", "media": a["id"], "revision": str(a["revision"] + 1), "alt": "進捗デモ", "rating": ""})
    hidden = send(wav_bytes(0.2), "下書き用.wav", "audio/wav").get("media", {})
    post(payload(f"下書き\n[Image:{hidden['id']}]", status="draft"))
    check("下書きだけの音声は読者に 404", public.get(f"/?media={hidden['id']}").status == 404)
    check("管理画面では下書きの音声も聞ける", c.get(f"/admin/index.php?p=log_image&media={hidden['id']}").status == 200)

    catalog = json.loads(c.get("/admin/index.php?p=log_media_json&page=1&q=").text)["items"]
    kinds = {m["id"]: m.get("kind") for m in catalog}
    check("メディア一覧に種類が入る", kinds.get(a["id"]) == "audio" and kinds.get(v["id"]) == "video")

    r = post({"do": "log_backup"})
    zf = zipfile.ZipFile(io.BytesIO(r.body))
    names = zf.namelist()
    check("バックアップに音声・動画とジャケット", f"media/{a['id']}/{a['f']}" in names and f"media/{v['id']}/{v['f']}" in names and f"media/{a['id']}/{a['cover']}" in names)
    files = {"backup": ("log.zip", r.body, "application/zip")}
    rr = post({"do": "log_restore", "overwrite": "1"}, files=files)
    again = public.get(src.replace(f"v={a['revision']}", "v=9"))
    check("復元後も同じ音声を配信", rr.status in (200, 303) and again.body == audio)
    check("復元後もプレーヤーと波形", 'data-peaks="10,50,100,100,0"' in public.get("/?id=" + pid).text)


def main():
    RESULTS.mkdir(exist_ok=True)
    root = RESULTS / ("log-av-test-" + secrets.token_hex(4))
    root.mkdir()
    site = root / "site"
    helper.copy_server(site, HERE.parent / "server")
    process = start(site, 5197 + 20)
    try:
        c, csrf, _ = install(5217)
        run(c, csrf, 5217)
    finally:
        process.terminate()
        process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == "__main__":
    main()
