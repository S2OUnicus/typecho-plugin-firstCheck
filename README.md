# FirstCheck for Typecho

FirstCheck は、Typecho サイトの初回アクセス時にパスワード確認画面を表示し、確認に成功したユーザーだけを本来のページへ戻すためのプラグインです。

テーマフォルダー内の `firstCheck.php` を確認画面として使用します。パスワードは1つでも複数でも設定でき、Google reCAPTCHA v2、darkmode、失敗回数による一時ブロック、除外パスなどを管理画面から設定できます。

## 主な機能

- 初回アクセス時にパスワード確認画面を表示
- 投稿、固定ページ、アーカイブなどの表示前にチェック
- ブログ index をチェック対象にするかどうかを選択可能
- 管理画面、Typecho action ルート、アップロードファイル、テーマ資産、プラグイン資産、静的ファイルは除外
- テーマフォルダー内の `firstCheck.php` を確認画面として使用
- パスワードは1つまたは複数設定可能
- パスワードは半角英数字のみ、3〜14桁
- 入力欄数は、設定済みパスワードの最大桁数から自動判断
- 大文字・小文字を区別するかどうかを選択可能
- 金庫の暗証番号のような1桁ずつの入力UI
- 最大桁数まで入力すると自動確認
- 確認失敗時は入力内容を自動削除
- パスワード表示方式を選択可能
  - 完全隠す
  - 遅延暗号化、入力後200msだけ表示
  - そのまま表示
- Google reCAPTCHA v2 を使用するかどうかを選択可能
- reCAPTCHA 使用時は、ロード失敗、未入力、サーバー検証失敗を確認失敗として処理
- darkmode 対応
  - ブラウザで判断
  - 指定時間帯だけ darkmode
  - ずっと darkmode
  - 自動にしない
- 左下の丸いボタンで darkmode を手動切替可能
- 5回以上ミスした場合、一時ブロック
- 確認成功後の有効時間を設定可能
- 背景画像、提示文、meta author、meta theme-color を設定可能
- レスポンシブ対応
- UIKit CSS、Google Fonts を使用
- CSRF 対策、リダイレクトURL検証、Captcha hostname検証、HTMLエスケープ、ロックファイル保護を実装

## ファイル構成

```text
FirstCheck/
├── Plugin.php
├── firstCheck.php.example
└── README.md
```

## インストール

1. このリポジトリをダウンロード、または clone します。

```bash
git clone https://github.com/S2OUnicus/typecho-plugin-firstCheck.git
```

2. `FirstCheck` フォルダーを Typecho のプラグインフォルダーに配置します。

```text
usr/plugins/FirstCheck
```

3. `firstCheck.php.example` を現在使用中のテーマフォルダーへコピーし、`firstCheck.php` にリネームします。

```text
usr/themes/<your-active-theme>/firstCheck.php
```

4. Typecho 管理画面で `FirstCheck` を有効化します。

5. プラグイン設定画面で、パスワードなどの必要項目を設定します。

## アップデート

1. 新しい `FirstCheck` フォルダーで `usr/plugins/FirstCheck` を差し替えます。
2. `firstCheck.php.example` に変更がある場合は、テーマフォルダー側の `firstCheck.php` も差し替えてください。
3. 管理画面で設定項目を確認し、必要に応じて保存し直してください。

## 設定項目

| 項目 | 説明 | 初期値 |
| --- | --- | --- |
| 提示情報の文字 | パスワード入力欄の上に表示する説明文 | `パスワードを入力してください` |
| パスワード（複数可） | 1行に1つ、またはカンマ区切りで指定 | 空 |
| 大文字・小文字の区別 | パスワード全体に適用 | 区別しない |
| 入力中のパスワード表示方式 | 完全隠す、遅延暗号化、そのまま表示 | 完全隠す |
| ブログの index もパスワードチェックする | `/` と `/index.php` をチェックするか | チェックする |
| 背景画像 URL | 確認画面の背景画像 | 空 |
| author metaタグ | `meta name="author"` の値 | 空 |
| theme-color metaタグ | `meta name="theme-color"` の値 | 空 |
| darkmode の自動判定 | ブラウザ、時間帯、常時、自動なし | ずっと darkmode |
| darkmode 開始時刻 | 指定時間帯方式で使用 | `18` |
| darkmode 終了時刻 | 指定時間帯方式で使用 | `7` |
| Google reCAPTCHA v2 | 使用するかどうか | 使わない |
| Site Key | reCAPTCHA v2 の Site Key | 空 |
| Secret Key | reCAPTCHA v2 の Secret Key | 空 |
| ブロック時間（分） | 5回以上ミスした場合のブロック時間 | `60` |
| 確認後の有効時間（分） | 再確認不要にする時間 | `30` |
| 排除ディレクトリ・パス | チェック対象から除外するパス | 管理画面、action、uploads など |

## パスワード設定

パスワードは半角英数字のみ使用できます。
各パスワードは3桁以上14桁以下です。

1つだけ設定する場合:

```text
ABC123
```

複数設定する場合:

```text
ABC123
DEF456
AbCd7890
```

または、カンマ区切りでも設定できます。

```text
ABC123,DEF456,AbCd7890
```

どれか1つに一致すればアクセスできます。
入力欄数は、設定済みパスワードの最大桁数から自動判断されます。

例:

```text
ABC
AbCd1234
```

この場合、最大桁数は8桁なので、確認画面には8個の入力欄が表示されます。
ただし、3桁の `ABC` も有効なパスワードとして使用できます。

## パスワード表示方式

### 完全隠す

入力直後から `●` として表示します。

### 遅延暗号化

入力した桁だけ一瞬表示し、200ms後に `●` に切り替えます。

### そのまま表示

入力した文字を隠さず表示します。

`完全隠す` と `遅延暗号化` では、画面上の入力欄に実際の文字を残さず、送信直前に hidden フィールドへ組み立てます。
コピーと切り取り操作も抑制します。

## 自動確認

最大桁数まで入力すると、自動で確認します。

ただし、複数パスワードの桁数が違う場合、短いパスワードは最大桁数まで到達しません。
その場合は、確認ボタンまたは Enter キーで確認できます。

確認に失敗して画面に戻った場合、入力済みの内容は自動で削除されます。

## ブログ index の扱い

`ブログの index もパスワードチェックする` を `チェックしない` にすると、通常の記事リストトップページでは確認画面を出しません。

対象例:

```text
/
/index.php
```

投稿、固定ページ、アーカイブなどは引き続きチェック対象です。

Typecho のログインなどで使用される action ルートは、ログインを妨げないよう常に除外されます。

例:

```text
/action/login
/index.php/action/login
```

## darkmode

設定画面で以下から選択できます。

| モード | 説明 |
| --- | --- |
| ブラウザで判断 | `prefers-color-scheme` に従います |
| 指定時間帯だけ darkmode | 開始時刻〜終了時刻の間だけ darkmode にします |
| ずっと darkmode | 常に darkmode にします |
| 自動にしない | 自動判定を行いません |

確認画面の左下には、常に丸い darkmode 切替ボタンが表示されます。
クリックすると手動設定として保存され、次回以降もその選択が優先されます。
ダブルクリックすると手動設定を解除し、自動判定に戻ります。

## Google reCAPTCHA v2

Google reCAPTCHA v2 は任意です。

`使わない` を選択した場合、Captcha欄とGoogleスクリプトは読み込まれません。

`使う` を選択した場合、以下の両方が必要です。

- Site Key
- Secret Key

reCAPTCHA 使用時は、以下を確認失敗として扱います。

- Site Key または Secret Key が未設定
- Googleスクリプトのロード失敗
- Captcha 未完了
- サーバー側検証失敗
- hostname が一致しない検証レスポンス

## metaタグ

`firstCheck.php` では以下のmetaタグを出力します。

```html
<meta charset="UTF-8">
<meta http-equiv="Cache-Control" content="no-siteapp">
<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
<meta name="referrer" content="origin-when-cross-origin">
<meta name="robots" content="index,follow">
<meta name="viewport" content="width=device-width,height=device-height,initial-scale=1,minimum-scale=1,maximum-scale=1,user-scalable=no">
<meta name="format-detection" content="telephone=no,email=no">
<meta name="google" content="notranslate">
<meta name="author" content="">
<meta name="theme-color" content="">
```

`author` と `theme-color` はプラグイン設定画面から指定できます。

`theme-color` は、空欄、または以下の形式のみ出力します。

```text
#RGB
#RRGGBB
#RRGGBBAA
```

## 外部リソース

確認画面では以下を使用します。

- Google Fonts: `Zen Maru Gothic`
- UIKit CSS / JS: `3.23.13`
- Google reCAPTCHA v2、使用する場合のみ

## セキュリティと異常処理

FirstCheck は以下の対策を行います。

- Typecho の実行コンテキスト外からの直接実行を拒否
- CSRF トークン検証
- パスワードの形式検証
- 複数パスワードの正規化
- 大文字・小文字を区別しない設定時の正規化
- HTML出力時のエスケープ
- 背景URLの検証
- `//example.com` のようなプロトコル相対URLを拒否
- 確認成功後のリダイレクト先を同一サイト内の相対URLに制限
- reCAPTCHA のサーバー側検証
- reCAPTCHA hostname の照合
- 失敗回数による一時ブロック
- ロックファイルの atomic write
- ロックディレクトリへの `.htaccess` と `index.html` の自動生成
- ロックディレクトリが書き込み不可の場合のセッションフォールバック
- 例外詳細を確認画面に表示せず、サーバーログに記録

ただし、このプラグインは簡易的なアクセス確認を目的としたものです。
高度な会員制サイト、決済情報、個人情報、機密情報の保護には、サーバー側認証、HTTPS、適切な権限設計、WAFなどを併用してください。

## 除外対象

以下は標準でチェック対象から除外されます。

- Typecho 管理画面
- Typecho action ルート
- アップロードファイル
- テーマ資産
- プラグイン資産
- favicon
- robots.txt
- sitemap
- feed
- 一般的な静的ファイル拡張子

必要に応じて、設定画面の `排除ディレクトリ・パス` に追加してください。

## 注意事項

- `firstCheck.php` は直接アクセス用の独立ページではありません。
- プラグインが Typecho のコンテキスト内で `include` するテンプレートです。
- テーマ側の `firstCheck.php` をカスタマイズした場合、アップデート時に差し替え漏れがないよう注意してください。
- パスワードは Typecho のプラグイン設定として保存されます。管理画面とデータベースの権限管理を適切に行ってください。
- reCAPTCHA を使用する場合は、Google 側で対象ドメインを正しく設定してください。

## 変更履歴

### 1.0.1

- `firstCheck.php` にmetaタグ一式を追加
- 既存の `viewport` と `robots` を指定内容へ統一
- `robots index,follow` と衝突する `X-Robots-Tag: noindex,nofollow` ヘッダーを削除
- `Referrer-Policy` を `origin-when-cross-origin` に統一
- 設定画面に `author metaタグ` を追加
- 設定画面に `theme-color metaタグ` を追加
- `theme-color` の形式検証を追加
- `firstCheck.php.example` 内のコメントを日本語化
- CSSを1行に圧縮
- JSを1行に圧縮
- CSRF、Captcha、リダイレクトURL、背景URL、meta出力、失敗ロックまわりを再確認

### 1.0.0

- 安定版としてバージョンを `1.0.0` に更新
- 最大桁数まで入力した場合の自動確認を追加
- 確認失敗時、入力済み内容を自動削除する処理を追加
- reCAPTCHA 有効時の自動確認条件を調整
- 自動確認中の二重送信防止を追加

### 0.6.0

- 確認画面のCSS仕様を調整
- `<title>` を `サイト名 - 安全確認` に変更
- Google Fonts を `Zen Maru Gothic` に変更
- UIKit を cdnjs `3.23.13` に変更
- UIKit CSS / JS に `integrity`、`crossorigin`、`referrerpolicy` を追加
- 画面下部の補足表示を、大文字・小文字の区別のみの表示に変更

### 0.5.0

- ブログ index をパスワードチェック対象にするかどうかの設定を追加
- `/action/...` と `/index.php/action/...` を常に除外するよう変更
- Typecho ログイン画面がパスワードチェックで妨げられる問題を修正
- 初期値を調整
  - 提示情報: `パスワードを入力してください`
  - 大文字・小文字の区別: 区別しない
  - パスワード表示方式: 完全隠す
  - darkmode: ずっと darkmode
  - reCAPTCHA: 使わない
  - ブロック時間: 60分
  - 確認後の有効時間: 30分
- darkmode に `ずっと darkmode` を追加

### 0.4.0

- パスワード表示方式の選択肢を追加
  - 完全隠す
  - 遅延暗号化
  - そのまま表示
- 遅延暗号化では、入力した桁を200ms後に黒丸表示へ変更
- 実文字を画面上の入力欄に残さず、送信直前に hidden フィールドへ組み立てる方式へ変更
- コピー、切り取り操作の抑制を追加
- リセット時に内部配列、表示、hidden値をすべてクリアする処理を追加

### 0.3.0

- パスワード桁数の手動設定を削除
- 設定済みパスワードの最大桁数から入力欄数を自動判断するよう変更
- パスワード長の最小3桁、最大14桁を維持
- 大文字・小文字を区別するかどうかの設定を追加
- darkmode の自動判定方式を追加
  - ブラウザで判断
  - 指定時間帯だけ darkmode
  - 自動にしない
- 左下の丸い darkmode 手動切替ボタンを追加
- reCAPTCHA v2 を使用するかどうかの設定を追加
- reCAPTCHA 使用時のロード失敗、未入力、サーバー検証失敗の処理を強化
- セキュリティヘッダー、背景URL、ロックファイルまわりの処理を強化

### 0.2.0

- 複数パスワードに対応
- 1行に1つ、またはカンマ区切りで複数設定できるよう変更
- どれか1つに一致すればアクセス許可するよう変更
- 旧設定名からのフォールバックを追加

### 0.1.0

- 初回版
- テーマフォルダー内の `firstCheck.php` を確認画面として表示
- パスワード確認後、元のリクエスト先へリダイレクト
- 背景画像、提示情報、パスワード、パスワード桁数の設定を追加
- 5回以上ミスした場合の一時ブロックを追加
- 確認成功後の有効時間を追加
- Google reCAPTCHA v2 に対応
- 管理画面、静的ファイル、除外パスの基本除外を追加
- darkmode とレスポンシブ表示に対応

## 今後の予定

必要に応じて、以下の機能を検討できます。

- 管理画面からのテーマテンプレート自動生成
- ログ閲覧画面
- IP単位、Cookie単位、セッション単位のブロック方式切替
- 多言語化ファイルの分離
- Composer 対応
