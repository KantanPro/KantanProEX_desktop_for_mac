<?php
/**
 * デスクトップ版の画面テンプレート（テーマを使わない）。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="data:,"><?php // アプリのウィンドウなのでファビコンは不要（/favicon.ico の 404 を防ぐ） ?>
<?php wp_head(); ?>
<style>
	html, body { margin: 0; padding: 0; background: #fff; }
	#wpadminbar { display: none !important; }
	html { margin-top: 0 !important; }
	.ktp-desktop-main { max-width: none; margin: 0; padding: 12px 16px 24px; box-sizing: border-box; }
	@media (prefers-color-scheme: dark) { html, body { background: #111827; } }
</style>
</head>
<body <?php body_class( 'ktp-desktop' ); ?>>
<?php wp_body_open(); ?>
<main class="ktp-desktop-main">
<?php
// the_content を通さない（wpautop / wptexturize を避ける）。ショートコードだけ展開する
echo do_shortcode( '[ktpwp_all_tab]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
