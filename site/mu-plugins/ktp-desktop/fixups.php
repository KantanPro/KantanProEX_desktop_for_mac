<?php
/**
 * 一度だけ行うデータの手直し。
 *
 * - 会社情報の <br />: 0.2.1 までの KantanBiz 取り込みは、会社情報の改行を <br /> にして保存していた。
 *   プラグインは会社情報をメールの署名にそのまま入れるので、メール本文に <br /> が出ていた。
 *   「各行の終わりに <br /> が付いているだけ」（取り込みが作った形）のときだけ取り除く。自分で HTML を書いた会社情報には触れない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'init',
	function () {
		if ( get_option( 'ktp_desktop_fixup_company_info_br' ) ) {
			return;
		}
		$general = get_option( 'ktp_general_settings', array() );
		if ( is_array( $general ) && ! empty( $general['company_info'] ) ) {
			$info     = (string) $general['company_info'];
			$stripped = preg_replace( '/<br\s*\/?>(\r?\n)/i', '$1', $info );
			// 取り除いたあとに HTML タグが残っていなければ、取り込みが作った形とみなして保存する
			if ( $stripped !== $info && $stripped === wp_strip_all_tags( $stripped ) ) {
				$general['company_info'] = $stripped;
				update_option( 'ktp_general_settings', $general );
			}
		}
		update_option( 'ktp_desktop_fixup_company_info_br', 1, true );
	},
	5
);
