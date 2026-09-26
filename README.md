# NagiSwipe

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

軽量・単一ファイルで完結する画像ポップアップギャラリー・ライブラリです。
モバイルファースト、タッチ操作の快適さを目指しています。

[**Demo / Documentation (Cloudflare Pages)**](https://nagiswipe.pages.dev/)

## 特徴
- **ドロップイン導入**: JSとCSSを読み込むだけで、ページ内の画像リンクを自動的にギャラリー化します。
- **モバイル最適化**: スワイプ、ピンチズーム、ダブルタップに対応。
- **軽量・高速**: 依存ライブラリなし。
- **スムーズな操作感**: 60fpsを目指した滑らかなアニメーション。
- **安心設計**: ブラウザの「戻る」ボタンでの離脱防止ロジック、自動DOM生成。

## 導入方法

HTMLの `<head>` 内で以下のファイルを読み込んでください。

### CDN経由 (推奨)
[jsDelivr](https://www.jsdelivr.com/) を利用して高速に配信されます。最新の安定版を使用する場合は以下のURLをコピーしてください。

```html
<!-- CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@b0f8e32/NagiSwipe-main.css">

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/gh/Lichiphen/NagiSwipe@b0f8e32/NagiSwipe-main.js"></script>
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
    caption: true,  // 下部キャプションを表示（false で無効）
    counter: true   // 左上の「3 / 12」表示（false で無効）
});
```

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
