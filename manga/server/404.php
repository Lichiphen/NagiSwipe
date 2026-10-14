<?php
declare(strict_types=1);
define('NAGIMANGA', true);
require __DIR__ . '/lib/bootstrap.php';
// The 404 page speaks the language of the public pages.
nm_lang(nm_site_lang());
nm_not_found();
