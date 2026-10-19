# KantanProEX（DT）for Mac / Windows

KantanProEX（WordPress プラグイン）を、ブラウザも WordPress サイトもインターネットも使わずに動かすデスクトップアプリ（Mac 版・Windows 版）です。
データはお使いのパソコン（Mac / Windows）の中だけに保存されます。

- 製品の購入・ダウンロード: [kantanpro.com](https://www.kantanpro.com/)（買い切り）
- このリポジトリには、アプリを組み立てるためのコードと、アップデートのお知らせ用のファイル（`update.json`）を置いています。
  アプリ本体（Mac は dmg、Windows はインストーラー .exe）はここでは配布していません。

## 仕組み

- Electron のウィンドウの中で、同梱の PHP 8.3 と WordPress + SQLite を `127.0.0.1` だけで動かし、KantanProEX をそのまま表示します。
  既定はこのパソコンだけです。メニュー「ファイル」→「LAN 共有…」でオンにしたときだけ、事務所の LAN 向けの https の窓口（`lib/lan.js`）を開き、許可したスタッフが他のパソコンのブラウザからパスワードでログインして使えます（インターネットには公開しません）。iPad・iPhone からも使えます（1.0.0〜）。親機の画面の QR から事務所用の証明書を 1 回だけ入れると警告なしで開け、ホーム画面にも追加できます。オーナー（管理者）も、設定でオンにすれば LAN から使えます（設定・バックアップ・取り込みは親機だけ）。iPad・iPhone では印刷の代わりに PDF を作り、共有シートから AirPrint・保存ができます（`ios-print.js`）。
- 外部への通信は行いません（画面からの読み込みは 127.0.0.1 以外を止めています）。
  例外は、起動時に `update.json` を1回読むアップデートのお知らせ（設定で止められます）と、本人が設定した郵便番号 API・メール送信、任意のユーザー登録（登録したメールアドレスだけを kantanpro.com に送る）だけです。
- データは `~/Library/Application Support/KantanProEX/` に置き、起動時に1日1回「書類」フォルダへ自動でバックアップします。
- WordPress 版との違い（デスクトップ版で調整している内容）は `site/mu-plugins/ktp-desktop.php` にまとめています。プラグイン本体は改変しません。

## WordPress 版（KantanProEX（WP））からの移行

KantanProEX（WP）1.4.46 以降の「KantanProEX 設定 → バックアップ → デスクトップ版へ移行」で書き出した ZIP を、メニュー「ファイル」→「WordPress 版から取り込む…」で取り込みます（`scripts/import-wordpress.php`）。
同じプラグインなので、業務データの表（顧客・受注・明細・コスト・契約・部署・協力会社・サービス・メール履歴・スタッフチャット・案件ファイル）は ID ごとそのまま入ります。帳票やメールの設定、帳票のロゴと印影、案件ファイル、サービス画像も移ります。

- アプリ内の業務データは取り込んだ内容に入れ替わります。取り込み前に自動でバックアップします。
- WP 版の管理者（書き出した人）はアプリのオーナーに、ほかの管理者・スタッフ（ktpwp_access）はスタッフになります。スタッフのパスワードは移らないので、「LAN 共有」の画面で決め直します。
- SMTP のパスワード・日本郵便 API のシークレットキー・Stripe の鍵は書き出されません。日本郵便 API は入れ直すまで止めておきます。
- 自動テスト: `KTP_TEST_USER_DATA=<別の場所> KTP_TEST_IMPORT_WP_FILE=<ZIP> npm start`

## KantanBiz（SaaS 版）からの移行

KantanBiz のオーナーが「デスクトップ版へ移行」から書き出した ZIP を、メニュー「ファイル」→「KantanBiz から取り込む…」で取り込みます。
顧客・協力会社・サービス・案件（明細・チャット・ファイル・タスク・メール履歴）・契約・会社情報・帳票設定が移ります（`scripts/import-kantanbiz.php`）。

- アプリ内の業務データは取り込んだ内容に入れ替わります。取り込み前に自動でバックアップします。
- SMTP のパスワードは書き出されないので、設定画面で入れ直してください。
- KantanBiz の機能との対応は [docs/KANTANBIZ.md](docs/KANTANBIZ.md)
- 自動テスト: `KTP_TEST_USER_DATA=<別の場所> KTP_TEST_IMPORT_FILE=<ZIP> npm start`（起動後に取り込みを実行し、結果を `logs/app.log` に残す）

## 組み立て方（開発者向け）

必要なもの: macOS（Intel / x64）、Node.js、KantanProEX プラグインのソース。

```bash
npm install
npm run prepare:php      # 静的ビルドの PHP を bin/ に取得
npm run prepare:site     # WordPress 一式を site-template/ に組み立て（KTPEX_SOURCE_DIR でプラグインの場所を指定）
npm start                # 開発用に起動（データは KantanProEX-dev に分かれる）
npm run build:mac        # dist/KantanProEX-<版>.dmg を作る
npm run build:win        # dist/KantanProEX-Setup-<版>.exe を作る（Windows 版。Mac 上でも作れる）
scripts/test-dist.sh     # 作った配布版を、普段のデータとは別の場所で自動テスト
```

新しい版の出し方は [docs/RELEASE.md](docs/RELEASE.md) を見てください。

## 主なファイル

| 場所 | 内容 |
|---|---|
| `main.js` | ウィンドウ・メニュー・起動の流れ・外部通信の遮断 |
| `lib/site.js` | WordPress 一式の配置と初回セットアップ |
| `lib/php.js` | 同梱 PHP（内蔵サーバー・wp-cli）の実行 |
| `lib/backup.js` | バックアップと復元 |
| `lib/lan.js` | LAN 共有の窓口（https・私設アドレスだけ許可・PHP へ中継） |
| `lib/update.js` | アップデートのお知らせ |
| `php/router.php` | PHP 内蔵サーバー用のルーター |
| `site/mu-plugins/` | デスクトップ版用の調整（自動ログイン・画面テンプレート・不要な設定の非表示など） |
| `assets/baseline-ddl.json` | 初回に作るテーブルの構造（データなし） |
