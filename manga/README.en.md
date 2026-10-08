# NagiManga (manga viewer)

[日本語](README.md) | English

The manga viewer of NagiSeries ([NagiSwipe](../README.en.md#nagiswipe), NagiManga and [NagiLog](README-LOG.en.md)).

Paste **a single tag** into your blog or site, and a manga viewer opens when it is clicked. Works are managed in a small admin panel (PHP) that needs no database.

Since v0.4.0, NagiManga includes NagiLog, a personal log for posting text, pictures and manga.
It has a posting screen for phones, bold text, picture replacement, manga cards, categories, six designs and URLs made from the date and time. Since v0.6.0 it can also post video and audio.
How to use it, its strengths and weaknesses, installation and test results are in the [NagiLog README](README-LOG.en.md).

The admin panel can be shown in Japanese or English (see [Admin panel language](#admin-panel-language)). Some names of buttons and menus below are given in Japanese too, as they appear in the Japanese panel.

<!-- TOC -->
## Contents

- [Demo](#demo)
- [What it does](#what-it-does)
- [Two ways to use it](#two-ways-to-use-it)
- [With the admin panel (PHP)](#with-the-admin-panel-php)
  - [1. Upload](#1-upload)
  - [2. Setup (within 30 minutes)](#2-setup-within-30-minutes)
  - [3. Checks after installing](#3-checks-after-installing)
  - [4. Make a work and share it](#4-make-a-work-and-share-it)
  - [Posting to Tegalog](#posting-to-tegalog)
- [Features](#features)
  - [Making a work from EPUB](#making-a-work-from-epub)
  - [Light versions (for phones)](#light-versions-for-phones)
  - [Passwords (limited release)](#passwords-limited-release)
  - [Backup](#backup)
  - [Updates (v0.2.0 and later)](#updates-v020-and-later)
  - [Admin panel language](#admin-panel-language)
  - [Embed code](#embed-code)
  - [Work pages (share links, v0.3.0 and later)](#work-pages-share-links-v030-and-later)
  - [Hotlink protection (advanced, v0.3.0 and later)](#hotlink-protection-advanced-v030-and-later)
  - [Plugins (guest mode)](#plugins-guest-mode)
  - [Troubleshooting](#troubleshooting)
  - [Protecting the data folder](#protecting-the-data-folder)
  - [Tighter IP restriction for the admin panel (optional)](#tighter-ip-restriction-for-the-admin-panel-optional)
- [Without PHP (FTP only)](#without-php-ftp-only)
- [Security approach](#security-approach)
  - [Attack tests](#attack-tests)
- [Development](#development)
- [License](#license)
<!-- /TOC -->

## Demo

**https://notebook.lichiphen.com/nagimanga/demo.html**

- Try the ways of reading (right to left with spreads / left to right / vertical, as paged manga or webtoon)
- There is a password-protected sample (the password is `demo`) and a work imported from a CLIP STUDIO PAINT EPUB
- "ゲストとして管理画面を見る" (view the admin panel as a guest) shows the author's admin panel, read only (nothing can be changed)

**NagiLog demo: https://notebook.lichiphen.com/nagimanga/**

- The log the author actually uses. You can see posts, the phone menu, the mail dialog and the 404 page (inside a black hole)
- Screenshots and explanations are in the [NagiLog README](README-LOG.en.md#screens)

**If you are deciding whether to use it, or want to keep using it for years:** strengths and weaknesses, the technology used and how to take your data out are in [docs/overview.en.md](docs/overview.en.md) (for non-developers).

## What it does

**For readers (the viewer)**

- Right to left (manga) / left to right / vertical (paged manga, each page fitted to the screen, and full-width webtoon)
- Spreads on wide screens (with or without a single cover page). A wide single picture becomes its own page automatically
- Page turning: swipe, tap the screen edges, the ◀ ▶ buttons on the bottom bar, the keyboard, the mouse wheel (vertical reading can also be dragged with the mouse)
- Zoom: pinch, double tap, and on computers the "− 100% ＋" buttons on the top bar, the `+` `-` `0` keys and Ctrl + wheel (move the mouse near the top or bottom edge to show the bars)
- Bookmark (the next time it opens where you stopped), the browser's Back closes it
- Small pictures (light versions) on phones and large ones (normal versions) on computers and when zoomed, chosen automatically

**For creators (the admin panel)**

- Drag and drop pictures, reorder them, issue share tags (the URL is made from the domain you installed on)
- **Make a work directly from a manga EPUB**, such as one from CLIP STUDIO PAINT
- Password-protected releases (readers enter the password when they click)
- Backup and restore (one ZIP)
- A plugin that shows the admin panel to guests, read only (for demos)

It uses no database and no outside libraries (only the standard features of PHP and JavaScript).

## Two ways to use it

| | With the admin panel (PHP) | Without PHP (FTP only) |
|---|---|---|
| For | People who want to manage works in an admin panel (FTP only for the first upload) | People who know their way around folders and URLs |
| Adding pictures | Drag and drop in the admin panel, or from EPUB | Upload with FTP |
| Password protection | Yes | No (anyone who knows a picture's URL can see it) |
| Needs | PHP 8.1 or later (GD, fileinfo, mbstring, ZipArchive); a server where `.htaccess` works (Apache family) is recommended | Any server that can serve static files |

---

## With the admin panel (PHP)

### 1. Upload

Upload `nagimanga-*.zip` from the [releases page](https://github.com/Lichiphen/NagiSwipe/releases) (or the contents of `manga/server` in this repository) to any place on your server, under a name such as `nagimanga`.

```
nagimanga/
├── read.php          the window for readers (read only)
├── index.php         the public entrance of NagiLog (no file name needed in the URL)
├── log.php           the log itself, and redirects from older URLs
├── .htaccess
├── admin/            the admin panel
├── viewer/           the viewer (NagiManga.js / NagiManga.css)
├── plugins/          where plugins go (empty at first)
├── lib/              the program (not visible from outside)
└── data/             pictures and settings of the works (not visible from outside)
```

- **Do not forget to upload `.htaccess`.** Some FTP programs hide files starting with `.` and do not send them (turn on "show hidden files" to see them). There are five: in `nagimanga/`, `admin/`, `lib/`, `data/` and `plugins/`.
- Make the `data` folder writable (on most servers the default 755 is fine).

### 2. Setup (within 30 minutes)

Open `https://your-site/nagimanga/admin/` in a browser and choose the administrator password (10 characters or more).

- This screen is shown **only for 30 minutes after it is first opened** (so that nobody else sets it up first). If the time has passed, delete `data/install.lock` with FTP and open it again.
- Turn on "allow the admin panel only from the current IP address" whenever you can.

When it is done, **the login URL** is shown. **Bookmark it.** Opening the admin panel directly gives "Not Found". Since v0.4.0 you can also go through "ログイン" (Log in) on the public site to a password-only entrance. The IP restriction and the limit on attempts apply there too.

### 3. Checks after installing

- Log in with the bookmarked URL and make sure the work list **does not show the warning "the data folder can be seen from the internet"** (if it does, `.htaccess` did not arrive or does not work; see "Protecting the data folder" below)
- Opening `https://your-site/nagimanga/data/` gives an error such as "Forbidden", not the contents
- Opening `https://your-site/nagimanga/admin/` as it is gives "Not Found" (proof that setup is done)

### 4. Make a work and share it

1. Make a work in the admin panel and drag and drop the page pictures all at once (they are ordered by file name: 001, 002, …). If you have an EPUB, use "EPUB から作る" (make from EPUB).
2. On the work's page, choose the reading direction and spreads under "共有用のタグ" (share tag), press "コピー" (Copy) and paste it into your blog's HTML.

```html
<a href="#" data-nagimanga="Ab3dE5gH7jK9" data-endpoint="https://example.com/nagimanga/read.php"
   data-direction="rtl" data-view="auto" data-cover="1">Read chapter 1</a>
<script src="https://example.com/nagimanga/viewer/NagiManga.js?v=..." defer></script>
```

| Attribute | Value |
|---|---|
| `data-direction` | `rtl` (right to left) / `ltr` (left to right) / `vertical` (vertical reading) |
| `data-view` | `auto` (spreads on wide screens) / `single` (always one page) |
| `data-cover` | `1` (page 1 alone, spreads start on odd pages) / `0` (spreads from page 1) |
| `data-vertical` | For vertical reading: omitted (paged manga: one page fits the screen height) / `webtoon` (full width, at most 900px) |

- One `<script>` line per page is enough. Put as many links as you like.
- The URL in the tag is made from the address you have the admin panel open at. Enter "設定 → 設置 URL" (Settings → Installation URL) only when you want a different one.
- **To embed on another site (domain)**, register that site's address (for example `https://blog.example.com`) under "設定 → 別のサイトに埋め込む場合" (Settings → Embedding on other sites).

### Posting to Tegalog

Tegalog posts cannot use the HTML tag for blogs. Use the **"共有リンク" (share link)** at the top of the work's page instead.

1. In Tegalog's admin panel, open **[設定] → [システム設定] → 【画像拡大スクリプトの選択】** and choose "**他のスクリプトを使う：URLを指定**".
2. Write NagiManga's URL in the "JavaScriptのURL" field and save. The **"設置用コード" (embed code)** screen of the admin panel has a line you can paste into this field as it is (NagiSwipe and NagiManga together, with cache busters).
   If you use NagiSwipe for zooming pictures, write it **after NagiSwipe's URL and one space** (on one line).

   ```
   https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@bbce2be/NagiSwipe-main.js https://example.com/nagimanga/viewer/NagiManga.js
   ```

   The part after `@` in the URL (such as `@93db749`) identifies the version of the file. When NagiSwipe is updated, the value in the README changes too. Paste the URL again when you want the new version (until then your site keeps the version you have). After updating NagiManga, paste the new line from "設置用コード" as well.

3. Make manga open **in posts without pictures**: Tegalog does not load the files above on pages of posts without pictures. In [設定] → [ページの表示] → 【投稿本文の表示／URL処理】, under "▼画像URLを画像として埋め込む表示", check **"画像リンクに独自のclass属性値を追加する"**, type `nagimanga` between `class="` and `"`, and save.
4. Open the work in NagiManga's admin panel and press "コピー" next to "共有リンク".
5. Post to Tegalog with the link text (in the example below, "Read chapter 1").

   ```
   [Read chapter 1]https://example.com/nagimanga/read.php?nagimanga=Ab3dE5gH7jK9&dir=rtl
   ```

Pressing "Read chapter 1" in the post opens the manga right there. The reading direction and spread settings are in the URL.

- The URL also works when opened elsewhere, such as in an RSS reader (a page for reading the work opens).
- If Tegalog and NagiManga are on addresses that start differently (the `https://…/` part), register Tegalog's address under "設定 → 別のサイトに埋め込む場合".
- On blogs where you can write HTML, keep using the share tag.

---

## Features

### Making a work from EPUB

A **manga EPUB** exported from CLIP STUDIO PAINT and similar (fixed layout, pages that are only pictures) can be imported with "EPUB から作る" on the work list.

- The page order, reading direction (right to left / left to right) and title come from the EPUB (type a title to replace it).
- If you also choose "小容量版 EPUB（任意）" (light version EPUB, optional), it is assigned as the light versions in the same order. You can add it later with "小容量版 EPUB から割り当てる" on the work's page.
- Pictures are checked and redrawn before saving, as with normal uploads. Blank pages that align spreads are kept.
- EPUBs with DRM (copy protection) and text-only EPUBs cannot be read.
- EPUBs larger than the server's upload limit (`upload_max_filesize` / `post_max_size`) cannot be imported. The limit is shown in the admin panel.

### Light versions (for phones)

Each page can have a smaller "light version". The "ページ" (Pages) part of the work's page has two places to upload.

- **Normal version (computers, zooming)**: the usual page pictures
- **Light version (phones, optional)**: the same pages made smaller. They are assigned in file name order from page 1 (001 → page 1). If the count differs from the number of pages, you are asked to confirm

The viewer reads the light version when it is enough for the size shown and the screen's pixel density, and switches to the normal version on large computer screens or when zoomed (it loads in the background and then swaps, so the page never flashes white). With the device's "data saver" on, light versions come first. Pages without a light version use the normal one.

- Pages with a light version are marked "小" in the list on the work's page
- "小容量版をすべて外す" removes them all (the normal versions stay)
- In password-protected works, light versions also need the key
- They are part of backups and restores

### Passwords (limited release)

Set it under "パスワード" on the work's page. Readers enter the password when they click the link.

- The password is entered **each time the viewer opens** (a correct password hands over a "viewing key" valid only while it is open, thrown away when it closes; the key itself also expires after 2 hours).
- Changing the password invalidates all earlier keys.
- You can see the password you set with "表示" (Show) under "現在のパスワード" on the work's page (it is stored encrypted with the server's secret key; after restoring to another server it cannot be shown, so set it again).
- After 5 wrong tries, the work cannot be tried for 15 minutes (against brute force).

### Backup

"バックアップ" downloads all works as one ZIP. Download it regularly and keep it on your computer or elsewhere.

- It holds the works (page pictures, light versions, order, titles, reading direction, reader passwords). Work IDs stay the same, so tags pasted into blogs keep working.
- It does not hold settings such as the administrator password, login URL and IP restriction, or the secret keys (you set them up again on the new server).
- Restore by uploading the ZIP on the same screen. Everything in the ZIP is checked before it is put back.
- If the server's PHP has no ZipArchive, copy the whole `data/works` folder with FTP.

### Updates (v0.2.0 and later)

When a new version comes out, a notice appears above the work list and above "LOG・投稿" (Log and posts) when you log in ("更新" (Update) in the menu also gets a "新" (new) mark). Press the button on the update screen to update on the spot. The release notes are shown formatted (headings, lists, bold, links).

- It downloads `nagimanga-vX.Y.Z.zip` from the official GitHub release and uses it only when it matches the checksum (SHA-256) GitHub publishes. The contents are checked one by one too.
- Only program files are replaced. Works and settings (the `data` folder), plugins and `lib/paths.php` stay as they are.
- The files from before the update are kept in `data/update/` (as `.bak` files that cannot run) and can be put back with "元に戻す" (Undo). The last 3 updates are kept.
- It checks at most once every 12 hours. On the update screen you can turn off the check at login or check right away.
- It cannot update by itself when the server cannot reach GitHub (neither PHP's curl nor allow_url_fopen), has no ZipArchive, or cannot write the program files. Then follow "手動で更新する" (update by hand) on the screen (upload everything except the `data` folder over the old files).
- From v0.1.0 to v0.2.0, update by hand (v0.1.0 has no update feature).

### Admin panel language

The admin panel can be shown in Japanese or English: the work list, work pages, Log and posts, categories, fixed pages, the media list, settings, backup, updates, the login screen and the messages shown when saving.

- Choose under "設定 › 共通・安全 › 管理画面の言語" (Settings › Common and security › Admin panel language). It starts as "自動" (Auto): the browser's language if it is supported, Japanese otherwise.
- The language of public pages (the log, work pages) does not change. Your own text, such as posts and category names, is not translated.

**Adding a language**: put a JSON file such as `fr.json` in `server/lib/lang/` and it appears in the settings (with a region, such as `zh-CN.json` for Chinese, it works too).

- It holds pairs of `{"Japanese text": "translation"}`. The Japanese text in the program is the key as it is. Write the language name to show in the choices (for example `"Français"`) as `"_name"`. Keys starting with `_` are notes for translators and are not shown.
- Keep placeholders such as `{n}` in the translation. Do not use `'` `"` `&` `<` `>` in translations; use `’` for apostrophes.
- Texts without a translation are shown in Japanese. `python dev/i18n_test.py` checks for missing translations, mismatched placeholders and characters that cannot be used.
- When you add text to the screens, pass it through `nm_t('text')` in PHP or `t('text')` in JS and add the translation to each JSON in `lib/lang/`. For sentences with numbers or names, make the whole sentence the key, as in `nm_t('{n}件の記録', ['n' => $count])`.

### Embed code

"設置用コード" (embed code) in the menu gathers the code for loading the viewer.

- The viewer's URL (the `?v=…` at the end is a **cache buster**: it changes when NagiManga is updated, so readers' browsers do not keep an old viewer)
- A line you can paste as it is into Tegalog's "JavaScriptのURL" field (together with NagiSwipe), and the steps on the Tegalog side
- The `<script>` tag for blogs and HTML

**After updating NagiManga, open this screen again and paste the new values.**

### Work pages (share links, v0.3.0 and later)

The **"共有リンク" (share link)** at the top of the work's page (`read.php?nagimanga=…`) opens a page for reading that work, and the viewer opens by itself.

- Paste this link where manga cannot be embedded, such as note, Ameba Blog, Instagram or X. It shows as a **card with the cover** (OGP). Password-protected works show NagiManga's common picture instead of the cover.
- The page has the cover, the title, a "読む" (Read) button and a **"戻る" (Back) button**. Its destination is set under "設定 → 個別ページ" (empty means automatic: back to the page you came from; when you came from an app such as Instagram and there is nowhere to go back to, the button is not shown).
- "非公開にする" (make private) on a work's page turns its work page off. Viewers embedded in blogs and Tegalog keep working.
- Work IDs are random strings that cannot be guessed. Existing works keep working as they are (no need to make them again).

### Hotlink protection (advanced, v0.3.0 and later)

With "設定 → 上級者向けの設定 → 直リンク防止" (Settings → Advanced → Hotlink protection) on, manga pictures can be read only from pages of "this site", "sites registered for embedding" and "sites on the allow list". It keeps other sites from pasting your pictures and raising your server's traffic. It is off by default.

- `.htaccess` is not rewritten (the check is done inside read.php, so it works on nginx servers too).
- Work pages of share links and the covers for link cards keep working with it on.
- Write the allow list per site (for example `https://note.com`). Browsers do not send the path (such as `/username/`) when loading from other sites, so partial paths cannot be matched.

### Plugins (guest mode)

PHP plugins placed in `nagimanga/plugins/` add features (only the files placed there are loaded). One plugin is included:

**[`plugins/guest-mode.php`](plugins/guest-mode.php) (guest, read only)**: for showing visitors the inside of the admin panel, as in a demo. Copy this file into `nagimanga/plugins/`, and guests can enter at `admin/index.php?guest`.

- Guests can see works, pages, share tags and the preview, but uploading, reordering, deleting, passwords, light versions, EPUB, backups and changing settings are all refused. The settings screen (login URL, IP, logs) and reader passwords are not shown.
- These limits are enforced by NagiManga itself, not by the plugin. The plugin only decides whether the entrance is open, the passphrase, the guide text and the link shown after leaving.
- Your own login (secret URL + password + IP restriction) is unchanged.
- Deleting the file closes the entrance and locks out guests who are inside.

### Troubleshooting

| Problem | What to do |
|---|---|
| The setup screen says "Not Found" (30 minutes passed) | Delete `data/install.lock` with FTP and open it again |
| You forgot the login URL | Open `data/config.php` with FTP and open `admin/index.php?k=` followed by the value of `login_key` |
| Your IP address changed and you are locked out | With FTP, set `allowed_ips` in `data/config.php` to `array ( )` (and the line in `admin/.htaccess` if you wrote an IP restriction there) |
| Too many wrong logins | Wait 15 minutes (the login screen says "Not Found" meanwhile) |
| The admin panel says the data folder can be seen from the internet | See "Protecting the data folder" below |
| An EPUB cannot be imported | Check that it has no DRM, is not text only, and is within the upload limit (shown in the admin panel) |

### Protecting the data folder

The `data` folder is hidden from outside with `.htaccess`. On servers where `.htaccess` does not work (such as nginx), do one of these:

1. **Recommended:** move the `data` folder outside the public folder (for example `/home/you/nagimanga-data`) and write its place in a new `lib/paths.php`

   ```php
   <?php return '/home/you/nagimanga-data';
   ```

2. On nginx, set `location ~ /nagimanga/(data|lib|plugins)/ { deny all; }`

The storage folder names are made from the secret key, but that is not encryption. If `.htaccess` does not work and the real path of a picture becomes known, the picture can be opened directly. Move the storage outside the public folder or deny access in the server settings.

- The settings files are PHP, so their contents are not shown.
- Work folder names are made from the secret key and cannot be guessed from public IDs.
- Each folder has an empty `index.html`, so folder listings are not shown.

The log's internal records are PHP files that give 404 when opened directly. Protecting static pictures needs the storage place or server settings above.

### Tighter IP restriction for the admin panel (optional)

Remove the `#` from the IP restriction line in `admin/.htaccess` and write your IP address. It doubles the IP restriction set in the admin panel.

---

## Without PHP (FTP only)

1. Put `server/viewer/NagiManga.js` and `NagiManga.css` in the same folder.
2. Open [`tools/manifest-maker.html`](tools/manifest-maker.html) in a browser, choose the page pictures and make `manifest.json` (the pictures are not sent anywhere).
3. Put `manifest.json` and the pictures on the server, and paste this tag into the page.

```html
<a href="#" data-nagimanga-manifest="/manga/ep1/manifest.json" data-direction="rtl">Read chapter 1</a>
<script src="/manga/NagiManga.js" defer></script>
```

Picture locations in `manifest.json` are relative to the place of `manifest.json` (or full URLs).

```json
{ "title": "Chapter 1", "direction": "rtl", "pages": [ { "src": "001.jpg", "w": 1200, "h": 1700 } ] }
```

---

## Security approach

- **The public side is read only.** All `read.php` can do is "list a work's pages", "check a password" and "return a picture". Every odd request gets the same "Not Found", telling nothing about what exists.
- **Five locks on the admin panel.** IP restriction → secret login URL → password (locked after 5 failures; at most 40 per hour overall) → a session bound to the browser (30 days from the last login, up to 1 year in the settings) → CSRF protection on every action.
- **Uploaded pictures are always redrawn.** Whether a file is a picture is judged from its contents, not its name; it is read with GD and written to a new file. Programs hidden in pictures and data such as the shooting location do not survive. File names are given anew. SVG is not accepted for manga pages. EPUB and backup ZIPs are also checked item by item before use.
- **Programs do not run in the picture folders.** `data` cannot be seen from outside, and only `.webp` / `.jpg` are stored.
- **Error details are not shown on screen.** So that server paths and the like do not leak. Records go to `data/logs/security.log`, which can be read on the settings page of the admin panel.

### Attack tests

[`dev/attack_test.py`](dev/attack_test.py) runs the normal checks and reproduced attacks together. With Python 3 and PHP 8.1 or later (GD, fileinfo, zip, mbstring, sodium or openssl) nothing else needs installing. The log is checked with [`dev/log_test.py`](dev/log_test.py).

```bash
python dev/attack_test.py
```

What it covers: PHP disguised as a picture, uploads of `.htaccess` / SVG / HTML, pixel bombs, escaping folders with `../`, ZIP slip and ZIP bombs, malicious EPUBs (XXE, DRM, paths pointing outside the book), CSRF, XSS, forged, tampered, expired and reused viewing keys, brute force (including forged `X-Forwarded-For`), getting around the IP restriction, odd Host headers, changes by guests (19 kinds) and information leaks on servers where `.htaccess` does not work. The results are written to `dev/results/attack-report.md`.

## Development

```bash
dev\serve.cmd              # http://127.0.0.1:5190 (data in dev/data)
python dev/seed.py         # registers sample works and makes dev/results/demo.html
python dev/build_release.py  # the release ZIP (dev/results/nagimanga-vX.Y.Z.zip)
python dev/i18n_test.py    # checks the admin panel translations (server/lib/lang/*.json) for gaps
```

The jsDelivr URLs in the README are pinned to the commit that last changed the file (for example `…/NagiSwipe@93db749/NagiSwipe-main.js`). A commit URL never changes its contents, and the URL changes whenever the file does, so browsers and jsDelivr never keep an old file. Install the hook with `cp scripts/post-commit.sh .git/hooks/post-commit`, and after a commit that changes NagiSwipe-main.js / .css the README URLs are rewritten automatically and the pages of the [documentation site](https://nagiswipe.pages.dev/) (`python site/build.py`) are rebuilt and committed (by hand: `python scripts/cachebust.py README.md README.en.md manga/README.md manga/README.en.md`, then `python site/build.py`).

To release, raise `NM_VERSION` in `lib/bootstrap.php` and `VERSION` in `viewer/NagiManga.js` to the same number, make the ZIP with `dev/build_release.py` and attach it to a GitHub release. Installed copies of NagiManga look at the release's `nagimanga-vX.Y.Z.zip` (holding a `nagimanga/` folder) to announce updates.

Version numbers: small fixes and additions raise the last number (0.5.0 → 0.5.1 → … → 0.5.99). Only big updates raise the middle number (0.6.0). Installed copies announce an update only when the number is higher than theirs, so a number lower than one already released is never used.

## License

[MIT License](../LICENSE) (c) 2026 Lichiphen
