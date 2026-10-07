<?php
/*
 * NagiManga EPUB import (fixed-layout comics)
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Reads the pages of an image-based EPUB (e.g. CLIP STUDIO PAINT export) in
 * spine order and imports every page image through nm_import_image (decoded
 * and re-encoded like any upload). Text, scripts and styles are ignored.
 *
 * The EPUB is treated as hostile input: nothing is extracted to disk except
 * the images being imported, every entry is read with a size cap, paths never
 * leave the archive, XML is parsed without network or external entities, and
 * encrypted (DRM) books are refused.
 */
declare(strict_types=1);

if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

const NM_EPUB_MAX_PAGES = 1000;
const NM_EPUB_MAX_XML = 2 * 1024 * 1024;
const NM_EPUB_MAX_IMAGE = 60 * 1024 * 1024;

/**
 * @return array{title:string, direction:string, pages:array<int,array{entry:string, index:int}>}|string
 *         book info, or an error message for the admin
 */
function nm_epub_inspect(ZipArchive $zip): array|string
{
    if ($zip->numFiles > 20000) return nm_t('ファイル数が多すぎます');
    if ($zip->locateName('META-INF/encryption.xml') !== false) {
        return nm_t('この EPUB は暗号化（DRM）されているため読めません。自分で書き出した EPUB を使ってください');
    }

    // container.xml -> the package document (OPF)
    $container = nm_epub_xml($zip, 'META-INF/container.xml');
    if (!$container) return nm_t('EPUB として読めません（container.xml がありません）');
    $opfPath = '';
    foreach ($container->getElementsByTagName('rootfile') as $rf) {
        $opfPath = nm_epub_path('', (string)$rf->getAttribute('full-path'));
        if ($opfPath !== null && $opfPath !== '') break;
    }
    if (!$opfPath) return nm_t('EPUB として読めません（目録が見つかりません）');
    $opf = nm_epub_xml($zip, $opfPath);
    if (!$opf) return nm_t('EPUB の目録を読めません');
    $opfDir = str_contains($opfPath, '/') ? substr($opfPath, 0, strrpos($opfPath, '/') + 1) : '';

    $title = '';
    foreach ($opf->getElementsByTagNameNS('http://purl.org/dc/elements/1.1/', 'title') as $t) {
        $title = trim((string)$t->textContent);
        if ($title !== '') break;
    }

    // manifest: id -> item
    $items = [];
    foreach ($opf->getElementsByTagName('item') as $it) {
        $id = (string)$it->getAttribute('id');
        $href = nm_epub_path($opfDir, (string)$it->getAttribute('href'));
        if ($id === '' || $href === null) continue;
        $items[$id] = [
            'href' => $href,
            'type' => strtolower((string)$it->getAttribute('media-type')),
            'fallback' => (string)$it->getAttribute('fallback'),
        ];
    }

    $spine = $opf->getElementsByTagName('spine')->item(0);
    if (!$spine) return nm_t('EPUB の目録にページの順番がありません');
    $ppd = strtolower((string)$spine->getAttribute('page-progression-direction'));
    $direction = $ppd === 'ltr' ? 'ltr' : 'rtl';

    $pages = [];
    foreach ($spine->getElementsByTagName('itemref') as $ref) {
        if (strtolower((string)$ref->getAttribute('linear')) === 'no') continue;
        $item = $items[(string)$ref->getAttribute('idref')] ?? null;
        if (!$item) continue;
        $img = nm_epub_page_image($zip, $item, $items);
        if ($img === null) continue;
        $index = $zip->locateName($img);
        if ($index === false) continue;
        $pages[] = ['entry' => $img, 'index' => $index];
        if (count($pages) > NM_EPUB_MAX_PAGES) return nm_t('ページが多すぎます（{n} ページまで）', ['n' => NM_EPUB_MAX_PAGES]);
    }
    if (!$pages) return nm_t('画像のページが見つかりません（文章だけの EPUB には対応していません）');

    return ['title' => mb_substr($title, 0, 200), 'direction' => $direction, 'pages' => $pages];
}

/**
 * The image shown on one spine item: the item itself if it is an image,
 * otherwise the largest <img> / SVG <image> in its XHTML, otherwise its
 * manifest fallback image.
 */
function nm_epub_page_image(ZipArchive $zip, array $item, array $items): ?string
{
    if (str_starts_with($item['type'], 'image/')) return $item['href'];

    if (str_contains($item['type'], 'html') || str_ends_with($item['href'], '.xhtml') || str_ends_with($item['href'], '.html')) {
        $doc = nm_epub_xml($zip, $item['href']);
        if ($doc) {
            $dir = str_contains($item['href'], '/') ? substr($item['href'], 0, strrpos($item['href'], '/') + 1) : '';
            $best = null;
            $bestArea = -1;
            $candidates = [];
            foreach ($doc->getElementsByTagName('img') as $el) $candidates[] = [$el, (string)$el->getAttribute('src')];
            foreach ($doc->getElementsByTagNameNS('http://www.w3.org/2000/svg', 'image') as $el) {
                $href = (string)$el->getAttributeNS('http://www.w3.org/1999/xlink', 'href');
                if ($href === '') $href = (string)$el->getAttribute('href');
                $candidates[] = [$el, $href];
            }
            foreach ($candidates as [$el, $src]) {
                $path = nm_epub_path($dir, $src);
                if ($path === null) continue;
                $area = (float)$el->getAttribute('width') * (float)$el->getAttribute('height');
                if ($area > $bestArea) {
                    $best = $path;
                    $bestArea = $area;
                }
            }
            if ($best !== null) return $best;
        }
    }

    $fb = $items[$item['fallback']] ?? null;
    return $fb && str_starts_with($fb['type'], 'image/') ? $fb['href'] : null;
}

/**
 * Resolve an href inside the archive. Returns null for anything that is not a
 * plain relative path within the EPUB (URLs, data:, absolute paths, escaping ..).
 */
function nm_epub_path(string $baseDir, string $href): ?string
{
    $href = trim($href);
    if ($href === '' || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href) || str_starts_with($href, '/') || str_contains($href, '\\')) {
        return null;
    }
    $href = rawurldecode(strtok($href, '#?'));
    if (str_contains($href, "\0")) return null;
    $parts = [];
    foreach (explode('/', $baseDir . $href) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') {
            if (!$parts) return null; // would leave the archive
            array_pop($parts);
            continue;
        }
        $parts[] = $seg;
    }
    return $parts ? implode('/', $parts) : null;
}

/** Parse an XML entry safely (no network, no entities, no DTD). */
function nm_epub_xml(ZipArchive $zip, string $name): ?DOMDocument
{
    $index = $zip->locateName($name);
    if ($index === false) return null;
    $raw = nm_zip_read($zip, $index, NM_EPUB_MAX_XML);
    if ($raw === null || $raw === '') return null;
    // Entity declarations are never needed for EPUB: refuse them (XXE / billion laughs)
    if (preg_match('/<!ENTITY/i', $raw)) {
        nm_log('epub_entity_refused', $name);
        return null;
    }
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($raw, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? $doc : null;
}

/**
 * Import all pages of an uploaded EPUB into a new work.
 *
 * @return array{id:string, count:int, skipped:int, title:string}|string
 */
function nm_epub_import(string $epubPath, string $titleOverride, string $series, array $cfg): array|string
{
    if (!class_exists('ZipArchive')) return nm_t('サーバーの PHP に ZipArchive がありません');
    @set_time_limit(0);

    $zip = new ZipArchive();
    if ($zip->open($epubPath, ZipArchive::RDONLY) !== true) return nm_t('EPUB（ZIP）として開けません');
    try {
        $book = nm_epub_inspect($zip);
        if (is_string($book)) return $book;

        $title = $titleOverride !== '' ? $titleOverride : ($book['title'] !== '' ? $book['title'] : '無題');
        $id = nm_random_id();
        while (is_dir(nm_work_dir($id))) $id = nm_random_id();
        nm_create_work_dirs($id);
        $dir = nm_work_dir($id) . '/pages';

        $pages = [];
        $skipped = 0;
        $tmp = NM_DATA . '/tmp/epub-' . bin2hex(random_bytes(6));
        nm_ensure_dir(dirname($tmp));
        try {
            foreach ($book['pages'] as $i => $p) {
                $bytes = nm_zip_read($zip, $p['index'], NM_EPUB_MAX_IMAGE);
                if ($bytes === null) {
                    $skipped++;
                    continue;
                }
                file_put_contents($tmp, $bytes);
                unset($bytes);
                $res = nm_import_image($tmp, $dir, $i + 1, (int)($cfg['image_quality'] ?? 90), NM_EPUB_MAX_IMAGE);
                if (is_string($res)) {
                    nm_log('epub_page_rejected', "$id " . mb_substr($p['entry'], 0, 100) . ' ' . $res);
                    $skipped++;
                    continue;
                }
                $pages[] = ['f' => $res['f'], 'w' => $res['w'], 'h' => $res['h'], 'o' => mb_substr(basename($p['entry']), 0, 200)];
            }
        } finally {
            @unlink($tmp);
        }

        if (!$pages) {
            nm_rmdir_recursive(nm_work_dir($id));
            return nm_t('取り込める画像がありませんでした');
        }
        nm_save_work([
            'id' => $id,
            'title' => mb_substr($title, 0, 200),
            'series' => mb_substr($series, 0, 200),
            'direction' => $book['direction'],
            'password_hash' => '',
            'seq' => count($pages),
            'created' => time(),
            'pages' => $pages,
        ]);
        return ['id' => $id, 'count' => count($pages), 'skipped' => $skipped, 'title' => $title];
    } finally {
        $zip->close();
    }
}

/**
 * Attach the pages of a light (small) EPUB to an existing work, page by page
 * in reading order.
 *
 * @return array{attached:int, pages:int, epubPages:int}|string
 */
function nm_epub_attach_light(string $id, string $epubPath, array $cfg): array|string
{
    if (!class_exists('ZipArchive')) return nm_t('サーバーの PHP に ZipArchive がありません');
    $work = nm_load_work($id);
    if (!$work) return nm_t('作品が見つかりません');
    @set_time_limit(0);

    $zip = new ZipArchive();
    if ($zip->open($epubPath, ZipArchive::RDONLY) !== true) return nm_t('EPUB（ZIP）として開けません');
    try {
        $book = nm_epub_inspect($zip);
        if (is_string($book)) return $book;

        $dir = nm_work_dir($id) . '/pages';
        $tmp = NM_DATA . '/tmp/epub-' . bin2hex(random_bytes(6));
        nm_ensure_dir(dirname($tmp));
        $imported = []; // page index => light info
        try {
            foreach ($book['pages'] as $i => $p) {
                if ($i >= count($work['pages'])) break;
                $bytes = nm_zip_read($zip, $p['index'], NM_EPUB_MAX_IMAGE);
                if ($bytes === null) continue;
                file_put_contents($tmp, $bytes);
                unset($bytes);
                $res = nm_import_image($tmp, $dir, $i + 1, (int)($cfg['image_quality'] ?? 90), NM_EPUB_MAX_IMAGE, false);
                if (is_string($res)) {
                    nm_log('epub_page_rejected', "$id light " . mb_substr($p['entry'], 0, 100) . ' ' . $res);
                    continue;
                }
                $imported[$i] = $res;
            }
        } finally {
            @unlink($tmp);
        }

        nm_with_lock('work-' . $id, static function () use ($id, $imported, $dir) {
            $w = nm_load_work($id);
            if (!$w) {
                foreach ($imported as $m) nm_delete_page_files($dir, $m['f']);
                return;
            }
            foreach ($imported as $i => $m) {
                if (!isset($w['pages'][$i])) {
                    nm_delete_page_files($dir, $m['f']);
                    continue;
                }
                if (isset($w['pages'][$i]['m']['f'])) nm_delete_page_files($dir, $w['pages'][$i]['m']['f']);
                $w['pages'][$i]['m'] = $m;
            }
            nm_save_work($w);
        });
        return ['attached' => count($imported), 'pages' => count($work['pages']), 'epubPages' => count($book['pages'])];
    } finally {
        $zip->close();
    }
}
