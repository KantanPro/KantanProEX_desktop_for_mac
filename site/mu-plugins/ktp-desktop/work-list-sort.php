<?php
/**
 * 仕事リストの並び替え（KantanBiz の WorkListSort 相当）。
 *
 * 本体は SQL の並び順が固定（受注=納期順、他=新しい順）なので、仕事リストの案件一覧を取る SQL の
 * ORDER BY だけをここで差し替える。選んだ並び順は利用者ごとに覚える（ページ送りでも保たれる）。
 * 既定（未選択）は本体のまま。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_SORT_META = 'ktp_dt_work_list_sort';

function ktp_dt_sort_options() {
	return array(
		''        => '標準（受注は納期順、他は新しい順）',
		'newest'  => '受付日の新しい順',
		'oldest'  => '受付日の古い順',
		'due'     => '納期の近い順',
		'updated' => '更新の新しい順',
		'client'  => '顧客名順',
	);
}

/** 今回の並び順（URL の sort があれば保存して使い、無ければ保存済みを使う） */
function ktp_dt_sort_current() {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	$options = ktp_dt_sort_options();
	if ( isset( $_GET['sort'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort = sanitize_key( wp_unslash( $_GET['sort'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sort = isset( $options[ $sort ] ) ? $sort : '';
		update_user_meta( get_current_user_id(), KTP_DT_SORT_META, $sort );
		return $sort;
	}
	$saved = (string) get_user_meta( get_current_user_id(), KTP_DT_SORT_META, true );
	return isset( $options[ $saved ] ) ? $saved : '';
}

function ktp_dt_sort_order_by( $sort ) {
	global $wpdb;
	$o   = $wpdb->prefix . 'ktp_order';
	$c   = $wpdb->prefix . 'ktp_client';
	$due = "COALESCE(NULLIF(promised_delivery_date, '0000-00-00'), NULLIF(desired_delivery_date, '0000-00-00'))";
	switch ( $sort ) {
		case 'newest':
			return 'ORDER BY time DESC';
		case 'oldest':
			return 'ORDER BY time ASC, id ASC';
		case 'due':
			return "ORDER BY CASE WHEN {$due} IS NULL THEN 1 ELSE 0 END ASC, {$due} ASC, time DESC";
		case 'updated':
			return 'ORDER BY updated_at DESC, id DESC';
		case 'client':
			return "ORDER BY (SELECT company_name FROM `{$c}` WHERE `{$c}`.id = `{$o}`.client_id) ASC, time DESC";
	}
	return '';
}

/** 仕事リストの案件一覧の SQL だけを書き換える */
add_filter(
	'query',
	function ( $sql ) {
		static $sort = null;
		if ( is_admin() || wp_doing_ajax() || ! isset( $_GET['tab_name'] ) || 'list' !== $_GET['tab_name'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $sql;
		}
		if ( 0 !== strncmp( ltrim( $sql ), 'SELECT', 6 ) || false === strpos( $sql, 'ORDER BY' ) ) {
			return $sql;
		}
		global $wpdb;
		$o = $wpdb->prefix . 'ktp_order';
		if ( ! preg_match( '/FROM\s+`?' . preg_quote( $o, '/' ) . '`?\s+WHERE\s+progress\s*=\s*\d+/', $sql ) ) {
			return $sql;
		}
		if ( ! preg_match( '/ORDER BY (?:days_until_delivery ASC, )?time DESC/', $sql ) ) {
			return $sql;
		}
		if ( null === $sort ) {
			$sort = ktp_dt_sort_current();
		}
		$order_by = ktp_dt_sort_order_by( $sort );
		if ( '' === $order_by ) {
			return $sql;
		}
		return preg_replace( '/ORDER BY (?:days_until_delivery ASC, )?time DESC/', $order_by, $sql, 1 );
	}
);

/** 並び替えの選択欄を、6つの進捗ボタンの下にセンター寄せで出す */
add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) ) {
			return $output;
		}
		$marker = '<div class="ktp_work_list_box">';
		if ( ! isset( $_GET['tab_name'] ) || 'list' !== $_GET['tab_name'] || ( false === strpos( $output, $marker ) && false === strpos( $output, 'ktp-list-progress-filter' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $output;
		}
		// 本体が受注タブに出す「ソート順: 約束納期…」の固定文は、並び順を変えても残って食い違うので消す
		// （標準の並びは選択欄の「標準（受注は納期順、他は新しい順）」に書いてある）
		$output  = preg_replace( '/<div class="ktp-list-sort-notice">.*?<\/div>/s', '', $output );
		$current = ktp_dt_sort_current();
		$html    = '<div class="ktp-dt-sort" style="margin:-8px 0 0;font-size:13px;line-height:1;text-align:center;"><label>並び順：<select id="ktp-dt-sort-select" style="font-size:13px;padding:2px 6px;">';
		foreach ( ktp_dt_sort_options() as $value => $label ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select></label></div>';
		// 並び順の下の空きを詰める（一覧の枠の上パディング 20px を 0 に）
		$html .= '<style>#list_content .ktp_work_list_box{padding-top:0}</style>';
		$html .= '<script>(function(){var s=document.getElementById("ktp-dt-sort-select");if(!s)return;s.addEventListener("change",function(){var u=new URL(window.location.href);u.searchParams.set("sort",s.value);u.searchParams.delete("page_start");window.location.href=u.toString();});})();</script>';
		// 6つの進捗ボタン（.ktp-list-progress-filter）の直下に、センター寄せで入れる
		$pf = strpos( $output, 'ktp-list-progress-filter' );
		if ( false !== $pf ) {
			// 進捗ボタン（<a class="progress-btn">…</a>）が続く範囲だけを対象にして、その直後の </div> の後ろへ入れる
			if ( preg_match( '/\G(?:\s*<a [^>]*class="progress-btn"[^>]*>.*?<\/a>)+\s*<\/div>/s', $output, $m, 0, strpos( $output, '>', $pf ) + 1 ) ) {
				$end = strpos( $output, $m[0], $pf ) + strlen( $m[0] );
				return substr_replace( $output, $html, $end, 0 );
			}
		}
		return str_replace( $marker, $html . $marker, $output );
	},
	10,
	2
);
