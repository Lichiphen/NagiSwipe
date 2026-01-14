# NagiSwipe

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

軽量・単一ファイルで完結する画像ポップアップギャラリー・ライブラリです。
モバイルファースト、タッチ操作の快適さを目指しています。

## 特徴
- **ドロップイン導入**: JSとCSSを読み込むだけで、ページ内の画像リンクを自動的にギャラリー化します。
- **モバイル最適化**: スワイプ、ピンチズーム、ダブルタップに対応。
- **軽量・高速**: 依存ライブラリなし。
- **スムーズな操作感**: 60fpsを目指した滑らかなアニメーション。
- **安心設計**: ブラウザの「戻る」ボタンでの離脱防止ロジック、自動DOM生成。

## 導入方法

HTMLの `<head>` 内で以下のファイルを読み込んでください。

### CDN経由 (推奨)
[jsDelivr](https://www.jsdelivr.com/) を利用して高速に配信されます。

```html
<!-- CSS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/gl/lichiphen/nagiswipe@main/NagiSwipe-main.css">

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/gl/lichiphen/nagiswipe@main/NagiSwipe-main.js"></script>
```

### 使い方
ページ内の `<a href="image.jpg">` のような形式のリンクが自動的に検出され、クリック時にギャラリーが開きます。

## 免責事項：掲載画像について
本プロジェクトのデモ（`index.html`等）で表示される画像は、[Lorem Picsum](https://picsum.photos/) からサービスとして取得しているサンプルです。

- **画像自体の著作権は Lichiphen には帰属しません。**
- **これらサンプル画像は本ソフトウェア（NagiSwipe）の MIT LICENSE の対象外です。**
- 各画像のライセンスについては、サービス提供元および各写真家の規定に従ってください。

## ライセンス
[MIT License](LICENSE) (c) 2026 Lichiphen
