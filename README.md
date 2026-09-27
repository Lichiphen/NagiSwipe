# NagiSwipe

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

JS と CSS の 2 ファイルを読み込むだけで動く、軽量な画像ポップアップギャラリー・ライブラリです。
モバイルファースト、タッチ操作の快適さを目指しています。漫画ビューアー **NagiManga** も同梱しています。

[**Demo / Documentation (Cloudflare Pages)**](https://nagiswipe.pages.dev/)

[**漫画ビューアー NagiManga のデモ**](https://notebook.lichiphen.com/nagimanga/demo.html)（右から左・見開き・縦読み・パスワード付き・EPUB から取り込んだ作品、管理画面をゲストで見る）— 詳しくは [manga/README.md](manga/README.md)

<!-- TOC -->
## 目次

- [特徴](#特徴)
- [導入方法](#導入方法)
  - [CDN経由 (推奨)](#cdn経由-推奨)
  - [使い方](#使い方)
  - [キャプション（v1.1.0〜）](#キャプションv110)
  - [オプション](#オプション)
  - [画像サイズの指定（任意）](#画像サイズの指定任意)
- [てがろぐで使う](#てがろぐで使う)
- [漫画ビューアー（NagiManga）](#漫画ビューアーnagimanga)
- [権利・免責事項：掲載画像について](#権利免責事項掲載画像について)
- [ライセンス](#ライセンス)
<!-- /TOC -->

## 特徴
- **ドロップイン導入**: JSとCSSを読み込むだけで、ページ内の画像リンクを自動的にギャラリー化します。
- **モバイル最適化**: スワイプ、ピンチズーム、ダブルタップに対応。
- **軽量・高速**: 依存ライブラリなし。
- **スムーズな操作感**: サムネイルから拡大して開き、サムネイルへ縮んで閉じる。スワイプ中は前後の画像も指に追従。
- **慣性スクロール**: ズーム中は指を離しても滑り、画像の端で跳ね返る。素早く払えば短い距離でもページ送り。
- **サムネイル先出し**: 高画質版が届くまではページ上のサムネイルを表示。前後の画像も先に読み込み。
- **キャプション標準搭載**: 画像の `alt` などを画面下部に表示。
- **安心設計**: ブラウザの「戻る」（マウスの戻るボタンやスワイプバックを含む）でビューアーだけを閉じる。自動DOM生成。

## 導入方法

HTMLの `<head>` 内で以下のファイルを読み込んでください。

### CDN経由 (推奨)
[jsDelivr](https://www.jsdelivr.com/) を利用して高速に配信されます。最新のリリース（v1.3.0）を使う場合は、以下の URL をコピーしてください。

```html
<!-- CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.3.0/NagiSwipe-main.css?97c58dd">

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.3.0/NagiSwipe-main.js?93db749"></script>
```

※ `@v1.3.0` のようにバージョンを固定しておくと、更新で表示が変わることがありません。常に最新の `main` ブランチを使いたい場合は `@main` にしてください（キャッシュの都合で反映が遅れることがあります）。

### 使い方
ページ内の `<a href="image.jpg">` のような形式のリンクが自動的に検出され、クリック時にギャラリーが開きます。

### キャプション（v1.1.0〜）
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

### オプション
```js
NagiSwipe.init({
    caption: true,        // 下部キャプションを表示（false で無効）
    counter: true,        // 左上の「3 / 12」表示（false で無効）
    zoomAnimation: true,  // サムネイルから拡大して開く / 縮んで閉じる（false でフェード）
    history: true         // ブラウザの「戻る」でビューアーを閉じる（false で無効）
});
```

OS の「視差効果を減らす」設定（`prefers-reduced-motion`）が有効な場合は、拡大アニメーションの代わりにフェードになります。

### 画像サイズの指定（任意）
指定しなくても動きますが、リンクに実画像の幅と高さを書いておくと、読み込み前から正しい大きさで表示できます。

```html
<a href="photo.jpg" data-ns-width="3000" data-ns-height="2000"><img src="photo-thumb.jpg" alt="…"></a>
```

## てがろぐで使う

[てがろぐ](https://www.nishishi.com/cgi/tegalog/) では、スキンのファイルを書き換えなくても、管理画面で設定するだけで NagiSwipe を使えます。

1. てがろぐの管理画面で **[設定] → [システム設定] → 【画像拡大スクリプトの選択】** を開きます。
2. 「**他のスクリプトを使う：URLを指定**」を選び、次の 2 つをコピーして貼り付け、保存します。
   - 「JavaScriptのURL」欄: `https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.3.0/NagiSwipe-main.js?93db749`
   - 「CSSのURL」欄: `https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.3.0/NagiSwipe-main.css?97c58dd`

これで、投稿の画像をクリックすると NagiSwipe で大きく表示されます。

**漫画ビューアー（NagiManga）も使う場合** は、同じ「JavaScriptのURL」欄で、NagiSwipe の URL の **うしろに半角スペースを 1 つ入れて**、NagiManga の URL を続けて書きます（1 行のまま続けます。「CSSのURL」欄はそのままで大丈夫です）。

```
https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@v1.3.0/NagiSwipe-main.js?93db749 https://あなたのサイト/nagimanga/viewer/NagiManga.js?d978231
```

あわせて、**画像のない投稿でも** 漫画を開けるように設定します。てがろぐは、画像のない投稿のページでは上の欄のファイルを読み込まないためです。[設定] → [ページの表示] → 【投稿本文の表示／URL処理】 の「▼画像URLを画像として埋め込む表示」にある **「画像リンクに独自のclass属性値を追加する」** にチェックを入れ、`class="` と `"` の間の欄に `nagimanga` と入力して保存します。

てがろぐに漫画を載せる方法は、[manga/README.md の「てがろぐに載せる」](manga/README.md#てがろぐに載せる) を見てください。

[NagiMemo](https://github.com/Lichiphen/NagiMemo)（てがろぐ用のスキン）でも同じ方法で使えます。

## 漫画ビューアー（NagiManga）
[`manga/`](manga/) に、漫画ビューアーとデータベース不要の簡易 CMS を同梱しています。

- 右から左・見開き・縦読み（ページ漫画／ウェブトゥーン）、しおり、拡大
- 画像のドラッグ＆ドロップや、CLIP STUDIO PAINT などの漫画 EPUB から作品を作れます
- スマホ用の小容量版と、パソコン・拡大用の通常版を自動で読み分けます
- パスワード付きの限定公開、共有タグの発行、ZIP でのバックアップ

デモ: https://notebook.lichiphen.com/nagimanga/demo.html ／ 導入方法: [manga/README.md](manga/README.md) ／ 開発者でない方向けの手引き: [manga/docs/overview.md](manga/docs/overview.md)

## 権利・免責事項：掲載画像について
本プロジェクトのデモ（`index.html`等）で使用されている画像について：

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
