#!/usr/bin/env python3
"""
Hardening from the 0.11.1 security review, attacked over HTTP on a throw-away install:
  - the site-wide login limit no longer shuts the owner out (browser mark, allow list)
  - IPv6 counts per /64, and each locked work has an hourly limit for everyone together
  - the security log is a PHP file (empty 404 without .htaccess), old logs move into it
  - the update check record is a PHP file too (an old check.json goes)
Many addresses come from X-Forwarded-For, with 127.0.0.1 set as a trusted proxy.

    python dev/hardening_test.py
"""
import argparse
import base64
import hashlib
import json
import os
import re
import secrets
import socket
import subprocess
import sys
import time
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--port", type=int, default=5397)
args = parser.parse_args()
HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import attack_test as h  # noqa: E402
import log_test as base  # noqa: E402

PASSWORD = "log-test-password"
checks = []


def check(name, ok):
    checks.append((name, bool(ok)))
    print(f"[{'PASS' if ok else 'FAIL'}] {name}", flush=True)


def php(site, code):
    prefix = "define('NAGIMANGA',true); require '" + (site / "lib/bootstrap.php").as_posix() + "'; "
    cmd = h.php_cmd(0, False, site)
    cmd = cmd[:cmd.index("-S")] + ["-r", prefix + code]
    r = subprocess.run(cmd, env=dict(os.environ, NAGIMANGA_DATA=str(site / "data")), capture_output=True, text=True, encoding="utf-8", check=True)
    return json.loads(r.stdout) if r.stdout else None


def literal(value):
    return "json_decode(base64_decode('" + base64.b64encode(json.dumps(value).encode()).decode() + "'),true)"


def set_config(site, **values):
    php(site, "$c=nm_config(true); foreach(" + literal(values) + " as $k=>$v) $c[$k]=$v; nm_save_config($c);")


def ip(addr):
    return {"X-Forwarded-For": addr}


def login_try(key, addr, password, client=None):
    c = client or h.Client(args.port)
    page = c.get(f"/admin/index.php?k={key}", headers=ip(addr))
    if h.is_404(page):
        return c, page
    return c, c.post("/admin/index.php", {"csrf": h.csrf_of(page.text), "k": key, "password": password}, headers=ip(addr))


def logged_in(c, addr):
    return "作品を作る" in c.get("/admin/index.php", headers=ip(addr)).text


def run(site, owner, csrf, key):
    data = site / "data"
    set_config(site, trusted_proxies=["127.0.0.1"])

    # --- IPv6 per /64 ----------------------------------------------------------------------------
    nets = php(site, "echo json_encode([nm_client_net('2001:db8:1:2:aaaa::1'), nm_client_net('2001:db8:1:2:ffff:1:2:3'), nm_client_net('2001:db8:1:3::1'),"
                     " nm_client_net('::ffff:198.51.100.9'), nm_client_net('198.51.100.9')]);")
    check("IPv6 は先頭の /64 で一人と数え、IPv4 を IPv6 で書いたものは IPv4 のまま", nets[0] == nets[1] != nets[2] and nets[3] == nets[4] == "198.51.100.9")

    r = owner.post("/admin/index.php", {"do": "create", "title": "鍵付き", "direction": "rtl", "csrf": csrf})
    wid = re.search(r"id=([A-Za-z0-9]{12})", r.getheader("Location") or "").group(1)
    owner.post("/admin/index.php", {"do": "upload", "id": wid, "csrf": csrf}, files={"page": ("1.png", h.png(30, 40), "image/png")})
    work_pw = "yomu-" + secrets.token_hex(3)
    owner.post("/admin/index.php", {"do": "setpw", "id": wid, "password": work_pw, "csrf": csrf})
    reader = h.Client(args.port)
    tok = json.loads(reader.post("/read.php", {"a": "u", "id": wid, "password": work_pw}, headers=ip("192.0.2.77")).text).get("token", "")

    anon = h.Client(args.port)
    same = [anon.post("/read.php", {"a": "u", "id": wid, "password": f"g{i}"}, headers=ip(f"2001:db8:1:2::{i + 1:x}")).status for i in range(6)]
    check("閲覧パスワード: 同じ /64 の中でアドレスを替えても 5 回で止まる", same[:5] == [403] * 5 and same[5] == 429)
    r = anon.post("/read.php", {"a": "u", "id": wid, "password": "g"}, headers=ip("2001:db8:1:3::1"))
    check("閲覧パスワード: 別の /64 は別の相手として数える", r.status == 403)

    # Past 60 wrong answers an hour for the work from everyone, answers go one at a time, 20 seconds apart.
    turn = data / "ratelimit" / (hashlib.sha256(("unlock-all-turn|" + wid).encode()).hexdigest() + ".json")
    statuses = []
    for i in range(70):
        started = time.time()
        statuses.append(anon.post("/read.php", {"a": "u", "id": wid, "password": f"x{i}"}, headers=ip(f"2001:db8:{i + 16:x}::1")).status)
        if turn.is_file():
            break
    check("閲覧パスワード: 作品ごとに 1 時間 60 回を超えても、止めずに 1 件ずつ確かめる",
          turn.is_file() and set(statuses) == {403} and 50 <= len(statuses) <= 61 and time.time() - started < 5)
    # The next turn is taken by someone already waiting: no second waiter, only "busy".
    turn.write_text(json.dumps([int(time.time()) + 40]), encoding="utf-8")
    busy = anon.post("/read.php", {"a": "u", "id": wid, "password": "y1"}, headers=ip("2001:db8:fff1::1"))
    check("閲覧パスワード: 次の番まで埋まっていれば、すぐに「混み合っている」（429・busy）", busy.status == 429 and json.loads(busy.text).get("error") == "busy")
    turn.write_text(json.dumps([int(time.time()) + 2]), encoding="utf-8")
    started = time.time()
    r = anon.post("/read.php", {"a": "u", "id": wid, "password": work_pw}, headers=ip("2001:db8:ffff::1"))
    check("閲覧パスワード: 上限を超えていても、初めての読者は番を待てば開ける", r.status == 200 and json.loads(r.text).get("ok") is True and time.time() - started >= 1)
    r = reader.get(f"/read.php?a=m&id={wid}&t={tok}", headers=ip("192.0.2.77"))
    check("閲覧パスワード: 前に開けた読者は、上限の間も読める", r.status == 200 and json.loads(r.text).get("locked") is False)

    # --- The site-wide login limit ----------------------------------------------------------------
    mark = owner.cookies.get("nm_device", "")
    check("ログインしたブラウザーに持ち主の印（nm_device）を渡す", re.fullmatch(r"[a-f0-9]{32}\.[a-f0-9]{64}", mark) is not None)
    plain = h.Client(args.port)
    plain.cookies = {k: v for k, v in owner.cookies.items() if k != "nm_device"}
    plain.get("/admin/index.php")
    check("更新前からログインしていた持ち主にも、次に開いたとき印を渡す",
          re.fullmatch(r"[a-f0-9]{32}\.[a-f0-9]{64}", plain.cookies.get("nm_device", "")) is not None)

    for n in range(8):
        for i in range(5):
            c, r = login_try(key, f"198.51.100.{n + 1}", f"wrong{n}-{i}")
            if h.is_404(r):
                break
    log = (data / "logs" / "security.log.php").read_text(encoding="utf-8")
    check("（準備）8 つの IP から 5 回ずつ間違えた", log.count("login_failed") >= 40)

    c, r = login_try(key, "203.0.113.5", PASSWORD)
    check("全体の上限のあと、印のないブラウザーは正しいパスワードでも待たされる（画面は出る）",
          r.status == 200 and "前にログインしたことのあるブラウザー" in r.text and not logged_in(c, "203.0.113.5"))
    check("待たせたことはログに残る", "login_busy" in (data / "logs" / "security.log.php").read_text(encoding="utf-8"))
    page = h.Client(args.port).get(f"/admin/index.php?k={key}", headers=ip("203.0.113.6"))
    check("全体の上限の間も、ログイン画面は 404 にならない（パスキーで入れる）", page.status == 200 and 'name="password"' in page.text)

    back = h.Client(args.port)
    back.cookies = {"nm_device": mark}
    c, r = login_try(key, "203.0.113.9", PASSWORD, back)
    check("印のある持ち主のブラウザーは、全体の上限の間もログインできる", r.status == 303 and logged_in(c, "203.0.113.9"))

    locked_ip = h.Client(args.port)
    locked_ip.cookies = {"nm_device": mark}
    c, r = login_try(key, "198.51.100.1", PASSWORD, locked_ip)
    check("印のある持ち主は、攻撃者と同じ IP（ロック中）からでもログインできる", r.status == 303 and logged_in(c, "198.51.100.1"))

    forged = h.Client(args.port)
    forged.cookies = {"nm_device": mark[:33] + ("0" if mark[33] != "0" else "1") + mark[34:]}
    c, r = login_try(key, "203.0.113.10", PASSWORD, forged)
    check("偽造した印は効かない", r.status == 200 and not logged_in(c, "203.0.113.10"))

    set_config(site, allowed_ips=["192.0.2.50"])
    c, r = login_try(key, "192.0.2.50", PASSWORD)
    check("許可 IP に入っているアドレスは、印がなくても全体の上限の外", r.status == 303 and logged_in(c, "192.0.2.50"))
    set_config(site, allowed_ips=[])

    # A stolen mark: five wrong passwords and it no longer helps (the owner logs in again to get it back)
    thief = h.Client(args.port)
    thief.cookies = {"nm_device": mark}
    for i in range(5):
        login_try(key, "203.0.113.66", f"steal{i}", thief)
    c, r = login_try(key, "203.0.113.66", PASSWORD, thief)
    check("盗んだ印で 5 回間違えると、その印は全体の上限の外ではなくなる", r.status == 200 and not logged_in(c, "203.0.113.66"))
    check("印での失敗は、持ち主の IP もサイト全体も増やさない（印ごとに数える）",
          "login_device_locked" in (data / "logs" / "security.log.php").read_text(encoding="utf-8"))

    # --- Changing the password from an open session ----------------------------------------------------
    for f in (data / "ratelimit").glob("*.json"):
        f.unlink()
    who = "203.0.113.90"
    c, r = login_try(key, who, PASSWORD)
    token = h.csrf_of(c.get("/admin/index.php?p=settings&section=common", headers=ip(who)).text)
    tries = 0
    for i in range(15):
        c.post("/admin/index.php", {"do": "settings_pw", "csrf": token, "current": f"guess{i}", "password": "taken-over-pw", "password2": "taken-over-pw"}, headers=ip(who))
        tries += 1
        if "しばらく待って" in c.get("/admin/index.php?p=settings&section=common", headers=ip(who)).text:
            break
    c.post("/admin/index.php", {"do": "settings_pw", "csrf": token, "current": PASSWORD, "password": "taken-over-pw", "password2": "taken-over-pw"}, headers=ip(who))
    _, r = login_try(key, "203.0.113.91", "taken-over-pw")
    check("ログイン中の画面からでも、今のパスワードは回数の制限なしには試せない（変更されない）",
          tries <= 11 and r.status == 200 and "admin_password_changed" not in (data / "logs" / "security.log.php").read_text(encoding="utf-8"))

    # --- Security log --------------------------------------------------------------------------------
    # The update check: an old check.json goes, the new one is a PHP file (nothing reachable, no network here).
    upd = data / "update"
    upd.mkdir(exist_ok=True)
    (upd / "check.json").write_text('{"current": "0.11.1"}', encoding="utf-8")
    php(site, "putenv('NAGIMANGA_UPDATE_API=http://127.0.0.1:9/none'); require '" + (site / "lib/update.php").as_posix() + "'; nm_update_status(true); echo json_encode(nm_update_cached() !== null);")
    cache = (upd / "check.php").read_text(encoding="utf-8") if (upd / "check.php").exists() else ""
    check("更新の確認の記録は PHP 形式（古い check.json は消える）", cache.startswith("<?php http_response_code(404); exit; __halt_compiler();\n") and not (upd / "check.json").exists()
          and php(site, "require '" + (site / "lib/update.php").as_posix() + "'; echo json_encode(nm_update_cached()['current'] ?? '');") == php(site, "echo json_encode(NM_VERSION);"))
    raw = base.start(site, args.port + 1, raw=True)
    try:
        r = h.Client(args.port + 1).get("/data/logs/security.log.php")
        check(".htaccess を読まないサーバーでもログは空の 404", r.status == 404 and r.text.strip() == "")
        r = h.Client(args.port + 1).get("/data/update/check.php")
        check(".htaccess を読まないサーバーでも、更新の確認の記録（版番号）は空の 404", r.status == 404 and r.text.strip() == "")
    finally:
        raw.terminate()
        raw.wait(timeout=10)
    logs = data / "logs"
    (logs / "security.log.php").unlink()
    (logs / "security.log").write_text("2026-10-01T00:00:00+09:00\t192.0.2.1\told_event\t\n", encoding="utf-8")
    (logs / "security.log.1").write_text("2026-09-01T00:00:00+09:00\t192.0.2.1\tolder_event\t\n", encoding="utf-8")
    php(site, "nm_log('after_update');")
    new = (logs / "security.log.php").read_text(encoding="utf-8")
    check("0.11 までのログは PHP 形式へ移り、古いファイルは消える",
          new.startswith("<?php http_response_code(404); exit; __halt_compiler();\n") and "old_event" in new and "after_update" in new
          and "older_event" in (logs / "security.log.1.php").read_text(encoding="utf-8")
          and not (logs / "security.log").exists() and not (logs / "security.log.1").exists())
    lines = php(site, "echo json_encode(nm_log_lines(10));")
    check("ログを読むとき、先頭の PHP の行は出さない", lines[0].split("\t")[2] == "old_event" and not any("__halt_compiler" in x for x in lines))
    php(site, "nm_log('x', '<?php echo 1; ?>');")
    check("ログに PHP の印が書かれても、Web から開いて動くことはない", "<?php echo 1;" in (logs / "security.log.php").read_text(encoding="utf-8")
          and php(site, "include nm_log_file(); echo json_encode('ran on');") is None)


def main():
    with socket.socket() as probe:
        probe.bind((h.HOST, args.port))
    temp = HERE / "results" / ("hardening-test-" + secrets.token_hex(4))
    site = temp / "site"
    h.copy_server(site)
    process = base.start(site, args.port)
    try:
        owner, csrf, key = base.install(args.port)
        run(site, owner, csrf, key)
    finally:
        process.terminate()
        process.wait(timeout=10)
    passed = sum(ok for _, ok in checks)
    print(f"\n{passed} / {len(checks)} passed")
    (temp / "report.json").write_text(json.dumps({"passed": passed, "total": len(checks), "checks": checks}, ensure_ascii=False, indent=2), encoding="utf-8")
    raise SystemExit(0 if passed == len(checks) else 1)


if __name__ == "__main__":
    main()
