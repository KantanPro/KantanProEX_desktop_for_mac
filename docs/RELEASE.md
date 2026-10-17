# 新しい版を出す手順

アプリは起動時に1日1回、このリポジトリの `update.json`（main ブランチ）を読み、
`version` がアプリより新しければ「新しい版があります」と知らせて `url` を開きます。
**`update.json` を更新した時点で、使っている人全員にお知らせが出ます。** dmg を配布ページに置いてから更新してください。

1. `package.json` の `version` を上げる（例: 0.1.0 → 0.2.0）
2. `npm run build:mac` で `dist/KantanProEX-<版>.dmg` を作る
3. `scripts/test-dist.sh` で配布版を自動テストする（普段使っているデータには触れない）
4. kantanpro.com の WooCommerce の商品（ダウンロード商品）のファイルを新しい dmg に差し替える
   - 購入済みの人は「マイアカウント → ダウンロード」（`/wc-account/downloads/`）から新しい版を取れる
5. `update.json` の `version`・`released`・`notes` を書き換えて main に push する

## 注意

- Apple の署名・公証をしていないので、自動での入れ替えはできない（お知らせ → 本人が dmg から入れ直す）。
  初めて開くときは右クリック →「開く」が要る。
- データはアプリの外（`~/Library/Application Support/KantanProEX/`）にあるので、入れ直しても消えない。
  アプリ内の WordPress 一式は、版が変わると起動時に自動で入れ替わる。
