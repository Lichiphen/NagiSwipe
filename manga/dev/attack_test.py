#!/usr/bin/env python3
"""
NagiManga: functional + attack simulation tests (standard library only).

Starts two PHP built-in servers on a fresh, throw-away data folder:
  - :5191 with dev/router.php (behaves like Apache with the shipped .htaccess)
  - :5192 without router      (a host that ignores .htaccess)
and runs every scenario against them. Nothing touches dev/data.

    python dev/attack_test.py
"""
import hashlib
import http.client
import http.cookies
import http.server
import io
import json
import os
import re
import secrets
import shutil
import struct
import subprocess
import sys
import threading
import time
import urllib.parse
import zipfile
import zlib
from email.utils import parsedate_to_datetime
from pathlib import Path

HERE = Path(__file__).resolve().parent
SERVER_DIR = HERE.parent / "server"
RESULTS = HERE / "results"
PORT = 5191
PORT_RAW = 5192
MOCK_PORT = 5193   # stands in for GitHub in the self-update tests
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
        self._cookie_jar = {}

    @property
    def cookies(self):
        """Name-only compatibility view for copying authenticated test clients."""
        self._expire_cookies()
        return {name: record["value"] for (name, _), record in self._cookie_jar.items()}

    @cookies.setter
    def cookies(self, values):
        # Existing parallel-save fixtures explicitly import an owner's session.
        self._cookie_jar = {(name, "/"): {"value": value, "expires": None, "secure": False}
                            for name, value in dict(values).items()}

    def _expire_cookies(self):
        now = time.time()
        for key, record in list(self._cookie_jar.items()):
            if record["expires"] is not None and record["expires"] <= now:
                del self._cookie_jar[key]

    @staticmethod
    def _default_cookie_path(request_path):
        path = urllib.parse.urlsplit(request_path).path
        if not path.startswith("/"):
            return "/"
        return path.rsplit("/", 1)[0] or "/"

    def _store_cookie(self, header, request_path):
        parsed = http.cookies.SimpleCookie()
        try:
            parsed.load(header)
        except http.cookies.CookieError:
            return
        for name, morsel in parsed.items():
            domain = morsel["domain"].lstrip(".").lower()
            if domain and domain != HOST.lower():
                continue
            cookie_path = morsel["path"]
            if not cookie_path.startswith("/"):
                cookie_path = self._default_cookie_path(request_path)
            expires = None
            if morsel["max-age"]:
                try:
                    expires = time.time() + int(morsel["max-age"])
                except ValueError:
                    pass
            elif morsel["expires"]:
                try:
                    expires = parsedate_to_datetime(morsel["expires"]).timestamp()
                except (ValueError, TypeError, OverflowError):
                    pass
            key = (name, cookie_path)
            if expires is not None and expires <= time.time():
                self._cookie_jar.pop(key, None)
            else:
                self._cookie_jar[key] = {"value": morsel.value, "expires": expires, "secure": bool(morsel["secure"])}

    def _cookie_header(self, request_path):
        self._expire_cookies()
        path = urllib.parse.urlsplit(request_path).path or "/"
        matched = []
        for (name, cookie_path), record in self._cookie_jar.items():
            path_matches = path == cookie_path or (path.startswith(cookie_path)
                           and (cookie_path.endswith("/") or path[len(cookie_path):].startswith("/")))
            # These test servers use HTTP; browsers do not send Secure cookies.
            if path_matches and not record["secure"]:
                matched.append((len(cookie_path), name, record["value"]))
        matched.sort(key=lambda item: item[0], reverse=True)
        return "; ".join(f"{name}={value}" for _, name, value in matched)

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
        if cookies:
            cookie_header = self._cookie_header(path)
            if cookie_header:
                headers["Cookie"] = cookie_header
        headers.setdefault("User-Agent", "nm-attack-test")
        conn = http.client.HTTPConnection(HOST, self.port, timeout=60)
        conn.request(method, path, body=payload, headers=headers)
        res = conn.getresponse()
        data = res.read()
        for h, v in res.getheaders():
            if h.lower() == "set-cookie":
                self._store_cookie(v, path)
        res.body = data
        res.text = data.decode("utf-8", "replace")
        conn.close()
        return res

    def get(self, path, **kw):
        return self.request("GET", path, **kw)

    def post(self, path, body=None, **kw):
        return self.request("POST", path, body=body, **kw)


def jpeg_size(data):
    """(width, height) of a JPEG, or None."""
    i = 2
    while i + 9 < len(data):
        if data[i] != 0xFF:
            return None
        marker, length = data[i + 1], struct.unpack(">H", data[i + 2:i + 4])[0]
        if marker in (0xC0, 0xC1, 0xC2):
            h, w = struct.unpack(">HH", data[i + 5:i + 9])
            return w, h
        i += 2 + length
    return None


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
    for e in ("gd", "fileinfo", "zip", "mbstring", "sodium", "openssl"):
        cmd += ["-d", f"extension={e}"]
    cmd += ["-d", "memory_limit=256M", "-d", "upload_max_filesize=32M", "-d", "post_max_size=40M",
            "-S", f"{HOST}:{port}", "-t", str(site)]
    if router:
        cmd.append(str(HERE / "router.php"))
    return cmd


def start_servers(site):
    # A copy of server/ with its own data/ inside the web root, exactly like a real install
    env = {k: v for k, v in os.environ.items() if k != "NAGIMANGA_DATA"}
    env["NAGIMANGA_UPDATE_API"] = f"http://{HOST}:{MOCK_PORT}/releases"
    env["NAGIMANGA_UPDATE_DL"] = f"http://{HOST}:{MOCK_PORT}/dl/"
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

# ---------------------------------------------------------------------------
# Mock GitHub for the self-update (release list + package downloads)
# ---------------------------------------------------------------------------

MOCK = {"releases": b"[]", "files": {}}


class MockGitHub(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        name = self.path[len("/dl/"):] if self.path.startswith("/dl/") else ""
        if self.path.startswith("/releases"):
            body, ctype = MOCK["releases"], "application/json"
        elif name in MOCK["files"]:
            body, ctype = MOCK["files"][name], "application/zip"
        else:
            self.send_response(404)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


def start_mock():
    srv = http.server.ThreadingHTTPServer((HOST, MOCK_PORT), MockGitHub)
    threading.Thread(target=srv.serve_forever, daemon=True).start()
    return srv


def make_package(version, marker="", extra=None, drop=()):
    """A release package like nagimanga-vX.Y.Z.zip, built from the pristine server/ folder."""
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", zipfile.ZIP_DEFLATED) as z:
        for f in sorted(SERVER_DIR.rglob("*")):
            rel = f.relative_to(SERVER_DIR).as_posix()
            if not f.is_file() or rel in drop or rel == "lib/paths.php":
                continue
            if rel.startswith("data/") and rel not in ("data/.htaccess", "data/index.html"):
                continue
            if rel.startswith("plugins/") and rel not in ("plugins/.htaccess", "plugins/index.html"):
                continue
            data = f.read_bytes()
            if rel == "lib/bootstrap.php":
                data = re.sub(rb"const NM_VERSION = '[0-9.]+';", f"const NM_VERSION = '{version}';".encode(), data)
            if rel == "viewer/NagiManga.js" and marker:
                data += ("\n" + marker + "\n").encode()
            z.writestr("nagimanga/" + rel, data)
        for name in ('NagiSwipe-main.js', 'NagiSwipe-main.css'):
            if 'viewer/' + name not in drop:
                z.write(SERVER_DIR.parents[1] / name, 'nagimanga/viewer/' + name)
        z.writestr("README.txt", "readme")
        z.writestr("optional/guest-mode.php", "<?php // optional")
        for name, data in (extra or {}).items():
            z.writestr(zipfile.ZipInfo(name), data)
    return buf.getvalue()


def publish(version, pkg, digest=None):
    name = f"nagimanga-v{version}.zip"
    MOCK["files"][name] = pkg
    d = "sha256:" + hashlib.sha256(pkg).hexdigest() if digest is None else digest
    MOCK["releases"] = json.dumps([{
        "tag_name": "v9.0.0", "draft": False, "prerelease": False,
        "body": "テスト用のリリース<script>alert(1)</script>", "published_at": "2026-09-27T00:00:00Z",
        "html_url": "https://github.com/Lichiphen/NagiSwipe/releases/tag/v9.0.0",
        "assets": [{"name": name, "size": len(pkg), "digest": d,
                    "browser_download_url": f"http://{HOST}:{MOCK_PORT}/dl/{name}"}],
    }]).encode()


def run_update(adm, csrf, data_dir):
    site = data_dir.parent
    cur = re.search(r"const NM_VERSION = '([0-9.]+)'", (SERVER_DIR / "lib" / "bootstrap.php").read_text(encoding="utf-8")).group(1)

    def program():
        return {p.relative_to(site).as_posix(): p.read_bytes() for p in site.rglob("*")
                if p.is_file() and not p.relative_to(site).as_posix().startswith("data/")}

    def data_files():
        return {p.relative_to(data_dir).as_posix(): p.read_bytes() for p in data_dir.rglob("*")
                if p.is_file() and p.relative_to(data_dir).parts[0] in ("works", "config.php")}

    def apply():
        adm.post("/admin/index.php", {"do": "update_apply", "csrf": csrf})
        return adm.get("/admin/index.php?p=update").text

    def check_now():
        adm.post("/admin/index.php", {"do": "update_check", "csrf": csrf})

    before, data_before = program(), data_files()

    publish(cur, make_package(cur))
    check_now()
    r = adm.get("/admin/index.php?p=update")
    check("更新: 最新なら「最新のバージョンです」と出て、更新ボタンは出ない", "最新のバージョンです" in r.text and 'value="update_apply"' not in r.text)

    marker = "/* nm-update-test */"
    good = make_package("9.9.9", marker=marker)
    publish("9.9.9", good)
    check_now()
    r = adm.get("/admin/index.php")
    check("更新: 新しいバージョンがあると、作品一覧にお知らせ、メニューに「新」が出る",
          "update-banner" in r.text and "9.9.9" in r.text and "badge new" in r.text)
    r = adm.get("/admin/index.php?p=update")
    check("更新: リリースノートは文字として出る（HTML は効かない）",
          'value="update_apply"' in r.text and "<script>alert(1)" not in r.text and "&lt;script&gt;alert(1)" in r.text)
    check("更新: CSRF トークンなしの更新は 404（何も変わらない）",
          is_404(adm.post("/admin/index.php", {"do": "update_apply"})) and program() == before)

    publish("9.9.9", good, digest="sha256:" + "0" * 64)
    t = apply()
    check("更新: 確認用の値（SHA-256）が合わない更新ファイルは使わない", "正しくない" in t and program() == before)
    publish("9.9.9", good, digest="")
    t = apply()
    check("更新: 確認用の値がない更新ファイルは使わない", "確認用の値" in t and program() == before)

    bad_packages = {
        "フォルダの外へ書く（../）": make_package("9.9.9", extra={"nagimanga/../evil.php": b"<?php echo 'PWNED';"}),
        "フォルダの外へ書く（lib/../../）": make_package("9.9.9", extra={"nagimanga/lib/../../evil2.php": b"<?php echo 1;"}),
        "実行できる別の拡張子（.phtml）": make_package("9.9.9", extra={"nagimanga/admin/shell.phtml": b"<?php system($_GET['c']);"}),
        "隠しファイル（.user.ini）": make_package("9.9.9", extra={"nagimanga/.user.ini": b"auto_prepend_file=x"}),
        "バージョンが一致しない": make_package("9.9.8"),
        "必要なファイルがない": make_package("9.9.9", drop=("read.php",)),
        "LOGの保存処理がない": make_package("9.9.9", drop=("lib/log.php",)),
        "画像ビューアーがない": make_package("9.9.9", drop=("viewer/NagiSwipe-main.js",)),
    }
    for label, pkg in bad_packages.items():
        publish("9.9.9", pkg)
        t = apply()
        check(f"更新: 不正な更新ファイルは拒否（{label}）",
              program() == before and "更新しました" not in t and not (site.parent / "evil.php").exists()
              and not (site / "evil2.php").exists() and not list(site.rglob("*.phtml")))

    pkg = make_package("9.9.9", marker=marker, extra={
        "nagimanga/data/config.php": b"<?php return ['admin_hash' => ''];",
        "nagimanga/data/works/x.php": b"<?php echo 1;",
        "nagimanga/plugins/evil.php": b"<?php echo 1;",
        "nagimanga/lib/paths.php": b"<?php return '/tmp';",
    })
    publish("9.9.9", pkg)
    t = apply()
    viewer = (site / "viewer" / "NagiManga.js").read_text(encoding="utf-8")
    check("更新: 正しい更新ファイルなら更新される（ビューアーとバージョン表示）", marker in viewer and "NagiManga 9.9.9" in t,
          " / ".join(re.findall(r'class="flash[^"]*">([^<]*)', t)) or t[:300])
    check("更新: 作品と設定（data フォルダ）は 1 バイトも変わらない", data_files() == data_before)
    check("更新: 更新ファイルの data/・plugins/・lib/paths.php は無視される",
          not (site / "plugins" / "evil.php").exists() and not (site / "lib" / "paths.php").exists()
          and not (data_dir / "works" / "x.php").exists())
    kept = [p for p in (data_dir / "update").rglob("*") if p.is_file()]
    check("更新: 更新前のファイルは data の中に .bak として保管され、PHP のまま置かれない",
          any(p.suffix == ".bak" for p in kept) and not any(p.suffix == ".php" for p in kept))
    bak = next((p for p in kept if p.name == "read.php.bak"), None)
    check("更新: 保管したファイルは外から見えない（.htaccess が効くサーバー）",
          bak is not None and Client().get("/data/" + bak.relative_to(data_dir).as_posix()).status in (403, 404))
    check("更新: 更新後はお知らせが消える", "update-banner" not in adm.get("/admin/index.php").text)
    new_ver = hashlib.sha256((site / "viewer" / "NagiManga.js").read_bytes() + (site / "viewer" / "NagiManga.css").read_bytes()).hexdigest()[:12]
    embed = adm.get("/admin/index.php?p=embed").text
    check("更新: 設置用コード画面に新しいキャッシュバスターが出る",
          f"NagiManga.js?v={new_ver}" in embed and "nagimanga" in embed and "NagiSwipe-main.js" in embed)
    check("更新: ログに残る", "update_applied" in (data_dir / "logs" / "security.log").read_text(encoding="utf-8"))

    r = adm.get("/admin/index.php?p=update")
    m = re.search(r'name="backup" value="([^"]+)"', r.text)
    adm.post("/admin/index.php", {"do": "update_rollback", "backup": m.group(1) if m else "", "csrf": csrf})
    check("更新: 「元に戻す」で更新前のファイルにすべて戻る", bool(m) and program() == before)
    check("更新: 元に戻したら、その元に戻すデータは消える", bool(m) and not (data_dir / "update" / m.group(1)).exists())
    adm.post("/admin/index.php", {"do": "update_rollback", "backup": "../../config", "csrf": csrf})
    check("更新: 元に戻す先に変な名前を渡しても何も起きない", program() == before and data_files() == data_before)

    publish("9.9.9", good)
    adm.post("/admin/index.php", {"do": "update_auto", "csrf": csrf})
    check_now()
    off = "update-banner" not in adm.get("/admin/index.php").text
    adm.post("/admin/index.php", {"do": "update_auto", "auto": "1", "csrf": csrf})
    on = "update-banner" in adm.get("/admin/index.php").text
    check("更新: 自動の確認をオフにすると、作品一覧にお知らせを出さない", off and on)


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

    # --- Idle timeout, then log in again (the first attempt used to fail) -------------------
    idle = Client()
    r = idle.get(f"/admin/index.php?k={key}")
    idle.post("/admin/index.php", {"csrf": csrf_of(r.text), "k": key, "password": admin_pw})
    sess = data_dir / "sessions" / f"sess_{idle.cookies.get('nm_admin', '')}"
    if sess.exists():
        sess.write_text(re.sub(r"nm_seen\|i:\d+", "nm_seen|i:1000", sess.read_text()))
    expired = is_404(idle.get("/admin/index.php"))
    r = idle.get(f"/admin/index.php?k={key}")
    r = idle.post("/admin/index.php", {"csrf": csrf_of(r.text), "k": key, "password": admin_pw})
    check("30 分放置で自動ログアウトし、そのあと 1 回目でログインし直せる",
          sess.exists() is False and expired and r.status == 303 and "作品を作る" in idle.get("/admin/index.php").text)

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
    r = adm.get(f"/admin/index.php?p=work&id={wid}", headers={"Host": "notebook.example.test"})
    check("共有タグの URL は開いているドメインから自動で作る", 'data-endpoint="http://notebook.example.test/read.php"' in r.text
          and "http://notebook.example.test/viewer/NagiManga.js?v=" in r.text)
    check("共有タグの欄に URL 形式（HTML を書けない場所向け）もある", "js-share-url" in r.text)
    r = adm.get(f"/admin/index.php?p=work&id={wid}", headers={"Host": "evil.example\"><script>"})
    check("おかしな Host ヘッダーはタグに入らない", "<script>\"" not in r.text and 'evil.example"' not in r.text)
    adm.post("/admin/index.php", {"do": "settings_general", "csrf": csrf, "base_url": "https://cdn.example.test/manga",
                                  "image_quality": "90", "max_upload_mb": "30"})
    r = adm.get(f"/admin/index.php?p=work&id={wid}")
    check("設置 URL を設定すると、そちらが優先される", 'data-endpoint="https://cdn.example.test/manga/read.php"' in r.text)
    adm.post("/admin/index.php", {"do": "settings_general", "csrf": csrf, "base_url": "", "image_quality": "90", "max_upload_mb": "30"})

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

    # --- Share URL (read.php?nagimanga=ID) opened on its own --------------------------------
    r = anon.get(f"/read.php?nagimanga={wid}&dir=ltr&view=single")
    body = r.body.decode("utf-8", "replace")
    csp = r.getheader("Content-Security-Policy") or ""
    check("共有 URL を直接開くと、ビューアーを開くページが返る",
          r.status == 200 and f'data-nagimanga="{wid}"' in body and 'data-direction="ltr"' in body
          and 'data-view="single"' in body and "viewer/NagiManga.js?v=" in body)
    check("共有 URL のページはインラインスクリプトを持たず、CSP で禁止している",
          "script-src 'self'" in csp and "frame-ancestors 'none'" in csp and not re.search(r"<script(?![^>]*\bsrc=)", body))
    r = anon.get(f"/read.php?nagimanga={wid}&dir=%22%3E%3Cscript%3Ealert(1)%3C/script%3E&view=%22onmouseover=x&cover=%3Cb%3E")
    body = r.body.decode("utf-8", "replace")
    check("共有 URL の読み方などの値は決まった値だけ使う（書き込みを反映しない）",
          r.status == 200 and "onmouseover" not in body and "<b>" not in body
          and not any(a in body for a in ("data-direction", "data-view", "data-cover")))
    share_bad = ["/read.php?nagimanga=", "/read.php?nagimanga=../../config", "/read.php?nagimanga=" + "A" * 12,
                 f"/read.php?nagimanga[]={wid}", "/read.php?nagimanga=%C0%AFAAAAAAAAAA"]
    check("共有 URL の不正な ID・存在しない作品は同じ 404", all(is_404(anon.get(u)) for u in share_bad))
    check("共有 URL の形でも a= が付けば今までどおりの処理（不正なら 404）",
          is_404(anon.get(f"/read.php?nagimanga={wid}&a=x")))

    # --- Individual page: link card, switch, back button, hotlink protection ------------------
    r = anon.get(f"/read.php?nagimanga={wid}")
    og = dict(re.findall(r'<meta (?:property|name)="((?:og|twitter):[a-z:]+)" content="([^"]*)"', r.text))
    check("個別ページ: リンクのカード（OGP）のタグがある", og.get("og:title") and og.get("twitter:card") == "summary_large_image"
          and "a=o&amp;id=" + wid in og.get("og:image", "") and f"nagimanga={wid}" in og.get("og:url", ""))
    check("個別ページ: 表紙・読む・戻る（自動は最初は隠す）がある",
          'class="cover"' in r.text and 'id="nm-open"' in r.text and 'data-back="auto" hidden' in r.text)
    img = anon.get(f"/read.php?a=o&id={wid}")
    check("個別ページ: カード用の表紙は 1200x630 の JPEG", img.status == 200 and img.getheader("Content-Type") == "image/jpeg"
          and jpeg_size(img.body) == (1200, 630))
    check("個別ページ: カード用の表紙はどのサイトからでも取れる（CORP cross-origin）",
          img.getheader("Cross-Origin-Resource-Policy") == "cross-origin")

    adm.post("/admin/index.php", {"do": "settings_page", "page_back": "javascript:alert(1)", "csrf": csrf})
    check("個別ページ: 戻る先に javascript: などは保存できない", "javascript:" not in (data_dir / "config.php").read_text(encoding="utf-8"))
    adm.post("/admin/index.php", {"do": "settings_page", "page_back": "https://blog.example.test/", "csrf": csrf})
    r = anon.get(f"/read.php?nagimanga={wid}")
    check("個別ページ: 戻る先を決めると、そのリンクになる", 'id="nm-back" href="https://blog.example.test/"' in r.text)
    adm.post("/admin/index.php", {"do": "settings_page", "page_back": "", "csrf": csrf})

    adm.post("/admin/index.php", {"do": "page_public", "id": wid, "csrf": csrf})
    check("個別ページ: 非公開にすると、個別ページとカード用の表紙は 404",
          is_404(anon.get(f"/read.php?nagimanga={wid}")) and is_404(anon.get(f"/read.php?a=o&id={wid}")))
    check("個別ページ: 非公開でも、埋め込んだビューアー（ページ一覧）は読める", anon.get(f"/read.php?a=m&id={wid}").status == 200)
    adm.post("/admin/index.php", {"do": "page_public", "id": wid, "on": "1", "csrf": csrf})
    check("個別ページ: 公開に戻せる", anon.get(f"/read.php?nagimanga={wid}").status == 200)

    man = json.loads(anon.get(f"/read.php?a=m&id={wid}").text)
    page_src = "/" + man["pages"][0]["src"]
    check("直リンク防止: 初期設定はオフ（画像の URL を直接開ける）", anon.get(page_src).status == 200)
    adm.post("/admin/index.php", {"do": "settings_advanced", "hotlink": "1", "hotlink_allow": "javascript:alert(1)", "csrf": csrf})
    check("直リンク防止: 許可リストの変な書き方は保存しない", "'hotlink' => true" not in (data_dir / "config.php").read_text(encoding="utf-8"))
    adm.post("/admin/index.php", {"do": "settings_advanced", "hotlink": "1", "hotlink_allow": "https://note.com", "csrf": csrf})
    host = f"{HOST}:{PORT}"
    check("直リンク防止: オンにすると、画像の URL を直接開いても 404", is_404(anon.get(page_src)))
    check("直リンク防止: ほかのサイトに貼られた画像・ページ一覧も 404",
          is_404(anon.get(page_src, headers={"Referer": "https://evil.example/", "Sec-Fetch-Site": "cross-site"}))
          and is_404(anon.get(f"/read.php?a=m&id={wid}", headers={"Origin": "https://evil.example"})))
    check("直リンク防止: よく似た名前のサイトも 404",
          is_404(anon.get(page_src, headers={"Referer": f"http://{HOST}.evil.example/"})))
    check("直リンク防止: このサイトのページからは読める（Sec-Fetch-Site / Referer）",
          anon.get(page_src, headers={"Sec-Fetch-Site": "same-origin"}).status == 200
          and anon.get(page_src, headers={"Referer": f"http://{host}/read.php?nagimanga={wid}"}).status == 200)
    check("直リンク防止: 許可リストのサイトからは読める", anon.get(page_src, headers={"Referer": "https://note.com/"}).status == 200)
    check("直リンク防止: オンでも、個別ページとカード用の表紙は使える",
          anon.get(f"/read.php?nagimanga={wid}").status == 200 and anon.get(f"/read.php?a=o&id={wid}").status == 200)
    adm.post("/admin/index.php", {"do": "settings_advanced", "csrf": csrf})
    check("直リンク防止: オフに戻せる", anon.get(page_src).status == 200)

    adm.post("/admin/index.php", {"do": "setpw", "id": wid, "password": "og-test-pw", "csrf": csrf})
    r = anon.get(f"/read.php?nagimanga={wid}")
    og = dict(re.findall(r'<meta (?:property|name)="((?:og|twitter):[a-z:]+)" content="([^"]*)"', r.text))
    check("個別ページ: パスワード付きの作品は表紙を出さない（カードは共通の画像）",
          og.get("og:image", "").endswith("/viewer/og.jpg") and 'class="cover"' not in r.text and "a=t&amp;" not in r.text
          and is_404(anon.get(f"/read.php?a=o&id={wid}")))
    adm.post("/admin/index.php", {"do": "clearpw", "id": wid, "csrf": csrf})

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
    r = adm.get(f"/admin/index.php?p=work&id={wid}")
    check("管理画面で現在の閲覧パスワードを確認できる（普段は伏せ字）",
          f'type="password" readonly value="{work_pw}"' in r.text and "js-pw-toggle" in r.text)
    on_disk = "".join(p.read_text(encoding="utf-8") for p in (data_dir / "works").rglob("work.json"))
    check("保存ファイルに閲覧パスワードの平文が残らない（暗号化）", work_pw not in on_disk and '"password_enc": "' in on_disk)
    r = anon.get(f"/read.php?a=m&id={wid}")
    check("公開の窓口は閲覧パスワードを一切返さない", work_pw not in r.text and "password" not in r.text)
    locked = json.loads(r.text)
    check("パスワード付き作品はページ一覧を返さない", locked.get("locked") is True and "pages" not in locked)
    if pages:
        check("パスワード付き作品の画像は鍵なしで 404", is_404(anon.get("/" + pages[0]["src"].split("&t=")[0])))
    r = anon.get(f"/read.php?nagimanga={wid}")
    check("パスワード付き作品の共有 URL のページは、ページ画像もパスワードも含まない",
          r.status == 200 and "a=i" not in r.text and "a=t" not in r.text and work_pw not in r.text)
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
        check("バックアップに閲覧パスワードの平文が入らない", work_pw.encode() not in good_zip)

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

    # --- EPUB import ------------------------------------------------------------------------------
    def make_epub(pages, ppd="rtl", title="テスト本", opf=None, container=None, extra=()):
        """pages: list of (xhtml_body_or_None, image_name, image_bytes)"""
        buf = io.BytesIO()
        with zipfile.ZipFile(buf, "w") as z:
            z.writestr("mimetype", "application/epub+zip", compress_type=zipfile.ZIP_STORED)
            z.writestr("META-INF/container.xml", container or
                       '<?xml version="1.0"?><container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
                       '<rootfiles><rootfile full-path="item/standard.opf" media-type="application/oebps-package+xml"/></rootfiles></container>')
            manifest, spine = [], []
            for i, (body, img, data) in enumerate(pages):
                if img and data is not None:
                    z.writestr(f"item/image/{img}", data)
                    manifest.append(f'<item id="i{i}" href="image/{img}" media-type="image/png"/>')
                if body is not None:
                    z.writestr(f"item/xhtml/p{i}.xhtml", '<?xml version="1.0"?><!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" '
                               'xmlns:epub="http://www.idpf.org/2007/ops"><head><title>t</title></head><body>' + body + '</body></html>')
                    manifest.append(f'<item id="p{i}" href="xhtml/p{i}.xhtml" media-type="application/xhtml+xml" fallback="i{i}"/>')
                    spine.append(f'<itemref idref="p{i}"/>')
            z.writestr("item/standard.opf", opf or
                       '<?xml version="1.0" encoding="UTF-8"?><package xmlns="http://www.idpf.org/2007/opf" version="3.0">'
                       f'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>{title}</dc:title></metadata>'
                       f'<manifest>{"".join(manifest)}</manifest><spine page-progression-direction="{ppd}">{"".join(spine)}</spine></package>')
            for name, data in extra:
                z.writestr(name, data)
        return buf.getvalue()

    def svg_page(img):
        return ('<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 60 90">'
                f'<image width="60" height="90" xlink:href="../image/{img}"/></svg>')

    def works_count():
        return len(list((data_dir / "works").glob("*/work.json")))

    def import_epub(data, title=""):
        r = adm.post("/admin/index.php", {"do": "epub", "csrf": csrf, "title": title}, files={"epub": ("b.epub", data, "application/epub+zip")})
        loc = r.getheader("Location") or ""
        m = re.search(r"id=([A-Za-z0-9]{12})", loc)
        flashes = re.findall(r'class="flash flash-\w+">([^<]+)', adm.get("/admin/index.php" + (f"?p=work&id={m.group(1)}" if m else "")).text)
        return (m.group(1) if m else None), " / ".join(flashes)

    good = make_epub([
        (svg_page("a.png"), "a.png", png(60, 90, seed=1)),                     # CLIP STUDIO style (svg <image>)
        ('<img src="../image/b.png" alt=""/>', "b.png", png(60, 90, seed=2)),   # <img>
        ("<p>no image here</p>", "c.png", png(60, 90, seed=3)),                  # manifest fallback
        (svg_page("white.png"), "white.png", png(60, 90, seed=0)),
    ])
    eid, msg = import_epub(good)
    man = json.loads(anon.get(f"/read.php?a=m&id={eid}").text) if eid else {}
    check("EPUB（svg / img / fallback の 3 通り）から全ページを取り込める", bool(eid) and len(man.get("pages", [])) == 4, msg)
    check("EPUB のタイトルと読む向き（rtl）を引き継ぐ", man.get("title") == "テスト本" and man.get("direction") == "rtl")
    eid2, _ = import_epub(make_epub([(svg_page("a.png"), "a.png", png(60, 90))], ppd="ltr"), title="上書きタイトル")
    man2 = json.loads(anon.get(f"/read.php?a=m&id={eid2}").text) if eid2 else {}
    check("左から右の EPUB は ltr、入力したタイトルが優先", man2.get("direction") == "ltr" and man2.get("title") == "上書きタイトル")

    n1 = works_count()
    xxe_opf = ('<?xml version="1.0"?><!DOCTYPE package [<!ENTITY x SYSTEM "file:///C:/Windows/win.ini">]>'
               '<package xmlns="http://www.idpf.org/2007/opf"><metadata xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:title>&x;</dc:title></metadata>'
               '<manifest><item id="i0" href="image/a.png" media-type="image/png"/></manifest><spine><itemref idref="i0"/></spine></package>')
    eid, msg = import_epub(make_epub([(None, "a.png", png(60, 90))], opf=xxe_opf))
    check("XXE（外部実体）入りの EPUB は拒否", eid is None and "目録" in msg and "[fonts]" not in msg, msg)
    eid, msg = import_epub(make_epub([(svg_page("a.png"), "a.png", png(60, 90))], extra=[("META-INF/encryption.xml", "<encryption/>")]))
    check("DRM（encryption.xml）付きは拒否", eid is None and "DRM" in msg, msg)
    eid, msg = import_epub(make_epub([("<p>文章だけ</p>", None, None)]))
    check("文章だけの EPUB は拒否", eid is None and "画像のページが見つかりません" in msg, msg)
    trav = make_epub([('<img src="../../../../../evil.png"/>', None, None)], extra=[("evil.png", png(10, 10))])
    eid, msg = import_epub(trav)
    check("EPUB の外を指すパス（../）は使わない", eid is None, msg)
    eid, msg = import_epub(make_epub([(svg_page("a.png"), "a.png", png(60, 90))],
                                     container='<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
                                               '<rootfile full-path="../../config.php"/></rootfiles></container>'))
    check("目録の場所を外に向けても読まない", eid is None, msg)
    fake = make_epub([(svg_page("a.png"), "a.png", b"\x89PNG\r\n\x1a\n<?php system($_GET['c']); ?>")])
    eid, msg = import_epub(fake)
    check("画像に偽装した PHP だけの EPUB は作品を作らない", eid is None and "取り込める画像がありません" in msg, msg)
    bombbuf = io.BytesIO()
    with zipfile.ZipFile(bombbuf, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("META-INF/container.xml", '<container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles>'
                                             '<rootfile full-path="o.opf"/></rootfiles></container>')
        z.writestr("o.opf", '<package xmlns="http://www.idpf.org/2007/opf"><manifest><item id="i0" href="big.png" media-type="image/png"/>'
                            '</manifest><spine><itemref idref="i0"/></spine></package>')
        z.writestr("big.png", b"\x00" * (70 * 1024 * 1024))
    eid, msg = import_epub(bombbuf.getvalue())
    check("展開すると巨大な画像（ZIP 爆弾）は読まない", eid is None, msg)
    check("拒否した EPUB は作品もファイルも残さない", works_count() == n1 and not list((data_dir / "tmp").glob("epub-*")))
    check("XXE の試みはログに残る", "epub_entity_refused" in (data_dir / "logs" / "security.log").read_text(encoding="utf-8"))

    # --- Light (small) versions -----------------------------------------------------------------
    r = adm.post("/admin/index.php", {"do": "create", "title": "Light", "direction": "rtl", "csrf": csrf})
    lid = re.search(r"id=([A-Za-z0-9]{12})", r.getheader("Location")).group(1)
    for i in (1, 2, 3):
        adm.post("/admin/index.php", {"do": "upload", "id": lid, "csrf": csrf}, files={"page": (f"{i:03d}.png", png(120, 180, seed=i), "image/png")})
    adm.post("/admin/index.php", {"do": "sort_name", "id": lid, "csrf": csrf})
    work_html = adm.get(f"/admin/index.php?p=work&id={lid}").text
    check("作品ページに通常版・小容量版の 2 つのアップロード欄がある", "js-drop" in work_html and "js-drop-light" in work_html and "小容量版（スマホ用・任意）" in work_html)
    lfiles = re.findall(r'<li class="page" draggable="true" data-f="([^"]+)"', work_html)

    def up_light(f, data=None, work=None):
        res = adm.post("/admin/index.php", {"do": "upload_light", "id": work or lid, "f": f, "csrf": csrf},
                       files={"page": ("l.png", data or png(60, 90), "image/png")})
        try:
            return json.loads(res.text)
        except ValueError:
            return {"ok": False, "status": res.status}

    oks = [up_light(f).get("ok") for f in lfiles[:2]]
    man = json.loads(anon.get(f"/read.php?a=m&id={lid}").text)
    lp = man.get("pages", [])
    check("小容量版を 2 ページに付けられる（3 ページ目は通常版だけ）", all(oks) and len(lp) == 3
          and lp[0].get("light", {}).get("w") == 60 and lp[1].get("light") and not lp[2].get("light"))
    check("小容量版の画像が取れる（WebP・長期キャッシュ）", anon.get("/" + lp[0]["light"]["src"]).status == 200)
    check("別の作品のページを指定して小容量版は付けられない", up_light(lfiles[0], work=wid_b).get("ok") is False)
    check("存在しないページには付けられない", up_light("p0999_deadbeef.webp").get("ok") is False)
    check("画像でないものは小容量版にも使えない", up_light(lfiles[2], data=b"GIF89a<?php system('x'); ?>").get("ok") is False)
    check("管理画面に「小」の印と件数が出る", 'class="page-light"' in adm.get(f"/admin/index.php?p=work&id={lid}").text
          and "<strong>2 / 3</strong>" in adm.get(f"/admin/index.php?p=work&id={lid}").text)

    # Protected work: the light copy needs the reader key too
    adm.post("/admin/index.php", {"do": "setpw", "id": lid, "password": "light-pw", "csrf": csrf})
    check("パスワード付きでは小容量版も鍵なしで 404", is_404(anon.get("/" + lp[0]["light"]["src"].split("&t=")[0])))
    tok_l = json.loads(anon.post("/read.php", {"a": "u", "id": lid, "password": "light-pw"}).text).get("token", "")
    lp2 = json.loads(anon.get(f"/read.php?a=m&id={lid}&t={urllib.parse.quote(tok_l)}").text).get("pages", [])
    check("鍵があれば小容量版も取れる", bool(lp2) and anon.get("/" + lp2[0]["light"]["src"]).status == 200)
    adm.post("/admin/index.php", {"do": "clearpw", "id": lid, "csrf": csrf})

    # Backup / restore keeps the light versions
    r = adm.post("/admin/index.php", {"do": "backup", "id": lid, "csrf": csrf})
    light_zip = r.body
    adm.post("/admin/index.php", {"do": "delete", "id": lid, "confirm": "Light", "csrf": csrf})
    adm.post("/admin/index.php", {"do": "restore", "csrf": csrf}, files={"backup": ("b.zip", light_zip, "application/zip")})
    lp3 = json.loads(anon.get(f"/read.php?a=m&id={lid}").text).get("pages", [])
    check("バックアップから復元しても小容量版が残る", len(lp3) == 3 and bool(lp3[0].get("light")) and bool(lp3[1].get("light")))

    # Deleting a page deletes its light file; clearing removes the rest
    pages_dir = next(p.parent for p in (data_dir / "works").rglob("work.json") if json.loads(p.read_text(encoding="utf-8"))["id"] == lid) / "pages"
    light_names = [p["m"]["f"] for p in json.loads((pages_dir.parent / "work.json").read_text(encoding="utf-8"))["pages"] if "m" in p]
    adm.post("/admin/index.php", {"do": "delpage", "id": lid, "f": lfiles[0], "csrf": csrf})
    check("ページを削除すると小容量版のファイルも消える", not (pages_dir / light_names[0]).exists() and (pages_dir / light_names[1]).exists())
    adm.post("/admin/index.php", {"do": "clear_light", "id": lid, "csrf": csrf})
    lp4 = json.loads(anon.get(f"/read.php?a=m&id={lid}").text).get("pages", [])
    check("「小容量版をすべて外す」で外れ、ファイルも消える", not any(p.get("light") for p in lp4) and not (pages_dir / light_names[1]).exists())

    # EPUB: normal + light at once, and light onto an existing work
    big = make_epub([(svg_page(f"{i}.png"), f"{i}.png", png(120, 180, seed=i)) for i in range(3)])
    small = make_epub([(svg_page(f"{i}.png"), f"{i}.png", png(60, 90, seed=i)) for i in range(3)])
    r = adm.post("/admin/index.php", {"do": "epub", "csrf": csrf}, files={"epub": ("a.epub", big, "application/epub+zip"),
                                                                          "epub_light": ("b.epub", small, "application/epub+zip")})
    eid_l = re.search(r"id=([A-Za-z0-9]{12})", r.getheader("Location") or "").group(1)
    ep = json.loads(anon.get(f"/read.php?a=m&id={eid_l}").text).get("pages", [])
    check("EPUB の通常版と小容量版を一度に取り込める", len(ep) == 3 and all(p.get("light", {}).get("w") == 60 and p["w"] == 120 for p in ep))
    r = adm.post("/admin/index.php", {"do": "epub", "csrf": csrf}, files={"epub": ("a.epub", big, "application/epub+zip")})
    eid_m = re.search(r"id=([A-Za-z0-9]{12})", r.getheader("Location") or "").group(1)
    small2 = make_epub([(svg_page(f"{i}.png"), f"{i}.png", png(60, 90, seed=i)) for i in range(2)])
    adm.post("/admin/index.php", {"do": "epub_light", "id": eid_m, "csrf": csrf}, files={"epub_light": ("b.epub", small2, "application/epub+zip")})
    flash = " ".join(re.findall(r'class="flash flash-\w+">([^<]+)', adm.get(f"/admin/index.php?p=work&id={eid_m}").text))
    em = json.loads(anon.get(f"/read.php?a=m&id={eid_m}").text).get("pages", [])
    check("あとから小容量版 EPUB を割り当てられ、ページ数の違いは知らせる",
          [bool(p.get("light")) for p in em] == [True, True, False] and "数が違います" in flash, flash)

    # --- Guest mode plugin (read-only admin for demos) --------------------------------------------
    plugin_src = HERE.parent / "plugins" / "guest-mode.php"
    plugin_dst = data_dir.parent / "plugins" / "guest-mode.php"
    check("プラグインなしではゲストの入口は存在しない（404）", is_404(Client().get("/admin/index.php?guest")))
    check("plugins フォルダは外から見えない", anon.get("/plugins/").status in (403, 404) and anon.get("/plugins/guest-mode.php").status in (403, 404))
    plugin_dst.write_text(plugin_src.read_text(encoding="utf-8").replace("'exit_url' => ''", "'exit_url' => '/demo.html'"), encoding="utf-8")

    def snapshot():
        files = sorted(p for p in data_dir.rglob("*") if p.is_file() and "logs" not in p.parts and "sessions" not in p.parts
                       and "ratelimit" not in p.parts and "locks" not in p.parts)
        return {str(p): p.read_bytes() for p in files}

    g = Client()
    r = g.get("/admin/index.php?guest")
    check("プラグインを置くとゲストとして入れる", r.status == 303)
    r = g.get("/admin/index.php")
    check("ゲストには案内が出て、作品作成フォームは出ない", "デモ用のゲスト表示" in r.text and "作品を作る" not in r.text and wid in r.text)
    check("ゲストのメニューに設定・バックアップがない", "p=settings" not in r.text and "p=backup" not in r.text and "ゲストを終了" in r.text)
    gcsrf = csrf_of(r.text)
    r = g.get(f"/admin/index.php?p=work&id={wid}")
    forbidden_bits = ['value="upload"', 'js-drop', 'value="delpage"', 'value="setpw"', 'value="clearpw"', 'value="delete"',
                      'value="update"', 'value="sort_name"', 'js-save-order', 'js-pw', 'draggable="true"']
    check("ゲストの作品ページには変更用のフォームが一つもない", r.status == 200 and not [b for b in forbidden_bits if b in r.text])
    check("ゲストには閲覧パスワードが見えない", work_pw not in r.text and "ゲストには表示されません" in r.text)
    check("ゲストも共有タグと試し読みは使える", "js-share" in r.text and "js-preview" in r.text)
    img_m = re.search(r'src="(index\.php\?p=img[^"]+)"', r.text)
    check("ゲストもページ画像を見られる", bool(img_m) and g.get("/admin/" + img_m.group(1).replace("&amp;", "&")).status == 200)
    r = g.get("/admin/index.php?p=settings")
    check("ゲストは設定画面を見られない（ログイン URL・IP・ログが出ない）", key not in r.text and "security.log" not in r.text
          and "ゲスト（閲覧のみ）では設定" in r.text and "127.0.0.1" not in r.text)
    check("ゲストはバックアップ画面を見られない", "すべてをダウンロード" not in g.get("/admin/index.php?p=backup").text)
    r1, r2 = g.get("/admin/index.php?p=update"), g.get("/admin/index.php?p=embed")
    check("ゲストは更新画面・設置用コード画面を見られない、メニューにもない",
          "ゲスト（閲覧のみ）では更新" in r1.text and 'value="update_apply"' not in r1.text
          and "ゲスト（閲覧のみ）では設置用コード" in r2.text and "p=update" not in g.get("/admin/index.php").text)

    before = snapshot()
    cfg_before = (data_dir / "config.php").read_bytes()
    attempts = {
        "create": {"title": "guest"}, "update": {"id": wid, "title": "x"}, "sort_name": {"id": wid},
        "order": {"id": wid, "order": "[]"}, "delpage": {"id": wid, "f": "p0001_00000000.webp"},
        "setpw": {"id": wid, "password": "guestpw"}, "clearpw": {"id": wid}, "delete": {"id": wid, "confirm": xss},
        "backup": {}, "settings_pw": {"current": admin_pw, "password": "x" * 12, "password2": "x" * 12},
        "settings_access": {"allowed_ips": "", "allowed_origins": "https://evil.example"},
        "settings_general": {"base_url": "https://evil.example"}, "regen_key": {},
    }
    refused = []
    for do, extra in attempts.items():
        r = g.post("/admin/index.php", {"do": do, "csrf": gcsrf, **extra})
        refused.append(r.status in (303, 403) and r.getheader("Content-Type") != "application/zip")
    r = g.post("/admin/index.php", {"do": "upload", "id": wid, "csrf": gcsrf}, files={"page": ("g.png", png(20, 20), "image/png")})
    refused.append(r.status == 403)
    r = g.post("/admin/index.php", {"do": "restore", "csrf": gcsrf}, files={"backup": ("b.zip", good_zip or b"x", "application/zip")})
    refused.append(r.status == 303)
    r = g.post("/admin/index.php", {"do": "epub", "csrf": gcsrf}, files={"epub": ("b.epub", good, "application/epub+zip")})
    refused.append(r.status == 303)
    r = g.post("/admin/index.php", {"do": "upload_light", "id": wid, "f": "p0001_00000000.webp", "csrf": gcsrf},
               files={"page": ("l.png", png(20, 20), "image/png")})
    refused.append(r.status == 403 or r.status == 303)
    for do in ("clear_light", "epub_light"):
        r = g.post("/admin/index.php", {"do": do, "id": wid, "csrf": gcsrf},
                   files={"epub_light": ("b.epub", good, "application/epub+zip")} if do == "epub_light" else None)
        refused.append(r.status == 303)
    check("ゲストの変更操作 19 種はすべて拒否される（正しい CSRF トークン付きでも）", all(refused) and len(refused) == 19, str(refused))
    upd = [g.post("/admin/index.php", {"do": do, "csrf": gcsrf, "backup": "x", "auto": "1"}).status in (303, 403)
           for do in ("update_check", "update_apply", "update_rollback", "update_auto", "page_public", "settings_page", "settings_advanced")]
    check("ゲストは更新・元に戻す・自動確認・個別ページの公開・個別ページと上級者向けの設定を変えられない", all(upd), str(upd))
    check("ゲストの操作でデータは 1 バイトも変わらない", snapshot() == before and (data_dir / "config.php").read_bytes() == cfg_before)
    check("ゲストの拒否はログに残る", "guest_write_blocked" in (data_dir / "logs" / "security.log").read_text(encoding="utf-8"))

    # IP allow list: guests still enter, the admin login stays closed to other IPs
    original = cfg_file.read_text(encoding="utf-8")
    cfg_file.write_text(re.sub(r"'allowed_ips' =>\s*array \(\s*\)", "'allowed_ips' => array ( 0 => '203.0.113.10' )", original), encoding="utf-8")
    g2 = Client()
    guest_ok = g2.get("/admin/index.php?guest").status == 303 and "デモ用のゲスト表示" in g2.get("/admin/index.php").text
    login_closed = is_404(Client().get(f"/admin/index.php?k={key}"))
    admin_closed = is_404(adm.get("/admin/index.php"))
    cfg_file.write_text(original, encoding="utf-8")
    check("IP 制限中もゲストは入れる（bypass_ip）", guest_ok)
    check("IP 制限中、他の IP からは管理者ログイン画面も管理者セッションも 404", login_closed and admin_closed)

    # Logout page and exit link
    r = g2.post("/admin/index.php", {"do": "logout", "csrf": csrf_of(g2.get("/admin/index.php").text)})
    check("ゲスト終了のページにデモへのリンクが出る", "ゲストを終了しました" in r.text and 'href="/demo.html"' in r.text)
    plugin_dst.write_text(plugin_src.read_text(encoding="utf-8").replace("'exit_url' => ''", "'exit_url' => 'javascript:alert(1)'"), encoding="utf-8")
    g3 = Client()
    g3.get("/admin/index.php?guest")
    r = g3.post("/admin/index.php", {"do": "logout", "csrf": csrf_of(g3.get("/admin/index.php").text)})
    check("戻り先に javascript: などは使えない", "javascript:" not in r.text)

    # Entrance key
    plugin_dst.write_text(plugin_src.read_text(encoding="utf-8").replace("'enter_key' => ''", "'enter_key' => 'open-sesame'"), encoding="utf-8")
    check("合言葉を設定すると、合言葉なし・違う合言葉では 404",
          is_404(Client().get("/admin/index.php?guest")) and is_404(Client().get("/admin/index.php?guest=wrong")))
    check("正しい合言葉なら入れる", Client().get("/admin/index.php?guest=open-sesame").status == 303)

    # Admin keeps admin rights with the plugin present
    r = adm.get(f"/admin/index.php?p=work&id={wid}")
    check("プラグインがあっても管理者は今までどおり全部使える", 'value="upload"' in r.text or "js-drop" in r.text)

    # Remove the plugin: guests inside are thrown out
    g4 = Client()
    plugin_dst.write_text(plugin_src.read_text(encoding="utf-8"), encoding="utf-8")
    g4.get("/admin/index.php?guest")
    plugin_dst.unlink()
    check("プラグインを消すと、中にいたゲストも 404", is_404(g4.get("/admin/index.php")) and is_404(Client().get("/admin/index.php?guest")))

    # --- Self-update ---------------------------------------------------------------------------------
    run_update(adm, csrf, data_dir)

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
    mock = start_mock()
    procs = start_servers(site)
    try:
        run(data_dir)
    finally:
        for p in procs:
            p.terminate()
        mock.shutdown()
    passed = sum(1 for _, ok, _ in results if ok)
    lines = [f"# NagiManga attack test ({time.strftime('%Y-%m-%d %H:%M')})", "", f"{passed} / {len(results)} passed", "",
             "| 結果 | テスト |", "|---|---|"]
    lines += [f"| {'PASS' if ok else '**FAIL**'} | {name}{(' — ' + d) if d and not ok else ''} |" for name, ok, d in results]
    (RESULTS / "attack-report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"\n{passed} / {len(results)} passed")
    sys.exit(0 if passed == len(results) else 1)


if __name__ == "__main__":
    main()
