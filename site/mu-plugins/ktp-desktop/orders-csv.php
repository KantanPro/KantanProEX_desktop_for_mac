<?php
/**
 * 受注の CSV 書き出し（KantanBiz の OrderExportController 相当）。設定メニュー「受注CSV書き出し」から期間を指定して出す（仕事リストには出さない）。
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

/**
 * 受注 CSV の書き出し画面。仕事リストには出さず、設定メニュー（KantanProEX 設定）の下に置く。
 * 書き出し本体は上の wp_ajax ハンドラ。
 */
add_action(
	'admin_menu',
	function () {
		add_submenu_page(
			'ktp-settings',
			'受注CSV書き出し',
			'受注CSV書き出し',
			'manage_options',
			'ktp-desktop-orders-csv',
			function () {
				$tz   = wp_timezone();
				$from = ( new DateTimeImmutable( 'first day of this month', $tz ) )->format( 'Y-m-d' );
				$to   = ( new DateTimeImmutable( 'last day of this month', $tz ) )->format( 'Y-m-d' );
				$url  = wp_nonce_url( admin_url( 'admin-ajax.php?action=' . KTP_DT_CSV_ACTION ), KTP_DT_CSV_ACTION );
				?>
				<div class="wrap">
					<h1>受注CSV書き出し</h1>
					<p>作成日が期間内の受注を CSV（UTF-8）で書き出します。列は 受注ID・作成日時・顧客・案件名・進捗・金額・約束納期・メモ です。</p>
					<p>
						作成日：
						<input type="date" id="ktp-dt-csv-from" value="<?php echo esc_attr( $from ); ?>"> 〜
						<input type="date" id="ktp-dt-csv-to" value="<?php echo esc_attr( $to ); ?>">
						<a href="<?php echo esc_url( $url ); ?>" id="ktp-dt-csv-link" class="button button-primary" style="margin-left:8px;">CSVを書き出す</a>
					</p>
				</div>
				<script>
				(function(){var l=document.getElementById("ktp-dt-csv-link");l.addEventListener("click",function(){var u=new URL(l.href);u.searchParams.set("from",document.getElementById("ktp-dt-csv-from").value);u.searchParams.set("to",document.getElementById("ktp-dt-csv-to").value);l.href=u.toString();});})();
				</script>
				<?php
			}
		);
	},
	PHP_INT_MAX
);
