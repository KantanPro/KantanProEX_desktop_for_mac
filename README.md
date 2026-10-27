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
  例外は、起動時に `update.json` を1回読むアップデートのお知らせ（設定で止められます）と、本人が設定した郵便番号 API・メール送信・サイトからの受注、任意のユーザー登録（登録したメールアドレスだけを kantanpro.com に送る）だけです。
- データは `~/Library/Application Support/KantanProEX/` に置き、起動時に1日1回「書類」フォルダへ自動でバックアップします。
- WordPress 版との違い（デスクトップ版で調整している内容）は `site/mu-plugins/ktp-desktop.php` にまとめています。プラグイン本体は改変しません。

## サイトからの受注（ホームページの問い合わせ・注文）

デスクトップ版には外から受け付ける口がないので、ホームページ（WordPress）に受付プラグイン `bridge/ktp-dt-bridge` を入れてもらい、
そこに預けられた Contact Form 7 のお問い合わせと WooCommerce の注文を、アプリが取りに行きます（PC → サイトの一方向。PC は公開しない）。

- アプリ側: 「KantanProEX 設定」→「サイトからの受注」（`site/mu-plugins/ktp-desktop/intake.php`）。既定はオフ。サイトのアドレス（https のみ）と接続キーを入れてオンにすると、wp-cron で 15 分ごとに取り込む。受付プラグインの ZIP もこの画面から保存できる
- サイト側: 「設定」→「KantanProEX 受付」に接続キーが出る。REST `?rest_route=/ktp-dt-bridge/v1/inbox`（取得）・`/ack`（受け取り済み）を `X-KTP-Bridge-Key` で守る。渡し終えた分は 7 日で消す
- 受注の作り方は EX 本体と同じ（CF7: `KTPWP_Contact_Form::import_submission()`、WooCommerce: `KTPWP_WooCommerce_Integration::import_order_payload()`）。受付プラグインは WooCommerce の注文を本体の `order_payload()` と同じ形にして預ける。形を変えるときは両方そろえる
- 取り込んだ預かり分の uuid を覚えておき、「受け取った」を返せずに再び届いても二重にしない
- **公開商品**: 「サイト公開」にしたサービスを、アプリがサイトへ送る（`POST /catalog`・`/catalog-image`。内容が変わったときだけ。画像は 900px 以内の JPEG に縮めて、足りないものだけ）。サイト側は `bridge/ktp-dt-bridge/catalog.php` がショートコード `[ktp_dt_products]`（EX と同じ `[ktpwp_public_products]` も、EX が無いサイトでは使える）でカード表示し、申し込みフォーム（admin-ajax）の入力を受付箱（`kind=public_order`）に預ける。アプリは `KTPWP_Public_Product_Order::submit_order()` で受注にする（数量・税率・明細は EX と同じ）。完売・保留はサイトに送り直すので、次の同期（15 分以内）でボタンが消える
- 公開を止めた商品・完売後の申し込みは受注を作らず、連絡先を「最近の取り込み」に残して受け取りは済ませる
- **即時購入（Stripe 決済）**（`site/mu-plugins/ktp-desktop/stripe-purchase.php`、受付プラグイン 1.2.0〜）: 秘密鍵は「サイトからの受注」の画面で入れ、PC の中だけに置く（WP 版の Stripe 設定 `ktp_general_settings` は使わない。使うと請求メールへの決済リンクなど WP 版の Stripe 請求連携が丸ごと動くため）。
  「即時購入」にした公開商品ごとにアプリが Stripe の Payment Link を作り、URL だけを `buy_url` としてカタログに載せる。サイトの「購入する」は `buy.stripe.com` へのリンク（それ以外の URL は受付プラグインが捨てる）。
  取り込みのたびに `GET /v1/checkout/sessions?payment_link=…&status=complete` で入金を取りに行き、`submit_order()` → `KTPWP_Stripe_Billing::sync_public_checkout_session_for_order()` で WP 版と同じ「受注・入金済み・前払い・通知メール」にする（通知の内容は本体と同じ transient `ktpwp_instant_purchase_ctx_{受注ID}` に置く）。取り込んだ Checkout Session ID を覚えて二重にしない。
  商品名・値段・数量固定・単位が変わったらリンクを作り直して前のリンクを止める。止めたリンクも 3 日間は入金を取りに行く。1 円単位でない値段・50 円未満は対象外（「申し込む」のまま）。購入後に公開を止めた等で受注を作れない入金は、理由と購入者を「最近の取り込み」に残す
- **定期契約の即時購入**（受付プラグイン 1.4.0〜で「毎月・初回費用」を表示）: Payment Link を subscription 用（周期・毎回の金額＋初回費用は買い切りの価格）で作る。最初の購入は `submit_order()` → `KTPWP_Order_Contract_Conversion::convert()`（`skip_subscription_start`）で受注＋契約にし、`stripe_subscription_id` を DT が直接書く（本体の `attach_subscription_to_contract()` は SDK が無いと保存しないため）。これで本体の毎月の請求・請求メールは止まる。2 回目以降は取り込みのたびに `ktp_dt_stripe_sync_subscriptions()` が `GET /v1/invoices?status=paid` を取り、本体の `handle_subscription_invoice_paid()` に渡して今回分の受注を入金済みで作る。Stripe 側の解約 → 契約「解約」、DT の「解約」→ `cancel_at_period_end`、「一時停止」→ `pause_collection`。制限付きキーには Subscriptions（書き込み）と Invoices（読み取り）が要る。自動テストでは `KTP_DT_STRIPE_API_BASE`（127.0.0.1 のみ有効）で模擬 Stripe に向ける
- 本体の「即時購入」のチェックは、外して保存しても値が送られず前の値が残る（EX 本体の保存処理）。DT は app-template.php で外したときの 0 を hidden で先に置いている

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
| `bridge/ktp-dt-bridge/` | ホームページに入れる受付プラグイン（サイトからの受注） |
| `assets/baseline-ddl.json` | 初回に作るテーブルの構造（データなし） |
