<?php
/**
 * 一括請求書の「請求済」を取り消して「完了」に戻す（KantanBiz の unmark-invoiced 相当）。
 *
 * 印刷ダイアログをキャンセルしても進捗は「請求済」に変わってしまうため、その取り消し用。
 * プラグイン本体の ktp_set_invoice_completed が動く直前に、これから変わる受注書の ID を控え、
 * 取り消しでは「そのとき変えたぶんだけ」を、いまも請求済のものに限って戻す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ktp_dt_invoice_undo_key( $client_id ) {
	return 'ktp_dt_invoiced_' . (int) $client_id . '_' . get_current_user_id();
}

/** 本体の処理より先に、請求済に変わる受注書の ID を控える（本体と同じ条件） */
add_action(
	'wp_ajax_ktp_set_invoice_completed',
	function () {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'ktpwp_access' ) ) {
			return;
		}
		$client_id = isset( $_POST['client_id'] ) ? absint( wp_unslash( $_POST['client_id'] ) ) : 0;
		if ( ! $client_id ) {
			return;
		}
		global $wpdb;
		$o   = $wpdb->prefix . 'ktp_order';
		$c   = $wpdb->prefix . 'ktp_client';
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT o.id FROM `{$o}` o LEFT JOIN `{$c}` c ON o.client_id = c.id
				WHERE o.client_id = %d AND o.progress = 4
				AND NOT ( o.payment_timing = 'prepay' OR ( ( o.payment_timing IS NULL OR o.payment_timing = '' ) AND c.payment_timing = 'prepay' ) )",
				$client_id
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		set_transient( ktp_dt_invoice_undo_key( $client_id ), array_map( 'intval', $ids ), 6 * HOUR_IN_SECONDS );
	},
	1
);

add_action(
	'wp_ajax_ktp_dt_unmark_invoiced',
	function () {
		if ( ! current_user_can( 'edit_posts' ) && ! current_user_can( 'ktpwp_access' ) ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'ktp_ajax_nonce' ) ) {
			wp_send_json_error( array( 'message' => 'セキュリティ検証に失敗しました。' ), 403 );
		}
		$client_id = isset( $_POST['client_id'] ) ? absint( wp_unslash( $_POST['client_id'] ) ) : 0;
		$ids       = $client_id ? get_transient( ktp_dt_invoice_undo_key( $client_id ) ) : false;
		if ( ! is_array( $ids ) || ! $ids ) {
			wp_send_json_error( array( 'message' => '取り消せる請求済の変更がありません。' ), 404 );
		}
		global $wpdb;
		$o            = $wpdb->prefix . 'ktp_order';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// いまも請求済のものだけ戻す（取り消し前に手で進捗を動かしていたら上書きしない）
		$reverted = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$o}` SET progress = 4 WHERE client_id = %d AND progress = 5 AND id IN ({$placeholders})",
				array_merge( array( $client_id ), $ids )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders
		if ( false === $reverted ) {
			wp_send_json_error( array( 'message' => 'DB更新に失敗しました。' ), 500 );
		}
		delete_transient( ktp_dt_invoice_undo_key( $client_id ) );
		if ( class_exists( 'KTPWP_List_Warning_Counts' ) && method_exists( 'KTPWP_List_Warning_Counts', 'invalidate' ) ) {
			KTPWP_List_Warning_Counts::invalidate();
		}
		wp_send_json_success( array( 'reverted' => (int) $reverted ) );
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		wp_enqueue_script( 'ktp-desktop-invoice-undo', WPMU_PLUGIN_URL . '/ktp-desktop/invoice-undo.js', array(), (string) @filemtime( WPMU_PLUGIN_DIR . '/ktp-desktop/invoice-undo.js' ), true );
		wp_localize_script( 'ktp-desktop-invoice-undo', 'ktpDtInvoiceUndo', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );
	}
);
