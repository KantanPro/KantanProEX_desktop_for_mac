<?php
/**
 * 公開商品の個別ページ（item.php の template_include で使う）。テーマの header / footer の中に本文を出す。
 * ブロックテーマには header.php が無いので、テンプレートパーツの header / footer で外枠を作る。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$ktp_item = ktp_dt_bridge_current_item();
$ktp_body = $ktp_item ? ktp_dt_bridge_render_item( $ktp_item ) : '';

if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
	// テンプレートパーツは wp_head() より先に描画する（使うブロックのスタイルが head に入るように）
	ob_start();
	block_template_part( 'header' );
	$ktp_header = ob_get_clean();
	ob_start();
	block_template_part( 'footer' );
	$ktp_footer = ob_get_clean();
	?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
<div class="wp-site-blocks">
	<?php echo $ktp_header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
<main class="ktp-dtp-item-main"><?php echo $ktp_body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></main>
	<?php echo $ktp_footer; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</div>
	<?php wp_footer(); ?>
</body>
</html>
	<?php
	return;
}

get_header();
echo '<div class="ktp-dtp-item-main">' . $ktp_body . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
get_footer();
