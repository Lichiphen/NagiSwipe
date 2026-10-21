<?php
/*
 * Passkey login for the admin (WebAuthn Level 2): Proton Pass, iPhone's Passwords (iCloud Keychain), Google Password Manager,
 * 1Password, Windows Hello and other passkey providers.
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * A passkey proves both the device and the person (fingerprint, face or PIN: user verification is required), so it logs in
 * on its own, without the password or the two-step code; those stay as the way in when no passkey is at hand.
 * Written from the WebAuthn specification with PHP's own openssl; no outside library. Attestation is not asked for
 * ("none"): a personal site does not need to know the maker of the device. ES256 (P-256) and RS256 keys are accepted.
 * Passkeys are bound to the host name: they are kept in data/config.php as "passkeys"; deleting that entry over FTP removes them.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_PASSKEY_MAX = 10;
const NM_PASSKEY_TIMEOUT = 300;     // seconds a challenge stays valid
const NM_PASSKEY_NAME_MAX = 40;
const NM_PASSKEY_ALGS = [-7, -257]; // ES256, RS256

function nm_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
function nm_b64url_decode(string $text): ?string
{
    if ($text === '' || !preg_match('/\A[A-Za-z0-9_-]+\z/', $text)) return null;
    $raw = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    return $raw === false ? null : $raw;
}

// --- Where the passkeys belong ----------------------------------------------------------

/** The relying party: this host name, without the port (passkeys work only on the address they were made on). */
function nm_passkey_rp_id(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!preg_match('/\A[a-z0-9.\-]+(?::[0-9]{1,5})?\z/', $host)) return '';
    return (string)preg_replace('/:[0-9]+\z/', '', $host);
}
/** The page origin the browser must report (scheme, host and port as the admin is opened). */
function nm_passkey_origin(): string
{
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return (nm_is_https() ? 'https' : 'http') . '://' . $host;
}
/** Browsers allow passkeys on HTTPS, and on http://localhost for testing. */
function nm_passkey_possible(): bool
{
    $rp = nm_passkey_rp_id();
    return $rp !== '' && (nm_is_https() || $rp === 'localhost' || str_ends_with($rp, '.localhost'));
}

/** The saved passkeys, newest last. Each: id (base64url), pem, alg, count, name, created, used. */
function nm_passkeys(?array $cfg = null): array
{
    $list = ($cfg ?? nm_config() ?? [])['passkeys'] ?? [];
    if (!is_array($list)) return [];
    return array_values(array_filter($list, static fn($p) => is_array($p) && is_string($p['id'] ?? null) && is_string($p['pem'] ?? null) && in_array($p['alg'] ?? null, NM_PASSKEY_ALGS, true)));
}
/** Passkeys made on this host (another host's would never be offered by the browser). */
function nm_passkeys_here(?array $cfg = null): array
{
    $rp = nm_passkey_rp_id();
    return array_values(array_filter(nm_passkeys($cfg), static fn($p) => ($p['rp'] ?? $rp) === $rp));
}
/** The random user handle of this install (the same for every passkey, so a provider groups them as one account). */
function nm_passkey_user_handle(array &$cfg): string
{
    if (!is_string($cfg['passkey_user'] ?? null) || nm_b64url_decode($cfg['passkey_user']) === null) $cfg['passkey_user'] = nm_b64url(random_bytes(32));
    return $cfg['passkey_user'];
}

// --- Challenges ----------------------------------------------------------------------------

function nm_passkey_challenge(string $purpose): string
{
    $c = nm_b64url(random_bytes(32));
    $_SESSION['nm_passkey'] = ['c' => $c, 'p' => $purpose, 't' => time()];
    return $c;
}
/** Take the session's challenge for $purpose once (a second use, another purpose or an old one fails). */
function nm_passkey_take_challenge(string $purpose): ?string
{
    $c = $_SESSION['nm_passkey'] ?? null;
    unset($_SESSION['nm_passkey']);
    if (!is_array($c) || ($c['p'] ?? '') !== $purpose || time() - (int)($c['t'] ?? 0) > NM_PASSKEY_TIMEOUT || !is_string($c['c'] ?? null)) return null;
    return $c['c'];
}

/** Options for navigator.credentials.create() (binary fields in base64url; the page script turns them into bytes). */
function nm_passkey_create_options(): array
{
    $cfg = nm_config();
    $handle = nm_passkey_user_handle($cfg);
    if (($cfg['passkey_user'] ?? '') !== (nm_config()['passkey_user'] ?? '')) nm_save_config($cfg);
    $rp = nm_passkey_rp_id();
    return [
        'challenge' => nm_passkey_challenge('create'),
        'rp' => ['id' => $rp, 'name' => NM_TOTP_ISSUER],
        'user' => ['id' => $handle, 'name' => $rp, 'displayName' => NM_TOTP_ISSUER . ' (' . $rp . ')'],
        'pubKeyCredParams' => array_map(static fn($alg) => ['type' => 'public-key', 'alg' => $alg], NM_PASSKEY_ALGS),
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'attestation' => 'none',
        'timeout' => NM_PASSKEY_TIMEOUT * 1000,
        'excludeCredentials' => array_map(static fn($p) => ['type' => 'public-key', 'id' => $p['id']], nm_passkeys_here($cfg)),
    ];
}
/** Options for navigator.credentials.get(): no list of keys, the provider offers the passkeys it has for this site. */
function nm_passkey_get_options(): array
{
    return ['challenge' => nm_passkey_challenge('get'), 'rpId' => nm_passkey_rp_id(), 'userVerification' => 'required', 'timeout' => NM_PASSKEY_TIMEOUT * 1000, 'allowCredentials' => []];
}

// --- CBOR (RFC 8949): only what attestation objects and COSE keys use ------------------------

/** @return array{0: mixed, 1: int} the value and the offset after it */
function nm_cbor_decode(string $data, int $at = 0, int $depth = 0): array
{
    if ($depth > 16 || $at >= strlen($data)) throw new UnexpectedValueException('cbor');
    $head = ord($data[$at++]);
    $major = $head >> 5;
    $info = $head & 0x1F;
    if ($info < 24) $arg = $info;
    elseif ($info <= 27) {
        $n = 1 << ($info - 24);
        if ($at + $n > strlen($data)) throw new UnexpectedValueException('cbor');
        $arg = 0;
        for ($i = 0; $i < $n; $i++) $arg = ($arg << 8) | ord($data[$at + $i]);
        if ($arg < 0) throw new UnexpectedValueException('cbor');
        $at += $n;
    } else throw new UnexpectedValueException('cbor');
    switch ($major) {
        case 0: return [$arg, $at];
        case 1: return [-1 - $arg, $at];
        case 2:
        case 3:
            if ($at + $arg > strlen($data)) throw new UnexpectedValueException('cbor');
            return [substr($data, $at, $arg), $at + $arg];
        case 4:
            if ($arg > 1000) throw new UnexpectedValueException('cbor');
            $list = [];
            for ($i = 0; $i < $arg; $i++) { [$list[], $at] = nm_cbor_decode($data, $at, $depth + 1); }
            return [$list, $at];
        case 5:
            if ($arg > 1000) throw new UnexpectedValueException('cbor');
            $map = [];
            for ($i = 0; $i < $arg; $i++) {
                [$k, $at] = nm_cbor_decode($data, $at, $depth + 1);
                [$v, $at] = nm_cbor_decode($data, $at, $depth + 1);
                if (!is_int($k) && !is_string($k)) throw new UnexpectedValueException('cbor');
                $map[$k] = $v;
            }
            return [$map, $at];
        case 7:
            return [match ($info) { 20 => false, 21 => true, 22 => null, default => throw new UnexpectedValueException('cbor') }, $at];
    }
    throw new UnexpectedValueException('cbor');
}

// --- COSE public key → PEM --------------------------------------------------------------------

function nm_der(int $tag, string $body): string
{
    $len = strlen($body);
    if ($len < 0x80) $head = chr($len);
    else { $bytes = ltrim(pack('N', $len), "\0"); $head = chr(0x80 | strlen($bytes)) . $bytes; }
    return chr($tag) . $head . $body;
}
function nm_der_uint(string $raw): string
{
    $raw = ltrim($raw, "\0");
    if ($raw === '' || (ord($raw[0]) & 0x80)) $raw = "\0" . $raw;
    return nm_der(0x02, $raw);
}
/** @return array{0: string, 1: int} PEM and algorithm, or throws for a key that is not ES256 / RS256 */
function nm_cose_to_pem(array $cose): array
{
    $kty = $cose[1] ?? null; $alg = $cose[3] ?? null;
    if ($kty === 2 && $alg === -7 && ($cose[-1] ?? null) === 1 && is_string($cose[-2] ?? null) && is_string($cose[-3] ?? null) && strlen($cose[-2]) === 32 && strlen($cose[-3]) === 32) {
        // SubjectPublicKeyInfo for id-ecPublicKey on prime256v1, uncompressed point
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $cose[-2] . $cose[-3];
    } elseif ($kty === 3 && $alg === -257 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null) && strlen($cose[-1]) >= 256) {
        $rsa = nm_der(0x30, nm_der_uint($cose[-1]) . nm_der_uint($cose[-2]));
        $der = nm_der(0x30, nm_der(0x30, hex2bin('06092a864886f70d0101010500')) . nm_der(0x03, "\0" . $rsa));
    } else {
        throw new UnexpectedValueException('alg');
    }
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    if (openssl_pkey_get_public($pem) === false) throw new UnexpectedValueException('key');
    return [$pem, $alg];
}

// --- Checking what the browser sent ----------------------------------------------------------

/** clientDataJSON: the right ceremony, our challenge, this page's origin, not inside another site's frame. */
function nm_passkey_client_data(string $json, string $type, string $challenge): void
{
    $c = json_decode($json, true);
    if (!is_array($c) || ($c['type'] ?? '') !== $type || !hash_equals($challenge, (string)($c['challenge'] ?? ''))
        || ($c['origin'] ?? '') !== nm_passkey_origin() || !empty($c['crossOrigin'])) throw new UnexpectedValueException('client');
}
/** authenticatorData: for this host, the person was there (UP) and verified (UV). @return array{flags: int, count: int, rest: string} */
function nm_passkey_auth_data(string $data): array
{
    if (strlen($data) < 37 || !hash_equals(hash('sha256', nm_passkey_rp_id(), true), substr($data, 0, 32))) throw new UnexpectedValueException('rp');
    $flags = ord($data[32]);
    if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) throw new UnexpectedValueException('flags');
    return ['flags' => $flags, 'count' => unpack('N', substr($data, 33, 4))[1], 'rest' => substr($data, 37)];
}

/**
 * Check a new passkey (navigator.credentials.create) and return the record to save.
 * $in: clientDataJSON, attestationObject (base64url), name.
 */
function nm_passkey_register(array $in, string $name): array
{
    $challenge = nm_passkey_take_challenge('create');
    if ($challenge === null) throw new UnexpectedValueException('challenge');
    $client = nm_b64url_decode((string)($in['clientDataJSON'] ?? ''));
    $att = nm_b64url_decode((string)($in['attestationObject'] ?? ''));
    if ($client === null || $att === null || strlen($att) > 65536) throw new UnexpectedValueException('input');
    nm_passkey_client_data($client, 'webauthn.create', $challenge);
    [$obj] = nm_cbor_decode($att);
    if (!is_array($obj) || !is_string($obj['authData'] ?? null) || !is_string($obj['fmt'] ?? null)) throw new UnexpectedValueException('att');
    $auth = nm_passkey_auth_data($obj['authData']);
    if (($auth['flags'] & 0x40) === 0 || strlen($auth['rest']) < 18) throw new UnexpectedValueException('att');
    // Attested credential data: AAGUID (16), id length (2), id, COSE key
    $idLen = unpack('n', substr($auth['rest'], 16, 2))[1];
    $id = substr($auth['rest'], 18, $idLen);
    if ($idLen < 16 || $idLen > 1023 || strlen($id) !== $idLen) throw new UnexpectedValueException('att');
    [$cose] = nm_cbor_decode($auth['rest'], 18 + $idLen);
    if (!is_array($cose)) throw new UnexpectedValueException('att');
    [$pem, $alg] = nm_cose_to_pem($cose);
    $id64 = nm_b64url($id);
    foreach (nm_passkeys() as $p) if (hash_equals($p['id'], $id64)) throw new UnexpectedValueException('exists');
    return ['id' => $id64, 'pem' => $pem, 'alg' => $alg, 'count' => $auth['count'], 'rp' => nm_passkey_rp_id(), 'name' => $name, 'created' => time(), 'used' => 0];
}

/**
 * Check a passkey login (navigator.credentials.get); returns the index of the passkey used.
 * $in: id, clientDataJSON, authenticatorData, signature, userHandle (base64url).
 */
function nm_passkey_assert(array $in): int
{
    $challenge = nm_passkey_take_challenge('get');
    if ($challenge === null) throw new UnexpectedValueException('challenge');
    $id = (string)($in['id'] ?? '');
    $client = nm_b64url_decode((string)($in['clientDataJSON'] ?? ''));
    $auth = nm_b64url_decode((string)($in['authenticatorData'] ?? ''));
    $sig = nm_b64url_decode((string)($in['signature'] ?? ''));
    if ($client === null || $auth === null || $sig === null || strlen($client) > 8192 || strlen($auth) > 8192 || strlen($sig) > 1024) throw new UnexpectedValueException('input');
    $cfg = nm_config();
    $index = null;
    foreach (nm_passkeys($cfg) as $i => $p) if ($id !== '' && hash_equals($p['id'], $id)) $index = $i;
    if ($index === null) throw new UnexpectedValueException('unknown');
    $handle = (string)($in['userHandle'] ?? '');
    if ($handle !== '' && !hash_equals((string)($cfg['passkey_user'] ?? ''), $handle)) throw new UnexpectedValueException('user');
    nm_passkey_client_data($client, 'webauthn.get', $challenge);
    $data = nm_passkey_auth_data($auth);
    $key = nm_passkeys($cfg)[$index];
    if (openssl_verify($auth . hash('sha256', $client, true), $sig, $key['pem'], OPENSSL_ALGO_SHA256) !== 1) throw new UnexpectedValueException('signature');
    // Signature counter: providers that sync passkeys (iCloud, Proton Pass…) always send 0; a counter that goes back means a copied key.
    if (($data['count'] !== 0 || $key['count'] !== 0) && $data['count'] <= $key['count']) {
        nm_log('passkey_counter', $key['name']);
        throw new UnexpectedValueException('counter');
    }
    $list = nm_passkeys($cfg);
    $list[$index]['count'] = $data['count'];
    $list[$index]['used'] = time();
    $cfg['passkeys'] = $list;
    nm_save_config($cfg);
    return $index;
}

function nm_passkey_add(array $record): void
{
    $cfg = nm_config();
    $list = nm_passkeys($cfg);
    if (count($list) >= NM_PASSKEY_MAX) throw new UnexpectedValueException('max');
    $list[] = $record;
    $cfg['passkeys'] = $list;
    nm_save_config($cfg);
}
function nm_passkey_remove(string $id): bool
{
    $cfg = nm_config();
    $list = nm_passkeys($cfg);
    $left = array_values(array_filter($list, static fn($p) => !hash_equals($p['id'], $id)));
    if (count($left) === count($list)) return false;
    $cfg['passkeys'] = $left;
    nm_save_config($cfg);
    return true;
}
/** A name for the list ("iPhone", "Proton Pass"…), one line, short. */
function nm_passkey_name(string $name): string
{
    return mb_substr(trim((string)preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $name)), 0, NM_PASSKEY_NAME_MAX);
}
