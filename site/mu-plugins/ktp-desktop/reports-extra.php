<?php
/**
 * レポートタブに「仕入・支払」と「資金繰り予定」を追加する（KantanBiz の PurchaseReportService / CashflowScheduleService 相当）。
 *
 * - 仕入・支払: 協力会社ごと・締め期間ごとの支払照合リスト。原価明細の「支払済」を付けられる
 * - 資金繰り予定: 顧客からの入金予定と協力会社への支払予定を日付順に一覧
 * 予定日の計算は payment-schedule.php。レポート本体（売上など）は本体のまま。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/payment-schedule.php';

const KTP_DT_REPORT_NONCE = 'ktp_dt_report';

function ktp_dt_yen( $n ) {
	return number_format( (float) $n, 0 ) . '円';
}

function ktp_dt_report_can() {
	return is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'ktpwp_access' ) );
}

/** 発注済・仕入完了の原価明細（ボツの案件を除く）。支払情報つき */
function ktp_dt_purchase_items( $supplier_id = 0 ) {
	global $wpdb;
	$ci = $wpdb->prefix . 'ktp_order_cost_items';
	$o  = $wpdb->prefix . 'ktp_order';
	$pm = ktp_dt_cost_payment_table();
	$where = "ci.purchase_status IN ('ordered','completed') AND o.progress != 7";
	$args  = array();
	if ( $supplier_id > 0 ) {
		$where .= ' AND ci.supplier_id = %d';
		$args[] = $supplier_id;
	}
	$sql = "SELECT ci.*, o.project_name AS order_title, p.paid, p.paid_at, p.due_date_override, p.closing_reference_date
		FROM `{$ci}` ci JOIN `{$o}` o ON o.id = ci.order_id LEFT JOIN `{$pm}` p ON p.cost_item_id = ci.id
		WHERE {$where} ORDER BY ci.id";
	return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}

function ktp_dt_suppliers_by_id() {
	global $wpdb;
	$out = array();
	foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$wpdb->prefix}ktp_supplier`" ) as $s ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out[ (int) $s->id ] = $s;
	}
	return $out;
}

function ktp_dt_reference_in_period( DateTimeImmutable $ref, $period ) {
	if ( 'all_time' === $period ) {
		return true;
	}
	$now = ktp_dt_ps_today();
	switch ( $period ) {
		case 'this_year':
			return $ref->format( 'Y' ) === $now->format( 'Y' );
		case 'last_year':
			return (int) $ref->format( 'Y' ) === (int) $now->format( 'Y' ) - 1;
		case 'this_month':
			return $ref->format( 'Y-m' ) === $now->format( 'Y-m' );
		case 'last_month':
			return $ref->format( 'Y-m' ) === ktp_dt_ps_add_months( $now, -1 )->format( 'Y-m' );
		case 'last_3_months':
			return $ref >= ktp_dt_ps_add_months( $now, -3 );
		case 'last_6_months':
			return $ref >= ktp_dt_ps_add_months( $now, -6 );
	}
	return true;
}

/**
 * 協力会社 1 社分の支払照合。
 *
 * @return array{groups: list<array<string,mixed>>, summary: array<string,float|int>}
 */
function ktp_dt_purchase_report_for( $supplier, array $items, $period, $unpaid_only ) {
	$grouped = array();
	foreach ( $items as $item ) {
		if ( $unpaid_only && ! empty( $item->paid ) ) {
			continue;
		}
		$info      = ktp_dt_ps_closing_period( $item, $supplier );
		$reference = ktp_dt_ps_reference_date( $item );
		if ( null !== $reference && ! ktp_dt_reference_in_period( $reference, $period ) ) {
			continue;
		}
		if ( null === $reference && 'all_time' !== $period ) {
			continue;
		}
		$key = $info['period_key'];
		if ( ! isset( $grouped[ $key ] ) ) {
			$grouped[ $key ] = array(
				'period_key'  => $key,
				'label'       => $info['label'],
				'payment_due' => $info['payment_due'] ? $info['payment_due']->format( 'Y-m-d' ) : '',
				'rows'        => array(),
				'total'       => 0.0,
				'paid'        => 0.0,
				'unpaid'      => 0.0,
			);
		}
		$amount = (float) $item->amount;
		$grouped[ $key ]['rows'][] = array( 'item' => $item, 'reference' => $reference, 'info' => $info );
		$grouped[ $key ]['total'] += $amount;
		if ( ! empty( $item->paid ) ) {
			$grouped[ $key ]['paid'] += $amount;
		} else {
			$grouped[ $key ]['unpaid'] += $amount;
		}
	}
	krsort( $grouped );
	$summary = array( 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'rows' => 0 );
	foreach ( $grouped as $g ) {
		$summary['total']  += $g['total'];
		$summary['paid']   += $g['paid'];
		$summary['unpaid'] += $g['unpaid'];
		$summary['rows']   += count( $g['rows'] );
	}
	return array( 'groups' => array_values( $grouped ), 'summary' => $summary );
}

function ktp_dt_report_table_css() {
	return '<style>
.ktp-dt-rpt h4{margin:18px 0 8px;font-size:15px}.ktp-dt-rpt table{width:100%;border-collapse:collapse;margin:0 0 14px}
.ktp-dt-rpt th,.ktp-dt-rpt td{border:1px solid #e5e7eb;padding:5px 8px;font-size:13px;text-align:left}.ktp-dt-rpt th{background:#f5f6f7}
.ktp-dt-rpt td.num,.ktp-dt-rpt th.num{text-align:right;white-space:nowrap}.ktp-dt-rpt .cards{display:flex;flex-wrap:wrap;gap:10px;margin:8px 0 14px}
.ktp-dt-rpt .card{border:1px solid #e5e7eb;border-radius:8px;padding:8px 14px;background:#fff;min-width:150px}.ktp-dt-rpt .card b{display:block;font-size:18px}
.ktp-dt-rpt .over{color:#b91c1c;font-weight:600}.ktp-dt-rpt .muted{color:#6b7280}.ktp-dt-rpt form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:13px;margin-bottom:8px}
.ktp-dt-rpt .grp{background:#eef2ff;font-weight:600}.ktp-dt-rpt .tag{display:inline-block;padding:0 8px;border-radius:9999px;font-size:11px;background:#e5e7eb}
.ktp-dt-rpt .tag.paid{background:#dcfce7;color:#166534}.ktp-dt-rpt .tag.overdue{background:#fee2e2;color:#b91c1c}
</style>';
}

function ktp_dt_render_purchase_report() {
	global $wpdb;
	$periods = array(
		'all_time' => '全期間', 'this_year' => '今年', 'last_year' => '去年', 'this_month' => '今月',
		'last_month' => '先月', 'last_3_months' => '過去3ヶ月', 'last_6_months' => '過去6ヶ月',
	);
	$period      = isset( $_GET['period'] ) && isset( $periods[ $_GET['period'] ] ) ? sanitize_text_field( wp_unslash( $_GET['period'] ) ) : 'this_year'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$unpaid_only = ! empty( $_GET['unpaid'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$sel         = isset( $_GET['supplier'] ) ? absint( $_GET['supplier'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$suppliers   = ktp_dt_suppliers_by_id();
	$all_items   = ktp_dt_purchase_items();
	$by_supplier = array();
	foreach ( $all_items as $it ) {
		$by_supplier[ (int) $it->supplier_id ][] = $it;
	}

	$h  = ktp_dt_report_table_css() . '<div class="ktp-dt-rpt" data-nonce="' . esc_attr( wp_create_nonce( KTP_DT_REPORT_NONCE ) ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '">';
	$h .= '<h3 style="margin:0 0 8px;">仕入・支払（協力会社への支払照合）</h3>';
	$h .= '<form class="filters" method="get"><input type="hidden" name="tab_name" value="report"><input type="hidden" name="report_type" value="purchase">';
	$h .= '<label>協力会社 <select name="supplier"><option value="0">すべて</option>';
	foreach ( $by_supplier as $sid => $_ ) {
		$h .= '<option value="' . (int) $sid . '"' . selected( $sel, $sid, false ) . '>' . esc_html( isset( $suppliers[ $sid ] ) ? $suppliers[ $sid ]->company_name : '（協力会社未設定）' ) . '</option>';
	}
	$h .= '</select></label><label>期間 <select name="period">';
	foreach ( $periods as $k => $label ) {
		$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $period, $k, false ) . '>' . esc_html( $label ) . '</option>';
	}
	$h .= '</select></label><label><input type="checkbox" name="unpaid" value="1"' . checked( $unpaid_only, true, false ) . '> 未払いのみ</label>';
	$h .= '<button type="submit">表示</button></form>';
	$h .= '<p class="muted" style="font-size:12px;">発注済・仕入完了の原価明細が対象です。締め期間と支払予定日は、協力会社マスタの締日・支払条件（と仕入ステータスの日付）から計算します。</p>';

	$targets = $sel > 0 ? array( $sel => ( $by_supplier[ $sel ] ?? array() ) ) : $by_supplier;
	$grand   = array( 'total' => 0.0, 'paid' => 0.0, 'unpaid' => 0.0, 'rows' => 0 );
	$body    = '';
	foreach ( $targets as $sid => $items ) {
		$supplier = $suppliers[ $sid ] ?? null;
		$rep      = ktp_dt_purchase_report_for( $supplier, $items, $period, $unpaid_only );
		if ( 0 === $rep['summary']['rows'] ) {
			continue;
		}
		foreach ( array( 'total', 'paid', 'unpaid', 'rows' ) as $k ) {
			$grand[ $k ] += $rep['summary'][ $k ];
		}
		$body .= '<h4>' . esc_html( $supplier ? $supplier->company_name : '（協力会社未設定）' ) . ' <span class="muted" style="font-weight:400;font-size:12px;">合計 ' . esc_html( ktp_dt_yen( $rep['summary']['total'] ) ) . ' ／ 未払い ' . esc_html( ktp_dt_yen( $rep['summary']['unpaid'] ) ) . '</span></h4>';
		$body .= '<table><thead><tr><th>支払</th><th>案件</th><th>明細</th><th>基準日</th><th>仕入</th><th>支払予定日</th><th class="num">金額</th></tr></thead><tbody>';
		foreach ( $rep['groups'] as $g ) {
			$body .= '<tr class="grp"><td colspan="7">' . esc_html( $g['label'] ) . '　支払予定: ' . esc_html( $g['payment_due'] ?: '未設定' ) . '　小計 ' . esc_html( ktp_dt_yen( $g['total'] ) ) . '（未払い ' . esc_html( ktp_dt_yen( $g['unpaid'] ) ) . '）</td></tr>';
			foreach ( $g['rows'] as $row ) {
				$it   = $row['item'];
				$due  = $row['info']['payment_due'];
				$over = ( empty( $it->paid ) && $due && ktp_dt_ps_today() > $due );
				$body .= '<tr><td><input type="checkbox" class="ktp-dt-paid" data-id="' . (int) $it->id . '"' . checked( ! empty( $it->paid ), true, false ) . ' aria-label="支払済"></td>'
					. '<td><a href="' . esc_url( add_query_arg( array( 'tab_name' => 'order', 'order_id' => (int) $it->order_id ), home_url( '/' ) ) ) . '">#' . (int) $it->order_id . ' ' . esc_html( $it->order_title ) . '</a></td>'
					. '<td>' . esc_html( $it->product_name ) . '</td>'
					. '<td>' . esc_html( $row['reference'] ? $row['reference']->format( 'Y-m-d' ) : '—' ) . '</td>'
					. '<td>' . esc_html( 'completed' === $it->purchase_status ? '仕入完了' : '発注' ) . '</td>'
					. '<td class="' . ( $over ? 'over' : '' ) . '">' . esc_html( $due ? $due->format( 'Y-m-d' ) : '—' ) . ( $over ? ' 超過' : '' ) . ( ! empty( $it->paid_at ) ? '<div class="muted">支払日 ' . esc_html( substr( $it->paid_at, 0, 10 ) ) . '</div>' : '' ) . '</td>'
					. '<td class="num">' . esc_html( ktp_dt_yen( $it->amount ) ) . '</td></tr>';
			}
		}
		$body .= '</tbody></table>';
	}
	$h .= '<div class="cards"><div class="card">合計<b>' . esc_html( ktp_dt_yen( $grand['total'] ) ) . '</b></div><div class="card">支払済<b>' . esc_html( ktp_dt_yen( $grand['paid'] ) ) . '</b></div><div class="card">未払い<b>' . esc_html( ktp_dt_yen( $grand['unpaid'] ) ) . '</b></div><div class="card">明細数<b>' . (int) $grand['rows'] . '件</b></div></div>';
	$h .= '' === $body ? '<p class="muted">該当する原価明細がありません。</p>' : $body;
	$h .= '<script>(function(){var w=document.querySelector(".ktp-dt-rpt");if(!w)return;w.addEventListener("change",function(e){var c=e.target.closest(".ktp-dt-paid");if(!c)return;c.disabled=true;var b=new URLSearchParams({action:"ktp_dt_cost_paid",nonce:w.dataset.nonce,cost_item_id:c.dataset.id,paid:c.checked?"1":"0"});fetch(w.dataset.ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:b.toString()}).then(function(r){return r.json()}).then(function(j){if(j&&j.success){location.reload()}else{c.checked=!c.checked;c.disabled=false;alert((j&&j.data&&j.data.message)||"保存できませんでした")}}).catch(function(){c.checked=!c.checked;c.disabled=false;alert("通信に失敗しました")});});})();</script></div>';
	return $h;
}

/** 入金予定（顧客）と支払予定（協力会社）の行 */
function ktp_dt_cashflow_rows( $kind ) {
	global $wpdb;
	$today = ktp_dt_ps_today();
	$rows  = array();
	if ( 'all' === $kind || 'inflow' === $kind ) {
		$o = $wpdb->prefix . 'ktp_order';
		$i = $wpdb->prefix . 'ktp_order_invoice_items';
		$c = $wpdb->prefix . 'ktp_client';
		$orders = $wpdb->get_results(
			"SELECT o.id, o.project_name, o.progress, o.completion_date, o.payment_timing, o.total_amount, o.client_id,
				(SELECT SUM(amount) FROM `{$i}` it WHERE it.order_id = o.id AND it.is_provisional = 0) AS items_total
			FROM `{$o}` o WHERE o.progress IN (5,6) ORDER BY o.id"
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clients = array();
		foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$c}`" ) as $cl ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$clients[ (int) $cl->id ] = $cl;
		}
		foreach ( (array) $orders as $od ) {
			$cl     = $clients[ (int) $od->client_id ] ?? null;
			$due    = ktp_dt_ps_client_payment_due( $od, $cl );
			$paid   = 6 === (int) $od->progress;
			$amount = null !== $od->items_total && (float) $od->items_total > 0 ? (float) $od->items_total : (float) $od->total_amount;
			if ( $amount <= 0 ) {
				continue;
			}
			$rows[] = array(
				'kind' => 'inflow', 'status' => ktp_dt_cashflow_status( $paid, $due, $today ), 'date' => $due ? $due->format( 'Y-m-d' ) : '',
				'amount' => $amount, 'who' => $cl ? $cl->company_name : '（不明）', 'order_id' => (int) $od->id, 'order_title' => $od->project_name,
				'detail' => '入金', 'paid_at' => '',
			);
		}
	}
	if ( 'all' === $kind || 'outflow' === $kind ) {
		$suppliers = ktp_dt_suppliers_by_id();
		foreach ( ktp_dt_purchase_items() as $it ) {
			$sup    = $suppliers[ (int) $it->supplier_id ] ?? null;
			$info   = ktp_dt_ps_closing_period( $it, $sup );
			$due    = $info['payment_due'];
			$paid   = ! empty( $it->paid );
			$amount = (float) $it->amount;
			if ( $amount <= 0 ) {
				continue;
			}
			$rows[] = array(
				'kind' => 'outflow', 'status' => ktp_dt_cashflow_status( $paid, $due, $today ), 'date' => $due ? $due->format( 'Y-m-d' ) : '',
				'amount' => $amount, 'who' => $sup ? $sup->company_name : '（不明）', 'order_id' => (int) $it->order_id, 'order_title' => $it->order_title,
				'detail' => $it->product_name, 'paid_at' => ! empty( $it->paid_at ) ? substr( $it->paid_at, 0, 10 ) : '',
			);
		}
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			if ( '' === $a['date'] && '' !== $b['date'] ) {
				return 1;
			}
			if ( '' !== $a['date'] && '' === $b['date'] ) {
				return -1;
			}
			if ( $a['date'] !== $b['date'] ) {
				return strcmp( $a['date'], $b['date'] );
			}
			$ko = array( 'inflow' => 0, 'outflow' => 1 );
			$kc = $ko[ $a['kind'] ] <=> $ko[ $b['kind'] ];
			return 0 !== $kc ? $kc : $a['order_id'] <=> $b['order_id'];
		}
	);
	return $rows;
}

function ktp_dt_cashflow_status( $paid, $due, DateTimeImmutable $today ) {
	if ( $paid ) {
		return 'paid';
	}
	return ( $due && $today > $due ) ? 'overdue' : 'pending';
}

function ktp_dt_cashflow_filter( array $rows, $filter ) {
	if ( 'all' === $filter ) {
		return $rows;
	}
	if ( 'overdue' === $filter ) {
		return array_values( array_filter( $rows, static function ( $r ) { return 'overdue' === $r['status']; } ) );
	}
	$target = 'this_month' === $filter ? ktp_dt_ps_today()->format( 'Y-m' ) : ktp_dt_ps_add_months( ktp_dt_ps_today(), 1 )->format( 'Y-m' );
	return array_values( array_filter( $rows, static function ( $r ) use ( $target ) { return '' !== $r['date'] && substr( $r['date'], 0, 7 ) === $target; } ) );
}

function ktp_dt_render_cashflow() {
	$kinds   = array( 'all' => 'すべて', 'inflow' => '入金予定', 'outflow' => '支払予定' );
	$filters = array( 'all' => 'すべて', 'this_month' => '今月', 'next_month' => '来月', 'overdue' => '期限超過' );
	$kind    = isset( $_GET['kind'] ) && isset( $kinds[ $_GET['kind'] ] ) ? sanitize_text_field( wp_unslash( $_GET['kind'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$filter  = isset( $_GET['filter'] ) && isset( $filters[ $_GET['filter'] ] ) ? sanitize_text_field( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$rows    = ktp_dt_cashflow_filter( ktp_dt_cashflow_rows( $kind ), $filter );
	$sum     = array( 'in_p' => 0.0, 'out_p' => 0.0, 'in_o' => 0.0, 'out_o' => 0.0 );
	foreach ( $rows as $r ) {
		if ( 'paid' === $r['status'] ) {
			continue;
		}
		$k = ( 'inflow' === $r['kind'] ? 'in_' : 'out_' ) . ( 'overdue' === $r['status'] ? 'o' : 'p' );
		$sum[ $k ] += $r['amount'];
	}
	$h  = ktp_dt_report_table_css() . '<div class="ktp-dt-rpt"><h3 style="margin:0 0 8px;">資金繰り予定（入金・支払）</h3>';
	$h .= '<form class="filters" method="get"><input type="hidden" name="tab_name" value="report"><input type="hidden" name="report_type" value="cashflow">';
	$h .= '<label>種別 <select name="kind">';
	foreach ( $kinds as $k => $l ) {
		$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $kind, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	$h .= '</select></label><label>期間 <select name="filter">';
	foreach ( $filters as $k => $l ) {
		$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $filter, $k, false ) . '>' . esc_html( $l ) . '</option>';
	}
	$h .= '</select></label><button type="submit">表示</button></form>';
	$h .= '<p class="muted" style="font-size:12px;">入金予定は請求済・入金済の受注（完了日と顧客の締日・支払条件から計算）、支払予定は発注済・仕入完了の原価明細から出しています。</p>';
	$h .= '<div class="cards"><div class="card">入金待ち<b>' . esc_html( ktp_dt_yen( $sum['in_p'] ) ) . '</b></div><div class="card">入金遅れ<b class="over">' . esc_html( ktp_dt_yen( $sum['in_o'] ) ) . '</b></div><div class="card">支払予定<b>' . esc_html( ktp_dt_yen( $sum['out_p'] ) ) . '</b></div><div class="card">支払遅れ<b class="over">' . esc_html( ktp_dt_yen( $sum['out_o'] ) ) . '</b></div></div>';
	if ( ! $rows ) {
		return $h . '<p class="muted">該当する予定がありません。</p></div>';
	}
	$labels = array( 'paid' => '済', 'overdue' => '期限超過', 'pending' => '予定' );
	$h .= '<table><thead><tr><th>予定日</th><th>区分</th><th>相手先</th><th>案件</th><th>内容</th><th>状態</th><th class="num">金額</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$h .= '<tr><td>' . esc_html( '' !== $r['date'] ? $r['date'] : '未定' ) . '</td><td>' . ( 'inflow' === $r['kind'] ? '入金' : '支払' ) . '</td><td>' . esc_html( $r['who'] ) . '</td>'
			. '<td><a href="' . esc_url( add_query_arg( array( 'tab_name' => 'order', 'order_id' => $r['order_id'] ), home_url( '/' ) ) ) . '">#' . (int) $r['order_id'] . ' ' . esc_html( $r['order_title'] ) . '</a></td>'
			. '<td>' . esc_html( $r['detail'] ) . '</td><td><span class="tag ' . esc_attr( $r['status'] ) . '">' . esc_html( $labels[ $r['status'] ] ) . '</span>' . ( '' !== $r['paid_at'] ? ' <span class="muted">' . esc_html( $r['paid_at'] ) . '</span>' : '' ) . '</td>'
			. '<td class="num">' . esc_html( ktp_dt_yen( $r['amount'] ) ) . '</td></tr>';
	}
	return $h . '</tbody></table></div>';
}

add_action(
	'wp_ajax_ktp_dt_cost_paid',
	function () {
		if ( ! ktp_dt_report_can() ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		check_ajax_referer( KTP_DT_REPORT_NONCE, 'nonce' );
		global $wpdb;
		$id   = isset( $_POST['cost_item_id'] ) ? absint( $_POST['cost_item_id'] ) : 0;
		$paid = ! empty( $_POST['paid'] ) && '0' !== $_POST['paid'];
		$ci   = $wpdb->prefix . 'ktp_order_cost_items';
		if ( ! $id || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$ci}` WHERE id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			wp_send_json_error( array( 'message' => '明細が見つかりません。' ), 404 );
		}
		ktp_dt_ensure_cost_payment_table();
		$t        = ktp_dt_cost_payment_table();
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE cost_item_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$paid_at  = $paid ? ( $existing && ! empty( $existing->paid_at ) ? $existing->paid_at : current_time( 'mysql' ) ) : null;
		if ( $existing ) {
			$wpdb->update( $t, array( 'paid' => $paid ? 1 : 0, 'paid_at' => $paid_at ), array( 'cost_item_id' => $id ) );
		} else {
			$wpdb->insert( $t, array( 'cost_item_id' => $id, 'paid' => $paid ? 1 : 0, 'paid_at' => $paid_at ) );
		}
		wp_send_json_success( array( 'paid' => $paid ) );
	}
);

/** レポートタブに 2 つのボタンと本文を差し込む */
add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) || ! ktp_dt_report_can() ) {
			return $output;
		}
		if ( ! isset( $_GET['tab_name'] ) || 'report' !== $_GET['tab_name'] || false === strpos( $output, 'ktp-report-controller__actions' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $output;
		}
		$current = isset( $_GET['report_type'] ) ? sanitize_key( wp_unslash( $_GET['report_type'] ) ) : 'sales'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$btn     = static function ( $type, $label ) use ( $current ) {
			$active = $current === $type;
			$style  = 'padding:6px 10px;font-size:12px;border:1px solid ' . ( $active ? '#1565c0' : '#ddd' ) . ';border-radius:3px;text-decoration:none;display:inline-flex;align-items:center;margin-right:4px;' . ( $active ? 'background:#1976d2;color:#fff;' : 'background:#fff;color:#333;' );
			return '<a href="' . esc_url( add_query_arg( array( 'tab_name' => 'report', 'report_type' => $type ), home_url( '/' ) ) ) . '" style="' . esc_attr( $style ) . '">' . esc_html( $label ) . '</a>';
		};
		$buttons = $btn( 'purchase', '仕入・支払' ) . $btn( 'cashflow', '資金繰り予定' );
		$output  = preg_replace( '#(</div>\s*<div class="ktp-report-controller__actions">)#', $buttons . '$1', $output, 1 );

		if ( in_array( $current, array( 'purchase', 'cashflow' ), true ) ) {
			$start = strpos( $output, '<div id="ktp_report_inner_content"' );
			$end   = false === $start ? false : strpos( $output, '</div><script src=', $start );
			if ( false !== $start && false !== $end ) {
				$open_end = strpos( $output, '>', $start ) + 1;
				$inner    = 'purchase' === $current ? ktp_dt_render_purchase_report() : ktp_dt_render_cashflow();
				$output   = substr( $output, 0, $open_end ) . $inner . substr( $output, $end );
			}
		}
		return $output;
	},
	12,
	2
);
