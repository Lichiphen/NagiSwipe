<?php
/* Public LOG login entry, sharing the existing admin session. MIT (c) 2026 Lichiphen. */
declare(strict_types=1);
define('NL_PUBLIC_LOGIN', true);
unset($_GET['guest']);
$_GET['p'] = 'log';
require __DIR__ . '/index.php';
