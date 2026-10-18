# 新しい版を出す手順

アプリは起動時に1日1回、このリポジトリの `update.json`（main ブランチ）を読み、
`version` がアプリより新しければ「新しい版があります」と知らせて `url` を開きます。
**`update.json` を更新した時点で、使っている人全員にお知らせが出ます。** dmg を配布ページに置いてから更新してください。

1. `package.json` の `version` を上げる（例: 0.1.0 → 0.2.0）
2. `npm run build:mac` で `dist/KantanProEX-<版>.dmg` を作る
3. `scripts/test-dist.sh` で配布版を自動テストする（普段使っているデータには触れない）
4. kantanpro.com の WooCommerce 商品 **ID 19249**「【買い切り】KantanProEX デスクトップ版（Mac）」のダウンロードファイルを差し替える
   - dmg は WordPress が許可しない種類なので、**ZIP に入れて**上げる（`zip -0 KantanProEX-Mac-<版>.zip KantanProEX-<版>.dmg`）
   - **Cloudflare は 100MB 超のアップロードを 413 で断る。** サーバー本体（`origin.kantanpro.com` と同じ IP）へ直接送る:
     `curl --resolve www.kantanpro.com:443:<origin の IP> -u "$WP_USER:$WP_APP_PASSWORD" -F type=downloadable_product -F file=@zip https://www.kantanpro.com/wp-json/wp/v2/media`
     （ブラウザ相当の User-Agent を付ける。約4分。`--http1.1` と長めの `-m` で。1回目は途中で切れることがある）
   - `type=downloadable_product` で購入者専用の `woocommerce_uploads/` に入る（直リンクは 403）
   - `PUT wc/v3/products/19249` の `downloads` の `file` と `name` を新しい ZIP に更新（**`id`（UUID）は変えない**。購入済みの人の権限が切れる）
   - 購入済みの人は「マイアカウント → ダウンロード」（`/wc-account/downloads/`）から新しい版を取れる
5. `update.json` の `version`・`released`・`notes` を書き換えて main に push する

## 注意

- Apple の署名・公証をしていないので、自動での入れ替えはできない（お知らせ → 本人が dmg から入れ直す）。
  初めて開くときは右クリック →「開く」が要る。
- データはアプリの外（`~/Library/Application Support/KantanProEX/`）にあるので、入れ直しても消えない。
  アプリ内の WordPress 一式は、版が変わると起動時に自動で入れ替わる。

## Windows 版（インストーラー .exe）

Mac 版と同じリポジトリ・同じソース（`site/mu-plugins/`・`main.js`・`lib/`）から作る。違いは同梱する PHP とビルドだけ。

1. `npm run build:win`（`scripts/fetch-php-win.sh` が公式の PHP 8.3 と VC++ ランタイム、CA 証明書を取り込み、
   `dist/KantanProEX-Setup-<版>.exe` ができる。この Mac でも作れる）
2. **実機の Windows で動作確認する**（この Mac では Windows のアプリを動かせないため、自動テストはできない）
   - インストール → 起動 → 利用規約に同意 → 顧客・受注書を作る → 印刷（PDF 保存）→ 終了
   - KantanBiz の ZIP を「ファイル → KantanBiz から取り込む…」で取り込む
3. 配布: kantanpro.com の WooCommerce 商品 **ID 19280**「【買い切り】KantanProEX（DT）for Windows」（Mac 版とは別の商品）の
   ダウンロードファイルを差し替える（`zip -0 KantanProEX-DT-for-Windows-<版>.zip KantanProEX-Setup-<版>.exe` を、
   Mac 版と同じ手順でアップロードし、`PUT wc/v3/products/19280` の `downloads` を更新。**`id`（UUID）は変えない**）
4. `update.json` の Windows 用の `version_win` と `url_win` を書き換えて push（Mac 用の `version` / `url` とは別に進められる）

### Windows 版の注意
- 署名をしていないので、初めて開くとき SmartScreen の警告が出る（「詳細情報」→「実行」）
- 同梱の PHP は VC++ 2015-2022 ランタイムを必要とする。インストーラーが、入っていない PC にだけ静かに入れる（管理者の確認が 1 回出る）
- Windows の PHP 内蔵サーバーは同時処理（ワーカー）に未対応で、1 本で動く（`lib/php.js`）
- データは `%APPDATA%\KantanProEX\`、バックアップは「ドキュメント」の `KantanProEX バックアップ` に置く
