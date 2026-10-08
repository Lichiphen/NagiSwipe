[日本語](README.md) | **English**

# NagiSeries

[![NagiSwipe v1.3.2](https://img.shields.io/badge/NagiSwipe-v1.3.2-1d7a8c?style=flat-square&logo=javascript&logoColor=white)](#nagiswipe)
[![NagiManga v0.6.0](https://img.shields.io/badge/NagiManga-v0.6.0-c2410c?style=flat-square&logo=bookstack&logoColor=white)](manga/README.en.md)
[![NagiLog v0.6.0](https://img.shields.io/badge/NagiLog-v0.6.0-2563eb?style=flat-square&logo=rss&logoColor=white)](manga/README-LOG.en.md)
[![jsDelivr](https://img.shields.io/jsdelivr/gh/hm/Lichiphen/NagiSwipe?style=flat-square&logo=jsdelivr&logoColor=white&label=jsDelivr)](https://www.jsdelivr.com/package/gh/Lichiphen/NagiSwipe)
[![Latest release](https://img.shields.io/github/v/release/Lichiphen/NagiSwipe?style=flat-square&logo=github&label=release)](https://github.com/Lichiphen/NagiSwipe/releases/latest)
[![GitHub](https://img.shields.io/badge/GitHub-Lichiphen%2FNagiSwipe-181717?style=flat-square&logo=github)](https://github.com/Lichiphen/NagiSwipe)
[![GitLab](https://img.shields.io/badge/GitLab-lichiphen%2Fnagiswipe-FC6D26?style=flat-square&logo=gitlab&logoColor=white)](https://gitlab.com/lichiphen/nagiswipe)
[![Documentation](https://img.shields.io/badge/docs-nagiswipe.pages.dev-F38020?style=flat-square&logo=cloudflarepages&logoColor=white)](https://nagiswipe.pages.dev/index.en.html)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white)](manga/README.en.md)
[![License: MIT](https://img.shields.io/badge/License-MIT-eab308?style=flat-square)](LICENSE)

A series of small tools for personal websites. None of them needs a database or an outside service: put them on your own site and they work.

| | What it does | What you need | Read more |
|---|---|---|---|
| **NagiSwipe** | A light image popup gallery that works by loading one JS and one CSS file | The two files (they can be loaded from jsDelivr) | ["NagiSwipe" on this page](#nagiswipe) |
| **NagiManga** | A manga viewer that opens from a single tag, and an admin panel that needs no database | PHP 8.1 or later (it can also be used with FTP alone) | [manga/README.en.md](manga/README.en.md) |
| **NagiLog** | A personal log for posting text, pictures, manga, video and audio to your own site | The same installation as NagiManga | [manga/README-LOG.en.md](manga/README-LOG.en.md) |

[**Documentation site (Cloudflare Pages)**](https://nagiswipe.pages.dev/index.en.html) — the three documents together, with a table of contents and search.

Demos: [NagiSwipe](https://nagiswipe.pages.dev/demo.html) / [NagiManga](https://notebook.lichiphen.com/nagimanga/demo.html) (right to left, spreads, vertical reading, a password-protected work, a work imported from EPUB, and the admin panel as a guest) / [NagiLog](https://notebook.lichiphen.com/nagimanga/) (the log the author actually uses)

The tools and their admin panels were written in Japanese first. The admin panel of NagiManga and NagiLog can also be shown in English (see [Admin panel language](manga/README.en.md#admin-panel-language)).

<!-- TOC -->
## Contents

- [NagiSwipe](#nagiswipe)
  - [Features](#features)
  - [Getting started](#getting-started)
  - [Using it with Tegalog](#using-it-with-tegalog)
- [NagiManga](#nagimanga)
- [NagiLog](#nagilog)
- [Rights and disclaimer: images in the demos](#rights-and-disclaimer-images-in-the-demos)
- [License](#license)
<!-- /TOC -->

## NagiSwipe

A light image popup gallery library that works by loading two files, one JS and one CSS.
It is mobile first and aims to feel good under the finger. Try swiping and zooming in the [demo](https://nagiswipe.pages.dev/demo.html).

### Features
- **Drop-in**: load the JS and the CSS, and the image links on the page become a gallery by themselves.
- **Made for phones**: swipe, pinch zoom and double tap.
- **Light and fast**: no dependencies.
- **Smooth**: opens by growing out of the thumbnail and closes by shrinking back into it. While you swipe, the previous and next pictures follow your finger. Tapping a zoomed picture back to fit is drawn frame by frame, the same way a pinch is, so it no longer breaks up halfway on Android or iPhone (v1.3.2 and later).
- **Momentum**: while zoomed, the picture keeps sliding after you lift your finger and bounces at its edges. A quick flick turns the page even over a short distance.
- **Thumbnail first**: the thumbnail on the page is shown until the full picture arrives. The previous and next pictures are loaded ahead.
- **Captions built in**: the image's `alt` and similar text is shown at the bottom of the screen.
- **Safe by design**: the browser's Back (including the mouse back button and the back swipe) closes only the viewer. The DOM it needs is created automatically.

### Getting started

Load the following files in the `<head>` of your HTML.

#### Via CDN (recommended)
The files are served fast through [jsDelivr](https://www.jsdelivr.com/). Copy these URLs (they are always the URLs of the latest version).

```html
<!-- CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@83d6ea2/NagiSwipe-main.css">

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@83d6ea2/NagiSwipe-main.js"></script>
```

> [!NOTE]
> The part after `@` (such as `@93db749`) identifies the version of the file. When NagiSwipe is updated, the value in this README changes too. Until you paste the new URL, your site keeps showing the version you have. You can also give a release number, such as `@v1.6.0`. To always use the latest `main` branch, write `@main` (jsDelivr may keep an old file for up to 12 hours, and browsers for up to 7 days).

#### Usage
Links on the page such as `<a href="image.jpg">` are found automatically, and the gallery opens when one is clicked.

#### Captions (v1.1.0 and later)
When a picture opens, a caption is shown at the bottom of the screen. The first of these that is found is used:

1. `data-ns-caption` on the link (an empty value means no caption)
2. `data-caption` on the link
3. `alt` of the thumbnail `<img>`
4. `title` of the link
5. `title` of the thumbnail `<img>`
6. the `<figcaption>` of the `<figure>` around the link

```html
<a href="photo.jpg"><img src="photo-thumb.jpg" alt="The sea at dusk"></a>
```

It is shown as text, so HTML in an alt is not interpreted. The caption hides itself while you zoom.

#### Options
```js
NagiSwipe.init({
    caption: true,        // the caption at the bottom (false turns it off)
    counter: true,        // "3 / 12" at the top left (false turns it off)
    zoomAnimation: true,  // grow out of the thumbnail / shrink back into it (false fades instead)
    history: true         // the browser's Back closes the viewer (false turns it off)
});
```

When the system setting "Reduce motion" (`prefers-reduced-motion`) is on, the viewer fades instead of growing and shrinking.

#### Image size (optional)
It works without it, but if the link carries the picture's real width and height, the viewer shows it at the right size before it has loaded.

```html
<a href="photo.jpg" data-ns-width="3000" data-ns-height="2000"><img src="photo-thumb.jpg" alt="…"></a>
```

### Using it with Tegalog

[Tegalog](https://www.nishishi.com/cgi/tegalog/) (a Japanese micro-blog CGI) can use NagiSwipe through its admin settings alone, without editing the skin files. The menu names below are Tegalog's Japanese ones.

1. In Tegalog's admin panel, open **[設定] → [システム設定] → 【画像拡大スクリプトの選択】** (Settings → System settings → Image zoom script).
2. Choose "**他のスクリプトを使う：URLを指定**" (use another script: give its URL), paste these two, and save.
   - The "JavaScriptのURL" field: `https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@83d6ea2/NagiSwipe-main.js`
   - The "CSSのURL" field: `https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@83d6ea2/NagiSwipe-main.css`

Now a click on a picture in a post shows it large with NagiSwipe.

**To use the manga viewer (NagiManga) too**, write NagiManga's URL in the same "JavaScriptのURL" field, **after NagiSwipe's URL and one space** (keep it on one line; the CSS field stays as it is).

```
https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@83d6ea2/NagiSwipe-main.js https://your-site/nagimanga/viewer/NagiManga.js
```

> [!IMPORTANT]
> Also make manga open **in posts without pictures**: Tegalog does not load the files above on pages of posts without pictures. In [設定] → [ページの表示] → 【投稿本文の表示／URL処理】, under "▼画像URLを画像として埋め込む表示", check **"画像リンクに独自のclass属性値を追加する"** (add your own class to picture links), type `nagimanga` between `class="` and `"`, and save.

How to put manga in Tegalog posts is in ["Posting to Tegalog" in manga/README.en.md](manga/README.en.md#posting-to-tegalog).

It works the same way with [NagiMemo](https://github.com/Lichiphen/NagiMemo) (a skin for Tegalog).

## NagiManga

Paste **a single tag** into your blog or site, and a manga viewer opens when it is clicked. Works are managed in a small admin panel (PHP) that needs no database. The source is in [`manga/`](manga/).

- Right to left, spreads, vertical reading (paged manga / webtoon), bookmarks, zoom
- Make works by dragging and dropping pictures, or from manga EPUB files such as those of CLIP STUDIO PAINT
- Light pictures for phones and full ones for computers and zooming, chosen automatically
- Password-protected works, share tags, backups as one ZIP

Demo: https://notebook.lichiphen.com/nagimanga/demo.html / Installation: [manga/README.en.md](manga/README.en.md) / A guide for non-developers: [manga/docs/overview.en.md](manga/docs/overview.en.md)

## NagiLog

A personal log that needs no database, for posting text, pictures and manga to your own site. It is part of NagiManga and runs with the same installation and the same login.

- Posting from a phone, drafts, replacing pictures, manga cards
- Categories, hashtags, search, related posts, likes, RSS and a sitemap
- Content warnings (sensitive, R-18, R-18G), 3 light and 3 dark designs
- Video and audio (a waveform player, gapless repeat, writing the cover and loop points into songs, autoplay and repeat settings for videos)

![The top of NagiLog on a computer](manga/docs/images/log-pc.jpg)

Demo: https://notebook.lichiphen.com/nagimanga/ / Documentation: [manga/README-LOG.en.md](manga/README-LOG.en.md)

## Rights and disclaimer: images in the demos
About the images used in the demos of this project (`demo.html` and others):

- **Cat illustrations (Kyururun.png, Fu-n.png, Shimeshime.png)**
    - These are **digital art made by Lichiphen (the author)**.
    - The copyright belongs to the author; they are included as demo material for this library.

- **Other photographs (from Picsum and similar)**
    - These are samples fetched from outside services such as [Lorem Picsum](https://picsum.photos/).
    - **Lichiphen does not hold the copyright of these photographs.**
    - The photographs are not covered by the MIT LICENSE of this software (NagiSwipe). Follow the terms of their providers (such as Unsplash) for each picture.

- **Manga works shown in the NagiManga demo ("送り日", "ガーベラ" and others)**
    - They are works of Lichiphen (the author). They are not in this repository and not covered by the MIT LICENSE. The copyright belongs to the author.
    - The pictures of the sample manga and sample webtoon in the demo were drawn automatically for the demo.

## License
[MIT License](LICENSE) (c) 2026 Lichiphen
