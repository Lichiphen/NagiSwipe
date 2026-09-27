# NagiSwipe

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

軽量・単一ファイルで完結する画像ポップアップギャラリー・ライブラリです。
モバイルファースト、タッチ操作の快適さを目指しています。

[**Demo / Documentation (Cloudflare Pages)**](https://nagiswipe.pages.dev/)

[**漫画ビューアー NagiManga のデモ**](https://notebook.lichiphen.com/nagimanga/demo.html)（右から左・見開き・縦読み・パスワード付き・EPUB 取り込みの作品、管理画面をゲストで見る）— 詳しくは [manga/README.md](manga/README.md)

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
[jsDelivr](https://www.jsdelivr.com/) を利用して高速に配信されます。最新の安定版を使用する場合は以下のURLをコピーしてください。

```html
<!-- CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@93db749/NagiSwipe-main.css">

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@93db749/NagiSwipe-main.js"></script>
```

※常に最新の `main` ブランチを参照したい場合は `@main` を使用してください。

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

## 漫画ビューアー（NagiManga）
右から左・見開き・縦読みに対応した漫画ビューアーと、データベース不要の簡易 CMS（画像管理・共有タグ発行・パスワード付き公開・バックアップ）を [`manga/`](manga/) に同梱しています。導入方法は [manga/README.md](manga/README.md) を参照してください。

## 権利・免責事項：掲載画像について
本プロジェクトのデモ（`index.html`等）で使用されている画像について：

- **猫のイラスト（Kyururun.png, Fu-n.png, Shimeshime.png）**
    - これらは **Lichiphen（作者）本人が制作したデジタルアート** です。
    - 著作権は作者に帰属しますが、本ライブラリのデモ用として同梱されています。

- **その他の写真画像（Picsum経由等）**
    - これらは [Lorem Picsum](https://picsum.photos/) 等の外部サービスから取得しているサンプルです。
    - **これらの写真画像の著作権は Lichiphen には帰属しません。**
    - 写真素材については本ソフトウェア（NagiSwipe）の MIT LICENSE の対象外です。各画像のライセンスについては提供元（Unsplash等）の規定に従ってください。

## ライセンス
[MIT License](LICENSE) (c) 2026 Lichiphen
