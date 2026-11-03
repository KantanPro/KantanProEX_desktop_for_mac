<?php
/**
 * ヘッダーの回転矢印を「デスクトップ版の更新確認」にする。
 *
 * 同梱している KantanProEX（WP 版）の更新チェックは ktp-desktop.php で止めてある
 * （デスクトップ版はアプリごと入れ替えて更新するので、WP 版の新しい版は関係がない）。
 * 代わりに、本体（Electron の lib/update.js）が控えた最新版の情報を読み、
 * 新しいデスクトップ版が出ていれば矢印に赤い丸を付ける。
 *
 * ここからは外へ通信しない（デスクトップ版の決まり。ktp-desktop.php の pre_http_request）。
 * 通信するのは本体だけで、起動時と 6 時間ごとに update-state.json を書き直す。
 * 矢印を押したときは ?ktp_desktop_update=1 へ進もうとし、それを本体が捕まえて
 * 「アップデートを確認…」と同じお知らせを出す（main.js の guardNavigation）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 本体が控えた最新版の情報（site/ の一つ上＝アプリのデータ置き場にある） */
function ktp_dt_update_state() {
	$file = dirname( untrailingslashit( ABSPATH ) ) . '/update-state.json';
	if ( ! is_readable( $file ) ) {
		return array();
	}
	$state = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return is_array( $state ) ? $state : array();
}

/**
 * 新しい版が出ているか。
 * 本体がまだ一度も確認できていない（控えが無い）ときは false。
 * 「この版は知らせない」を選んだ版も false にする（押せば内容は見られる）。
 */
function ktp_dt_update_available() {
	$state   = ktp_dt_update_state();
	$current = ktp_desktop_app_version();
	$latest  = isset( $state['latest'] ) ? (string) $state['latest'] : '';
	if ( '' === $current || '' === $latest ) {
		return false;
	}
	if ( isset( $state['skippedVersion'] ) && $state['skippedVersion'] === $latest ) {
		return false;
	}
	return version_compare( $latest, $current, '>' );
}

/**
 * 画面側。矢印は本体の KantanProEX（ktpwp.php）が出しているので、出来上がった画面で捕まえて
 * 行き先と見た目だけ差し替える。
 */
add_action(
	'wp_footer',
	function () {
		if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		// LAN（iPad など）から見ているときは、その端末でアプリを更新できないので何もしない
		if ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ) {
			return;
		}
		$available = ktp_dt_update_available();
		?>
<style id="ktp-dt-update-style">
	.ktp-dt-update-link { position: relative; }
	.ktp-dt-update-badge { position: absolute; top: -1px; right: -3px; width: 8px; height: 8px; border-radius: 50%; background: #d63638; }
</style>
<script id="ktp-dt-update-script">
(function () {
	var url = <?php echo wp_json_encode( home_url( '/?ktp_desktop_update=1' ) ); ?>;
	var available = <?php echo $available ? 'true' : 'false'; ?>;

	function start() {
		var nav = document.querySelector('.ktp_header .navigation-links');
		var a = nav && nav.querySelector('a[title="更新"], a#ktpwp-header-update-check');
		if (!a) { return; }
		a.id = 'ktp-dt-update-check';
		a.className = (a.className ? a.className + ' ' : '') + 'ktp-dt-update-link';
		a.setAttribute('href', url);
		a.title = available ? '更新が利用可能です' : '更新を確認';
		if (available && !a.querySelector('.ktp-dt-update-badge')) {
			var badge = document.createElement('span');
			badge.className = 'ktp-dt-update-badge';
			a.appendChild(badge);
		}
	}

	if ('loading' === document.readyState) { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
		<?php
	},
	99
);
