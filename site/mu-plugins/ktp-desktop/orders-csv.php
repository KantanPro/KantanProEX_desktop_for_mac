<?php
/**
 * 受注の CSV 書き出し（KantanBiz の OrderExportController 相当）。仕事リストの上に期間指定の欄を出す。
 *
 * 列: 受注ID・作成日時・顧客・案件名・進捗・金額・約束納期・メモ（UTF-8 BOM つき）。
 * 期間は作成日で絞る（既定は今月）。金額は請求項目の合計（仮の行は除く）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_CSV_ACTION = 'ktp_dt_orders_csv';

/** CSV の数式として解釈されないように先頭の記号を無害化する */
function ktp_dt_csv_cell( $v ) {
	$v = (string) $v;
	if ( '' !== $v && preg_match( '/^[=+\-@\t\r]/', $v ) ) {
		return "'" . $v;
	}
	return $v;
}

add_action(
	'wp_ajax_' . KTP_DT_CSV_ACTION,
	function () {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'ktpwp_access' ) ) {
			wp_die( '権限がありません。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( KTP_DT_CSV_ACTION );
		$tz   = wp_timezone();
		$from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to   = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		$ok   = static function ( $d ) {
			return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) && false !== strtotime( $d );
		};
		if ( ! $ok( $from ) ) {
			$from = ( new DateTimeImmutable( 'first day of this month', $tz ) )->format( 'Y-m-d' );
		}
		if ( ! $ok( $to ) ) {
			$to = ( new DateTimeImmutable( 'last day of this month', $tz ) )->format( 'Y-m-d' );
		}
		global $wpdb;
		$o = $wpdb->prefix . 'ktp_order';
		$i = $wpdb->prefix . 'ktp_order_invoice_items';
		$c = $wpdb->prefix . 'ktp_client';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.created_at, o.project_name, o.progress, o.memo, o.promised_delivery_date, o.total_amount,
					COALESCE(c.company_name, o.customer_name) AS client_name,
					(SELECT SUM(amount) FROM `{$i}` it WHERE it.order_id = o.id AND it.is_provisional = 0) AS items_total
				FROM `{$o}` o LEFT JOIN `{$c}` c ON c.id = o.client_id
				WHERE o.created_at >= %s AND o.created_at <= %s ORDER BY o.id",
				$from . ' 00:00:00',
				$to . ' 23:59:59'
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$labels = array( 1 => '受付中', 2 => '見積中', 3 => '受注', 4 => '完了', 5 => '請求済', 6 => '入金済', 7 => 'ボツ' );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="orders-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( '受注ID', '作成日時', '顧客', '案件名', '進捗', '金額', '約束納期', 'メモ' ) );
		foreach ( (array) $rows as $r ) {
			$amount = null !== $r->items_total ? (float) $r->items_total : (float) $r->total_amount;
			$due    = ( $r->promised_delivery_date && '0000-00-00' !== $r->promised_delivery_date ) ? $r->promised_delivery_date : '';
			fputcsv(
				$out,
				array(
					(int) $r->id,
					$r->created_at,
					ktp_dt_csv_cell( $r->client_name ),
					ktp_dt_csv_cell( $r->project_name ),
					$labels[ (int) $r->progress ] ?? '',
					rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ),
					$due,
					ktp_dt_csv_cell( $r->memo ),
				)
			);
		}
		fclose( $out );
		exit;
	}
);

add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) ) {
			return $output;
		}
		$marker = '<div class="ktp_work_list_box">';
		if ( ! isset( $_GET['tab_name'] ) || 'list' !== $_GET['tab_name'] || false === strpos( $output, $marker ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $output;
		}
		$tz   = wp_timezone();
		$from = ( new DateTimeImmutable( 'first day of this month', $tz ) )->format( 'Y-m-d' );
		$to   = ( new DateTimeImmutable( 'last day of this month', $tz ) )->format( 'Y-m-d' );
		$url  = wp_nonce_url( admin_url( 'admin-ajax.php?action=' . KTP_DT_CSV_ACTION ), KTP_DT_CSV_ACTION );
		$html = '<div class="ktp-dt-csv" style="margin:0 0 8px;font-size:13px;">受注CSV（作成日）：'
			. '<input type="date" id="ktp-dt-csv-from" value="' . esc_attr( $from ) . '" style="font-size:13px;"> 〜 '
			. '<input type="date" id="ktp-dt-csv-to" value="' . esc_attr( $to ) . '" style="font-size:13px;"> '
			. '<a href="' . esc_url( $url ) . '" id="ktp-dt-csv-link" style="margin-left:6px;">CSVを書き出す</a></div>'
			. '<script>(function(){var l=document.getElementById("ktp-dt-csv-link");if(!l)return;l.addEventListener("click",function(e){var u=new URL(l.href);u.searchParams.set("from",document.getElementById("ktp-dt-csv-from").value);u.searchParams.set("to",document.getElementById("ktp-dt-csv-to").value);l.href=u.toString();});})();</script>';
		return str_replace( $marker, $html . $marker, $output );
	},
	11,
	2
);
