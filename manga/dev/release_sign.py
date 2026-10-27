#!/usr/bin/env python3
"""
Ed25519 signatures for NagiManga releases (RFC 8032, standard library only).

The installed sites check every update against the public keys in server/lib/update.php
(NM_UPDATE_KEYS), so a release ZIP swapped on GitHub is refused. The secret keys never
enter the repository.

    python dev/release_sign.py keygen          -> makes the main key and a spare key (once)
    python dev/release_sign.py pub             -> prints the public keys for NM_UPDATE_KEYS
    python dev/release_sign.py sign <zip> <version>   -> writes <zip>.sig (build_release.py calls this)

Keys: %APPDATA%\\NagiManga-release\\signing-key.txt (main) and spare-signing-key.txt (move the spare
off this PC, e.g. to a USB stick, and keep it for the day the main key is lost).
"""
import hashlib
import os
import sys
from pathlib import Path

KEY_DIR = Path(os.environ.get("NAGIMANGA_SIGNING_DIR") or Path(os.environ.get("APPDATA", Path.home())) / "NagiManga-release")
MAIN_KEY = KEY_DIR / "signing-key.txt"
SPARE_KEY = KEY_DIR / "spare-signing-key.txt"

P = 2 ** 255 - 19
L = 2 ** 252 + 27742317777372353535851937790883648493
D = -121665 * pow(121666, P - 2, P) % P
SQRT_M1 = pow(2, (P - 1) // 4, P)


def _add(a, b):
    x1, y1, z1, t1 = a
    x2, y2, z2, t2 = b
    A = (y1 - x1) * (y2 - x2) % P
    B = (y1 + x1) * (y2 + x2) % P
    C = 2 * D * t1 * t2 % P
    Dd = 2 * z1 * z2 % P
    E, F, G, H = B - A, Dd - C, Dd + C, B + A
    return (E * F % P, G * H % P, F * G % P, E * H % P)


def _mul(n, pt):
    q = (0, 1, 1, 0)
    while n:
        if n & 1:
            q = _add(q, pt)
        pt = _add(pt, pt)
        n >>= 1
    return q


def _encode(pt):
    x, y, z, _ = pt
    zi = pow(z, P - 2, P)
    x, y = x * zi % P, y * zi % P
    return (y | ((x & 1) << 255)).to_bytes(32, "little")


def _base():
    y = 4 * pow(5, P - 2, P) % P
    x2 = (y * y - 1) * pow(D * y * y + 1, P - 2, P) % P
    x = pow(x2, (P + 3) // 8, P)
    if (x * x - x2) % P:
        x = x * SQRT_M1 % P
    if x & 1:
        x = P - x
    return (x, y, 1, x * y % P)


B = _base()


def _expand(seed):
    h = hashlib.sha512(seed).digest()
    a = int.from_bytes(h[:32], "little")
    a &= (1 << 254) - 8
    a |= 1 << 254
    return a, h[32:]


def public_key(seed):
    return _encode(_mul(_expand(seed)[0], B))


def sign(seed, msg):
    a, prefix = _expand(seed)
    pub = _encode(_mul(a, B))
    r = int.from_bytes(hashlib.sha512(prefix + msg).digest(), "little") % L
    R = _encode(_mul(r, B))
    k = int.from_bytes(hashlib.sha512(R + pub + msg).digest(), "little") % L
    return R + ((r + k * a) % L).to_bytes(32, "little")


def release_message(version, zip_bytes):
    """What a release signature covers: the version and the ZIP's SHA-256 (same text as nm_update_message())."""
    return f"NagiManga release\n{version}\n{hashlib.sha256(zip_bytes).hexdigest()}\n".encode()


def read_seed(path):
    raw = path.read_text(encoding="ascii").split()[0]
    seed = bytes.fromhex(raw)
    if len(seed) != 32:
        raise SystemExit(f"{path} is not a signing key")
    return seed


def sign_release(zip_path, version, key=MAIN_KEY):
    """Write <zip>.sig (the signature in hex) next to the ZIP and return its path."""
    zip_path = Path(zip_path)
    if not key.is_file():
        raise SystemExit(f"No signing key at {key}. Run: python dev/release_sign.py keygen")
    sig = sign(read_seed(key), release_message(version, zip_path.read_bytes()))
    out = zip_path.with_name(zip_path.name + ".sig")
    out.write_text(sig.hex() + "\n", encoding="ascii")
    return out


def main(argv):
    cmd = argv[1] if len(argv) > 1 else ""
    if cmd == "keygen":
        KEY_DIR.mkdir(parents=True, exist_ok=True)
        for path in (MAIN_KEY, SPARE_KEY):
            if path.exists():
                print(f"keep  {path}")
                continue
            path.write_text(os.urandom(32).hex() + "\n", encoding="ascii")
            print(f"made  {path}")
        cmd = "pub"
    if cmd == "pub":
        for path in (MAIN_KEY, SPARE_KEY):
            print(f"{public_key(read_seed(path)).hex()}  {path.name}" if path.is_file() else f"(missing)  {path.name}")
        return
    if cmd == "sign" and len(argv) == 4:
        print(sign_release(argv[2], argv[3]))
        return
    raise SystemExit(__doc__)


if __name__ == "__main__":
    main(sys.argv)
