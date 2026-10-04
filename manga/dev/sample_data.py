#!/usr/bin/env python3
"""Export the local preview as sample backups, or build a fresh preview from them.

  python dev/sample_data.py export            # running preview (:5197) -> dev/sample/*.zip
  python dev/sample_data.py install <dir>     # new data folder from dev/sample/*.zip

The ZIPs come from the app's own backup feature, so they never contain the
admin password hash, secret key or login key. Each install creates new ones;
the sample admin password is the local fixture below.
"""
import argparse
import os
import socket
import subprocess
import sys
import time
from pathlib import Path

HERE = Path(__file__).resolve().parent
sys.path.insert(0, str(HERE))
import attack_test as helper  # noqa: E402

SAMPLE = HERE / 'sample'
WORKS_ZIP = SAMPLE / 'nagimanga-works.zip'
LOG_ZIP = SAMPLE / 'nagimanga-log.zip'
PASSWORD = 'log-test-password'  # local sample only; change it before any real use


def login(port):
    c = helper.Client(port)
    r = c.get('/admin/login.php')
    r = c.post('/admin/login.php', {'csrf': helper.csrf_of(r.text), 'password': PASSWORD})
    if r.status != 303:
        raise SystemExit(f'ログインできませんでした（HTTP {r.status}）。プレビューのパスワードを確認してください。')
    return c, helper.csrf_of(c.get('/admin/index.php?p=log').text)


def export(port):
    c, csrf = login(port)
    SAMPLE.mkdir(exist_ok=True)
    for target, action in ((WORKS_ZIP, 'backup'), (LOG_ZIP, 'log_backup')):
        r = c.post('/admin/index.php', {'do': action, 'csrf': csrf})
        if r.status != 200 or not r.body.startswith(b'PK'):
            raise SystemExit(f'{action} を書き出せませんでした（HTTP {r.status}）')
        target.write_bytes(r.body)
        print(f'{target.relative_to(HERE.parent)}  {len(r.body):,} bytes')


def free_port():
    with socket.socket() as s:
        s.bind((helper.HOST, 0))
        return s.getsockname()[1]


def install(data):
    data = Path(data).resolve()
    if (data / 'config.php').exists():
        raise SystemExit(f'{data} には設置済みのデータがあります。別のフォルダーを指定してください。')
    for zip_file in (WORKS_ZIP, LOG_ZIP):
        if not zip_file.is_file():
            raise SystemExit(f'{zip_file} がありません。先に export を実行してください。')
    data.mkdir(parents=True, exist_ok=True)
    port = free_port()
    env = dict(os.environ, NAGIMANGA_DATA=str(data))
    server = subprocess.Popen(helper.php_cmd(port, True, HERE.parent / 'server'), env=env, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        c = helper.Client(port)
        for _ in range(100):
            try:
                r = c.get('/admin/index.php')
                break
            except OSError:
                time.sleep(.05)
        else:
            raise SystemExit('PHPの開発サーバーを起動できませんでした。')
        r = c.post('/admin/index.php', {'csrf': helper.csrf_of(r.text), 'password': PASSWORD, 'password2': PASSWORD})
        if not (data / 'config.php').exists():
            raise SystemExit(f'初期設定に失敗しました（HTTP {r.status}）')
        c, csrf = login(port)
        # Works first: LOG posts embed manga by work ID.
        for zip_file, action in ((WORKS_ZIP, 'restore'), (LOG_ZIP, 'log_restore')):
            r = c.post('/admin/index.php', {'do': action, 'csrf': csrf, 'overwrite': '1'}, files={'backup': (zip_file.name, zip_file.read_bytes(), 'application/zip')})
            if r.status != 303:
                raise SystemExit(f'{zip_file.name} を復元できませんでした（HTTP {r.status}）')
        print(f'サンプルを {data} に作成しました。管理パスワード: {PASSWORD}')
    finally:
        server.terminate()
        server.wait()


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='command', required=True)
    sub.add_parser('export').add_argument('--port', type=int, default=5197)
    sub.add_parser('install').add_argument('data')
    args = parser.parse_args()
    export(args.port) if args.command == 'export' else install(args.data)
