[日本語](overview.md) | **English**

# A guide to NagiManga (for non-developers)

This document is for people who want to decide whether to use NagiManga, and for people who already use it and want to know whether they can keep using it. It avoids technical terms where it can, and explains the ones it uses. It is written without relying on particular years or services, so that it still helps you decide when you read it again in a few years, or in ten.

Installation steps are in [../README.en.md](../README.en.md).

---

<!-- TOC -->
## Contents

- [1. What NagiManga is](#1-what-nagimanga-is)
- [2. Who it suits, and who it does not](#2-who-it-suits-and-who-it-does-not)
- [3. Strengths](#3-strengths)
- [4. Weaknesses and cautions (honestly)](#4-weaknesses-and-cautions-honestly)
- [5. Using it for ten years](#5-using-it-for-ten-years)
- [6. The technology used](#6-the-technology-used)
  - [The screen readers see (the viewer)](#the-screen-readers-see-the-viewer)
  - [The server side (the admin panel and the window for readers)](#the-server-side-the-admin-panel-and-the-window-for-readers)
  - [For development and testing (not placed on the server)](#for-development-and-testing-not-placed-on-the-server)
- [7. The shape of the data (for the day NagiManga cannot be used)](#7-the-shape-of-the-data-for-the-day-nagimanga-cannot-be-used)
- [8. Words](#8-words)
<!-- /TOC -->

## 1. What NagiManga is

A tool that adds **a screen for reading manga (a viewer)** to your own website or blog.

- Readers click a link in a post and the manga opens over the whole screen. No app to install, no account to make.
- Creators drag and drop pictures into the admin panel and paste the "tag" it shows (a short piece of HTML) into their blog.
- You can add a password so that only people who know it can read.

## 2. Who it suits, and who it does not

**It suits people who**

- want to publish their own manga on their own site, under their own control
- do not want to depend on the rule changes or closing of manga posting services
- want to give readers a quiet reading place without ads or tracking
- have works they want only a limited circle, such as supporters or friends, to read

**It does not suit people who**

- do not want to rent a server or upload files at all (a posting service is easier)
- need view counts, comments, "likes", sales or payments (NagiManga has none of these; the reasons are in chapter 4)
- must make sure pictures can never be saved (as long as they are shown on the web, this cannot be fully prevented; chapter 4)

## 3. Strengths

| Strength | What it means |
|---|---|
| **It lives in your place** | Your works are inside the server you rent. Even if a service ends or changes its rules, your works do not disappear. |
| **Straight from EPUB** | Choose a manga EPUB exported from CLIP STUDIO PAINT or similar, and it becomes a work with its page order, reading direction and title. No need to export pictures one by one. |
| **Kind to phones** | Register a smaller "light version" too, and phones get the light pictures while computers and zoomed pages get the sharp ones, automatically. It takes about half the data. |
| **Nothing for readers to do** | One click opens it. On phones and computers it reads naturally with fingers, mouse or keyboard. |
| **Reads like manga** | Right-to-left page turning, spreads and a single cover page. Vertical reading can be "paged manga", one page fitted to the screen at a time, or full-width "webtoon". It opens where you stopped last time. |
| **No database** | Databases can break when misused and make moving servers hard. NagiManga runs on pictures and small text files (JSON) alone, so copying a folder is enough to move or back up. |
| **Stored in a readable form** | A work's information is written in a file format called JSON that Notepad can open. Even if NagiManga can no longer be used, the pictures and their order can be taken out (chapter 7). |
| **No outside parts** | It uses no libraries (parts) made by others. If a part's author stops updating it, or a flaw is found in a part, it does not affect NagiManga. |
| **Several layers of defense** | The admin panel is guarded by five locks, including the place (IP address), a secret URL and a password; if any one does not match, it pretends not to exist. Uploaded pictures are always redrawn, so harmful programs hidden in pictures do not survive. It is checked by 132 kinds of tests of normal use and reproduced attacks. |
| **You can show a demo** | Place one plugin, and visitors can look around the admin panel "view only". Changes and deletion are firmly stopped by the program itself. |
| **Free and open to change** | MIT license. Use it commercially or personally, read the inside and change it. |

## 4. Weaknesses and cautions (honestly)

| Caution | What it means, and what to do |
|---|---|
| **A server is needed** | You need a rental server or similar where PHP runs (there is a way to use it without PHP, but then there are no passwords). Servers cost money. |
| **Pictures are redrawn** | For safety, every uploaded picture is redrawn and saved in a format called WebP. The difference is hardly visible, but it is not exactly the original file. **Always keep your original pictures yourself.** GIF animations of manga pages keep only the first frame. |
| **A password is a "lock", not a "safe"** | The password is asked each time the viewer opens. Still, people who know it can save what they are reading or take screenshots. As long as it is shown on the web, there is no way to fully prevent that. Think of it as a way to keep the work from being found and read by strangers. |
| **Few features, on purpose** | There are no view counts, comments or sales. Adding them would need a database or outside services, making it more fragile and more of a target. Combine it with other tools if you need them. |
| **Backups are up to you** | Nothing saves your works somewhere else automatically. Download the ZIP from "バックアップ" (Backup) in the admin panel from time to time. |
| **The server needs care** | PHP versions have an end of support, and staying on an old one is risky. Raise PHP to a newer version in your rental server's control panel (chapter 5). |
| **Home IP addresses can change** | If the admin panel is limited to "only my IP address", a change on your line can lock you out. How to fix it is in "Troubleshooting" in the README. |
| **Made by one person** | It is not a product of a big company. When a problem is found, it may not be fixed right away. In exchange, it does not lean on outside parts, and reading the inside tells you how it works. |

## 5. Using it for ten years

Once or twice a year, check these:

1. **Make a backup.** "バックアップ → すべてをダウンロード" (download everything) in the admin panel. Keep it with your original pictures.
2. **Check the PHP version.** In your rental server's control panel, make sure PHP is a supported version. NagiManga is made for PHP 8.1 and later. After a big PHP upgrade, log in to the admin panel and check that your works open.
3. **Look at the admin panel's records.** In "設定 → セキュリティログ" (Settings → Security log), look for long runs of login failures you do not recognize. If there are many, change the login URL (one button in the settings) and your password.
4. **When moving servers.** Install NagiManga again on the new server, set it up and "restore" the backup ZIP. Work IDs carry over, but if the domain or place changed, the URL in share tags changes too, so paste the tags in your blog again (the new server's admin panel makes tags with the new address automatically).

Browsers change year by year, but NagiManga uses only standard features every browser has had for a long time (chapter 6). It does not lean on a particular company's service or on mechanisms likely to disappear.

## 6. The technology used

"What is used", "what for" and "why it was chosen". Technical names are kept as clues for looking things up in the future.

### The screen readers see (the viewer)

| Technology | What for | Why |
|---|---|---|
| HTML / CSS / JavaScript (standard features only) | The whole viewer | The basic languages of the web that every browser understands. No extra parts (libraries). |
| Pointer Events | Handling fingers, mouse and pen together | A standard browser feature. Touch and mouse work through the same mechanism. |
| fetch / JSON | Getting a work's page list | A standard browser feature. |
| History API | Closing with the browser's Back | A standard browser feature. |
| localStorage / sessionStorage | Remembering the bookmark (where you stopped) and the viewing key after a password | A standard browser feature. Stored only on the reader's device, never sent to the server. |
| CSS `env(safe-area-inset-*)` | Avoiding a phone's notch and home bar | A standard browser feature. |
| WebP pictures | Page pictures | Small files for their quality, supported by all major browsers. |

### The server side (the admin panel and the window for readers)

| Technology | What for | Why |
|---|---|---|
| PHP 8.1 and later | The admin panel and `read.php` | Available from the start on almost every rental server. |
| GD (picture processing that comes with PHP) | Redrawing pictures, making thumbnails | A part that comes with PHP as standard. |
| fileinfo (comes with PHP) | Judging from a file's contents whether it is a picture | To catch dangerous files with false names. |
| ZipArchive (comes with PHP) | Backup and restore | ZIP is a long-lived format any computer can open. |
| JSON files | Storing a work's information (title, order and so on) | To store it without a database, in a form Notepad can read. |
| `password_hash` (comes with PHP) | Storing and checking passwords | Stores not the password itself but a form that cannot be turned back. |
| sodium or openssl (comes with PHP) | Letting the admin panel show reader passwords | They are not stored as plain text but encrypted with the server's secret key. Without either, only showing them is unavailable; the rest works. |
| HMAC-SHA256 | Viewing keys, naming work folders | A widely used way of making marks that nobody without the secret key can make or guess. |
| `.htaccess` (Apache) | Hiding folders such as `data` from outside | Usable on many rental servers. The README also covers servers where it does not work. |

### For development and testing (not placed on the server)

| Technology | What for |
|---|---|
| Python 3 (standard features only) | A test that tries 132 kinds of attacks and behaviors together (`dev/attack_test.py`) |
| PHP's built-in development server | Checking on your own computer (`dev/serve.cmd`) |

## 7. The shape of the data (for the day NagiManga cannot be used)

Even if NagiManga stops working someday, your works can be taken out. A backup ZIP, or the `data/works` folder on the server, looks like this:

```
works/
└── (a folder per work)/
    ├── work.json          the work's information (opens in Notepad)
    └── pages/
        ├── p0001_xxxxxxxx.webp   page pictures
        ├── t_p0001_xxxxxxxx.webp small pictures (for lists)
        └── …
```

An example of what `work.json` holds:

```json
{
  "id": "Ab3dE5gH7jK9",
  "title": "Chapter 1",
  "series": "Days of a cat",
  "direction": "rtl",
  "pages": [
    { "f": "p0001_1a2b3c4d.webp", "w": 1200, "h": 1700, "o": "001.png" },
    { "f": "p0002_5e6f7a8b.webp", "w": 1200, "h": 1700, "o": "002.png" }
  ]
}
```

- The order in `pages` is the page order.
- `f` is the name of the stored picture, `o` the original name when uploaded, `w` and `h` the width and height.
- Pages with a light version have `"m": { "f": "…", "w": 1200, "h": 1683 }` (`f` is the light picture's name).
- `direction` is the reading direction (`rtl` = right to left, `ltr` = left to right, `vertical` = vertical reading).
- Today's computers can usually open `.webp` pictures as they are.

## 8. Words

| Word | Meaning |
|---|---|
| Server | A computer connected to the internet that holds a website's files. Most people rent one. |
| FTP | A way of sending files from your computer to a server. |
| PHP | A programming language that runs on servers. |
| IP address | A number like an address on the internet. |
| Tag | A short string that stands for a part of a web page. In NagiManga, the link you paste into your blog. |
| JSON | A way of writing information down that both people and computers can read. |
| WebP | One of the picture file formats. |
| Library | A part of a program made by someone else. |
| License (MIT) | The rules of use. MIT is a loose rule: "use it freely as long as you keep the copyright notice". |
