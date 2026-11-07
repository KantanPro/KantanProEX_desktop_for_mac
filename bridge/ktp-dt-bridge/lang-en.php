<?php
/**
 * 英語の訳（日本語の原文 => 英語）。サイトの言語（管理画面はログインしている人の言語）が日本語以外のときだけ読む。
 *
 * 訳は完全一致で引く（ktp_dt_bridge_t()）。原文を直したら、ここのキーも同じ文字に直すこと。
 * 動的な文言は sprintf の型で持つ（例: '（税%s%%）'。ktp_dt_bridge_tf() が値を差し込む）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// ---- 一覧・フィルタ ----
	'公開中の商品がありません。'   => 'There are no products available right now.',
	'すべて'                       => 'All',
	'該当する商品がありません。'   => 'No matching products.',
	'（初回 %s）'                  => ' (first payment %s)',
	'（税%s%%）'                   => '(Tax %s%%)',
	'← 商品一覧へ'                 => '← Back to products',
	'（税込 %s）'                  => '(incl. tax %s)',

	// ---- ボタン・状態（DT から日本語のまま届くラベルも含む） ----
	'購入する'                     => 'Buy now',
	'申し込む・問い合わせる'       => 'Order / Inquire',
	'受付停止中'                   => 'Not accepting orders',
	'完売御礼！'                   => 'Sold out!',
	'保留中'                       => 'On hold',
	'都度請求'                     => 'One-time',
	'毎月'                         => 'monthly',
	'2ヶ月に1回'                   => 'every 2 months',
	'3ヶ月に1回'                   => 'every 3 months',
	'6ヶ月に1回'                   => 'every 6 months',
	'毎年'                         => 'yearly',

	// ---- 申し込みフォーム ----
	'お申し込み・お問い合わせ'     => 'Order / Inquiry',
	'確認用（空のままにしてください）' => 'Leave this field empty',
	'会社名（任意）'               => 'Company (optional)',
	'お名前'                       => 'Name',
	'必須'                         => 'Required',
	'メールアドレス'               => 'Email',
	'電話番号（任意）'             => 'Phone (optional)',
	'数量'                         => 'Quantity',
	'ご要望・メッセージ（任意）'   => 'Message (optional)',
	'閉じる'                       => 'Close',
	'送信する'                     => 'Send',

	// ---- フォームの JS ----
	'お名前とメールアドレスを入力してください。' => 'Please enter your name and email address.',
	'送信しています…'              => 'Sending…',
	'受け付けました。'             => 'Received.',
	'送信できませんでした。'       => 'Could not send.',
	'送信できませんでした。時間をおいてもう一度お試しください。' => 'Could not send. Please try again in a little while.',

	// ---- 申し込みの返答（admin-ajax） ----
	'現在、お申し込みを受け付けておりません。' => 'We are not accepting orders at the moment.',
	'送信が続いたため、しばらくしてからもう一度お試しください。' => 'Too many submissions. Please try again in a little while.',
	'送信内容を確認できませんでした。時間をおいて再度お試しください。' => 'We could not verify your submission. Please try again in a little while.',
	'指定された商品は公開されていないか、存在しません。' => 'The selected product is not available or does not exist.',
	'現在この商品はお申し込みを受け付けておりません。' => 'This product is not accepting orders at the moment.',
	'お名前を入力してください。'   => 'Please enter your name.',
	'有効なメールアドレスを入力してください。' => 'Please enter a valid email address.',
	'HTMLタグを含む内容は送信できません。' => 'Content containing HTML tags cannot be sent.',
	'URLの記載が多いため送信できません。' => 'Too many URLs. The message cannot be sent.',
	'現在、設定されている言語以外でのお問い合わせは受け付けておりません。' => 'Inquiries in languages other than the configured one are not accepted at the moment.',
	'受け付けに失敗しました。時間をおいてもう一度お試しください。' => 'We could not receive your submission. Please try again in a little while.',
	'お申し込みを受け付けました。担当者よりご連絡いたします。' => 'Thank you. We have received your order and will be in touch.',

	// ---- 受付の口（REST。KantanProEX（DT）へ返すエラー） ----
	'商品の形式が正しくありません。' => 'The product data is not in the expected format.',
	'画像の形式が正しくありません。' => 'The image data is not in the expected format.',
	'画像は JPEG（4000px 以下）だけ受け付けます。' => 'Only JPEG images (4000px or smaller) are accepted.',
	'商品が登録されていません。'   => 'The product is not registered.',
	'画像を保存できませんでした。' => 'Could not save the image.',
	'接続キーの間違いが続いたため、しばらく受け付けません。' => 'Too many wrong connection keys. Requests are blocked for a while.',
	'接続キーが違います。'         => 'The connection key is wrong.',

	// ---- 設定画面（設定 → KantanProEX 受付） ----
	'KantanProEX（DT）サイト受付'  => 'KantanProEX (DT) Site Intake',
	'KantanProEX 受付'             => 'KantanProEX Intake',
	'設定'                         => 'Settings',
	'権限がありません。'           => 'You do not have permission to do this.',
	'保存しました。'               => 'Saved.',
	'接続キーを作り直しました。KantanProEX（DT）の「サイトからの受注」に、新しいキーを入れ直してください。' => 'The connection key was regenerated. Enter the new key under "Orders from your website" in KantanProEX (DT).',
	'このサイトは https ではありません。接続キーやお客様の情報が暗号化されずに流れるため、KantanProEX（DT）からは接続できません（https にしてください）。' => 'This site is not using https. The connection key and customer data would travel unencrypted, so KantanProEX (DT) cannot connect (please switch to https).',
	'このサイトの Contact Form 7 のお問い合わせ・WooCommerce の注文・商品の申し込みを、ここで預かっておきます。パソコンの KantanProEX（DT）が 15 分ごと（と起動したとき）に取りに来て、受注として取り込みます。パソコンを閉じている間の分も、次に開いたときに届きます。' => 'Contact Form 7 inquiries, WooCommerce orders and product orders from this site are held here. KantanProEX (DT) on your computer collects them every 15 minutes (and at startup) and imports them as orders. Anything received while your computer is off arrives the next time you open it.',
	'KantanProEX（DT）に入れる内容' => 'What to enter in KantanProEX (DT)',
	'KantanProEX（DT）の「KantanProEX 設定」→「サイトからの受注」に、次の 2 つを入れます。' => 'Enter the following two values in KantanProEX (DT) under "KantanProEX Settings" → "Orders from your website".',
	'サイトのアドレス'             => 'Site address',
	'接続キー'                     => 'Connection key',
	'このキーを知っている人は、預かっているお問い合わせ・注文を読めます。人に教えないでください。漏れたときは下の「接続キーを作り直す」を押します。' => 'Anyone who knows this key can read the inquiries and orders held here. Do not share it. If it leaks, press "Regenerate connection key" below.',
	'受け付けるもの'               => 'What to accept',
	'お問い合わせを預かる'         => 'Hold inquiries',
	'Contact Form 7 は有効になっていません。' => 'Contact Form 7 is not active.',
	'日本語以外のお問い合わせは受け付けない（スパム対策）' => 'Reject inquiries not written in Japanese (spam protection)',
	'公開商品'                     => 'Published products',
	'商品の申し込みを預かる'       => 'Hold product orders',
	'KantanProEX（DT）で「サイト公開」にした商品を、固定ページなどに <code>[ktp_dt_products]</code> と書いて表示します。申し込みは KantanProEX（DT）が取りに来て受注にします。' => 'Products you mark as "Published" in KantanProEX (DT) are shown by writing <code>[ktp_dt_products]</code> on a page. KantanProEX (DT) collects the orders and turns them into orders in the app.',
	'KantanProEX の値段は税抜（商品ページに税込を添え、Google ショッピング用フィードは税込で出す）' => 'Prices in KantanProEX exclude tax (Japan, yen only: tax-inclusive prices are added to product pages and the Google Shopping feed)',
	'注文を預かる'                 => 'Hold orders',
	'WooCommerce は有効になっていません。' => 'WooCommerce is not active.',
	'保存'                         => 'Save',
	'状態'                         => 'Status',
	'KantanProEX（DT）から届いている公開商品:' => 'Published products received from KantanProEX (DT):',
	'%s 件'                        => '%s',
	'（最後に更新された時刻: %s）' => '(last updated: %s)',
	'まだ渡していないもの:'        => 'Not handed over yet:',
	'KantanProEX（DT）が最後に取りに来た時刻:' => 'Last time KantanProEX (DT) collected:',
	'まだありません'               => 'Never',
	'渡し終えたものは %d 日後に、このサイトから消します。' => 'Items already handed over are deleted from this site after %d days.',
	'商品ページの URL'             => 'Product page URLs',
	'商品ごとのページです。SNS・ブログ・LINE・メールに貼ると、商品名と画像のカードで表示されます。一覧（<code>[ktp_dt_products]</code>）の商品名と画像からも開けます。' => 'A page for each product. Pasted into social media, blogs, chat or email, it shows as a card with the product name and image. You can also open it from the product name and image in the list (<code>[ktp_dt_products]</code>).',
	'商品'                         => 'Product',
	'開く'                         => 'Open',
	'Google ショッピング用フィード' => 'Google Shopping feed',
	'フィードの URL'               => 'Feed URL',
	'Google Merchant Center の「商品 → フィードを追加 → スケジュールされた取得」にこの URL を登録すると、商品が Google ショッピング（無料掲載）に載ります。値段・受付状況はこの URL から毎回読み直されます。<br>載るのは、画像があり、値段が 0 円でなく、定期契約（毎月など）でない商品です。送料・返品のルールは Merchant Center 側で設定します。サービス（作業・役務）は Google ショッピングに載せられない場合があります。' => 'Register this URL in Google Merchant Center under "Products → Add feed → Scheduled fetch" to list your products on Google Shopping (free listings). Prices and availability are re-read from this URL every time.<br>Products are listed only if they have an image, a price above zero and are not subscriptions (monthly and so on). Set shipping and return rules in Merchant Center. Services (work done for a client) may not be accepted on Google Shopping.',
	'商品ページは WordPress のサイトマップ（<code>wp-sitemap.xml</code>）にも入ります（SEO プラグインでサイトマップを切り替えている場合は入りません）。' => 'Product pages are also added to the WordPress sitemap (<code>wp-sitemap.xml</code>), unless an SEO plugin has replaced the sitemap.',
	'接続キーを作り直すと、今のキーでは接続できなくなります。続けますか？' => 'Regenerating the connection key will stop the current key from working. Continue?',
	'接続キーを作り直す'           => 'Regenerate connection key',
);
