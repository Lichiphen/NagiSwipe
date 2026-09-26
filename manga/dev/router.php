<?php
/*
 * Development router for `php -S` (the built-in server ignores .htaccess).
 * Mirrors the deny rules of the shipped .htaccess files. Not for production.
 */
$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$deny = preg_match('~(^|/)\.~', $path)                                    // dot files
    || preg_match('~^/(nagimanga/)?(lib|data|plugins)(/|$)~', $path)       // lib/, data/, plugins/ (also under /nagimanga/)
    || preg_match('~\.(md|log|lock|bak|tmp|json|ini|sh|zip)$~i', $path);    // root FilesMatch

// Static (no PHP) mode test files: /_dev/static/* -> dev/results/static/*
if (preg_match('~^/_dev/static/([A-Za-z0-9_.-]+)$~', $path, $m)) {
    $f = __DIR__ . '/results/static/' . $m[1];
    if (is_file($f)) {
        $types = ['json' => 'application/json', 'png' => 'image/png', 'html' => 'text/html; charset=UTF-8'];
        header('Content-Type: ' . ($types[pathinfo($f, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
        readfile($f);
        return true;
    }
}

// Development pages (demo.html written by seed.py)
if (preg_match('~^/_dev/([a-z0-9_-]+\.html)$~', $path, $m)) {
    $f = __DIR__ . '/results/' . $m[1];
    if (is_file($f)) {
        header('Content-Type: text/html; charset=UTF-8');
        readfile($f);
        return true;
    }
}

if ($deny) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Directory without index: no listing (Options -Indexes)
$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if (is_dir($file) && !is_file(rtrim($file, '/') . '/index.php') && !is_file(rtrim($file, '/') . '/index.html')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

return false; // let the built-in server handle it
