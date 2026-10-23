<?php
/**
 * デスクトップ版の画面テンプレート（テーマを使わない）。
 *
 * KantanProEX の見た目は、WP 版で使われている Cocoon の基本スタイルを前提にしている
 * （本文 18px、表のセル 0.8em、全要素の余白リセットなど）。テーマを通さない代わりに、
 * その前提の部分だけをここに持つ。ブロックテーマ（Twenty Twenty-Five）の全体スタイルは
 * ktp-desktop.php で読み込まないようにしている（入ると本文が 22px になる）。
 * 背景は常に白（ダークモードにしない）。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1<?php echo function_exists( 'ktp_dt_is_lan' ) && ktp_dt_is_lan() ? ', viewport-fit=cover' : ''; ?>">
<meta name="color-scheme" content="light">
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

	/* デスクトップ版では使わないもの（表示しないだけで、データはそのまま） */
	/* 規約画面の「ホームへ」: トップページがこの画面そのものなので、押しても同じ画面に戻るだけ */
	.ktpwp-terms-modal .ktpwp-home-link { display: none !important; }
	/* サービスの公開商品の欄: 公開の販売ページ（外部サイト）が前提 */
	.ktpwp-service-public-field,
	.ktpwp-service-field-block--stock,
	.ktpwp-service-field-block--public-quantity,
	.ktpwp-service-field-block--instant-purchase,
	.ktpwp-service-field-block--public-html { display: none !important; }
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
	.ktpwp-update-balloon { display: none !important; }
	/* 画面上部の小さなアイコンボタン（設定・再読み込み・アバター）は、44px に伸ばさず元の大きさのまま */
	.ktp-desktop-main .ktp-header-settings-toggle,
	.ktp-desktop-main .ktp-header-plugin-reload,
	.ktp-desktop-main .ktp-avatar-trigger { min-height: 0; }
	.ktp-desktop-main input[type=checkbox],
	.ktp-desktop-main input[type=radio] { width: 22px; height: 22px; }
	.ktp-desktop-main button, .ktp-desktop-main a, .ktp-desktop-main label, .ktp-desktop-main select { touch-action: manipulation; }
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
</body>
</html>
