<?php
/**
 * Plugin Name: KantanProEX Desktop
 * Description: デスクトップ版（Electron 同梱）用の調整。プラグイン本体には手を入れず、ここで差し替える。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 画面はテーマを通さず、アプリ専用の素のテンプレートで出す。
 *
 * ブロックテーマはテンプレート全体に wptexturize をかけるため、ショートコード出力内の
 * インライン script の `&&` が `&#038;&#038;` になり構文エラーになる（顧客タブで実際に発生）。
 * デスクトップではテーマのヘッダー・フッターも不要なので、本文のショートコードだけを描画する。
 */
add_filter(
	'template_include',
	function ( $template ) {
		if ( is_admin() || ! is_singular() ) {
			return $template;
		}
		$post = get_queried_object();
		if ( $post && has_shortcode( (string) $post->post_content, 'ktpwp_all_tab' ) ) {
			return __DIR__ . '/ktp-desktop/app-template.php';
		}
		return $template;
	},
	99
);

/**
 * 外部への HTTP 通信は原則すべて遮断する（GitHub の更新確認・バナー・規約同意の送信など）。
 *
 * 例外は、本人が設定画面で有効にして API キーを入れた機能だけ（いまは日本郵便の郵便番号 API）。
 * WP_HTTP_BLOCK_EXTERNAL / WP_ACCESSIBLE_HOSTS は定数なので「設定したら通す」ができず、ここで判定する。
 * 遮断はタイムアウトを待たず即座に WP_Error を返すので、オフラインでも画面が待たされない。
 */
function ktp_desktop_allowed_hosts() {
	$hosts = array();
	if ( class_exists( 'KTPWP_Japanpost_Address_API' ) && KTPWP_Japanpost_Address_API::is_enabled() ) {
		$hosts[] = (string) wp_parse_url( KTPWP_Japanpost_Address_API::get_base_url(), PHP_URL_HOST );
	}
	return array_filter( $hosts );
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return $preempt; // 自分自身（wp-cron など）は通す
		}
		if ( in_array( $host, ktp_desktop_allowed_hosts(), true ) ) {
			return $preempt;
		}
		return new WP_Error( 'ktp_desktop_offline', 'デスクトップ版では外部への通信を行いません: ' . $host );
	},
	1,
	3
);

/**
 * プラグイン本体の自動更新確認を止める（デスクトップ版はアプリごと更新する）。
 * cron の予約も外して、毎日の無駄な実行をなくす。
 */
add_action(
	'init',
	function () {
		$ts = wp_next_scheduled( 'ktpwp_daily_update_check' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'ktpwp_daily_update_check' );
		}
	}
);
add_filter( 'pre_schedule_event', function ( $pre, $event ) {
	return ( is_object( $event ) && $event->hook === 'ktpwp_daily_update_check' ) ? false : $pre;
}, 10, 2 );
add_filter( 'automatic_updater_disabled', '__return_true' );
