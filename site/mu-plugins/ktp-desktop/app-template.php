<?php
/**
 * デスクトップ版の画面テンプレート（テーマを使わない）。
 *
 * KantanProEX の見た目は、WP 版で使われている Cocoon の基本スタイルを前提にしている
 * （本文 18px、表のセル 0.8em、全要素の余白リセットなど）。テーマを通さない代わりに、
 * その前提の部分だけをここに持つ。ブロックテーマ（Twenty Twenty-Five）の全体スタイルは
 * ktp-desktop.php で読み込まないようにしている（入ると本文が 22px になる）。
 * 背景は白。ダークモードはヘッダーのボタンで切り替える（ktp-desktop.php の ktp_desktop_dark_css）。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?><?php echo ktp_desktop_is_dark() ? ' class="ktp-dark"' : ''; ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1<?php echo function_exists( 'ktp_dt_is_lan' ) && ktp_dt_is_lan() ? ', viewport-fit=cover' : ''; ?>">
<meta name="color-scheme" content="light">
<script>
// ダークモードでは Mac の部品を暗くするため、アプリ全体を Mac のダーク表示にする（main.js）。画面は反転で暗くするので、
// 本体のスクリプト（レポートのグラフの文字色など）には、Mac のダーク設定を「ライト」と答える（ダーク用の色と反転が重なると読めなくなる）
(function () {
	var mm = window.matchMedia;
	if (!mm) { return; }
	window.matchMedia = function (q) {
		var s = String(q);
		if (/prefers-color-scheme\s*:\s*dark/i.test(s)) { return mm.call(window, 'not all'); }
		if (/prefers-color-scheme\s*:\s*light/i.test(s)) { return mm.call(window, 'all'); }
		return mm.call(window, q);
	};
})();
</script>
<link rel="icon" href="data:,"><?php // アプリのウィンドウなのでファビコンは不要（/favicon.ico の 404 を防ぐ） ?>
<?php if ( function_exists( 'ktp_dt_is_lan' ) && ktp_dt_is_lan() ) : // iPad などのホーム画面に追加したとき用 ?>
<link rel="apple-touch-icon" href="<?php echo esc_url( WPMU_PLUGIN_URL . '/ktp-desktop/apple-touch-icon.png' ); ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="KantanProEX">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="format-detection" content="telephone=no">
<?php // iPad・iPhone では、隠し iframe の印刷が白紙になるので PDF にして共有シートへ出す（ios-print.js。iOS 以外では何もしない） ?>
<script>window.ktpIosPrint=<?php echo wp_json_encode( array( 'html2canvas' => content_url( 'plugins/KantanProEX/js/lib/html2canvas.min.js' ), 'jspdf' => content_url( 'plugins/KantanProEX/js/lib/jspdf.umd.min.js' ) ) ); ?>;</script>
<script src="<?php echo esc_url( WPMU_PLUGIN_URL . '/ktp-desktop/ios-print.js?v=' . (string) @filemtime( WPMU_PLUGIN_DIR . '/ktp-desktop/ios-print.js' ) ); ?>"></script>
<?php endif; ?>
<?php wp_head(); ?>
<style id="ktp-desktop-base">
	/* Cocoon の基本表示に合わせる（cocoon-master/style.css の「基本表示」） */
	* { padding: 0; margin: 0; box-sizing: border-box; word-wrap: break-word; overflow-wrap: break-word; }
	html { margin-top: 0 !important; background: #fff; }
	body {
		font-family: "Hiragino Kaku Gothic ProN", "Hiragino Sans", Meiryo, sans-serif;
		font-size: 18px;
		line-height: 1.8;
		color: #333;
		background: #fff;
	}
	a { color: #1967d2; }
	img { max-width: 100%; height: auto; }
	input, select, textarea, button { font-family: inherit; }
	table { margin-bottom: 20px; max-width: 100%; width: 100%; border-collapse: collapse; border-spacing: 0; }
	table th, table td { position: relative; padding: 0.5em; font-size: 0.8em; }
	table th { background-color: #f5f6f7; }
	table tr:nth-of-type(2n+1) { background-color: #fafbfc; }
	table :where(th, td) { border: 1px solid #eee; }
	#wpadminbar { display: none !important; }
	.ktp-dt-app-version { display: inline-block; margin-left: 0.6em; font-size: 0.5em; font-weight: 400; opacity: 0.75; vertical-align: middle; white-space: nowrap; }
	.ktp-desktop-main { max-width: none; margin: 0; padding: 12px 16px 24px; }
	/* 結果報告の通知（緑）がヘッダー（z-index 100000〜100002）の後ろに隠れるので、常に最前面へ */
	.success-notification,
	.ktp-floating-notification { z-index: 2147483647 !important; }
	/* 本体のポップアップは z-index 9999〜10001 で、ヘッダー（100000〜100002）だけ暗くならず手前に残るので、ヘッダーより上へ */
	.ktp-delete-popup-overlay,
	#ktp-purchase-status-choice-dialog,
	#ktp-purchase-order-email-popup,
	#ktp-service-selector-popup,
	#ktp-supplier-selector-modal { z-index: 100003 !important; }
	/* ダークモード（反転）と、その切り替えボタン */
	<?php echo ktp_desktop_dark_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	.ktp_header .navigation-links a.ktp-dt-theme-toggle { cursor: pointer; }

	/* デスクトップ版では使わないもの（表示しないだけで、データはそのまま） */
	/* 規約画面の「ホームへ」: トップページがこの画面そのものなので、押しても同じ画面に戻るだけ */
	.ktpwp-terms-modal .ktpwp-home-link { display: none !important; }
	/* 「在庫を管理する」のラベルは本体にスタイルが無く、標準の大きさ（周りより大きい）になる。「サイトに公開」と同じ大きさ・色にそろえる */
	.ktp-desktop-main label.ktpwp-service-stock-managed { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; color: #444; white-space: nowrap; }
	.ktp-desktop-main label.ktpwp-service-stock-managed input[type="checkbox"] { width: auto; margin: 0; flex: 0 0 auto; }
	/* 「公開フォームの数量」のラジオ・「即時購入」のチェック・「公開用 HTML」の入力欄: 本体にスタイルが無く崩れるので、ほかの欄と同じ大きさ・並びにそろえる */
	.ktp-desktop-main .ktpwp-service-field-block__control--radio { display: flex; flex-direction: column; align-items: flex-start; gap: 6px; }
	.ktp-desktop-main label.ktpwp-service-radio-option,
	.ktp-desktop-main .ktpwp-service-field-block__control label.ktpwp-service-public-field { display: inline-flex; align-items: center; gap: 6px; width: auto; margin: 0; font-size: 14px; color: #444; text-align: left; white-space: nowrap; }
	.ktp-desktop-main label.ktpwp-service-radio-option input,
	.ktp-desktop-main .ktpwp-service-field-block__control label.ktpwp-service-public-field input { width: auto; margin: 0; flex: 0 0 auto; }
	.ktp-desktop-main #public_html { width: 100%; box-sizing: border-box; }
	/* サービスの在庫数・契約・定期請求項目・初回費用は、WP 版に合わせてラベルを入力欄の上に左揃えで積む（既定は左 25% にラベルを置く並び） */
	.ktp-desktop-main .ktpwp-service-field-block,
	.ktp-desktop-main .ktpwp-service-field-block--recurring .ktpwp-service-field-block__label,
	.ktp-desktop-main .ktpwp-service-field-block--initial-fees .ktpwp-service-field-block__label { display: block; }
	.ktp-desktop-main .ktpwp-service-field-block__label,
	.ktp-desktop-main .ktpwp-service-field-block--recurring .ktpwp-service-field-block__label-text,
	.ktp-desktop-main .ktpwp-service-field-block--recurring .ktpwp-service-field-block__hint,
	.ktp-desktop-main .ktpwp-service-field-block--initial-fees .ktpwp-service-field-block__label-text,
	.ktp-desktop-main .ktpwp-service-field-block--initial-fees .ktpwp-service-field-block__hint { text-align: left; padding-top: 0; }
	.ktp-desktop-main .ktpwp-service-field-block__label-text.ktpwp-service-field-block__section-title { margin-top: 0; }
	.ktp-desktop-main .ktpwp-service-field-block__hint { margin-bottom: 6px; }
</style>
<?php if ( function_exists( 'ktp_dt_is_lan' ) && ktp_dt_is_lan() ) : // LAN（iPad など）から開いたときだけ: タッチ操作向けの調整 ?>
<style id="ktp-desktop-touch">
	html { -webkit-text-size-adjust: 100%; }
	.ktp-desktop-main { padding-left: max(16px, env(safe-area-inset-left)); padding-right: max(16px, env(safe-area-inset-right)); padding-bottom: max(24px, env(safe-area-inset-bottom)); }
	/* 16px 未満の入力欄は、iPad の Safari で入力時に画面が拡大されてしまう */
	.ktp-desktop-main input:not([type=checkbox]):not([type=radio]):not([type=range]):not([type=file]),
	.ktp-desktop-main select,
	.ktp-desktop-main textarea { font-size: 16px !important; }
	/* 押せる範囲を 44px 以上に */
	.ktp-desktop-main button,
	.ktp-desktop-main input[type=button],
	.ktp-desktop-main input[type=submit],
	.ktp-desktop-main a.button { min-height: 44px; }
	/* LAN（iPad・iPhone など）からは使えないヘッダーのボタンは出さない（窓口が拒否する: 設定の画面・アプリの終了・更新の確認） */
	.ktp_header a[href*="ktp_desktop_quit"],
	.ktp_header .ktp-header-settings-menu,
	.ktp_header .ktpwp-header-update-link,
	.ktp_header .navigation-links a.ktpwp-header-update-link,
	.ktp_header .navigation-links a#ktpwp-header-update-check,
	.ktp_header .navigation-links a[title="更新"],
	.ktpwp-update-balloon { display: none !important; }
	/* LAN からも使えるログアウトとヘルプは、アイコン + 文字の横長にして押しやすくする */
	.ktp_header .navigation-links a[title="ログアウト"],
	.ktp_header .navigation-links a[title="ヘルプ"] {
		width: auto !important; min-width: 0 !important; height: auto !important; min-height: 42px !important;
		padding: 4px 14px !important; gap: 6px !important; font-size: 14px !important; font-weight: 600 !important;
		white-space: nowrap; border-radius: 10px !important;
	}
	.ktp_header .navigation-links { width: auto !important; max-width: none !important; gap: 8px !important; flex-wrap: nowrap !important; top: 10px !important; }
	/* アバターも一緒に下げて、ヘルプとの間にすき間を作る（ヘッダーの下の余白を使う） */
	@media screen and (max-width: 480px) { .ktp_header .ktp-avatar-trigger { top: 15px !important; } }
	.ktp_header .navigation-links a[title="ログアウト"],
	.ktp_header .navigation-links a[title="ヘルプ"] { flex: 0 0 auto !important; overflow: visible !important; }
	/* iPhone の幅では、横長のボタンを置く場所をつくるため、いつも「KantanPro…」と途中で切れているシステム名は出さない（ロゴは残す） */
	@media screen and (max-width: 480px) { .ktp_header .logo-and-system-info .system-info { display: none !important; } }
	.ktp_header .navigation-links a[title="ログアウト"]::after { content: "ログアウト"; }
	/* ヘルプには文字（狭い画面では隠れている）があるので、それを出す */
	.ktp_header .navigation-links a[title="ヘルプ"] > span:not(.ktp-svg-icon) { display: inline !important; }
	/* 画面上部の小さなアイコンボタン（設定・再読み込み・アバター）は、44px に伸ばさず元の大きさのまま */
	.ktp-desktop-main .ktp-header-settings-toggle,
	.ktp-desktop-main .ktp-header-plugin-reload,
	.ktp-desktop-main .ktp-avatar-trigger { min-height: 0; }
	.ktp-desktop-main input[type=checkbox],
	.ktp-desktop-main input[type=radio] { width: 22px; height: 22px; }
	.ktp-desktop-main button, .ktp-desktop-main a, .ktp-desktop-main label, .ktp-desktop-main select { touch-action: manipulation; }
	/* サービスリスト（サービスタブの表だけ。顧客・協力会社などの一覧は同じクラスを使うので、--service で絞る）は項目が多く、幅に収めると文字が重なる。表の幅は確保して、外側の枠（overflow-x:auto）で横にスクロールさせる */
	.ktp-desktop-main .ktp-service-list-table.ktp-list-table--service { min-width: 720px; }
	/* サービス・協力会社などのタブの検索・ボタンの列は、折り返さず右にはみ出す（リスト印刷ボタンが切れる）ので、折り返す */
	.ktp-desktop-main [class*="-controller__bar"], .ktp-desktop-main [class*="-controller__tools"] { flex-wrap: wrap; row-gap: 8px; }
	/* 幅の広い表は、画面からはみ出さず横にスクロールできるように */
	.ktp-desktop-main .ktp-table-wrap, .ktp-desktop-main .table-responsive { overflow-x: auto; -webkit-overflow-scrolling: touch; }
</style>
<?php endif; ?>
</head>
<body <?php body_class( 'ktp-desktop' ); ?>>
<?php wp_body_open(); ?>
<main class="ktp-desktop-main">
<?php
// the_content を通さない（wpautop / wptexturize を避ける）。ショートコードだけ展開する
// デスクトップ版の独自の画面（ニュースレターなど）があればそれを、なければ通常のアプリ画面を出す
$ktp_desktop_custom_page = apply_filters( 'ktp_desktop_custom_page', null );
$ktp_desktop_html = null !== $ktp_desktop_custom_page ? $ktp_desktop_custom_page : do_shortcode( '[ktpwp_all_tab]' );
// ヘッダー・フッター・規約の版数は、中の KantanProEX 本体（1.4.x）ではなくデスクトップ版の版数にする（ktp-desktop.php）
$ktp_desktop_html = ktp_desktop_replace_version_text( $ktp_desktop_html );
echo $ktp_desktop_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</main>
<?php wp_footer(); ?>
<script id="ktp-desktop-instant-purchase-note">
// 「公開商品の即時購入（Stripe 決済）」の案内文。本体の文は「一般設定で Stripe 請求連携を有効にすると設定できます」だが、
// デスクトップ版は「サイトからの受注」の画面で Stripe の秘密鍵を入れる（stripe-purchase.php）。
// 本体は WP 版の Stripe 設定を見てチェックを無効にしているので、秘密鍵が入っていればここで有効にする。
// また、チェックを外すと値が送られず前の値のまま残る（本体の保存処理）ので、外したときの 0 を先に置く
(function(){
	var on = <?php echo wp_json_encode( function_exists( 'ktp_dt_stripe_enabled' ) && ktp_dt_stripe_enabled() ); ?>;
	var note = on
		? 'チェックすると、ホームページのボタンが「購入する」になり、Stripe の決済ページでそのままカード払いできます。入金されると受注（入金済み）で入ります。'
		: 'ホームページの商品は「申し込む」ボタンで受け付けます（受注が受付中で入り、あとから請求します）。その場でカード払いにするには、KantanProEX 設定 →「サイトからの受注」で Stripe の秘密鍵を入れてください。';
	function go(){
		document.querySelectorAll('.ktpwp-service-field-block--instant-purchase').forEach(function(block){
			var h = block.querySelector('.ktpwp-service-field-block__hint');
			if (h && h.textContent !== note) { h.textContent = note; }
			var cb = block.querySelector('input[type=checkbox][name=public_instant_purchase]');
			if (!on || !cb) { return; }
			if (cb.disabled) { cb.disabled = false; }
			if (!block.querySelector('input[type=hidden][name=public_instant_purchase]')) {
				var zero = document.createElement('input');
				zero.type = 'hidden'; zero.name = 'public_instant_purchase'; zero.value = '0';
				cb.parentNode.insertBefore(zero, cb);
			}
		});
	}
	go();
	new MutationObserver(go).observe(document.body, { childList: true, subtree: true });
})();
</script>
<script id="ktp-desktop-quit-button">
// ヘッダーのログアウトボタン（ログアウト URL は ktp-desktop.php で終了の合図に差し替え済み）を「アプリを終了」の見た目にする
(function () {
	var POWER = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 3h-2v10h2V3zm4.83 2.17-1.42 1.42A6.92 6.92 0 0 1 19 12c0 3.87-3.13 7-7 7s-7-3.13-7-7c0-2.08.91-3.95 2.58-5.42L6.17 5.17A8.932 8.932 0 0 0 3 12a9 9 0 0 0 18 0c0-2.74-1.23-5.18-3.17-6.83z"/></svg>';
	document.querySelectorAll('a[href*="ktp_desktop_quit"]').forEach(function (a) {
		a.title = 'アプリを終了';
		a.setAttribute('aria-label', 'アプリを終了');
		a.innerHTML = POWER;
	});
})();
</script>
<script id="ktp-desktop-theme-toggle">
// ヘッダーの「ダークモード / ライトモード」切り替えボタン（ヘルプの前に置く）。
// 選んだ表示はクッキーに残し、次の画面からはサーバー側で <html class="ktp-dark"> を付ける（ktp-desktop.php）
(function () {
	var MOON = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.389 5.389 0 0 1-4.4 2.26 5.403 5.403 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z"/></svg>';
	var SUN = '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10zM2 13h2a1 1 0 0 0 0-2H2a1 1 0 0 0 0 2zm18 0h2a1 1 0 0 0 0-2h-2a1 1 0 0 0 0 2zM11 2v2a1 1 0 0 0 2 0V2a1 1 0 0 0-2 0zm0 18v2a1 1 0 0 0 2 0v-2a1 1 0 0 0-2 0zM5.99 4.58a.996.996 0 0 0-1.41 1.41l1.06 1.06c.39.39 1.03.39 1.41 0s.39-1.03 0-1.41L5.99 4.58zm12.37 12.37a.996.996 0 0 0-1.41 1.41l1.06 1.06c.39.39 1.03.39 1.41 0a.996.996 0 0 0 0-1.41l-1.06-1.06zm1.06-10.96a.996.996 0 0 0-1.41-1.41l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06zM7.05 18.36a.996.996 0 0 0-1.41-1.41l-1.06 1.06c-.39.39-.39 1.03 0 1.41s1.03.39 1.41 0l1.06-1.06z"/></svg>';
	var root = document.documentElement;
	// ポップアップの暗い幕（半透明の黒）は、反転すると白っぽい幕になり画面が明るく見える。ダークモードの間だけ、
	// 画面全体を覆う半透明の黒い幕を半透明の白に差し替える（反転後は暗い幕になる）。幕は本体の JS がインライン style で作るので、出たときに拾う
	var DIM_CANDIDATES = 'body > *, [class*="overlay"], [class*="modal"], [id*="popup"], [id*="modal"], [id*="dialog"]';
	var dimQueued = false;
	function dimAlpha(el) {
		var cs = getComputedStyle(el);
		if (cs.position !== 'fixed') { return 0; }
		var m = cs.backgroundColor.match(/^rgba\(0, 0, 0, ([\d.]+)\)$/);
		var alpha = m ? Number(m[1]) : 0;
		if (!(alpha > 0 && alpha < 1)) { return 0; }
		var r = el.getBoundingClientRect();
		return r.width >= window.innerWidth * 0.9 && r.height >= window.innerHeight * 0.9 ? alpha : 0;
	}
	function fixDims() {
		dimQueued = false;
		if (!root.classList.contains('ktp-dark')) { return; }
		document.querySelectorAll(DIM_CANDIDATES).forEach(function (el) {
			if (el.hasAttribute('data-ktp-dt-dim')) { return; }
			var alpha = dimAlpha(el);
			if (alpha) {
				el.setAttribute('data-ktp-dt-dim', '');
				el.style.setProperty('background-color', 'rgba(255, 255, 255, ' + Math.min(0.85, alpha + 0.1) + ')', 'important');
			}
		});
	}
	function queueDims() {
		if (!dimQueued) { dimQueued = true; requestAnimationFrame(fixDims); }
	}
	var dimObserver = new MutationObserver(queueDims);
	function watchDims() {
		if (root.classList.contains('ktp-dark')) {
			dimObserver.observe(document.body, { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class', 'hidden'] });
			queueDims();
		} else {
			dimObserver.disconnect();
			document.querySelectorAll('[data-ktp-dt-dim]').forEach(function (el) {
				el.removeAttribute('data-ktp-dt-dim');
				el.style.removeProperty('background-color');
			});
		}
	}
	function paint(a) {
		var dark = root.classList.contains('ktp-dark');
		var label = dark ? 'ライトモードにする' : 'ダークモードにする';
		a.title = label;
		a.setAttribute('aria-label', label);
		a.innerHTML = dark ? SUN : MOON;
	}
	document.querySelectorAll('.ktp_header .navigation-links').forEach(function (nav) {
		var a = document.createElement('a');
		a.href = '#';
		a.className = 'ktp-dt-theme-toggle';
		a.setAttribute('role', 'button');
		a.style.cssText = 'display: inline-flex; align-items: center; gap: 4px; color: #0073aa; text-decoration: none;';
		paint(a);
		a.addEventListener('click', function (e) {
			e.preventDefault();
			var dark = root.classList.toggle('ktp-dark');
			document.cookie = 'ktp_dt_theme=' + (dark ? 'dark' : 'light') + '; path=/; max-age=315360000; SameSite=Lax';
			document.querySelectorAll('.ktp-dt-theme-toggle').forEach(paint);
			watchDims();
		});
		var help = nav.querySelector('a[title="ヘルプ"]');
		nav.insertBefore(a, help || null);
		nav.insertBefore(document.createTextNode(' '), help || null);
	});
	watchDims();
})();
</script>
</body>
</html>
