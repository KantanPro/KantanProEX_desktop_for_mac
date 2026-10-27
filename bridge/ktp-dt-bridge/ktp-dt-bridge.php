<?php
/**
 * Plugin Name: KantanProEX（DT）サイト受付
 * Description: Contact Form 7 のお問い合わせ・WooCommerce の注文・商品の申し込みをこのサイトで預かり、パソコンの KantanProEX（DT）が取りに来たら渡します。KantanProEX（DT）の公開商品を、ショートコードでこのサイトに表示します。
 * Version: 1.4.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: KantanPro
 * Author URI: https://www.kantanpro.com/
 * License: GPL-2.0-or-later
 * Text Domain: ktp-dt-bridge
 *
 * デスクトップ版はパソコンの中だけで動き、外から受け付ける口を持たない。
 * そこで、公開サイトで受けた問い合わせ・注文をこのプラグインが表（受付箱）に預かっておき、
 * デスクトップ版が接続キー付きで取りに来たときに渡す（デスクトップ版 → サイトへの一方向だけ）。
 *
 * 渡す形は KantanProEX 本体と合わせてある:
 * - Contact Form 7: get_posted_data() の値そのまま（KTPWP_Contact_Form::import_submission() が受け取る）
 * - WooCommerce: KTPWP_WooCommerce_Integration::order_payload() と同じ配列（import_order_payload() が受け取る）
 * - 公開商品: DT が商品を送ってくる（catalog.php）。申し込みは kind=public_order で預かり、DT が受注にする
 *   即時購入の商品は、DT が作った Stripe の決済ページへ「購入する」で進む（入金は DT が Stripe から直接受け取る）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_BRIDGE_VERSION   = '1.4.1';
const KTP_DT_BRIDGE_DB_OPT    = 'ktp_dt_bridge_db_version';
const KTP_DT_BRIDGE_KEY_OPT   = 'ktp_dt_bridge_key';
const KTP_DT_BRIDGE_SET_OPT   = 'ktp_dt_bridge_settings';
const KTP_DT_BRIDGE_PULL_OPT  = 'ktp_dt_bridge_last_pull';
const KTP_DT_BRIDGE_PAGE      = 'ktp-dt-bridge';
const KTP_DT_BRIDGE_NS        = 'ktp-dt-bridge/v1';
const KTP_DT_BRIDGE_MAX_BATCH = 50;
// 渡し終えた分は 7 日で消す（個人情報を長く置かない）。取りに来ないままの分は 180 日で消す
const KTP_DT_BRIDGE_KEEP_ACKED_DAYS   = 7;
const KTP_DT_BRIDGE_KEEP_PENDING_DAYS = 180;

require_once __DIR__ . '/catalog.php';

function ktp_dt_bridge_table() {
	global $wpdb;
	return $wpdb->prefix . 'ktp_dt_bridge_inbox';
}

function ktp_dt_bridge_settings() {
	$s = get_option( KTP_DT_BRIDGE_SET_OPT, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'cf7'     => ! isset( $s['cf7'] ) || ! empty( $s['cf7'] ),
		'woo'     => ! isset( $s['woo'] ) || ! empty( $s['woo'] ),
		'shop'    => ! isset( $s['shop'] ) || ! empty( $s['shop'] ),
		// KantanProEX 本体の既定（受け入れる言語は日本語だけ）に合わせる
		'ja_only' => ! isset( $s['ja_only'] ) || ! empty( $s['ja_only'] ),
	);
}

function ktp_dt_bridge_key() {
	return (string) get_option( KTP_DT_BRIDGE_KEY_OPT, '' );
}

function ktp_dt_bridge_new_key() {
	$key = 'ktpdt_' . wp_generate_password( 40, false, false );
	update_option( KTP_DT_BRIDGE_KEY_OPT, $key, false );
	return $key;
}

// ---- 有効化・表の用意 ----

function ktp_dt_bridge_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = ktp_dt_bridge_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			kind varchar(20) NOT NULL,
			payload longtext NOT NULL,
			created_at datetime NOT NULL,
			acked_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY acked_at (acked_at)
		) {$charset};"
	);
	update_option( KTP_DT_BRIDGE_DB_OPT, KTP_DT_BRIDGE_VERSION, false );
	if ( ktp_dt_bridge_key() === '' ) {
		ktp_dt_bridge_new_key();
	}
	if ( ! wp_next_scheduled( 'ktp_dt_bridge_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ktp_dt_bridge_cleanup' );
	}
}
register_activation_hook( __FILE__, 'ktp_dt_bridge_install' );

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'ktp_dt_bridge_cleanup' );
	}
);

// 更新でファイルだけ差し替わったとき（有効化フックが走らない）にも表を合わせる
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( KTP_DT_BRIDGE_DB_OPT ) !== KTP_DT_BRIDGE_VERSION ) {
			ktp_dt_bridge_install();
		}
	}
);

add_action(
	'ktp_dt_bridge_cleanup',
	function () {
		global $wpdb;
		$table = ktp_dt_bridge_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE acked_at IS NOT NULL AND acked_at < %s", gmdate( 'Y-m-d H:i:s', time() - KTP_DT_BRIDGE_KEEP_ACKED_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE acked_at IS NULL AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - KTP_DT_BRIDGE_KEEP_PENDING_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
);

/**
 * 受付箱に 1 件預ける。
 *
 * @param string $kind 'cf7' / 'woocommerce'。
 * @param array  $data 渡す中身。
 * @return bool
 */
function ktp_dt_bridge_enqueue( $kind, array $data ) {
	global $wpdb;
	$uuid = wp_generate_uuid4();
	$json = wp_json_encode(
		array(
			'v'           => 1,
			'uuid'        => $uuid,
			'kind'        => $kind,
			'received_at' => time(),
			'data'        => $data,
		)
	);
	if ( ! is_string( $json ) ) {
		return false;
	}
	return false !== $wpdb->insert(
		ktp_dt_bridge_table(),
		array(
			'uuid'       => $uuid,
			'kind'       => $kind,
			'payload'    => $json,
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s' )
	);
}

function ktp_dt_bridge_pending_count() {
	global $wpdb;
	$table = ktp_dt_bridge_table();
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE acked_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// ---- Contact Form 7 ----
// スパム対策は KantanProEX 本体（class-ktpwp-contact-form.php）と同じ判定。本体が同じサイトに入っていても混ざらないよう欄の名前は変えてある

function ktp_dt_bridge_sign_ts( $ts ) {
	return substr( hash_hmac( 'sha256', 'ktp-dt-bridge|' . (int) $ts, wp_salt( 'nonce' ) ), 0, 20 );
}

add_filter(
	'wpcf7_form_hidden_fields',
	function ( $fields ) {
		if ( ! ktp_dt_bridge_settings()['cf7'] ) {
			return $fields;
		}
		$fields             = is_array( $fields ) ? $fields : array();
		$ts                 = time();
		$fields['ktp_dt_hp'] = '';
		$fields['ktp_dt_ts'] = $ts . '-' . ktp_dt_bridge_sign_ts( $ts );
		return $fields;
	}
);

function ktp_dt_bridge_flatten( $value ) {
	if ( is_array( $value ) ) {
		$value = implode( ' ', array_filter( array_map( 'strval', $value ) ) );
	}
	return trim( (string) $value );
}

/** 文字の種類からおおまかな言語を見る（本体の detect_submission_language と同じ） */
function ktp_dt_bridge_detect_language( $text ) {
	$text = trim( (string) $text );
	if ( $text === '' ) {
		return 'unknown';
	}
	if ( preg_match( '/[\x{3040}-\x{309F}\x{30A0}-\x{30FF}]/u', $text ) ) {
		return 'ja';
	}
	if ( preg_match( '/[\x{AC00}-\x{D7A3}\x{0400}-\x{04FF}\x{0600}-\x{06FF}\x{0E00}-\x{0E7F}]/u', $text ) ) {
		return 'other';
	}
	// ひらがな・カタカナを伴わない漢字だけの文章は中国語とみなす
	if ( preg_match( '/[\x{4E00}-\x{9FFF}]/u', $text ) || preg_match( '/[A-Za-z]/', $text ) ) {
		return 'other';
	}
	return 'unknown';
}

/** 送信データから、件名・本文らしい欄の値を集める（言語の判定用） */
function ktp_dt_bridge_message_text( array $posted ) {
	$parts = array();
	foreach ( $posted as $key => $value ) {
		if ( preg_match( '/message|subject|title|body|inquiry|内容|本文|件名|問い合わせ/iu', (string) $key ) ) {
			$parts[] = ktp_dt_bridge_flatten( $value );
		}
	}
	return trim( implode( ' ', $parts ) );
}

add_filter(
	'wpcf7_validate',
	function ( $result, $tags ) {
		if ( ! ktp_dt_bridge_settings()['cf7'] || ! class_exists( 'WPCF7_Submission' ) || ! is_object( $result ) || ! method_exists( $result, 'invalidate' ) ) {
			return $result;
		}
		$submission = WPCF7_Submission::get_instance();
		$posted     = $submission ? $submission->get_posted_data() : array();
		if ( empty( $posted ) || ! is_array( $posted ) ) {
			return $result;
		}
		$first_tag = '';
		foreach ( (array) $tags as $tag ) {
			if ( is_object( $tag ) && ! empty( $tag->name ) ) {
				$first_tag = $tag->name;
				break;
			}
		}
		$spam = __( '送信内容を確認できませんでした。時間をおいて再度お試しください。', 'ktp-dt-bridge' );

		// 1) 見えない欄に値が入っていたらボット
		if ( ! empty( $_POST['ktp_dt_hp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$result->invalidate( $first_tag, $spam );
			return $result;
		}
		// 2) フォームを開いてから 3 秒未満の送信はボット
		$ts = isset( $_POST['ktp_dt_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['ktp_dt_ts'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $ts !== '' ) {
			$p = explode( '-', $ts, 2 );
			if ( count( $p ) === 2 && ctype_digit( $p[0] ) && hash_equals( ktp_dt_bridge_sign_ts( (int) $p[0] ), $p[1] ) && time() - (int) $p[0] < 3 ) {
				$result->invalidate( $first_tag, $spam );
				return $result;
			}
		}
		foreach ( $posted as $name => $value ) {
			$text = ktp_dt_bridge_flatten( $value );
			// 3) HTML タグの混入
			if ( preg_match( '/<\/?[a-zA-Z][a-zA-Z0-9]*(\s[^<>]*)?>/', $text ) === 1 ) {
				$result->invalidate( (string) $name, __( 'HTMLタグを含む内容は送信できません。', 'ktp-dt-bridge' ) );
				return $result;
			}
			// 4) URL が 3 つ以上
			$count = preg_match_all( '#(https?://|www\.)[^\s]+#i', $text );
			if ( $count !== false && $count > 2 ) {
				$result->invalidate( (string) $name, __( 'URLの記載が多いため送信できません。', 'ktp-dt-bridge' ) );
				return $result;
			}
		}
		// 5) 日本語以外のお問い合わせ（設定でオフにできる）
		if ( ktp_dt_bridge_settings()['ja_only'] && ktp_dt_bridge_detect_language( ktp_dt_bridge_message_text( $posted ) ) === 'other' ) {
			$result->invalidate( $first_tag, __( '現在、設定されている言語以外でのお問い合わせは受け付けておりません。', 'ktp-dt-bridge' ) );
		}
		return $result;
	},
	10,
	2
);

add_action(
	'wpcf7_mail_sent',
	function ( $contact_form ) {
		static $done = array();
		if ( ! ktp_dt_bridge_settings()['cf7'] || ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		$posted     = $submission ? $submission->get_posted_data() : array();
		if ( empty( $posted ) || ! is_array( $posted ) ) {
			return;
		}
		foreach ( array( 'ktp_dt_hp', 'ktp_dt_ts', 'ktp_hp_field', 'ktp_form_ts' ) as $k ) {
			unset( $posted[ $k ] );
		}
		$form_id = is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0;
		$sig     = md5( $form_id . wp_json_encode( $posted ) );
		if ( isset( $done[ $sig ] ) ) {
			return;
		}
		$done[ $sig ] = true;
		ktp_dt_bridge_enqueue(
			'cf7',
			array(
				'form_id'    => $form_id,
				'form_title' => is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '',
				'posted'     => $posted,
			)
		);
	}
);

// ---- WooCommerce ----

/** 税率の文字列（"10" / "10%" / "10.000"）を数にする。本体の parse_tax_rate_percent と同じ */
function ktp_dt_bridge_parse_rate( $rate ) {
	if ( $rate === null || $rate === '' ) {
		return null;
	}
	$str = str_replace( array( '%', '％' ), '', trim( (string) $rate ) );
	if ( $str === '' || ! is_numeric( $str ) ) {
		return null;
	}
	$f = (float) $str;
	return ( $f >= 0 && $f < 100 ) ? round( $f, 1 ) : null;
}

/** 注文の税率（明細から出せないときの予備）。本体の get_order_default_tax_rate と同じ */
function ktp_dt_bridge_order_default_rate( $order ) {
	foreach ( $order->get_items( 'tax' ) as $tax_item ) {
		if ( ! $tax_item instanceof WC_Order_Item_Tax ) {
			continue;
		}
		$rate = ktp_dt_bridge_parse_rate( $tax_item->get_rate_percent() );
		if ( $rate !== null ) {
			return $rate;
		}
		$rate_id = $tax_item->get_rate_id();
		if ( $rate_id && class_exists( 'WC_Tax' ) && method_exists( 'WC_Tax', 'get_rate_percent' ) ) {
			$rate = ktp_dt_bridge_parse_rate( WC_Tax::get_rate_percent( $rate_id ) );
			if ( $rate !== null ) {
				return $rate;
			}
		}
	}
	return null;
}

/** 明細の税率。本体の calc_line_tax_rate と同じ（8% / 10% に寄せる。非課税は null） */
function ktp_dt_bridge_line_rate( $subtotal, $subtax, $total, $tax, $fallback ) {
	$snap = function ( $rate ) {
		$r = round( $rate, 1 );
		if ( $r >= 7.5 && $r <= 8.5 ) {
			return 8.0;
		}
		if ( $r >= 9.5 && $r <= 10.5 ) {
			return 10.0;
		}
		return $r;
	};
	if ( $subtotal > 0 && $subtax > 0 ) {
		$rate = ( $subtax / $subtotal ) * 100;
		if ( $rate > 0 && $rate < 100 ) {
			return $snap( $rate );
		}
	}
	if ( $total > 0 && $tax > 0 ) {
		$rate_incl = ( $tax / ( $total - $tax ) ) * 100;
		if ( $rate_incl > 0 && $rate_incl < 100 ) {
			return $snap( $rate_incl );
		}
		$rate_excl = ( $tax / $total ) * 100;
		if ( $rate_excl > 0 && $rate_excl < 100 ) {
			return $snap( $rate_excl );
		}
	}
	if ( $tax > 0 || $subtotal > 0 || $total > 0 ) {
		return $fallback !== null ? $fallback : 10.0;
	}
	return null;
}

/** 注文を、本体の KTPWP_WooCommerce_Integration::order_payload() と同じ形にする */
function ktp_dt_bridge_order_payload( $order ) {
	$created  = $order->get_date_created();
	$fallback = ktp_dt_bridge_order_default_rate( $order );
	$items    = array();
	$all_dl   = true;
	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		$product = $item->get_product();
		if ( ! $product || ! $product->is_downloadable() ) {
			$all_dl = false;
		}
		$subtotal = (float) $item->get_subtotal();
		$total    = (float) $item->get_total();
		$items[]  = array(
			'name'     => $item->get_name(),
			'quantity' => (float) $item->get_quantity(),
			'subtotal' => $subtotal,
			'total'    => $total,
			'tax_rate' => ktp_dt_bridge_line_rate( $subtotal, (float) $item->get_subtotal_tax(), $total, (float) $item->get_total_tax(), $fallback ),
		);
	}
	return array(
		'id'      => (int) $order->get_id(),
		'number'  => (string) $order->get_order_number(),
		'created' => $created ? $created->getTimestamp() : time(),
		'billing' => array(
			'company'    => (string) $order->get_billing_company(),
			'first_name' => (string) $order->get_billing_first_name(),
			'last_name'  => (string) $order->get_billing_last_name(),
			'email'      => (string) $order->get_billing_email(),
			'phone'      => (string) $order->get_billing_phone(),
			'postcode'   => (string) $order->get_billing_postcode(),
			'state'      => (string) $order->get_billing_state(),
			'city'       => (string) $order->get_billing_city(),
			'address_1'  => (string) $order->get_billing_address_1(),
			'address_2'  => (string) $order->get_billing_address_2(),
		),
		'items'   => $items,
		// 1.3.0〜: 支払い済みで商品がすべてダウンロード商品なら、DT は入金済で取り込み進捗を固定する
		'is_paid'          => $order->is_paid(),
		'all_downloadable' => $all_dl && ! empty( $items ),
	);
}

/**
 * 注文を受付箱に預ける（1 注文 1 回）。
 * 明細がまだ無い時点（注文の作成途中）なら預けず、後の状態変更のときにもう一度試す。
 * ダウンロード商品だけの注文は、支払いが済んだ時点でもう一度だけ預ける（DT がその受注を入金済にして固定する）。
 *
 * @param int|WC_Order $order_or_id 注文。
 */
function ktp_dt_bridge_queue_wc_order( $order_or_id ) {
	static $done = array();
	if ( ! ktp_dt_bridge_settings()['woo'] || ! function_exists( 'wc_get_order' ) ) {
		return;
	}
	$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
	if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
		return;
	}
	$id = (int) $order->get_id();
	if ( $id <= 0 ) {
		return;
	}
	$queued      = (bool) $order->get_meta( '_ktp_dt_bridge_queued' );
	$paid_queued = (bool) $order->get_meta( '_ktp_dt_bridge_paid_queued' );
	if ( isset( $done[ $id ] ) || ( $queued && $paid_queued ) ) {
		return;
	}
	$payload = ktp_dt_bridge_order_payload( $order );
	if ( empty( $payload['items'] ) ) {
		return;
	}
	$paid_download = ! empty( $payload['is_paid'] ) && ! empty( $payload['all_downloadable'] );
	if ( $queued && ! $paid_download ) {
		return;
	}
	if ( ktp_dt_bridge_enqueue( 'woocommerce', $payload ) ) {
		$done[ $id ] = true;
		$order->update_meta_data( '_ktp_dt_bridge_queued', (string) time() );
		if ( $paid_download ) {
			$order->update_meta_data( '_ktp_dt_bridge_paid_queued', (string) time() );
		}
		$order->save_meta_data();
	}
}

// 本体の WooCommerce 連携と同じ場面で預ける（チェックアウト・管理画面での作成・状態変更）
add_action( 'woocommerce_checkout_order_created', 'ktp_dt_bridge_queue_wc_order', 20, 1 );
add_action(
	'woocommerce_new_order',
	function ( $order_id, $order = null ) {
		ktp_dt_bridge_queue_wc_order( $order instanceof WC_Order ? $order : $order_id );
	},
	20,
	2
);
foreach ( array( 'pending', 'on-hold', 'processing', 'completed' ) as $ktp_dt_bridge_status ) {
	add_action(
		'woocommerce_order_status_' . $ktp_dt_bridge_status,
		function ( $order_id, $order = null ) {
			ktp_dt_bridge_queue_wc_order( $order instanceof WC_Order ? $order : $order_id );
		},
		20,
		2
	);
}
unset( $ktp_dt_bridge_status );

// HPOS（注文の新しい保存方式）に対応している（注文は WC_Order の API だけで読む）
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// ---- デスクトップ版が取りに来る口（REST） ----

function ktp_dt_bridge_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
}

/** 接続キーを確かめる。間違いは IP ごとに 15 分で 10 回まで */
function ktp_dt_bridge_rest_auth( WP_REST_Request $request ) {
	$lock_key = 'ktp_dt_bridge_fail_' . md5( ktp_dt_bridge_client_ip() );
	$fails    = (int) get_transient( $lock_key );
	if ( $fails >= 10 ) {
		return new WP_Error( 'ktp_dt_bridge_locked', '接続キーの間違いが続いたため、しばらく受け付けません。', array( 'status' => 429 ) );
	}
	$given = (string) $request->get_header( 'x_ktp_bridge_key' );
	$key   = ktp_dt_bridge_key();
	if ( $key === '' || $given === '' || ! hash_equals( $key, $given ) ) {
		set_transient( $lock_key, $fails + 1, 15 * MINUTE_IN_SECONDS );
		return new WP_Error( 'ktp_dt_bridge_key', '接続キーが違います。', array( 'status' => 401 ) );
	}
	return true;
}

/** この要求が、受付の口（ktp-dt-bridge/v1/...）宛てか。/wp-json/ の形と ?rest_route= の形の両方を見る */
function ktp_dt_bridge_is_own_route() {
	$route = '';
	if ( isset( $GLOBALS['wp'] ) && is_object( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		$route = (string) $GLOBALS['wp']->query_vars['rest_route'];
	} elseif ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$route = sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	} else {
		$path   = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$prefix = '/' . rest_get_url_prefix() . '/';
		$pos    = strpos( $path, $prefix );
		$route  = false !== $pos ? substr( $path, $pos + strlen( $prefix ) - 1 ) : '';
	}
	return 0 === strpos( '/' . ltrim( $route, '/' ), '/' . KTP_DT_BRIDGE_NS . '/' );
}

/**
 * ほかのプラグインが「ログインしていない人の REST API」を止めていても、この受付の口だけは通す。
 * KantanProEX（WP）の REST 制限（?rest_route= の形を止める）や、セキュリティ系プラグインの REST 無効化が当たる。
 * 通したあとの認証は、各ルートの permission_callback（接続キー）で行う。
 */
add_filter(
	'rest_authentication_errors',
	function ( $result ) {
		return ( is_wp_error( $result ) && ktp_dt_bridge_is_own_route() ) ? null : $result;
	},
	PHP_INT_MAX
);

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			KTP_DT_BRIDGE_NS,
			'/inbox',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'ktp_dt_bridge_rest_auth',
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => KTP_DT_BRIDGE_MAX_BATCH,
						'minimum' => 0,
						'maximum' => KTP_DT_BRIDGE_MAX_BATCH,
					),
				),
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;
					update_option( KTP_DT_BRIDGE_PULL_OPT, time(), false );
					$limit   = (int) $request->get_param( 'limit' );
					$table   = ktp_dt_bridge_table();
					$entries = array();
					if ( $limit > 0 ) {
						$rows = $wpdb->get_col( $wpdb->prepare( "SELECT payload FROM {$table} WHERE acked_at IS NULL ORDER BY id ASC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						foreach ( (array) $rows as $json ) {
							$e = json_decode( (string) $json, true );
							if ( is_array( $e ) ) {
								$entries[] = $e;
							}
						}
					}
					$s = ktp_dt_bridge_settings();
					return rest_ensure_response(
						array(
							'bridge'  => KTP_DT_BRIDGE_VERSION,
							'site'    => get_bloginfo( 'name' ),
							'cf7'     => $s['cf7'] && class_exists( 'WPCF7_ContactForm' ),
							'woo'     => $s['woo'] && class_exists( 'WooCommerce' ),
							'shop'    => $s['shop'],
							'buy'     => true, // 1.2.0〜: 即時購入（Stripe の決済ページへの「購入する」）を表示できる
							'recurring_price' => true, // 1.4.0〜: 定期契約の「毎月」「初回費用」を値段の横に表示できる
							'paid_downloads' => true, // 1.3.0〜: WooCommerce の注文に支払い済み・ダウンロード商品の別を付けて渡す
							'catalog_hash'  => ktp_dt_bridge_catalog()['hash'],
							'catalog_count' => count( ktp_dt_bridge_catalog()['services'] ),
							'catalog_need_images' => ktp_dt_bridge_catalog_need_images(),
							'pending' => ktp_dt_bridge_pending_count(),
							'entries' => $entries,
						)
					);
				},
			)
		);
		register_rest_route(
			KTP_DT_BRIDGE_NS,
			'/ack',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'ktp_dt_bridge_rest_auth',
				'callback'            => function ( WP_REST_Request $request ) {
					global $wpdb;
					$uuids = $request->get_param( 'uuids' );
					$uuids = array_values( array_filter( (array) $uuids, 'wp_is_uuid' ) );
					$acked = 0;
					if ( $uuids ) {
						$table = ktp_dt_bridge_table();
						$in    = implode( ',', array_fill( 0, count( $uuids ), '%s' ) );
						$acked = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET acked_at = %s WHERE acked_at IS NULL AND uuid IN ({$in})", array_merge( array( current_time( 'mysql', true ) ), $uuids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
					}
					return rest_ensure_response(
						array(
							'acked'   => $acked,
							'pending' => ktp_dt_bridge_pending_count(),
						)
					);
				},
			)
		);
	}
);

// ---- 設定画面（設定 → KantanProEX 受付） ----

add_action(
	'admin_menu',
	function () {
		add_options_page( 'KantanProEX（DT）サイト受付', 'KantanProEX 受付', 'manage_options', KTP_DT_BRIDGE_PAGE, 'ktp_dt_bridge_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . KTP_DT_BRIDGE_PAGE ) ) . '">設定</a>' );
		return $links;
	}
);

add_action(
	'admin_post_ktp_dt_bridge_save',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'ktp_dt_bridge_save' );
		if ( ! empty( $_POST['new_key'] ) ) {
			ktp_dt_bridge_new_key();
			$msg = 'key';
		} else {
			update_option(
				KTP_DT_BRIDGE_SET_OPT,
				array(
					'cf7'     => ! empty( $_POST['cf7'] ),
					'woo'     => ! empty( $_POST['woo'] ),
					'shop'    => ! empty( $_POST['shop'] ),
					'ja_only' => ! empty( $_POST['ja_only'] ),
				),
				false
			);
			$msg = 'saved';
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . KTP_DT_BRIDGE_PAGE . '&ktp_dt_msg=' . $msg ) );
		exit;
	}
);

function ktp_dt_bridge_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( ktp_dt_bridge_key() === '' ) {
		ktp_dt_bridge_install();
	}
	$s       = ktp_dt_bridge_settings();
	$last    = (int) get_option( KTP_DT_BRIDGE_PULL_OPT, 0 );
	$https   = 0 === strpos( home_url( '/' ), 'https://' );
	$msg     = isset( $_GET['ktp_dt_msg'] ) ? sanitize_key( wp_unslash( $_GET['ktp_dt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$has_cf7 = class_exists( 'WPCF7_ContactForm' );
	$has_woo = class_exists( 'WooCommerce' );
	?>
<div class="wrap">
	<h1>KantanProEX（DT）サイト受付</h1>
	<?php if ( 'saved' === $msg ) : ?>
		<div class="notice notice-success is-dismissible"><p>保存しました。</p></div>
	<?php elseif ( 'key' === $msg ) : ?>
		<div class="notice notice-warning is-dismissible"><p>接続キーを作り直しました。KantanProEX（DT）の「サイトからの受注」に、新しいキーを入れ直してください。</p></div>
	<?php endif; ?>
	<?php if ( ! $https ) : ?>
		<div class="notice notice-error"><p>このサイトは https ではありません。接続キーやお客様の情報が暗号化されずに流れるため、KantanProEX（DT）からは接続できません（https にしてください）。</p></div>
	<?php endif; ?>

	<p>このサイトの Contact Form 7 のお問い合わせ・WooCommerce の注文・商品の申し込みを、ここで預かっておきます。パソコンの KantanProEX（DT）が 15 分ごと（と起動したとき）に取りに来て、受注として取り込みます。パソコンを閉じている間の分も、次に開いたときに届きます。</p>

	<h2>KantanProEX（DT）に入れる内容</h2>
	<p>KantanProEX（DT）の「KantanProEX 設定」→「サイトからの受注」に、次の 2 つを入れます。</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">サイトのアドレス</th>
			<td><input type="text" readonly class="regular-text code" value="<?php echo esc_attr( home_url( '/' ) ); ?>" onclick="this.select()"></td>
		</tr>
		<tr>
			<th scope="row">接続キー</th>
			<td>
				<input type="text" readonly class="large-text code" id="ktp-dt-key" value="<?php echo esc_attr( ktp_dt_bridge_key() ); ?>" onclick="this.select()">
				<p class="description">このキーを知っている人は、預かっているお問い合わせ・注文を読めます。人に教えないでください。漏れたときは下の「接続キーを作り直す」を押します。</p>
			</td>
		</tr>
	</table>

	<h2>受け付けるもの</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ktp_dt_bridge_save">
		<?php wp_nonce_field( 'ktp_dt_bridge_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Contact Form 7</th>
				<td>
					<label><input type="checkbox" name="cf7" value="1" <?php checked( $s['cf7'] ); ?>> お問い合わせを預かる</label>
					<?php if ( ! $has_cf7 ) : ?><p class="description">Contact Form 7 は有効になっていません。</p><?php endif; ?>
					<p><label><input type="checkbox" name="ja_only" value="1" <?php checked( $s['ja_only'] ); ?>> 日本語以外のお問い合わせは受け付けない（スパム対策）</label></p>
				</td>
			</tr>
			<tr>
				<th scope="row">公開商品</th>
				<td>
					<label><input type="checkbox" name="shop" value="1" <?php checked( $s['shop'] ); ?>> 商品の申し込みを預かる</label>
					<p class="description">KantanProEX（DT）で「サイト公開」にした商品を、固定ページなどに <code>[ktp_dt_products]</code> と書いて表示します。申し込みは KantanProEX（DT）が取りに来て受注にします。</p>
				</td>
			</tr>
			<tr>
				<th scope="row">WooCommerce</th>
				<td>
					<label><input type="checkbox" name="woo" value="1" <?php checked( $s['woo'] ); ?>> 注文を預かる</label>
					<?php if ( ! $has_woo ) : ?><p class="description">WooCommerce は有効になっていません。</p><?php endif; ?>
				</td>
			</tr>
		</table>
		<?php submit_button( '保存' ); ?>
	</form>

	<h2>状態</h2>
	<?php $ktp_cat = ktp_dt_bridge_catalog(); ?>
	<p>KantanProEX（DT）から届いている公開商品: <strong><?php echo esc_html( number_format_i18n( count( $ktp_cat['services'] ) ) ); ?> 件</strong>
	<?php if ( ! empty( $ktp_cat['updated'] ) ) : ?>（最後に更新された時刻: <?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $ktp_cat['updated'] ) ); ?>）<?php endif; ?></p>
	<p>まだ渡していないもの: <strong><?php echo esc_html( number_format_i18n( ktp_dt_bridge_pending_count() ) ); ?> 件</strong><br>
	KantanProEX（DT）が最後に取りに来た時刻: <strong><?php echo $last ? esc_html( wp_date( 'Y-m-d H:i', $last ) ) : 'まだありません'; ?></strong></p>
	<p class="description">渡し終えたものは <?php echo (int) KTP_DT_BRIDGE_KEEP_ACKED_DAYS; ?> 日後に、このサイトから消します。</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('接続キーを作り直すと、今のキーでは接続できなくなります。続けますか？');">
		<input type="hidden" name="action" value="ktp_dt_bridge_save">
		<input type="hidden" name="new_key" value="1">
		<?php wp_nonce_field( 'ktp_dt_bridge_save' ); ?>
		<?php submit_button( '接続キーを作り直す', 'secondary' ); ?>
	</form>
</div>
	<?php
}
