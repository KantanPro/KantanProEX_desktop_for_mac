# 新しい版を出す手順

アプリは起動時に1日1回、このリポジトリの `update.json`（main ブランチ）を読み、
`version` がアプリより新しければ「新しい版があります」と知らせて `url` を開きます。
**`update.json` を更新した時点で、使っている人全員にお知らせが出ます。** dmg を配布ページに置いてから更新してください。

1. `package.json` の `version` を上げる（例: 0.1.0 → 0.2.0）
2. `npm run build:mac` で `dist/KantanProEX-<版>.dmg` を作る
3. `scripts/test-dist.sh` で配布版を自動テストする（普段使っているデータには触れない）
4. 無料ダウンロードを差し替える（2026-11-01 から無料版。アプリは誰でも落とせ、有料版はライセンスキーで決まる。商品 19249 はライセンスだけを売り、配布ファイルは持たない）
   - 手順は `~/.kantanpro/bin/dt-release.py`（オリジンの IP を含むのでリポジトリの外）が通す: `check` → `build` → テスト結果を読む → `publish --go` → `verify`
   - dmg は WordPress が許可しない種類なので **ZIP に入れて**、**公開の uploads** に上げる（`type=downloadable_product` は付けない）
   - **Cloudflare は 100MB 超のアップロードを 413 で断る。** サーバー本体（オリジン）へ `curl --resolve` で直接送る（約4分、途中で切れたら再試行）
   - 変わらない URL **`/dl/kantanproex-dt-mac`**・**`/dl/kantanproex-dt-windows`**（Redirection の 302）の転送先を新しい ZIP に差し替える。商品ページのボタンも `update.json` の `url` / `url_win` もこの URL を指す
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
3. 配布: `zip -0 KantanProEX-DT-for-Windows-<版>.zip KantanProEX-Setup-<版>.exe` を Mac 版と同じく公開の uploads に上げ、`/dl/kantanproex-dt-windows` の転送先を差し替える（`dt-release.py publish win --go`）
4. `update.json` の Windows 用の `version_win` と `url_win` を書き換えて push（Mac 用の `version` / `url` とは別に進められる）

### Windows 版の注意
- 署名をしていないので、初めて開くとき SmartScreen の警告が出る（「詳細情報」→「実行」）
- 同梱の PHP は VC++ 2015-2022 ランタイムを必要とする。インストーラーが、入っていない PC にだけ静かに入れる（管理者の確認が 1 回出る）
- Windows の PHP 内蔵サーバーは同時処理（ワーカー）に未対応で、1 本で動く（`lib/php.js`）
- データは `%APPDATA%\KantanProEX\`、バックアップは「ドキュメント」の `KantanProEX バックアップ` に置く
