<?php
/*
 * NagiManga plugin: guest mode (read-only admin for demos)
 * Copyright (c) 2026 Lichiphen
 * Licensed under the MIT License
 *
 * Put this file into the server's plugins/ folder to let visitors look around
 * the admin without being able to change anything:
 *
 *   nagimanga/plugins/guest-mode.php
 *
 * Visitors enter through  admin/index.php?guest   (or ?guest=KEY, see below).
 * Delete the file to close the entrance again; guests inside are logged out.
 *
 * What a guest can and cannot do is enforced by NagiManga itself, not by this
 * file: guests can see the works, pages, share tags and preview, but every
 * change (upload, reorder, delete, passwords, backup, settings) is refused,
 * and the settings page, login URL, IP list, security log and reader
 * passwords are never shown to them. Your own admin login keeps working as
 * before.
 */
if (!defined('NAGIMANGA')) {
    http_response_code(404);
    exit;
}

nm_add_filter('guest_mode', static fn() => [
    // '' = anyone can enter with admin/index.php?guest
    // 'something' = only admin/index.php?guest=something
    'enter_key' => '',

    // true = guests may enter even when the admin is limited to your IP address.
    // (Your own admin login stays limited to the IP list either way.)
    'bypass_ip' => true,

    // Shown at the top of every admin page for guests
    'banner' => 'デモ用のゲスト表示です。管理画面の中を見ることはできますが、変更はできません。',

    // Link on the "guest ended" page, e.g. your demo page ('' = no link)
    'exit_url' => '',
]);
