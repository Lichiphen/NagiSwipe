#!/usr/bin/env python3
"""
NagiManga: functional + attack simulation tests (standard library only).

Starts two PHP built-in servers on a fresh, throw-away data folder:
  - :5191 with dev/router.php (behaves like Apache with the shipped .htaccess)
  - :5192 without router      (a host that ignores .htaccess)
and runs every scenario against them. Nothing touches dev/data.

    python dev/attack_test.py
"""
import http.client
import io
import json
import os
import re
import secrets
import shutil
import struct
import subprocess
import sys
import time
import urllib.parse
import zipfile
import zlib
from pathlib import Path

HERE = Path(__file__).resolve().parent
SERVER_DIR = HERE.parent / "server"
RESULTS = HERE / "results"
PORT = 5191
PORT_RAW = 5192
HOST = "127.0.0.1"

results = []


def check(name, ok, detail=""):
    results.append((name, bool(ok), detail))
    mark = "PASS" if ok else "FAIL"
    print(f"[{mark}] {name}" + (f"  ({detail})" if detail and not ok else ""))


# ---------------------------------------------------------------------------
# Fixtures (pure python image bytes)
# ---------------------------------------------------------------------------

def png(w, h, seed=0, extra_after_iend=b"", ihdr_override=None):
    """Grayscale PNG with a simple gradient."""
    def chunk(t, data):
        c = struct.pack(">I", len(data)) + t + data
        return c + struct.pack(">I", zlib.crc32(t + data) & 0xFFFFFFFF)
    raw = bytearray()
    for y in range(h):
        raw.append(0)
        raw.extend(((x + y + seed) * 7) & 0xFF for x in range(w))
    iw, ih = ihdr_override or (w, h)
    ihdr = struct.pack(">IIBBBBB", iw, ih, 8, 0, 0, 0, 0)
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr) + chunk(b"IDAT", zlib.compress(bytes(raw)))
            + chunk(b"IEND", b"") + extra_after_iend)


# ---------------------------------------------------------------------------
# HTTP client with a cookie jar and no automatic redirects
# ---------------------------------------------------------------------------

class Client:
    def __init__(self, port=PORT):
        self.port = port
        self.cookies = {}

    def request(self, method, path, body=None, headers=None, files=None, cookies=True):
        headers = dict(headers or {})
        if files is not None:
            boundary = "----nm" + secrets.token_hex(8)
            buf = io.BytesIO()
            for k, v in (body or {}).items():
                buf.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode())
            for k, (fname, data, ctype) in files.items():
                buf.write(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"; filename=\"{fname}\"\r\n"
                          f"Content-Type: {ctype}\r\n\r\n".encode())
                buf.write(data)
                buf.write(b"\r\n")
            buf.write(f"--{boundary}--\r\n".encode())
            payload = buf.getvalue()
            headers["Content-Type"] = f"multipart/form-data; boundary={boundary}"
        elif isinstance(body, dict):
            payload = urllib.parse.urlencode(body).encode()
            headers["Content-Type"] = "application/x-www-form-urlencoded"
        else:
            payload = body
        if cookies and self.cookies:
            headers["Cookie"] = "; ".join(f"{k}={v}" for k, v in self.cookies.items())
        headers.setdefault("User-Agent", "nm-attack-test")
        conn = http.client.HTTPConnection(HOST, self.port, timeout=60)
        conn.request(method, path, body=payload, headers=headers)
        res = conn.getresponse()
        data = res.read()
        for h, v in res.getheaders():
            if h.lower() == "set-cookie":
                name, _, rest = v.partition("=")
                value = rest.split(";", 1)[0]
                if "expires=Thu, 01 Jan 1970" in v or value == "deleted" or "Max-Age=0" in v:
                    self.cookies.pop(name, None)
                else:
                    self.cookies[name] = value
        res.body = data
        res.text = data.decode("utf-8", "replace")
        conn.close()
        return res

    def get(self, path, **kw):
        return self.request("GET", path, **kw)

    def post(self, path, body=None, **kw):
        return self.request("POST", path, body=body, **kw)


def csrf_of(html):
    m = re.search(r'name="csrf" value="([0-9a-f]+)"', html)
    return m.group(1) if m else ""


def is_404(res):
    return res.status == 404 and res.text.strip() == "Not Found"


# ---------------------------------------------------------------------------
# Servers
# ---------------------------------------------------------------------------

def php_cmd(port, router, site):
    php = shutil.which("php")
    ext = str(Path(php).parent / "ext")
    cmd = [php, "-n", "-d", f"extension_dir={ext}"]
    for e in ("gd", "fileinfo", "zip", "mbstring"):
        cmd += ["-d", f"extension={e}"]
    cmd += ["-d", "memory_limit=256M", "-d", "upload_max_filesize=32M", "-d", "post_max_size=40M",
            "-S", f"{HOST}:{port}", "-t", str(site)]
    if router:
        cmd.append(str(HERE / "router.php"))
    return cmd


def start_servers(site):
    # A copy of server/ with its own data/ inside the web root, exactly like a real install
    env = {k: v for k, v in os.environ.items() if k != "NAGIMANGA_DATA"}
    procs = [subprocess.Popen(php_cmd(PORT, True, site), env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL),
             subprocess.Popen(php_cmd(PORT_RAW, False, site), env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)]
    for port in (PORT, PORT_RAW):
        for _ in range(50):
            try:
                http.client.HTTPConnection(HOST, port, timeout=1).request("GET", "/")
                break
            except OSError:
                time.sleep(0.1)
    return procs


# ---------------------------------------------------------------------------
# Scenarios
# ---------------------------------------------------------------------------

def run(data_dir):
    admin_pw = secrets.token_urlsafe(18)
    anon = Client()

    # --- Before setup ---------------------------------------------------------
    check("未セットアップの read.php は 404", is_404(anon.get("/read.php?a=m&id=AAAAAAAAAAAA")))

    # --- Setup ------------------------------------------------------------------
    adm = Client()
    r = adm.get("/admin/index.php")
    check("初回はセットアップ画面が出る", r.status == 200 and "セットアップ" in r.text)
    check("セットアップ画面にサーバーのパスが出ない", str(data_dir) not in r.text and "\\" not in re.sub(r"<[^>]+>", "", r.text))
    r = adm.post("/admin/index.php", {"csrf": "bad", "password": admin_pw, "password2": admin_pw})
    check("CSRF トークンなしのセットアップは拒否", is_404(r))
    r = adm.get("/admin/index.php")
    r = adm.post("/admin/index.php", {"csrf": csrf_of(r.text), "password": "short", "password2": "short"})
    check("短い管理者パスワードは拒否", "10 文字以上" in r.text)
    r = adm.get("/admin/index.php")
    r = adm.post("/admin/index.php", {"csrf": csrf_of(r.text), "password": admin_pw, "password2": admin_pw})
    m = re.search(r'index\.php\?k=([A-Za-z0-9]{24})', r.text)
    check("セットアップ完了でログイン URL が出る", bool(m))
    key = m.group(1) if m else ""
    (RESULTS / "last-run-login-key.txt").write_text(f"{key}\n{admin_pw}\n", encoding="utf-8")

    # --- Admin gate ------------------------------------------------------------------
    c = Client()
    check("鍵なしの管理画面は 404", is_404(c.get("/admin/index.php")))
    check("違う鍵の管理画面は 404", is_404(c.get("/admin/index.php?k=" + "A" * 24)))
    check("セットアップ後にセットアップの POST をしても 404", is_404(c.post("/admin/index.php", {"csrf": "x", "password": "x" * 12, "password2": "x" * 12})))
    check("ログイン前の作品ページは 404", is_404(c.get("/admin/index.php?p=work&id=AAAAAAAAAAAA")))
    check("PUT などのメソッドは 404", is_404(c.request("PUT", "/admin/index.php")) and is_404(c.request("DELETE", "/read.php")))

    r = adm.get(f"/admin/index.php?k={key}")
    check("正しい鍵でログイン画面が出る", r.status == 200 and "ログイン" in r.text)
    r2 =adm.post("/admin/index.php", {"csrf": csrf_of(r.text), "k": key, "password": admin_pw})
    check("ログインできる", r2.status == 303)
    r = adm.get("/admin/index.php")
    check("ログイン後はダッシュボード", r.status == 200 and "作品を作る" in r.text)
    csp = r.getheader("Content-Security-Policy") or ""
    check("管理画面に厳しい CSP", "script-src 'self'" in csp and "frame-ancestors 'none'" in csp)
    check("管理画面は Referrer を送らない", r.getheader("Referrer-Policy") == "no-referrer")
    csrf = csrf_of(r.text)

    # Session cookie flags
    raw = Client()
    rr = raw.get(f"/admin/index.php?k={key}")
    sc = rr.getheader("Set-Cookie") or ""
    check("セッション Cookie は HttpOnly + SameSite=Strict", "HttpOnly" in sc and "SameSite=Strict" in sc)

    # --- CSRF on actions --------------------------------------------------------------
    check("CSRF トークンなしの操作は 404", is_404(adm.post("/admin/index.php", {"do": "create", "title": "x"})))
    check("別サイトからの POST（Origin 不一致）は 404",
          is_404(adm.post("/admin/index.php", {"do": "create", "title": "x", "csrf": csrf}, headers={"Origin": "https://evil.example"})))

    # --- Create work + XSS in title -----------------------------------------------------
    xss = '<script>alert(1)</script>"><img src=x onerror=alert(2)>'
    r = adm.post("/admin/index.php", {"do": "create", "title": xss, "series": "テスト", "direction": "rtl", "csrf": csrf})
    loc = r.getheader("Location") or ""
    wid = (re.search(r"id=([A-Za-z0-9]{12})", loc) or [None, ""])[1]
    check("作品を作成できる", bool(wid))
    r = adm.get("/admin/index.php")
    check("タイトルの XSS はエスケープされる（一覧）", "<script>alert(1)" not in r.text and "&lt;script&gt;" in r.text)
    r = adm.get(f"/admin/index.php?p=work&id={wid}")
    check("タイトルの XSS はエスケープされる（作品ページ）", "<script>alert(1)" not in r.text)

    # --- Uploads -------------------------------------------------------------------------
    def upload(name, data, ctype="image/png"):
        res = adm.post("/admin/index.php", {"do": "upload", "id": wid, "csrf": csrf}, files={"page": (name, data, ctype)},
                       headers={"X-NM-CSRF": csrf})
        try:
            return json.loads(res.text)
        except ValueError:
            return {"ok": False, "status": res.status}

    oks = [upload(f"{i:03d}.png", png(60, 90, seed=i))["ok"] for i in (3, 1, 2)]
    check("正常な PNG を 3 枚アップロード", all(oks))

    poly = png(40, 60, extra_after_iend=b"<?php echo 'PWNED'; system($_GET['c']); ?>")
    res = upload("evil.php.png", poly)
    check("PHP を末尾に仕込んだ PNG は描き直して受理", res.get("ok") is True)

    check("GIF ヘッダ + PHP（偽画像）は拒否", upload("shell.php", b"GIF89a\x01\x00\x01\x00<?php system($_GET['c']); ?>", "image/gif").get("ok") is False)
    check(".htaccess のアップロードは拒否", upload(".htaccess", b"AddType application/x-httpd-php .png\n", "text/plain").get("ok") is False)
    check("SVG（スクリプト入り）は拒否", upload("x.svg", b'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', "image/svg+xml").get("ok") is False)
    check("HTML は拒否", upload("x.html", b"<html><script>alert(1)</script></html>", "image/png").get("ok") is False)
    check("巨大ピクセル画像（展開爆弾）は拒否", upload("bomb.png", png(8, 8, ihdr_override=(30000, 30000))).get("ok") is False)
    check("空ファイルは拒否", upload("empty.png", b"").get("ok") is False)
    check("別作品 ID へのアップロードは 404", is_404(adm.post("/admin/index.php", {"do": "upload", "id": "ZZZZZZZZZZZZ", "csrf": csrf},
                                                             files={"page": ("a.png", png(10, 10), "image/png")})))
    check("パス入り ID（../）は 404", is_404(adm.post("/admin/index.php", {"do": "upload", "id": "../../../etc", "csrf": csrf},
                                                     files={"page": ("a.png", png(10, 10), "image/png")})))

    # Sort by name, then check the manifest order
    adm.post("/admin/index.php", {"do": "sort_name", "id": wid, "csrf": csrf})

    # --- Public manifest ---------------------------------------------------------------
    r = anon.get(f"/read.php?a=m&id={wid}")
    man = json.loads(r.text) if r.status == 200 else {}
    pages = man.get("pages", [])
    check("公開作品のページ一覧が取れる", r.status == 200 and len(pages) == 4)
    check("ページ一覧の JSON で < > はエスケープ", "<script>" not in r.text)
    check("ページがファイル名順（001, 002, 003, evil）", [p["w"] for p in pages][:3] == [60, 60, 60])
    if pages:
        img = anon.get("/" + pages[0]["src"])
        check("公開画像が取れる（WebP, nosniff）", img.status == 200 and img.getheader("Content-Type") in ("image/webp", "image/jpeg")
              and img.getheader("X-Content-Type-Options") == "nosniff")
        check("公開画像は長期キャッシュ", "immutable" in (img.getheader("Cache-Control") or ""))
        stored = b"".join(anon.get("/" + p["src"]).body for p in pages)
        check("保存画像に PHP コードが残っていない", b"<?php" not in stored and b"PWNED" not in stored)

    # --- read.php input fuzz -----------------------------------------------------------
    fuzz = [
        "/read.php", "/read.php?a=m", "/read.php?a=m&id=../../config", "/read.php?a=m&id=" + "A" * 13,
        f"/read.php?a=i&id={wid}&f=../work.json", f"/read.php?a=i&id={wid}&f=..%2Fwork.json",
        f"/read.php?a=i&id={wid}&f=%00.webp", f"/read.php?a=i&id={wid}&f=p0001_deadbeef.webp",
        f"/read.php?a=x&id={wid}", f"/read.php?a[]=m&id={wid}", f"/read.php?a=m&id[]={wid}",
        "/read.php?a=m&id=%C0%AFAAAAAAAAAA", "/read.php?a=m&id=" + "%FF" * 12,
    ]
    check("read.php の不正入力はすべて同じ 404", all(is_404(anon.get(u)) for u in fuzz))
    check("read.php の unlock は公開作品では 404", is_404(anon.post("/read.php", {"a": "u", "id": wid, "password": "x"})))

    # --- Direct file access ----------------------------------------------------------------
    direct = ["/data/config.php", "/data/", "/data/works/", "/data/logs/security.log", "/lib/bootstrap.php",
              "/lib/", "/admin/.htaccess", "/.htaccess", "/data/probe.txt"]
    check("data / lib / .htaccess への直接アクセスは拒否", all(anon.get(u).status in (403, 404) for u in direct))

    raw = Client(PORT_RAW)
    r = raw.get("/data/config.php")
    check(".htaccess 無効でも config.php は中身を出さない", r.status == 404 and "secret" not in r.text)
    r = raw.get("/lib/bootstrap.php")
    check(".htaccess 無効でも lib/*.php は 404", r.status == 404 and r.text.strip() == "")
    r = raw.get(f"/data/works/{wid}/work.json")
    # Apache: 404. The PHP built-in server answers 200 with the parent's empty index.html
    check(".htaccess 無効でも公開 ID から作品フォルダを推測できない", '"pages"' not in r.text and len(r.body) == 0 or r.status == 404)
    r = raw.get("/data/works/")
    check(".htaccess 無効でもフォルダ一覧は出ない", "work.json" not in r.text and r.status in (200, 403, 404) and len(r.text) < 50)
    r = raw.get("/data/probe.txt")
    check("（参考）.htaccess 無効時は probe.txt が見える → 管理画面で警告", r.status == 200 and "nm-probe" in r.text)

    # --- Password protection ---------------------------------------------------------------
    work_pw = "yomu-" + secrets.token_hex(3)
    adm.post("/admin/index.php", {"do": "setpw", "id": wid, "password": work_pw, "csrf": csrf})
    r = anon.get(f"/read.php?a=m&id={wid}")
    locked = json.loads(r.text)
    check("パスワード付き作品はページ一覧を返さない", locked.get("locked") is True and "pages" not in locked)
    if pages:
        check("パスワード付き作品の画像は鍵なしで 404", is_404(anon.get("/" + pages[0]["src"].split("&t=")[0])))
    r = anon.post("/read.php", {"a": "u", "id": wid, "password": "wrong"})
    check("違うパスワードは 403", r.status == 403)
    r = anon.post("/read.php", {"a": "u", "id": wid, "password": work_pw})
    tok = json.loads(r.text).get("token", "")
    check("正しいパスワードで閲覧用の鍵が出る", r.status == 200 and bool(tok))
    r = anon.get(f"/read.php?a=m&id={wid}&t={urllib.parse.quote(tok)}")
    pages2 = json.loads(r.text).get("pages", [])
    check("鍵付きでページ一覧が取れる", len(pages2) == 4)
    if pages2:
        img = anon.get("/" + pages2[0]["src"])
        check("鍵付きで画像が取れる（private キャッシュ）", img.status == 200 and "private" in (img.getheader("Cache-Control") or ""))
    exp = int(time.time()) + 3600
    forged = [f"{exp}." + "A" * 43, "9999999999." + "A" * 43, tok[:-1] + ("A" if tok[-1] != "A" else "B"),
              f"{int(time.time()) - 10}." + tok.split(".")[1], tok + "x", ""]
    check("偽造・改ざん・期限切れの鍵はすべて拒否",
          all(json.loads(anon.get(f"/read.php?a=m&id={wid}&t={urllib.parse.quote(t)}").text).get("locked") for t in forged))

    # Token of work A must not open work B
    r = adm.post("/admin/index.php", {"do": "create", "title": "B", "direction": "ltr", "csrf": csrf})
    wid_b = (re.search(r"id=([A-Za-z0-9]{12})", r.getheader("Location") or "") or [None, ""])[1]
    upload_b = adm.post("/admin/index.php", {"do": "upload", "id": wid_b, "csrf": csrf}, files={"page": ("1.png", png(30, 40), "image/png")})
    adm.post("/admin/index.php", {"do": "setpw", "id": wid_b, "password": "other-" + secrets.token_hex(3), "csrf": csrf})
    r = anon.get(f"/read.php?a=m&id={wid_b}&t={urllib.parse.quote(tok)}")
    check("作品 A の鍵で作品 B は開けない", json.loads(r.text).get("locked") is True)

    # Changing the password revokes old tokens
    adm.post("/admin/index.php", {"do": "setpw", "id": wid, "password": work_pw + "2", "csrf": csrf})
    r = anon.get(f"/read.php?a=m&id={wid}&t={urllib.parse.quote(tok)}")
    check("パスワード変更で古い鍵は無効", json.loads(r.text).get("locked") is True)

    # Brute force on the reader password, spoofing X-Forwarded-For
    statuses = [anon.post("/read.php", {"a": "u", "id": wid, "password": f"guess{i}"},
                          headers={"X-Forwarded-For": f"10.0.0.{i}"}).status for i in range(7)]
    check("閲覧パスワードの総当たりは 5 回で止まる（XFF 偽装も無効）", statuses[-1] == 429 and 429 in statuses[4:])
    r = anon.post("/read.php", {"a": "u", "id": wid, "password": work_pw + "2"})
    check("ロック中は正しいパスワードでも入れない", r.status == 429)

    # --- Page operations with bad input -----------------------------------------------------
    r = adm.post("/admin/index.php", {"do": "order", "id": wid, "csrf": csrf, "order": json.dumps(["../../config.php"])})
    check("並び順に他のファイル名を混ぜても拒否", r.status == 400)
    r = adm.post("/admin/index.php", {"do": "delpage", "id": wid, "csrf": csrf, "f": "../work.json"})
    r = adm.get(f"/admin/index.php?p=work&id={wid}")
    check("delpage に ../ を渡しても何も消えない", r.status == 200 and r.text.count('class="page"') == 4)
    check("管理画面の画像取得で ../ は 404", is_404(adm.get(f"/admin/index.php?p=img&id={wid}&f=../../config.php")))

    # --- Backup / restore ----------------------------------------------------------------------
    r = adm.post("/admin/index.php", {"do": "backup", "csrf": csrf})
    ok_zip = r.status == 200 and r.getheader("Content-Type") == "application/zip"
    check("バックアップ ZIP をダウンロードできる", ok_zip)
    good_zip = r.body if ok_zip else b""
    if ok_zip:
        with zipfile.ZipFile(io.BytesIO(good_zip)) as z:
            names = z.namelist()
        check("バックアップに設定（管理パスワード・秘密鍵）が入らない", not any("config" in n for n in names))

    def restore(data, overwrite=True):
        body = {"do": "restore", "csrf": csrf}
        if overwrite:
            body["overwrite"] = "1"
        adm.post("/admin/index.php", body, files={"backup": ("b.zip", data, "application/zip")})
        return adm.get("/admin/index.php?p=backup").text

    def evil_zip(entries):
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as z:
            z.writestr("nagimanga-backup.json", "{}")
            for n, d in entries:
                z.writestr(n, d)
        return buf.getvalue()

    txt = restore(evil_zip([("../../../evil.php", "<?php echo 1;"), ("works/../../evil2.php", "<?php echo 2;")]))
    check("ZIP スリップ（../）は展開されない", not (data_dir.parent / "evil.php").exists() and not (data_dir.parent.parent / "evil.php").exists()
          and not list(data_dir.rglob("evil*.php")))
    fake = {"id": "QQQQQQQQQQQQ", "title": "x", "pages": [{"f": "p0001_deadbeef.webp", "w": 1, "h": 1}]}
    txt = restore(evil_zip([("works/QQQQQQQQQQQQ/work.json", json.dumps(fake)),
                            ("works/QQQQQQQQQQQQ/pages/p0001_deadbeef.webp", "<?php system($_GET['c']); ?>")]))
    check("画像に偽装した PHP を含む ZIP は復元しない", "画像の中身が正しくありません" in txt and not list(data_dir.rglob("p0001_deadbeef.webp")))
    txt = restore(evil_zip([("works/RRRRRRRRRRRR/work.json", json.dumps({"id": "RRRRRRRRRRRR", "title": "y", "pages": []})),
                            ("works/RRRRRRRRRRRR/shell.php", "<?php echo 1;"), ("works/RRRRRRRRRRRR/.htaccess", "x")]))
    check("決まった形以外のファイルは無視", not list(data_dir.rglob("shell.php")) and not [p for p in data_dir.rglob(".htaccess") if "works" in str(p)])
    bomb = io.BytesIO()
    with zipfile.ZipFile(bomb, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("nagimanga-backup.json", "{}")
        z.writestr("works/SSSSSSSSSSSS/work.json", b"{" + b" " * (70 * 1024 * 1024) + b"}")
    txt = restore(bomb.getvalue())
    check("ZIP 爆弾（展開すると巨大）は拒否", "大きすぎる" in txt)
    check("NagiManga 以外の ZIP は拒否", "バックアップではありません" in restore(b"PK\x05\x06" + b"\x00" * 18) or "ZIP として開けません" in restore(b"PK\x05\x06" + b"\x00" * 18))
    if good_zip:
        adm.post("/admin/index.php", {"do": "delete", "id": wid, "confirm": xss, "csrf": csrf})
        gone = is_404(anon.get(f"/read.php?a=m&id={wid}"))
        txt = restore(good_zip)
        back = anon.get(f"/read.php?a=m&id={wid}")
        check("削除した作品をバックアップから復元できる", gone and back.status == 200 and json.loads(back.text).get("locked") is True)

    # --- Settings guard ---------------------------------------------------------------------------
    r = adm.post("/admin/index.php", {"do": "settings_access", "csrf": csrf, "allowed_ips": "203.0.113.10", "allowed_origins": ""})
    r = adm.get("/admin/index.php?p=settings")
    check("自分の IP を含まない IP 制限は保存できない（締め出し防止）", "含まれていないため" in r.text)
    r = adm.post("/admin/index.php", {"do": "settings_access", "csrf": csrf, "allowed_ips": "not-an-ip", "allowed_origins": ""})
    check("不正な IP 表記は保存できない", "正しくありません" in adm.get("/admin/index.php?p=settings").text)

    # IP allow list really blocks (write config directly, like FTP)
    cfg_file = data_dir / "config.php"
    original = cfg_file.read_text(encoding="utf-8")
    changed = re.sub(r"'allowed_ips' =>\s*array \(\s*\)", "'allowed_ips' => array ( 0 => '203.0.113.10' )", original)
    check("（準備）設定ファイルの IP 制限を書き換えられた", changed != original)
    cfg_file.write_text(changed, encoding="utf-8")
    blocked = is_404(adm.get("/admin/index.php"))
    spoof = is_404(adm.get("/admin/index.php", headers={"X-Forwarded-For": "203.0.113.10", "Client-IP": "203.0.113.10"}))
    cfg_file.write_text(original, encoding="utf-8")
    check("許可 IP 以外は管理画面が 404（ログイン済みでも）", blocked)
    check("X-Forwarded-For 偽装で IP 制限を抜けられない", spoof)

    # --- Admin login brute force (last: it locks this IP) ------------------------------------------
    b = Client()
    locked_at = None
    for i in range(8):
        r = b.get(f"/admin/index.php?k={key}")
        if is_404(r):
            locked_at = i
            break
        b.post("/admin/index.php", {"csrf": csrf_of(r.text), "k": key, "password": f"wrong{i}"})
    check("管理者ログインの総当たりは 5 回でロック", locked_at == 5, f"locked_at={locked_at}")
    r = b.get(f"/admin/index.php?k={key}")
    check("ロック中はログイン画面自体が 404", is_404(r))

    log = (data_dir / "logs" / "security.log").read_text(encoding="utf-8") if (data_dir / "logs" / "security.log").exists() else ""
    check("セキュリティログに記録される", "login_failed" in log and "unlock_failed" in log and "upload_rejected" in log)


def main():
    RESULTS.mkdir(exist_ok=True)
    site = RESULTS / ("site-" + time.strftime("%Y%m%d-%H%M%S"))
    shutil.copytree(SERVER_DIR, site, ignore=shutil.ignore_patterns("paths.php"))
    data_dir = site / "data"
    procs = start_servers(site)
    try:
        run(data_dir)
    finally:
        for p in procs:
            p.terminate()
    passed = sum(1 for _, ok, _ in results if ok)
    lines = [f"# NagiManga attack test ({time.strftime('%Y-%m-%d %H:%M')})", "", f"{passed} / {len(results)} passed", "",
             "| 結果 | テスト |", "|---|---|"]
    lines += [f"| {'PASS' if ok else '**FAIL**'} | {name}{(' — ' + d) if d and not ok else ''} |" for name, ok, d in results]
    (RESULTS / "attack-report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"\n{passed} / {len(results)} passed")
    sys.exit(0 if passed == len(results) else 1)


if __name__ == "__main__":
    main()
