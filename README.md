# NagiSeries

日本語 | [English](README.en.md)

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

個人サイトのための、小さな道具のシリーズです。どれもデータベースや外部サービスを使わず、自分のサイトに置くだけで動きます。

| | できること | 必要なもの | 説明 |
|---|---|---|---|
| **NagiSwipe** | JS と CSS を読み込むだけで動く、軽量な画像ポップアップギャラリー | JS と CSS の 2 ファイル（jsDelivr から読み込めます） | [このページの「NagiSwipe」](#nagiswipe) |
| **NagiManga** | タグを 1 つ貼るだけで開く漫画ビューアーと、データベースのいらない管理画面 | PHP 8.1 以上（FTP だけで置くこともできます） | [manga/README.md](manga/README.md) |
| **NagiLog** | 文章・画像・漫画を、自分のサイトへ投稿できる個人用LOG | NagiManga と同じ設置 | [manga/README-LOG.md](manga/README-LOG.md) |

[**ドキュメントサイト（Cloudflare Pages）**](https://nagiswipe.pages.dev/) — 3 つの説明をまとめて、目次と検索つきで読めます（[英語版](https://nagiswipe.pages.dev/index.en.html)もあります）。

デモ: [NagiSwipe](https://nagiswipe.pages.dev/demo.html) ／ [NagiManga](https://notebook.lichiphen.com/nagimanga/demo.html)（右から左・見開き・縦読み・パスワード付き・EPUB から取り込んだ作品、管理画面をゲストで見る） ／ [NagiLog](https://notebook.lichiphen.com/nagimanga/)（作者が実際に使っている LOG）

<!-- TOC -->
## 目次

- [NagiSwipe](#nagiswipe)
  - [特徴](#特徴)
  - [導入方法](#導入方法)
  - [てがろぐで使う](#てがろぐで使う)
- [NagiManga](#nagimanga)
- [NagiLog](#nagilog)
- [ドキュメントサイト](#ドキュメントサイト)
- [権利・免責事項：掲載画像について](#権利免責事項掲載画像について)
- [ライセンス](#ライセンス)
<!-- /TOC -->

## NagiSwipe

JS と CSS の 2 ファイルを読み込むだけで動く、軽量な画像ポップアップギャラリー・ライブラリです。
モバイルファースト、タッチ操作の快適さを目指しています。[デモ](https://nagiswipe.pages.dev/demo.html)で、スワイプやズームを試せます。

### 特徴
- **ドロップイン導入**: JSとCSSを読み込むだけで、ページ内の画像リンクを自動的にギャラリー化します。
- **モバイル最適化**: スワイプ、ピンチズーム、ダブルタップに対応。
- **軽量・高速**: 依存ライブラリなし。
- **スムーズな操作感**: サムネイルから拡大して開き、サムネイルへ縮んで閉じる。スワイプ中は前後の画像も指に追従。拡大した画像をタップでフィットへ戻す動きは、ピンチと同じく1コマずつ描くので、Android・iPhoneでも途中で欠けません（v1.3.2〜）。
- **慣性スクロール**: ズーム中は指を離しても滑り、画像の端で跳ね返る。素早く払えば短い距離でもページ送り。
- **サムネイル先出し**: 高画質版が届くまではページ上のサムネイルを表示。前後の画像も先に読み込み。
- **キャプション標準搭載**: 画像の `alt` などを画面下部に表示。
- **安心設計**: ブラウザの「戻る」（マウスの戻るボタンやスワイプバックを含む）でビューアーだけを閉じる。自動DOM生成。

### 導入方法

HTMLの `<head>` 内で以下のファイルを読み込んでください。

#### CDN経由 (推奨)
[jsDelivr](https://www.jsdelivr.com/) を利用して高速に配信されます。以下の URL をコピーしてください（いつも最新版の URL が書いてあります）。

```html
<!-- CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@ab44ada/NagiSwipe-main.css">

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@bbce2be/NagiSwipe-main.js"></script>
```

※ `@` の後ろ（`@93db749` など）はファイルのバージョンを表す値で、NagiSwipe を更新するとこの README の値も新しくなります。URL を貼り直すまでは、今のバージョンのまま表示が変わりません。`@v1.4.0` のようにリリースの番号でも指定できます。常に最新の `main` ブランチを使いたい場合は `@main` にしてください（jsDelivr で最大 12 時間、ブラウザで最大 7 日、古いファイルが残ることがあります）。

#### 使い方
ページ内の `<a href="image.jpg">` のような形式のリンクが自動的に検出され、クリック時にギャラリーが開きます。

#### キャプション（v1.1.0〜）
画像を開くと、画面下部にキャプションを表示します。次の順で最初に見つかったものを使います。

1. リンクの `data-ns-caption`（空文字を指定するとキャプションなし）
2. リンクの `data-caption`
3. サムネイル `<img>` の `alt`
4. リンクの `title`
5. サムネイル `<img>` の `title`
6. リンクを含む `<figure>` の `<figcaption>`

```html
<a href="photo.jpg"><img src="photo-thumb.jpg" alt="夕暮れの海"></a>
```

テキストとして表示するので、alt に HTML が含まれていても解釈されません。ズーム中は自動で隠れます。

#### オプション
```js
NagiSwipe.init({
    caption: true,        // 下部キャプションを表示（false で無効）
    counter: true,        // 左上の「3 / 12」表示（false で無効）
    zoomAnimation: true,  // サムネイルから拡大して開く / 縮んで閉じる（false でフェード）
    history: true         // ブラウザの「戻る」でビューアーを閉じる（false で無効）
});
```

OS の「視差効果を減らす」設定（`prefers-reduced-motion`）が有効な場合は、拡大アニメーションの代わりにフェードになります。

#### 画像サイズの指定（任意）
指定しなくても動きますが、リンクに実画像の幅と高さを書いておくと、読み込み前から正しい大きさで表示できます。

```html
<a href="photo.jpg" data-ns-width="3000" data-ns-height="2000"><img src="photo-thumb.jpg" alt="…"></a>
```

### てがろぐで使う

[てがろぐ](https://www.nishishi.com/cgi/tegalog/) では、スキンのファイルを書き換えなくても、管理画面で設定するだけで NagiSwipe を使えます。

1. てがろぐの管理画面で **[設定] → [システム設定] → 【画像拡大スクリプトの選択】** を開きます。
2. 「**他のスクリプトを使う：URLを指定**」を選び、次の 2 つをコピーして貼り付け、保存します。
   - 「JavaScriptのURL」欄: `https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@bbce2be/NagiSwipe-main.js`
   - 「CSSのURL」欄: `https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@ab44ada/NagiSwipe-main.css`

これで、投稿の画像をクリックすると NagiSwipe で大きく表示されます。

**漫画ビューアー（NagiManga）も使う場合** は、同じ「JavaScriptのURL」欄で、NagiSwipe の URL の **うしろに半角スペースを 1 つ入れて**、NagiManga の URL を続けて書きます（1 行のまま続けます。「CSSのURL」欄はそのままで大丈夫です）。

```
https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@bbce2be/NagiSwipe-main.js https://あなたのサイト/nagimanga/viewer/NagiManga.js
```

あわせて、**画像のない投稿でも** 漫画を開けるように設定します。てがろぐは、画像のない投稿のページでは上の欄のファイルを読み込まないためです。[設定] → [ページの表示] → 【投稿本文の表示／URL処理】 の「▼画像URLを画像として埋め込む表示」にある **「画像リンクに独自のclass属性値を追加する」** にチェックを入れ、`class="` と `"` の間の欄に `nagimanga` と入力して保存します。

てがろぐに漫画を載せる方法は、[manga/README.md の「てがろぐに載せる」](manga/README.md#てがろぐに載せる) を見てください。

[NagiMemo](https://github.com/Lichiphen/NagiMemo)（てがろぐ用のスキン）でも同じ方法で使えます。

## NagiManga

ブログやサイトに **タグを 1 つ貼るだけ** で、クリックしたときに漫画ビューアーが開きます。作品の管理は、データベースのいらない小さな管理画面（PHP）で行います。ソースは [`manga/`](manga/) にあります。

- 右から左・見開き・縦読み（ページ漫画／ウェブトゥーン）、しおり、拡大
- 画像のドラッグ＆ドロップや、CLIP STUDIO PAINT などの漫画 EPUB から作品を作れます
- スマホ用の小容量版と、パソコン・拡大用の通常版を自動で読み分けます
- パスワード付きの限定公開、共有タグの発行、ZIP でのバックアップ

デモ: https://notebook.lichiphen.com/nagimanga/demo.html ／ 導入方法: [manga/README.md](manga/README.md) ／ 開発者でない方向けの手引き: [manga/docs/overview.md](manga/docs/overview.md)

## NagiLog

文章・画像・漫画を自分のサイトへ投稿できる、データベース不要の個人用LOGです。NagiManga に含まれていて、同じ設置・同じログインで動きます。

- スマホからの投稿、下書き、画像の差し替え、漫画カード
- カテゴリ、ハッシュタグ、検索、関連記事、いいね、RSS・サイトマップ
- 閲覧注意（センシティブ・R-18・R-18G）、ライト 3 種類・ダーク 3 種類のデザイン
- 動画と音声（波形のプレーヤー、つなぎ目のないループ再生、曲へのジャケットとループ位置の書き込み、動画の自動再生・くり返しの設定）

![NagiLogのトップ（パソコン）](manga/docs/images/log-pc.jpg)

デモ: https://notebook.lichiphen.com/nagimanga/ ／ 説明: [manga/README-LOG.md](manga/README-LOG.md)

## ドキュメントサイト

https://nagiswipe.pages.dev/ は、この README・[manga/README.md](manga/README.md)・[manga/README-LOG.md](manga/README-LOG.md)・[manga/docs/overview.md](manga/docs/overview.md) から作っています。英語のページ（`nagilog.en.html` など）は、それぞれの `.en.md`（[README.en.md](README.en.md) など）から作ります。文章を直すときは README を編集し、次のコマンドでページを作り直してから、一緒にコミットしてください（Python 3 の標準ライブラリだけで動きます）。

```bash
python site/build.py
```

ページの元になるファイルは [`site/`](site/) にあります（トップページの文章は `site/top.html` と英語の `site/top.en.html`、見た目は `site/site.css`）。各ページの上の「English」「日本語」で、同じページのもう一方の言語へ移れます。サイトのファイルは、NagiManga の配布 ZIP や GitHub のソースコードの ZIP には含めません。

## 権利・免責事項：掲載画像について
本プロジェクトのデモ（`demo.html`等）で使用されている画像について：

- **猫のイラスト（Kyururun.png, Fu-n.png, Shimeshime.png）**
    - これらは **Lichiphen（作者）本人が制作したデジタルアート** です。
    - 著作権は作者に帰属しますが、本ライブラリのデモ用として同梱されています。

- **その他の写真画像（Picsum経由等）**
    - これらは [Lorem Picsum](https://picsum.photos/) 等の外部サービスから取得しているサンプルです。
    - **これらの写真画像の著作権は Lichiphen には帰属しません。**
    - 写真素材については本ソフトウェア（NagiSwipe）の MIT LICENSE の対象外です。各画像のライセンスについては提供元（Unsplash等）の規定に従ってください。

- **NagiManga のデモに掲載している漫画作品（「送り日」「ガーベラ」など）**
    - Lichiphen（作者）本人の作品です。このリポジトリには含まれておらず、MIT LICENSE の対象外です。著作権は作者に帰属します。
    - デモの見本マンガ・見本ウェブトゥーンの絵は、デモ用に自動で描いたものです。

## ライセンス
[MIT License](LICENSE) (c) 2026 Lichiphen
