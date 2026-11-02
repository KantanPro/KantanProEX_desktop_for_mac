# KantanProEX（DT）for Mac / Windows

KantanProEX（WordPress プラグイン）を、ブラウザも WordPress サイトもインターネットも使わずに動かすデスクトップアプリ（Mac 版・Windows 版）です。
データはお使いのパソコン（Mac / Windows）の中だけに保存されます。

- ダウンロード（無料）・有料版ライセンスの購入（買い切り）: [kantanpro.com](https://www.kantanpro.com/product/kantanproex-desktop/)
- このリポジトリには、アプリを組み立てるためのコードと、アップデートのお知らせ用のファイル（`update.json`）を置いています。
  アプリ本体（Mac は dmg、Windows はインストーラー .exe）はここでは配布していません。

## 仕組み

- Electron のウィンドウの中で、同梱の PHP 8.3 と WordPress + SQLite を `127.0.0.1` だけで動かし、KantanProEX をそのまま表示します。
  既定はこのパソコンだけです。メニュー「ファイル」→「LAN 共有…」でオンにしたときだけ、事務所の LAN 向けの https の窓口（`lib/lan.js`）を開き、許可したスタッフが他のパソコンのブラウザからパスワードでログインして使えます（インターネットには公開しません）。iPad・iPhone からも使えます（1.0.0〜）。親機の画面の QR から事務所用の証明書を 1 回だけ入れると警告なしで開け、ホーム画面にも追加できます。オーナー（管理者）も、設定でオンにすれば LAN から使えます（設定・バックアップ・取り込みは親機だけ）。iPad・iPhone では印刷の代わりに PDF を作り、共有シートから AirPrint・保存ができます（`ios-print.js`）。
- 外部への通信は行いません（画面からの読み込みは 127.0.0.1 以外を止めています）。
  例外は、起動時に1日1回 `update.json` を読むアップデートのお知らせ（設定で止められます。kantanpro.com の転送を通して読むため、サーバーに IP アドレスが記録され、起動台数の集計に使います）と、本人が設定した郵便番号 API・メール送信・サイトからの受注、任意のユーザー登録（登録したメールアドレスだけを kantanpro.com に送る）だけです。
- データは `~/Library/Application Support/KantanProEX/` に置き、起動時に1日1回「書類」フォルダへ自動でバックアップします。
- WordPress 版との違い（デスクトップ版で調整している内容）は `site/mu-plugins/ktp-desktop.php` にまとめています。プラグイン本体は改変しません。

## 無料版と有料版（ライセンス）

アプリは 1 本のまま（無料版と有料版でビルドは分けない）。有料版かどうかはライセンスキーで決める（`site/mu-plugins/ktp-desktop/license.php`）。

- 状態: `paid`（有効なキー）／`legacy`（無料版に対応する前から入っていたインストール＝既存の購入者。有料版と同じ）／`trial`（新規インストールから 30 日。全機能）／`free`（試用が終わった状態）
- 無料のまま: 顧客・サービス・協力会社・受注・仕事リスト・明細・タスク・請求の取り消し・支払予定・書類の作成と印刷/PDF・請求書メールの送信・バックアップ/復元・CSV 書き出し・取り込み・郵便番号・ダークモード・利用規約まわり
- 有料版だけ: LAN 共有（スタッフ・iPad/iPhone）、サイトからの受注・Stripe 即時購入・定期契約の自動処理、受注書・サービスの複製（顧客・協力会社の複製の POST も念のため止める。本体に画面のボタンは無い）、契約（定期請求リスト・契約の ajax）、レポートタブ、一斉メール、音声操作、Web-FAX、デザインの背景画像
- 判定関数: `ktp_dt_is_paid()`（paid/legacy/trial なら true＝全機能）、`ktp_dt_license_state()`、`ktp_dt_trial_days_left()`、`ktp_dt_has_paid_license()`（paid/legacy。宣伝の 1 行を消す判定。試用中は宣伝を出す）、`ktp_dt_show_ad()`（有料版でなく、ユーザー登録もしていない）、`ktp_dt_locked_screen_html($feature)`（案内画面の共通部品）。EX 本体の `KTPWP_Edition` は 127.0.0.1 で常に有効になるので判定に使わない
- 新規か既存かの見分け: `lib/site.js` が初回セットアップの最初に `data/fresh-install.json` を書く。無くても、オーナー（ID が最小の管理者）の登録日 `user_registered` が `KTP_DT_FREEMIUM_SINCE`（2026-11-02 日本時間）より前のときだけ既存（legacy）。それ以外で目印が無ければ試用（開始は管理者の登録日）。目印・license.json・option を消しても有料扱いにならない。取り込み（import-wordpress / import-kantanbiz）はオーナーの登録日を変えない。記録は DB の option `ktp_dt_license` と、データ置き場の `license.json`（Electron も読む）の二重持ち。バックアップの復元で DB が古い状態に戻っても、legacy/paid が free に落ちない（どちらかが legacy なら legacy、キーは正しいほう、試用の開始は早いほう）
- キー: `base64url(JSON {"p":"ktp-dt","k":鍵番号,"e":メール,"i":発行ID,"t":時刻,"c":引き継ぎの合言葉}) . base64url(署名)`。署名は ECDSA P-256 + SHA-256（同梱 PHP には sodium が無く openssl はある。`openssl_verify`）。アプリには公開鍵だけが入っている（`KTP_DT_LICENSE_PUBKEYS`: `kms1` = Google Cloud KMS の本番の鍵、`bk1` = 予備の鍵）。`k` が無い・一覧に無い鍵番号は不合格。`kms1` は PEM が目印 `__KMS1_PUBLIC_KEY__` のままなら常に不合格（PEM が届いたら license.php で差し替える）。期限なし
- キーの発行: 通常は kantanpro.com が KMS で自動で行う。KMS が使えないときの非常用に `bin/php scripts/issue-license.php <購入者のメール> [発行ID] [前の発行ID] [--kid=bk1]`（予備の秘密鍵は `~/.kantanpro/dt-license/backup-bk1-private.pem`、権限 600、リポジトリには置かない）
- 発行の台帳: 発行するたびに `~/.kantanpro/dt-license/issued.csv`（発行日時・メール・発行ID・差し替え前の発行ID・引き継ぎの合言葉）に 1 行足す
- 引き継ぎの合言葉: 発行のときに 8 文字（`XXXX-XXXX`、読み間違えやすい 0/O/1/I/L/U は使わない）を作り、キーの中（`"c"`）に入れて署名する。書き換え・作り出しはできない。ライセンス画面に発行ID・発行日と合わせた 1 行で出す（コピーボタンつき）
- **メールアドレス・名義の変更**（本人のアドレス変更も、親から子などの事業の引き継ぎも同じ手続き）:
  1. キーはメールアドレスが変わってもそのまま使える（アプリはキーの中のメールを何とも照らし合わせない）。差し替えは記録を新しいアドレス・新しい名義に合わせるため
  2. 本人の確かめ方:
     - A. 登録のメールアドレスから届いた依頼、または kantanpro.com のマイアカウントにログインして出た依頼 → そのまま発行し直す（元の購入者が「子のアドレスに変えたい」と送ってきた場合もここ）
     - C. 「引き継ぎの合言葉」の 1 行（ライセンス画面に出る「注文 ◯ ・ 日付 発行 ・ 合言葉 XXXX-XXXX」）を添えた依頼 → 台帳の発行ID と合言葉が合えばそのまま発行し直す（親のパソコンのアプリや紙の控えから、子が送ってくる場合もここ）
     - B. 登録のメールアドレスも合言葉も使えない（引退・死亡・会社のアドレス廃止など） → 注文番号・購入日・請求先の名前を確かめ（引き継ぎなら元の購入者との関係も）、前のアドレスに「変更の依頼がありました」と知らせ、7 日待って異議がなければ発行し直す
  3. 台帳か注文から前の発行ID を探し、新しいメールで発行し直す: `bin/php scripts/issue-license.php <新しいメール> <前の発行ID>-r1 <前の発行ID>`
  4. 新しいキーをメールで送る。受け取った人は「ライセンス」画面の「キーを入れ替える」（新しく入れるパソコンなら普通の入力欄）に貼り付ける
  5. 前のキーは取り消せない（ネットにつながずに確かめる方式のため）。引き継ぎのときは、元の購入者は前のキーを使わないことを規約で約束してもらう
  6. kantanpro.com の WooCommerce 側も、顧客のアカウントと注文の請求先メールを新しいアドレスに直す
  - なりすまされても、元の購入者のキーは使えたまま・業務データはパソコンの中なので、被害は「キーを 1 本ただで渡す」ことに限られる。手続きを重くしすぎない
  - 将来 kantanpro.com でキーを自動発行するようになったら、マイアカウントに「今のメールアドレスでキーを再発行」を付ける（ログインが本人確認の代わりになる）
- 入力画面: 「KantanProEX 設定」→「ライセンス」（メニューのファイル→「ライセンス…」からも）。試用中・無料版はヘッダーに小さく「試用期間 あと◯日」「無料版」を出す。有料版（paid）になった後も「キーを入れ替える」から新しいキーに差し替えられる
- 無料版の制限の入れ方: DT の独自機能は各ファイルの入口で判定（設定値は消さない）。EX 本体のタブ（レポート・定期請求リスト）は、本体は変えず `do_shortcode_tag` で該当パネルを案内画面に差し替え、契約・レポートの ajax は `admin_init` で断る。複製は、ajax（`ktp_duplicate_order` / `ktp_duplicate_service`）を `admin_init` で 403 にし、POST の `query_post=duplicate|duplication`（サービス・協力会社の本体処理）は `init` で `$_POST` を空にして断り、受注書とサービスの複製ボタンは `wp_footer` の capture 段階のクリック処理で案内（モーダル）に差し替える。受注明細の行追加で前の行をコピーする動きは複製機能ではないので対象外。顧客タブには本体に複製のボタンも処理も無い（協力会社は処理だけあり画面にボタンは無い）。LAN 共有・音声操作は PHP 側で断るほか、Electron も窓口を立てない（`lib/license.js`）
- 宣伝の 1 行: 書類（ajax `ktp_get_order_preview` の応答の HTML）の下端と、メール署名に入れる。印刷/PDF の経路は変えていない。一括請求書・顧客別の請求書印刷には入れていない
- テスト用の環境変数: `KTP_TEST_LICENSE_NOW_OFFSET_DAYS=31`（判定の時刻を日数だけずらす。`KTP_TEST_USER_DATA` を付けたテスト起動のときだけ効く）。確認の例: 別のデータ場所で `KTP_TEST_USER_DATA=work/xxx env -u ELECTRON_RUN_AS_NODE electron .`（新規なら trial、31 日ずらすと free）。既存インストールの確認は、`data/fresh-install.json`・`license.json` と DB の option `ktp_dt_license` が無い状態で起動する（legacy になる）

## サイトからの受注（ホームページの問い合わせ・注文）

デスクトップ版には外から受け付ける口がないので、ホームページ（WordPress）に受付プラグイン `bridge/ktp-dt-bridge` を入れてもらい、
そこに預けられた Contact Form 7 のお問い合わせと WooCommerce の注文を、アプリが取りに行きます（PC → サイトの一方向。PC は公開しない）。

- アプリ側: 「KantanProEX 設定」→「サイトからの受注」（`site/mu-plugins/ktp-desktop/intake.php`）。既定はオフ。サイトのアドレス（https のみ）と接続キーを入れてオンにすると、wp-cron で 15 分ごとに取り込む。受付プラグインの ZIP もこの画面から保存できる
- サイト側: 「設定」→「KantanProEX 受付」に接続キーが出る。REST `?rest_route=/ktp-dt-bridge/v1/inbox`（取得）・`/ack`（受け取り済み）を `X-KTP-Bridge-Key` で守る。渡し終えた分は 7 日で消す
- 受注の作り方は EX 本体と同じ（CF7: `KTPWP_Contact_Form::import_submission()`、WooCommerce: `KTPWP_WooCommerce_Integration::import_order_payload()`）。受付プラグインは WooCommerce の注文を本体の `order_payload()` と同じ形にして預ける。形を変えるときは両方そろえる
- 取り込んだ預かり分の uuid を覚えておき、「受け取った」を返せずに再び届いても二重にしない
- **公開商品**: 「サイト公開」にしたサービスを、アプリがサイトへ送る（`POST /catalog`・`/catalog-image`。内容が変わったときだけ。画像は 900px 以内の JPEG に縮めて、足りないものだけ）。サイト側は `bridge/ktp-dt-bridge/catalog.php` がショートコード `[ktp_dt_products]`（EX と同じ `[ktpwp_public_products]` も、EX が無いサイトでは使える）でカード表示し、申し込みフォーム（admin-ajax）の入力を受付箱（`kind=public_order`）に預ける。アプリは `KTPWP_Public_Product_Order::submit_order()` で受注にする（数量・税率・明細は EX と同じ）。完売・保留はサイトに送り直すので、次の同期（15 分以内）でボタンが消える
- **商品ごとのページとフィード**（受付プラグイン 1.5.0〜、`bridge/ktp-dt-bridge/item.php`）: サイトの `/item/{ID}/`（パーマリンクが基本設定なら `/?ktp_item={ID}`）に商品 1 件のページを出す。テーマの header/footer の中に出し（ブロックテーマはテンプレートパーツ）、OGP・canonical・JSON-LD（Product）を付ける。重複しないよう Cocoon・Yoast・Rank Math・AIOSEO・Jetpack の OGP 等はそのページだけ止める。一覧カードの商品名・画像はこのページへのリンク（`link=no` で外せる。Cocoon の目次が見出しの中身を書き換えるので、リンクは h3 の外に置く）。
  `/?ktp_feed=google` は Google Merchant Center 用の RSS（画像あり・0 円でない・定期契約でない商品だけ。受付プラグインの「値段は税抜」設定で税込にする）。商品ページは `wp-sitemap.xml` にも入る。取得応答の `item_url`（%d が ID）・`feed_url` をアプリが覚え、「サイトからの受注」画面に商品ごとの URL とコピーボタンを出す
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

必要なもの: macOS、Node.js、KantanProEX プラグインのソース。Mac 版はユニバーサル（Intel と Apple シリコンの両方で動く1本）で、簡易署名（ad-hoc）を付けて作る（scripts/after-pack.js）。

```bash
npm install
npm run prepare:php      # 静的ビルドの PHP（Intel 用と Apple シリコン用をまとめたもの）を bin/ に取得
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
