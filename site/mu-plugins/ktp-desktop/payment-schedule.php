<?php
/**
 * 支払・入金の予定日の計算（KantanBiz の SupplierPaymentSchedule / ClientPaymentSchedule の移植）。
 *
 * 協力会社の締日・支払条件から原価明細の「締め期間」と「支払予定日」を、
 * 顧客の締日・支払条件から受注の「入金予定日」を出す。画面を持たない計算だけのファイル。
 * 原価明細ごとの「支払済」「支払予定日の手動指定」「締め基準日の手動指定」は DT 専用テーブルに持つ。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_COST_PAYMENT_DB_OPTION = 'ktp_desktop_cost_payment_db';

function ktp_dt_cost_payment_table() {
	global $wpdb;
	return $wpdb->prefix . 'ktp_dt_cost_payment';
}

/** 支払済などを持つ DT 専用テーブル（KantanBiz の order_cost_items.payment_* の移し先） */
function ktp_dt_ensure_cost_payment_table() {
	if ( '1' === get_option( KTP_DT_COST_PAYMENT_DB_OPTION ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = ktp_dt_cost_payment_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			cost_item_id bigint(20) unsigned NOT NULL,
			paid tinyint(1) NOT NULL DEFAULT 0,
			paid_at datetime DEFAULT NULL,
			due_date_override date DEFAULT NULL,
			closing_reference_date date DEFAULT NULL,
			PRIMARY KEY  (cost_item_id)
		) {$charset};"
	);
	update_option( KTP_DT_COST_PAYMENT_DB_OPTION, '1', true );
}
add_action( 'init', 'ktp_dt_ensure_cost_payment_table', 20 );

function ktp_dt_ps_today() {
	return new DateTimeImmutable( 'today', wp_timezone() );
}

function ktp_dt_ps_date( $value ) {
	$value = trim( (string) $value );
	if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $value, $m ) || '0000-00-00' === substr( $value, 0, 10 ) ) {
		return null;
	}
	try {
		return new DateTimeImmutable( $m[1] . '-' . $m[2] . '-' . $m[3], wp_timezone() );
	} catch ( Exception $e ) {
		return null;
	}
}

/** 「末」「末日」「25」などの日指定を、base の月の日付にする */
function ktp_dt_ps_resolve_day( DateTimeImmutable $base, $day_raw ) {
	$day = trim( (string) $day_raw );
	if ( '' === $day || 'なし' === $day ) {
		return null;
	}
	if ( in_array( $day, array( '末', '末日' ), true ) ) {
		return $base->modify( 'last day of this month' )->setTime( 0, 0 );
	}
	$num = (int) preg_replace( '/[^0-9]/', '', $day );
	if ( $num < 1 ) {
		return null;
	}
	$days_in_month = (int) $base->format( 't' );
	return $base->modify( 'first day of this month' )->setTime( 0, 0 )->modify( '+' . ( min( $num, $days_in_month ) - 1 ) . ' days' );
}

/** 月に足す（月末超えを丸める。Carbon の addMonthsNoOverflow 相当） */
function ktp_dt_ps_add_months( DateTimeImmutable $d, $months ) {
	$first = $d->modify( 'first day of this month' );
	$target = $first->modify( ( $months >= 0 ? '+' : '' ) . $months . ' months' );
	$day = min( (int) $d->format( 'j' ), (int) $target->format( 't' ) );
	return $target->setDate( (int) $target->format( 'Y' ), (int) $target->format( 'n' ), $day )->setTime( 0, 0 );
}

function ktp_dt_ps_month_offset( $raw ) {
	$v = trim( (string) $raw );
	if ( '' === $v ) {
		return null;
	}
	if ( in_array( $v, array( '当月', '0', '0ヶ月', '同月' ), true ) ) {
		return 0;
	}
	if ( in_array( $v, array( '翌月', '1', '1ヶ月', '翌1ヶ月' ), true ) ) {
		return 1;
	}
	if ( in_array( $v, array( '翌々月', '2', '2ヶ月' ), true ) ) {
		return 2;
	}
	$num = (int) preg_replace( '/[^0-9]/', '', $v );
	return $num >= 0 ? $num : null;
}

/** 基準日が属する締め日（基準日が当月の締め日を過ぎていれば翌月の締め日） */
function ktp_dt_ps_closing_for_reference( DateTimeImmutable $reference, $closing_day_raw ) {
	$this_month = ktp_dt_ps_resolve_day( $reference, $closing_day_raw );
	if ( null === $this_month ) {
		return null;
	}
	if ( $reference <= $this_month ) {
		return $this_month;
	}
	return ktp_dt_ps_resolve_day( ktp_dt_ps_add_months( $reference, 1 ), $closing_day_raw );
}

/** 締め日から、支払条件（支払月・支払日）で出した支払日 */
function ktp_dt_ps_due_from_closing( DateTimeImmutable $closing, $payment_month_raw, $payment_day_raw ) {
	if ( '' === trim( (string) $payment_month_raw ) || '' === trim( (string) $payment_day_raw ) ) {
		return null;
	}
	$offset = ktp_dt_ps_month_offset( $payment_month_raw );
	if ( null === $offset ) {
		return null;
	}
	return ktp_dt_ps_resolve_day( ktp_dt_ps_add_months( $closing, $offset ), $payment_day_raw );
}

/**
 * 原価明細の基準日: 手動指定 → 仕入完了日 → 発注日 → 仕入ステータスの日付。
 *
 * @param object $item cost item 行（purchase_status_dates / purchase_status_date / closing_reference_date を持つ）
 */
function ktp_dt_ps_reference_date( $item ) {
	if ( ! empty( $item->closing_reference_date ) ) {
		$manual = ktp_dt_ps_date( $item->closing_reference_date );
		if ( $manual ) {
			return $manual;
		}
	}
	return ktp_dt_ps_automatic_reference_date( $item );
}

function ktp_dt_ps_automatic_reference_date( $item ) {
	$history = json_decode( (string) ( $item->purchase_status_dates ?? '' ), true );
	$history = is_array( $history ) ? $history : array();
	foreach ( array( 'completed', 'ordered' ) as $status ) {
		$d = ktp_dt_ps_date( $history[ $status ] ?? '' );
		if ( $d ) {
			return $d;
		}
	}
	return ktp_dt_ps_date( $item->purchase_status_date ?? '' );
}

/**
 * 原価明細の締め期間と支払予定日。
 *
 * @return array{mode:string,period_key:string,label:string,period_start:?DateTimeImmutable,period_end:?DateTimeImmutable,payment_due:?DateTimeImmutable,payment_due_is_manual:bool}
 */
function ktp_dt_ps_closing_period( $item, $supplier ) {
	$manual_due = ! empty( $item->due_date_override ) ? ktp_dt_ps_date( $item->due_date_override ) : null;
	$due_for    = static function ( $closing ) use ( $manual_due, $supplier ) {
		if ( $manual_due ) {
			return $manual_due;
		}
		if ( ! $supplier || null === $closing ) {
			return null;
		}
		return ktp_dt_ps_due_from_closing( $closing, $supplier->payment_month ?? '', $supplier->payment_day ?? '' );
	};
	$reference = ktp_dt_ps_reference_date( $item );
	if ( null === $reference ) {
		return array(
			'mode'                  => 'calendar',
			'period_key'            => 'unknown',
			'label'                 => '基準日が未設定',
			'period_start'          => null,
			'period_end'            => null,
			'payment_due'           => $due_for( null ),
			'payment_due_is_manual' => (bool) $manual_due,
		);
	}
	$closing_raw = trim( (string) ( $supplier->closing_day ?? '' ) );
	$period_end  = ( '' === $closing_raw || 'なし' === $closing_raw ) ? null : ktp_dt_ps_closing_for_reference( $reference, $closing_raw );
	if ( null === $period_end ) {
		$start = $reference->modify( 'first day of this month' )->setTime( 0, 0 );
		$end   = $reference->modify( 'last day of this month' )->setTime( 0, 0 );
		return array(
			'mode'                  => 'calendar',
			'period_key'            => $end->format( 'Y-m' ),
			'label'                 => $end->format( 'Y年n月' ),
			'period_start'          => $start,
			'period_end'            => $end,
			'payment_due'           => $due_for( $end ),
			'payment_due_is_manual' => (bool) $manual_due,
		);
	}
	$prev_closing = ktp_dt_ps_resolve_day( ktp_dt_ps_add_months( $period_end, -1 ), $closing_raw );
	return array(
		'mode'                  => 'closing',
		'period_key'            => $period_end->format( 'Y-m-d' ),
		'label'                 => $period_end->format( 'Y年n月' ) . '分（' . $period_end->format( 'Y/n/j' ) . ' 締め）',
		'period_start'          => $prev_closing ? $prev_closing->modify( '+1 day' ) : null,
		'period_end'            => $period_end,
		'payment_due'           => $due_for( $period_end ),
		'payment_due_is_manual' => (bool) $manual_due,
	);
}

/**
 * 受注の入金予定日（完了日を基準に顧客の締日・支払条件で計算。前払いは対象外）。
 *
 * @param object $order  completion_date を持つ行
 * @param object $client closing_day / payment_month / payment_day / payment_timing を持つ行
 */
function ktp_dt_ps_client_payment_due( $order, $client ) {
	if ( ! $client || empty( $order->completion_date ) ) {
		return null;
	}
	$timing = ! empty( $order->payment_timing ) ? $order->payment_timing : ( $client->payment_timing ?? '' );
	if ( 'prepay' === $timing ) {
		return null;
	}
	$closing_raw = trim( (string) ( $client->closing_day ?? '' ) );
	if ( '' === $closing_raw || '' === trim( (string) ( $client->payment_month ?? '' ) ) || '' === trim( (string) ( $client->payment_day ?? '' ) ) ) {
		return null;
	}
	$completion = ktp_dt_ps_date( $order->completion_date );
	if ( ! $completion ) {
		return null;
	}
	$closing = ktp_dt_ps_closing_for_reference( $completion, $closing_raw );
	return $closing ? ktp_dt_ps_due_from_closing( $closing, $client->payment_month, $client->payment_day ) : null;
}
