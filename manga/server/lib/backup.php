<?php
/*
 * NagiManga backup / restore (ZIP)
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * A backup holds works/<id>/work.json and works/<id>/pages/* only. Settings
 * (admin password, secret key) are never exported.
 *
 * Restore treats the ZIP as hostile: only entries that match the exact layout
 * are read, entry by entry, with size caps (no extractTo, no path from the ZIP
 * ever reaches the file system unchecked), and every image is checked again.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_BACKUP_MARK = 'nagimanga-backup.json';
const NM_RESTORE_MAX_ENTRIES = 20000;
const NM_RESTORE_MAX_ENTRY = 60 * 1024 * 1024;       // per file
const NM_RESTORE_MAX_TOTAL = 4 * 1024 * 1024 * 1024; // whole archive, uncompressed

function nm_zip_available(): bool
{
    return class_exists('ZipArchive');
}

/**
 * Build a ZIP of the given works (all when empty) and send it.
 */
function nm_backup_download(array $ids = []): never
{
    if (!nm_zip_available()) nm_not_found();
    @set_time_limit(0);

    $works = $ids ? array_filter(array_map('nm_load_work', $ids)) : nm_list_works();
    $tmp = NM_DATA . '/tmp/backup-' . bin2hex(random_bytes(6)) . '.zip';
    nm_ensure_dir(dirname($tmp));

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::EXCL) !== true) nm_not_found();
    $zip->addFromString(NM_BACKUP_MARK, json_encode([
        'app' => 'NagiManga',
        'version' => NM_VERSION,
        'created' => date('c'),
        'works' => array_values(array_map(static fn($w) => $w['id'], $works)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    foreach ($works as $w) {
        $dir = nm_work_dir($w['id']);
        $zip->addFile("$dir/work.json", "works/{$w['id']}/work.json");
        foreach ($w['pages'] as $p) {
            foreach (['', 't_'] as $prefix) {
                $f = "$dir/pages/$prefix{$p['f']}";
                if (!is_file($f)) continue;
                $name = "works/{$w['id']}/pages/$prefix{$p['f']}";
                $zip->addFile($f, $name);
                // Images are already compressed: store them as is (fast)
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        }
    }
    $zip->close();

    $label = count($ids) === 1 && $works ? '-' . reset($works)['id'] : '';
    $name = 'nagimanga-backup' . $label . '-' . date('Ymd-His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/**
 * @return array{restored:int, skipped:int, errors:string[]}
 */
function nm_backup_restore(string $zipPath, bool $overwrite): array
{
    $result = ['restored' => 0, 'skipped' => 0, 'errors' => []];
    if (!nm_zip_available()) {
        $result['errors'][] = 'サーバーの PHP に ZipArchive がありません';
        return $result;
    }
    @set_time_limit(0);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        $result['errors'][] = 'ZIP として開けません';
        return $result;
    }

    try {
        if ($zip->numFiles > NM_RESTORE_MAX_ENTRIES) {
            $result['errors'][] = 'ファイル数が多すぎます';
            return $result;
        }
        if ($zip->locateName(NM_BACKUP_MARK) === false) {
            $result['errors'][] = 'NagiManga のバックアップではありません';
            return $result;
        }

        // Group valid entries by work; anything else is ignored (and logged)
        $byWork = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if (!$st) continue;
            $name = (string)$st['name'];
            if ($name === NM_BACKUP_MARK || str_ends_with($name, '/')) continue;
            if (!preg_match('~\Aworks/([A-Za-z0-9]{12})/(work\.json|pages/(?:t_)?p[0-9]{4}_[a-f0-9]{8}\.(?:webp|jpg))\z~', $name, $m)) {
                nm_log('restore_ignored_entry', mb_substr($name, 0, 120));
                continue;
            }
            if ($st['size'] > NM_RESTORE_MAX_ENTRY) {
                $result['errors'][] = '大きすぎるファイルを含んでいます';
                return $result;
            }
            $total += $st['size'];
            if ($total > NM_RESTORE_MAX_TOTAL) {
                $result['errors'][] = 'バックアップが大きすぎます';
                return $result;
            }
            $byWork[$m[1]][$m[2]] = $i;
        }

        foreach ($byWork as $id => $entries) {
            $err = nm_restore_work($zip, (string)$id, $entries, $overwrite);
            if ($err === null) $result['restored']++;
            elseif ($err === 'exists') $result['skipped']++;
            else $result['errors'][] = "$id: $err";
        }
    } finally {
        $zip->close();
    }
    return $result;
}

/** @return string|null error, 'exists', or null on success */
function nm_restore_work(ZipArchive $zip, string $id, array $entries, bool $overwrite): ?string
{
    if (!isset($entries['work.json'])) return 'work.json がありません';
    $finalDir = nm_work_dir($id);
    if (is_dir($finalDir) && !$overwrite) return 'exists';

    $raw = nm_zip_read($zip, $entries['work.json'], 1024 * 1024);
    $data = $raw === null ? null : json_decode($raw, true);
    if (!is_array($data)) return 'work.json が壊れています';
    $work = nm_sanitize_work($data, $id);

    // Stage everything in a temp dir, swap in at the end
    $stage = NM_DATA . '/tmp/restore-' . bin2hex(random_bytes(6));
    nm_ensure_dir("$stage/pages");
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    try {
        $kept = [];
        foreach ($work['pages'] as $p) {
            foreach (['', 't_'] as $prefix) {
                $key = "pages/$prefix{$p['f']}";
                if (!isset($entries[$key])) {
                    if ($prefix === '') continue 2; // page without image: drop the page
                    continue;
                }
                $bytes = nm_zip_read($zip, $entries[$key], NM_RESTORE_MAX_ENTRY);
                if ($bytes === null) return '画像を読めません';
                $dest = "$stage/pages/$prefix{$p['f']}";
                file_put_contents($dest, $bytes);
                // Must really be the image type its name says
                $want = str_ends_with($p['f'], '.webp') ? 'image/webp' : 'image/jpeg';
                $info = @getimagesize($dest);
                if ($finfo->file($dest) !== $want || !$info || ($info['mime'] ?? '') !== $want) {
                    nm_log('restore_bad_image', "$id/$prefix{$p['f']}");
                    return '画像の中身が正しくありません';
                }
                if ($prefix === '') {
                    $p['w'] = (int)$info[0];
                    $p['h'] = (int)$info[1];
                }
            }
            $kept[] = $p;
        }
        $work['pages'] = $kept;
        nm_write_json("$stage/work.json", $work);
        // Empty index files against directory listings
        @file_put_contents("$stage/index.html", '');
        @file_put_contents("$stage/pages/index.html", '');
        nm_ensure_works_root();

        return nm_with_lock('work-' . $id, static function () use ($stage, $finalDir) {
            nm_ensure_dir(dirname($finalDir));
            if (is_dir($finalDir)) nm_rmdir_recursive($finalDir);
            if (!@rename($stage, $finalDir)) return '保存できませんでした';
            return null;
        });
    } finally {
        if (is_dir($stage)) nm_rmdir_recursive($stage);
    }
}

/** Read one entry with a hard size cap (the declared size may lie). */
function nm_zip_read(ZipArchive $zip, int $index, int $max): ?string
{
    $fh = $zip->getStream($zip->getNameIndex($index));
    if (!$fh) return null;
    $buf = '';
    while (!feof($fh)) {
        $chunk = fread($fh, 65536);
        if ($chunk === false) break;
        $buf .= $chunk;
        if (strlen($buf) > $max) {
            fclose($fh);
            return null;
        }
    }
    fclose($fh);
    return $buf;
}

/** Keep only known fields with sane values. */
function nm_sanitize_work(array $d, string $id): array
{
    $title = is_string($d['title'] ?? null) ? mb_substr(trim($d['title']), 0, 200) : '';
    $pages = [];
    $seen = [];
    foreach (($d['pages'] ?? []) as $p) {
        if (!is_array($p) || !is_string($p['f'] ?? null) || !preg_match(NM_PAGE_PATTERN, $p['f'])) continue;
        if (isset($seen[$p['f']])) continue;
        $seen[$p['f']] = true;
        $pages[] = [
            'f' => $p['f'],
            'w' => (int)($p['w'] ?? 0),
            'h' => (int)($p['h'] ?? 0),
            'o' => is_string($p['o'] ?? null) ? mb_substr($p['o'], 0, 200) : '',
        ];
    }
    return [
        'id' => $id,
        'title' => $title !== '' ? $title : '無題',
        'series' => is_string($d['series'] ?? null) ? mb_substr(trim($d['series']), 0, 200) : '',
        'direction' => in_array($d['direction'] ?? '', ['rtl', 'ltr', 'vertical'], true) ? $d['direction'] : 'rtl',
        // Password hashes are restored as is (they are hashes, not passwords)
        'password_hash' => is_string($d['password_hash'] ?? null) && str_starts_with($d['password_hash'], '$') ? $d['password_hash'] : '',
        'seq' => max((int)($d['seq'] ?? 0), count($pages)),
        'created' => (int)($d['created'] ?? time()),
        'updated' => time(),
        'pages' => $pages,
    ];
}
