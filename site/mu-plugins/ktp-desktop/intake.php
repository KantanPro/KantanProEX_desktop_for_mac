<?php
/**
 * サイトからの受注（公開サイトの Contact Form 7 のお問い合わせ・WooCommerce の注文を取り込む）。
 *
 * デスクトップ版には外から受け付ける口がないので、公開サイトに受付プラグイン（bridge/ktp-dt-bridge）を入れてもらい、
 * そこに預けられた分をこちらから取りに行く（このパソコン → サイトへの一方向。パソコンをネットに公開しない）。
 *
 * - 既定はオフ。オンにしてサイトのアドレスと接続キーを入れたときだけ、そのサイトへの通信を許す（ktp_desktop_allowed_hosts）
 * - 取りに行くのは wp-cron（Electron が 5 分ごとに回す）で 15 分ごと。起動直後の cron でも、期限が過ぎていれば走る
 * - 取り込みは KantanProEX 本体の処理をそのまま使う（CF7: import_submission、WooCommerce: import_order_payload）。
 *   WP 版でサイトから直接受けたときと同じ顧客の照合・明細・税率・スタッフチャット・通知メールになる
 * - 取り込めたものだけ、取り込んだ印（uuid）を残してからサイトへ「受け取った」と返す。返せなかった分は次回また届くが、印があるので二重にならない
 * - 取り込めなかったものは返さず、次回もう一度試す
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_INTAKE_OPT     = 'ktp_dt_intake';
const KTP_DT_INTAKE_STATE   = 'ktp_dt_intake_state';
const KTP_DT_INTAKE_DONE    = 'ktp_dt_intake_done';
const KTP_DT_INTAKE_LOG     = 'ktp_dt_intake_log';
const KTP_DT_INTAKE_UNSEEN  = 'ktp_dt_intake_unseen';
const KTP_DT_INTAKE_PAGE    = 'ktp-dt-intake';
const KTP_DT_INTAKE_NONCE   = 'ktp_dt_intake';
const KTP_DT_INTAKE_HOOK    = 'ktp_dt_intake_poll';
const KTP_DT_INTAKE_BATCH   = 50;
const KTP_DT_INTAKE_KEEP    = 2000; // 取り込んだ印を覚えておく件数
const KTP_DT_INTAKE_LOG_MAX = 50;

function ktp_dt_intake_settings() {
	$s = get_option( KTP_DT_INTAKE_OPT, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'enabled' => ! empty( $s['enabled'] ),
		'url'     => isset( $s['url'] ) ? (string) $s['url'] : '',
		'key'     => isset( $s['key'] ) ? (string) $s['key'] : '',
		// 公開商品をサイトに掲載するか（既定はオン。「サイト公開」にしたサービスだけが対象）
		'catalog' => ! isset( $s['catalog'] ) || ! empty( $s['catalog'] ),
	);
}

function ktp_dt_intake_state() {
	$s = get_option( KTP_DT_INTAKE_STATE, array() );
	return is_array( $s ) ? $s : array();
}

/** localhost など、このパソコンの中のサイト（検証用）。ここだけは http でも接続する */
function ktp_dt_intake_is_local_host( $host ) {
	return in_array( strtolower( (string) $host ), array( 'localhost', '127.0.0.1', '::1' ), true );
}

/** サイトのアドレスを確かめて整える。https でないもの（このパソコンの中を除く）は使わない */
function ktp_dt_intake_normalize_url( $url ) {
	$url = trim( (string) $url );
	if ( $url === '' ) {
		return '';
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		$url = 'https://' . $url;
	}
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return new WP_Error( 'ktp_dt_intake_url', 'サイトのアドレスを正しく入れてください（例: https://example.com/）。' );
	}
	$scheme = strtolower( (string) $parts['scheme'] );
	if ( 'https' !== $scheme && ! ktp_dt_intake_is_local_host( $parts['host'] ) ) {
		return new WP_Error( 'ktp_dt_intake_url', 'サイトのアドレスは https で始まるものにしてください（接続キーとお客様の情報を暗号化して受け取るため）。' );
	}
	return untrailingslashit( esc_url_raw( $url ) ) . '/';
}

/** 通信を許すサイトのホスト名（オンで、アドレスが入っているときだけ）。ktp-desktop.php の遮断から外す */
function ktp_dt_intake_host() {
	$s = ktp_dt_intake_settings();
	if ( ! $s['enabled'] || $s['url'] === '' ) {
		return '';
	}
	return strtolower( (string) wp_parse_url( $s['url'], PHP_URL_HOST ) );
}

/** 受付プラグインの REST の URL。パーマリンクの設定に左右されないよう ?rest_route= の形を使う */
function ktp_dt_intake_endpoint( $route, array $query = array() ) {
	$s = ktp_dt_intake_settings();
	return add_query_arg( array_merge( array( 'rest_route' => '/ktp-dt-bridge/v1/' . $route ), $query ), $s['url'] );
}

/**
 * 受付プラグインに問い合わせる。
 *
 * @return array|WP_Error 応答の JSON。
 */
function ktp_dt_intake_request( $method, $route, array $query = array(), $body = null ) {
	$s = ktp_dt_intake_settings();
	if ( $s['url'] === '' || $s['key'] === '' ) {
		return new WP_Error( 'ktp_dt_intake_conf', 'サイトのアドレスと接続キーを入れてください。' );
	}
	$args = array(
		'method'  => $method,
		'timeout' => 20,
		'headers' => array(
			'X-KTP-Bridge-Key' => $s['key'],
			'Accept'           => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode( $body );
	}
	$res = wp_remote_request( ktp_dt_intake_endpoint( $route, $query ), $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'ktp_dt_intake_net', 'サイトにつながりませんでした（' . $res->get_error_message() . '）。インターネットの接続とサイトのアドレスを確かめてください。' );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 401 === $code ) {
		return new WP_Error( 'ktp_dt_intake_key', '接続キーが違います。サイトの「設定」→「KantanProEX 受付」に出ているキーを入れ直してください。' );
	}
	if ( 429 === $code ) {
		return new WP_Error( 'ktp_dt_intake_locked', '接続キーの間違いが続いたため、サイトが 15 分ほど受け付けを止めています。キーを確かめて、しばらくしてから試してください。' );
	}
	if ( 404 === $code || ( is_array( $json ) && isset( $json['code'] ) && 'rest_no_route' === $json['code'] ) ) {
		return new WP_Error( 'ktp_dt_intake_noplugin', 'サイトに受付プラグイン（KantanProEX（DT）サイト受付）が見つかりません。サイトに入れて有効にしてください。' );
	}
	if ( $code < 200 || $code >= 300 || ! is_array( $json ) ) {
		return new WP_Error( 'ktp_dt_intake_http', 'サイトから思わぬ応答がありました（HTTP ' . $code . '）。サイトのセキュリティ設定で REST API が止められていないか確かめてください。' );
	}
	return $json;
}

/** 取り込みの記録（設定画面に新しい順で出す） */
function ktp_dt_intake_log( array $row ) {
	$log = get_option( KTP_DT_INTAKE_LOG, array() );
	$log = is_array( $log ) ? $log : array();
	array_unshift( $log, array_merge( array( 'time' => time() ), $row ) );
	update_option( KTP_DT_INTAKE_LOG, array_slice( $log, 0, KTP_DT_INTAKE_LOG_MAX ), false );
}

/** 取り込んだ印（uuid → 時刻） */
function ktp_dt_intake_done() {
	$done = get_option( KTP_DT_INTAKE_DONE, array() );
	return is_array( $done ) ? $done : array();
}

function ktp_dt_intake_mark_done( $uuid ) {
	$done          = ktp_dt_intake_done();
	$done[ $uuid ] = time();
	if ( count( $done ) > KTP_DT_INTAKE_KEEP ) {
		arsort( $done );
		$done = array_slice( $done, 0, KTP_DT_INTAKE_KEEP, true );
	}
	update_option( KTP_DT_INTAKE_DONE, $done, false );
}

/**
 * 公開商品の画像（ファイルがあれば、縮小した JPEG と元ファイルのハッシュ）。
 * 初期画像（no-image）は掲載しない。ファイルは、画像の URL からこのアプリのフォルダ内の場所を引く。
 *
 * @param KTPWP_Service_DB $db  サービス DB。
 * @param object           $row サービス行。
 * @return array{hash: string, path: string}|null
 */
function ktp_dt_intake_service_image( $db, $row ) {
	$url = (string) $db->resolve_image_url( (int) $row->id, isset( $row->image_url ) ? (string) $row->image_url : '' );
	if ( $url === '' || false !== strpos( $url, 'no-image' ) || $url === (string) $db->get_default_image_url() ) {
		return null;
	}
	$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	$file = realpath( untrailingslashit( ABSPATH ) . '/' . ltrim( $path, '/' ) );
	$root = realpath( ABSPATH );
	if ( ! $file || ! $root || 0 !== strpos( $file, $root ) || ! is_file( $file ) ) {
		return null;
	}
	return array( 'hash' => (string) md5_file( $file ), 'path' => $file );
}

/** サイトに送る公開商品の一覧を作る（hash は、中身と画像の変更を見分けるためのもの） */
function ktp_dt_intake_catalog_build() {
	if ( ! class_exists( 'KTPWP_Service_DB' ) ) {
		return null;
	}
	$db   = KTPWP_Service_DB::get_instance();
	$rows = $db->get_services( 'service', array( 'is_public' => true, 'limit' => 0, 'order_by' => 'id', 'order' => 'DESC' ) );
	$out  = array();
	$imgs = array();
	foreach ( (array) $rows as $row ) {
		$avail = class_exists( 'KTPWP_Contract_Service_Public_Availability' )
			? KTPWP_Contract_Service_Public_Availability::get_public_availability( (int) $row->id, $row )
			: array( 'acceptance_open' => true, 'status_label' => '' );
		$img   = ktp_dt_intake_service_image( $db, $row );
		if ( $img ) {
			$imgs[ (int) $row->id ] = $img['path'];
		}
		$out[] = array(
			'id'             => (int) $row->id,
			'name'           => (string) $row->service_name,
			'price'          => (float) $row->price,
			'tax_rate'       => ( isset( $row->tax_rate ) && '' !== (string) $row->tax_rate && null !== $row->tax_rate ) ? (float) $row->tax_rate : null,
			'unit'           => (string) $row->unit,
			'category'       => (string) $row->category,
			'memo'           => (string) $row->memo,
			'public_html'    => KTPWP_Service_DB::format_public_html_for_display( $row->public_html ?? '' ),
			'quantity_fixed' => KTPWP_Service_DB::is_public_quantity_fixed( $row ),
			'open'           => ! empty( $avail['acceptance_open'] ),
			'status_label'   => (string) ( $avail['status_label'] ?? '' ),
			'image_hash'     => $img ? $img['hash'] : '',
		);
	}
	return array(
		'hash'     => md5( (string) wp_json_encode( $out ) ),
		'services' => $out,
		'images'   => $imgs,
	);
}

/** 画像を 900px 以内の JPEG に縮めて base64 にする（サイトの容量と通信量を減らす） */
function ktp_dt_intake_image_payload( $path ) {
	$editor = wp_get_image_editor( $path );
	if ( is_wp_error( $editor ) ) {
		return '';
	}
	$editor->resize( 900, 900, false );
	$editor->set_quality( 82 );
	$tmp   = wp_tempnam( 'ktp-dt-img' );
	$saved = $editor->save( $tmp, 'image/jpeg' );
	$data  = ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) ? (string) file_get_contents( $saved['path'] ) : '';
	foreach ( array( $tmp, is_array( $saved ) ? ( $saved['path'] ?? '' ) : '' ) as $f ) {
		if ( $f && is_file( $f ) ) {
			@unlink( $f );
		}
	}
	return $data === '' ? '' : base64_encode( $data );
}

/**
 * 公開商品をサイトに合わせる。サイトの状態（取得応答の catalog_hash・catalog_need_images）を見て、
 * 変わっているときだけ商品を送り、足りない画像だけ送る。
 *
 * @param array $site 受付プラグインの取得応答。
 * @return array{count: int, pushed: bool, error: string}
 */
function ktp_dt_intake_catalog_sync( array $site ) {
	$none = array( 'count' => 0, 'pushed' => false, 'error' => '' );
	if ( ! ktp_dt_intake_settings()['catalog'] || ! isset( $site['catalog_hash'] ) ) {
		return $none; // 受付プラグインが古く、商品に対応していない
	}
	$cat = ktp_dt_intake_catalog_build();
	if ( ! $cat ) {
		return $none;
	}
	$need   = isset( $site['catalog_need_images'] ) && is_array( $site['catalog_need_images'] ) ? array_map( 'intval', $site['catalog_need_images'] ) : array();
	$pushed = false;
	if ( (string) $site['catalog_hash'] !== $cat['hash'] ) {
		$res = ktp_dt_intake_request( 'POST', 'catalog', array(), array( 'hash' => $cat['hash'], 'services' => $cat['services'] ) );
		if ( is_wp_error( $res ) ) {
			return array( 'count' => count( $cat['services'] ), 'pushed' => false, 'error' => '公開商品をサイトに送れませんでした: ' . $res->get_error_message() );
		}
		$pushed = true;
		$need   = isset( $res['need_images'] ) && is_array( $res['need_images'] ) ? array_map( 'intval', $res['need_images'] ) : array();
	}
	foreach ( $need as $id ) {
		$hash = '';
		foreach ( $cat['services'] as $s ) {
			if ( (int) $s['id'] === $id ) {
				$hash = (string) $s['image_hash'];
			}
		}
		if ( $hash === '' || empty( $cat['images'][ $id ] ) ) {
			continue;
		}
		$data = ktp_dt_intake_image_payload( $cat['images'][ $id ] );
		if ( $data === '' ) {
			continue;
		}
		$res = ktp_dt_intake_request( 'POST', 'catalog-image', array(), array( 'id' => $id, 'hash' => $hash, 'data' => $data ) );
		if ( is_wp_error( $res ) ) {
			return array( 'count' => count( $cat['services'] ), 'pushed' => $pushed, 'error' => '商品の画像をサイトに送れませんでした: ' . $res->get_error_message() );
		}
		$pushed = true;
	}
	return array( 'count' => count( $cat['services'] ), 'pushed' => $pushed, 'error' => '' );
}

/**
 * 預かり分 1 件を受注にする。
 *
 * @param array $entry 受付プラグインの 1 件（uuid / kind / received_at / data）。
 * @return array{ok: bool, order_id: int, label: string, message: string}
 */
function ktp_dt_intake_import_entry( array $entry ) {
	$kind = isset( $entry['kind'] ) ? (string) $entry['kind'] : '';
	$data = isset( $entry['data'] ) && is_array( $entry['data'] ) ? $entry['data'] : array();
	$at   = isset( $entry['received_at'] ) ? (int) $entry['received_at'] : 0;

	if ( 'cf7' === $kind ) {
		$posted = isset( $data['posted'] ) && is_array( $data['posted'] ) ? $data['posted'] : array();
		$label  = 'お問い合わせ' . ( ! empty( $data['form_title'] ) ? '（' . sanitize_text_field( (string) $data['form_title'] ) . '）' : '' );
		if ( ! class_exists( 'KTPWP_Contact_Form' ) || ! method_exists( 'KTPWP_Contact_Form', 'import_submission' ) ) {
			return array( 'ok' => false, 'order_id' => 0, 'label' => $label, 'message' => 'KantanProEX 本体が古く、お問い合わせを取り込めません。アプリを新しくしてください。' );
		}
		$order_id = KTPWP_Contact_Form::get_instance()->import_submission(
			$posted,
			isset( $data['form_id'] ) ? (int) $data['form_id'] : 0,
			array(
				'received_at'       => $at,
				'allow_same_sender' => true,
			)
		);
		// 0 は、ブロック中のアドレス・メールアドレスなしなど、WP 版でも受注を作らない送信（受け取りは済ませる）
		return array(
			'ok'       => true,
			'order_id' => (int) $order_id,
			'label'    => $label,
			'message'  => $order_id ? '' : '受注は作りませんでした（ブロック中のアドレス、またはメールアドレスがない送信）',
		);
	}

	if ( 'public_order' === $kind ) {
		$form       = isset( $data['form'] ) && is_array( $data['form'] ) ? $data['form'] : array();
		$service_id = isset( $data['service_id'] ) ? (int) $data['service_id'] : 0;
		$label      = '商品の申し込み（' . sanitize_text_field( (string) ( $data['service_name'] ?? '' ) ) . '）';
		if ( ! class_exists( 'KTPWP_Public_Product_Order' ) ) {
			return array( 'ok' => false, 'order_id' => 0, 'label' => $label, 'message' => 'KantanProEX 本体が古く、商品の申し込みを取り込めません。アプリを新しくしてください。' );
		}
		$who = sprintf(
			'申し込んだ人: %s %s / %s / %s',
			sanitize_text_field( (string) ( $form['company_name'] ?? '' ) ),
			sanitize_text_field( (string) ( $form['contact_name'] ?? '' ) ),
			sanitize_email( (string) ( $form['email'] ?? '' ) ),
			sanitize_text_field( (string) ( $form['phone'] ?? '' ) )
		);
		$order_api = KTPWP_Public_Product_Order::get_instance();
		if ( ! $order_api->get_public_service( $service_id ) ) {
			// 申し込みのあと、公開を止めた商品。受注は作らず、連絡先を記録に残す（受け取りは済ませる）
			return array( 'ok' => true, 'order_id' => 0, 'label' => $label, 'message' => '公開を止めた商品への申し込みのため、受注は作りませんでした。' . $who );
		}
		$r = $order_api->submit_order( $service_id, $form );
		if ( ! empty( $r['success'] ) ) {
			return array( 'ok' => true, 'order_id' => (int) ( $r['order_id'] ?? 0 ), 'label' => $label, 'message' => '' );
		}
		$msg = (string) ( $r['message'] ?? '' );
		if ( false !== strpos( $msg, '失敗しました' ) ) {
			return array( 'ok' => false, 'order_id' => 0, 'label' => $label, 'message' => $msg ); // 一時的な保存の失敗。次回もう一度試す
		}
		// 完売・保留・ブロック中のアドレスなど、受注にしない申し込み（理由と連絡先を記録に残す）
		return array( 'ok' => true, 'order_id' => 0, 'label' => $label, 'message' => '受注は作りませんでした（' . $msg . '）' . $who );
	}

	if ( 'woocommerce' === $kind ) {
		$label = 'WooCommerce 注文 #' . sanitize_text_field( (string) ( $data['number'] ?? ( $data['id'] ?? '' ) ) );
		if ( ! class_exists( 'KTPWP_WooCommerce_Integration' ) && defined( 'KTPWP_PLUGIN_DIR' ) && is_readable( KTPWP_PLUGIN_DIR . 'includes/class-ktpwp-woocommerce-integration.php' ) ) {
			require_once KTPWP_PLUGIN_DIR . 'includes/class-ktpwp-woocommerce-integration.php';
		}
		if ( ! class_exists( 'KTPWP_WooCommerce_Integration' ) || ! method_exists( 'KTPWP_WooCommerce_Integration', 'import_order_payload' ) ) {
			return array( 'ok' => false, 'order_id' => 0, 'label' => $label, 'message' => 'KantanProEX 本体が古く、WooCommerce の注文を取り込めません。アプリを新しくしてください。' );
		}
		$order_id = KTPWP_WooCommerce_Integration::get_instance()->import_order_payload( $data );
		if ( ! $order_id ) {
			global $wpdb;
			return array( 'ok' => false, 'order_id' => 0, 'label' => $label, 'message' => '受注を作れませんでした' . ( $wpdb->last_error ? '（' . $wpdb->last_error . '）' : '' ) );
		}
		return array( 'ok' => true, 'order_id' => (int) $order_id, 'label' => $label, 'message' => '' );
	}

	return array( 'ok' => false, 'order_id' => 0, 'label' => $kind, 'message' => 'このアプリでは扱えない種類です。アプリを新しくしてください。' );
}

/**
 * サイトに預けられた分を取りに行き、受注にする。
 *
 * @return array{ok: bool, imported: int, skipped: int, failed: int, message: string}
 */
function ktp_dt_intake_run() {
	$s = ktp_dt_intake_settings();
	if ( ! $s['enabled'] ) {
		return array( 'ok' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'message' => '「サイトから受注を取り込む」がオフです。' );
	}
	// cron と「今すぐ取り込む」が重ならないようにする
	if ( get_transient( 'ktp_dt_intake_lock' ) ) {
		return array( 'ok' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0, 'message' => 'いま取り込み中です。少し待ってから試してください。' );
	}
	set_transient( 'ktp_dt_intake_lock', 1, 5 * MINUTE_IN_SECONDS );

	$imported = 0;
	$skipped  = 0;
	$failed   = 0;
	$error    = '';
	$failures = array();
	$site     = null; // 最初の取得応答（公開商品の同期で、サイトの状態を見るのに使う）
	$catalog  = array( 'count' => 0, 'pushed' => false, 'error' => '' );

	try {
		// 1 回に 50 件ずつ、最大 10 回（500 件）まで
		for ( $round = 0; $round < 10; $round++ ) {
			$res = ktp_dt_intake_request( 'GET', 'inbox', array( 'limit' => KTP_DT_INTAKE_BATCH ) );
			if ( is_wp_error( $res ) ) {
				$error = $res->get_error_message();
				break;
			}
			if ( null === $site ) {
				$site = $res;
			}
			$entries = isset( $res['entries'] ) && is_array( $res['entries'] ) ? $res['entries'] : array();
			if ( ! $entries ) {
				break;
			}
			$done = ktp_dt_intake_done();
			$acks = array();
			foreach ( $entries as $entry ) {
				$uuid = is_array( $entry ) && isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
				if ( ! wp_is_uuid( $uuid ) ) {
					continue;
				}
				if ( isset( $done[ $uuid ] ) ) {
					$acks[] = $uuid; // 取り込み済み（前回「受け取った」を返せなかった分）
					continue;
				}
				try {
					$r = ktp_dt_intake_import_entry( $entry );
				} catch ( Throwable $e ) {
					$r = array( 'ok' => false, 'order_id' => 0, 'label' => (string) ( $entry['kind'] ?? '' ), 'message' => $e->getMessage() );
				}
				if ( ! $r['ok'] ) {
					$failed++;
					$failures[ $uuid ] = $r['label'] . ': ' . $r['message'];
					continue;
				}
				// 印を先に残す（このあと「受け取った」を返せなくても、次回二重に取り込まない）
				ktp_dt_intake_mark_done( $uuid );
				$acks[] = $uuid;
				if ( $r['order_id'] ) {
					$imported++;
				} else {
					$skipped++;
				}
				ktp_dt_intake_log(
					array(
						'label'       => $r['label'],
						'order_id'    => $r['order_id'],
						'received_at' => isset( $entry['received_at'] ) ? (int) $entry['received_at'] : 0,
						'message'     => $r['message'],
					)
				);
			}
			if ( $acks ) {
				$ack = ktp_dt_intake_request( 'POST', 'ack', array(), array( 'uuids' => $acks ) );
				if ( is_wp_error( $ack ) ) {
					$error = $ack->get_error_message();
					break;
				}
			}
			// 全部取り込めなかった回は、同じものがまた返ってくるだけなので打ち切る
			if ( count( $entries ) < KTP_DT_INTAKE_BATCH || ! $acks ) {
				break;
			}
		}
		// 取り込みのあとに商品を合わせる（受注で在庫・受付状況が変わるため）
		if ( $error === '' && is_array( $site ) ) {
			// 取り込みで商品の状態が変わるので、サイトの状態は取得し直す
			if ( $imported ) {
				$again = ktp_dt_intake_request( 'GET', 'inbox', array( 'limit' => 0 ) );
				$site  = is_wp_error( $again ) ? $site : $again;
			}
			$catalog = ktp_dt_intake_catalog_sync( $site );
			if ( $catalog['error'] !== '' ) {
				$error = $catalog['error'];
			}
		}
	} finally {
		delete_transient( 'ktp_dt_intake_lock' );
	}

	$state = ktp_dt_intake_state();
	// 同じ預かり分の失敗は、回を重ねても 1 回だけ記録に出す
	$known = isset( $state['failures'] ) && is_array( $state['failures'] ) ? $state['failures'] : array();
	foreach ( $failures as $uuid => $msg ) {
		if ( ! isset( $known[ $uuid ] ) ) {
			ktp_dt_intake_log( array( 'label' => '取り込めませんでした', 'order_id' => 0, 'received_at' => 0, 'message' => $msg ) );
		}
	}
	$ok    = $error === '' && $failed === 0;
	$state = array(
		'last_run'    => time(),
		'last_ok'     => $ok ? time() : (int) ( $state['last_ok'] ?? 0 ),
		'last_error'  => $error !== '' ? $error : ( $failed ? $failed . ' 件を取り込めませんでした。次回もう一度試します。' : '' ),
		'fail_streak' => $ok ? 0 : (int) ( $state['fail_streak'] ?? 0 ) + 1,
		'failures'    => $failures,
	);
	update_option( KTP_DT_INTAKE_STATE, $state, false );
	if ( $imported ) {
		update_option( KTP_DT_INTAKE_UNSEEN, (int) get_option( KTP_DT_INTAKE_UNSEEN, 0 ) + $imported, false );
	}

	$parts = array();
	if ( $imported ) {
		$parts[] = $imported . ' 件を受注に取り込みました。';
	}
	if ( $skipped ) {
		$parts[] = $skipped . ' 件は受注を作りませんでした（ブロック中のアドレスなど）。';
	}
	if ( $catalog['pushed'] ) {
		$parts[] = '公開商品 ' . $catalog['count'] . ' 件をサイトに反映しました。';
	}
	if ( $state['last_error'] !== '' ) {
		$parts[] = $state['last_error'];
	}
	if ( ! $parts ) {
		$parts[] = '新しいお問い合わせ・注文はありませんでした。';
	}
	return array(
		'ok'       => $error === '',
		'imported' => $imported,
		'skipped'  => $skipped,
		'failed'   => $failed,
		'message'  => implode( ' ', $parts ),
		'catalog'  => $catalog,
	);
}

// ---- 15 分ごとの取り込み（wp-cron。Electron が 5 分ごとに wp-cron.php を呼ぶ） ----

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		$schedules['ktp_dt_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => '15 分ごと',
		);
		return $schedules;
	}
);

add_action(
	'init',
	function () {
		$on   = ktp_dt_intake_settings()['enabled'];
		$next = wp_next_scheduled( KTP_DT_INTAKE_HOOK );
		if ( $on && ! $next ) {
			wp_schedule_event( time() + 60, 'ktp_dt_15min', KTP_DT_INTAKE_HOOK );
		} elseif ( ! $on && $next ) {
			wp_clear_scheduled_hook( KTP_DT_INTAKE_HOOK );
		}
	}
);

add_action(
	KTP_DT_INTAKE_HOOK,
	function () {
		ktp_dt_intake_run();
	}
);

// ---- 設定画面（KantanProEX 設定 → サイトからの受注） ----

function ktp_dt_intake_can_manage() {
	return current_user_can( 'manage_options' ) && ! ( function_exists( 'ktp_dt_is_lan' ) && ktp_dt_is_lan() );
}

add_action(
	'admin_menu',
	function () {
		if ( function_exists( 'ktp_dt_is_lan' ) && ktp_dt_is_lan() ) {
			return;
		}
		add_submenu_page(
			'ktp-settings',
			'サイトからの受注',
			'サイトからの受注',
			'manage_options',
			KTP_DT_INTAKE_PAGE,
			function () {
				if ( ktp_dt_intake_can_manage() ) {
					echo '<div class="wrap">' . ktp_dt_intake_page_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			}
		);
	},
	PHP_INT_MAX
);

add_action(
	'admin_post_ktp_dt_intake_save',
	function () {
		if ( ! ktp_dt_intake_can_manage() ) {
			wp_die( '親機の画面でオーナーだけが操作できます。' );
		}
		check_admin_referer( KTP_DT_INTAKE_NONCE );
		$old = ktp_dt_intake_settings();
		$url = ktp_dt_intake_normalize_url( isset( $_POST['url'] ) ? wp_unslash( $_POST['url'] ) : '' );
		$key = isset( $_POST['key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['key'] ) ) ) : '';
		$on  = ! empty( $_POST['enabled'] );
		$cat = ! empty( $_POST['catalog'] );
		$msg = 'saved';
		if ( is_wp_error( $url ) ) {
			set_transient( 'ktp_dt_intake_notice', $url->get_error_message(), 60 );
			$url = $old['url'];
			$msg = 'error';
		}
		if ( $key === '' ) {
			$key = $old['key']; // 空欄のまま保存したら、入っているキーをそのまま使う
		}
		if ( $on && ( $url === '' || $key === '' ) ) {
			set_transient( 'ktp_dt_intake_notice', 'オンにするには、サイトのアドレスと接続キーを入れてください。', 60 );
			$on  = false;
			$msg = 'error';
		}
		update_option(
			KTP_DT_INTAKE_OPT,
			array(
				'enabled' => $on,
				'url'     => $url,
				'key'     => $key,
				'catalog' => $cat,
			),
			false
		);
		// つなぎ先を変えたら、前のサイトの状態は消す（取り込んだ印は残す）
		if ( $url !== $old['url'] ) {
			delete_option( KTP_DT_INTAKE_STATE );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . KTP_DT_INTAKE_PAGE . '&ktp_dt_msg=' . $msg ) );
		exit;
	}
);

add_action(
	'wp_ajax_ktp_dt_intake_test',
	function () {
		check_ajax_referer( KTP_DT_INTAKE_NONCE, 'nonce' );
		if ( ! ktp_dt_intake_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		if ( ! ktp_dt_intake_settings()['enabled'] ) {
			wp_send_json_error( array( 'message' => '先にオンにして保存してください。' ) );
		}
		$res = ktp_dt_intake_request( 'GET', 'inbox', array( 'limit' => 0 ) );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$what = array();
		$what[] = ! empty( $res['cf7'] ) ? 'Contact Form 7: 受付中' : 'Contact Form 7: 受け付けていません';
		$what[] = ! empty( $res['woo'] ) ? 'WooCommerce: 受付中' : 'WooCommerce: 受け付けていません';
		$what[] = isset( $res['catalog_hash'] ) ? ( '公開商品: ' . (int) ( $res['catalog_count'] ?? 0 ) . ' 件を掲載中' . ( ! empty( $res['shop'] ) ? '' : '（申し込みは受け付けていません）' ) ) : '公開商品: 受付プラグインが古いため使えません（新しい受付プラグインを入れてください）';
		wp_send_json_success(
			array(
				'message' => '「' . sanitize_text_field( (string) ( $res['site'] ?? '' ) ) . '」につながりました。' . implode( '／', $what ) . '。まだ取り込んでいないもの: ' . (int) ( $res['pending'] ?? 0 ) . ' 件',
			)
		);
	}
);

add_action(
	'wp_ajax_ktp_dt_intake_run',
	function () {
		check_ajax_referer( KTP_DT_INTAKE_NONCE, 'nonce' );
		if ( ! ktp_dt_intake_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		$r = ktp_dt_intake_run();
		if ( $r['imported'] ) {
			// 設定画面で結果を見ているので、アプリの画面の「届きました」通知には数えない
			update_option( KTP_DT_INTAKE_UNSEEN, max( 0, (int) get_option( KTP_DT_INTAKE_UNSEEN, 0 ) - $r['imported'] ), false );
		}
		$r['ok'] ? wp_send_json_success( $r ) : wp_send_json_error( $r );
	}
);

/** 受付プラグインの ZIP を作って渡す（サイトの「プラグインを追加」→「アップロード」で入れてもらう） */
add_action(
	'admin_post_ktp_dt_intake_bridge_zip',
	function () {
		if ( ! ktp_dt_intake_can_manage() ) {
			wp_die( '親機の画面でオーナーだけが操作できます。' );
		}
		check_admin_referer( KTP_DT_INTAKE_NONCE );
		$src = ABSPATH . 'ktp-desktop/bridge/ktp-dt-bridge';
		if ( ! is_dir( $src ) || ! class_exists( 'ZipArchive' ) ) {
			wp_die( '受付プラグインのファイルが見つかりません。アプリを入れ直してください。' );
		}
		$tmp = wp_tempnam( 'ktp-dt-bridge.zip' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			wp_die( 'ZIP を作れませんでした。' );
		}
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			if ( $file->isFile() ) {
				$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $src ) ) ), '/' );
				$zip->addFile( $file->getPathname(), 'ktp-dt-bridge/' . $rel );
			}
		}
		$zip->close();
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="ktp-dt-bridge.zip"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		readfile( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		@unlink( $tmp );
		exit;
	}
);

function ktp_dt_intake_page_html() {
	$s       = ktp_dt_intake_settings();
	$state   = ktp_dt_intake_state();
	$log     = get_option( KTP_DT_INTAKE_LOG, array() );
	$log     = is_array( $log ) ? $log : array();
	$notice  = get_transient( 'ktp_dt_intake_notice' );
	$msg     = isset( $_GET['ktp_dt_msg'] ) ? sanitize_key( wp_unslash( $_GET['ktp_dt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$err     = isset( $state['last_error'] ) ? (string) $state['last_error'] : '';
	$next    = wp_next_scheduled( KTP_DT_INTAKE_HOOK );
	if ( $notice ) {
		delete_transient( 'ktp_dt_intake_notice' );
	}
	// 設定画面を開いたら、アプリの画面の「届きました」通知は済んだことにする
	delete_option( KTP_DT_INTAKE_UNSEEN );
	ob_start();
	?>
<div id="ktp-dt-intake" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( KTP_DT_INTAKE_NONCE ) ); ?>" style="max-width:760px;margin:0 auto;font-size:14px;line-height:1.7;">
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">← アプリに戻る</a></p>
	<h2 style="margin:6px 0 12px;">サイトからの受注</h2>
	<p style="color:#6b7280;font-size:13px;">お使いのホームページ（WordPress）の Contact Form 7 のお問い合わせと WooCommerce の注文を、このアプリの受注として取り込みます。ホームページに受付用のプラグインを入れておくと、そこに預けられた分を、このアプリが 15 分ごと（と起動したとき）に取りに行きます。「サイト公開」にした商品も、このアプリからホームページへ送って表示できます。この PC をインターネットに公開することはありません。</p>

	<?php if ( 'saved' === $msg ) : ?>
		<p style="border:1px solid #86efac;background:#f0fdf4;color:#166534;padding:8px 12px;border-radius:6px;">保存しました。</p>
	<?php endif; ?>
	<?php if ( $notice ) : ?>
		<p style="border:1px solid #fca5a5;background:#fef2f2;color:#b91c1c;padding:8px 12px;border-radius:6px;"><?php echo esc_html( (string) $notice ); ?></p>
	<?php endif; ?>

	<div style="border:1px solid <?php echo $s['enabled'] ? ( $err ? '#fca5a5' : '#86efac' ) : '#d1d5db'; ?>;background:<?php echo $s['enabled'] ? ( $err ? '#fef2f2' : '#f0fdf4' ) : '#f9fafb'; ?>;padding:12px 16px;border-radius:6px;margin:12px 0;">
		<p style="margin:0 0 6px;font-weight:700;">現在: <?php echo $s['enabled'] ? 'オン（' . esc_html( (string) wp_parse_url( $s['url'], PHP_URL_HOST ) ) . ' から取り込みます）' : 'オフ'; ?></p>
		<?php if ( $s['enabled'] ) : ?>
			<p style="margin:0;">最後に確かめた時刻: <?php echo ! empty( $state['last_run'] ) ? esc_html( wp_date( 'Y-m-d H:i', (int) $state['last_run'] ) ) : 'まだありません'; ?>
			<?php if ( $next ) : ?>／次回: <?php echo esc_html( wp_date( 'H:i', (int) $next ) ); ?> ごろ<?php endif; ?></p>
			<?php if ( $err !== '' ) : ?>
				<p style="color:#b91c1c;margin:6px 0 0;"><?php echo esc_html( $err ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<h3 style="margin:20px 0 6px;">1. ホームページに受付プラグインを入れる</h3>
	<ol style="margin:0 0 0 20px;">
		<li>下のボタンで、受付プラグイン（ZIP）を保存します。</li>
		<li>ホームページの管理画面で「プラグイン」→「新規プラグインを追加」→「プラグインのアップロード」から ZIP を選び、有効にします。</li>
		<li>「設定」→「KantanProEX 受付」を開くと、サイトのアドレスと接続キーが出ます。</li>
	</ol>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 0;">
		<input type="hidden" name="action" value="ktp_dt_intake_bridge_zip">
		<?php wp_nonce_field( KTP_DT_INTAKE_NONCE ); ?>
		<button type="submit" class="button">受付プラグイン（ZIP）を保存</button>
	</form>
	<p style="color:#6b7280;font-size:13px;margin:6px 0 0;">ホームページは https で開けるものに限ります。WordPress 版の KantanPro / KantanProEX を同じホームページで使っている場合は、二重に受注ができないよう、そちらの Contact Form 7・WooCommerce 連携を止めてください。</p>

	<h3 style="margin:20px 0 6px;">2. このアプリに、サイトのアドレスと接続キーを入れる</h3>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ktp_dt_intake_save">
		<?php wp_nonce_field( KTP_DT_INTAKE_NONCE ); ?>
		<table class="form-table" role="presentation" style="margin-top:0;">
			<tr>
				<th scope="row"><label for="ktp-dt-intake-url">サイトのアドレス</label></th>
				<td><input type="url" id="ktp-dt-intake-url" name="url" class="regular-text" placeholder="https://example.com/" value="<?php echo esc_attr( $s['url'] ); ?>" autocapitalize="off"></td>
			</tr>
			<tr>
				<th scope="row"><label for="ktp-dt-intake-key">接続キー</label></th>
				<td>
					<input type="password" id="ktp-dt-intake-key" name="key" class="regular-text" autocomplete="off" placeholder="<?php echo $s['key'] !== '' ? '設定済み（変えるときだけ入れる）' : 'ktpdt_ で始まるキー'; ?>">
				</td>
			</tr>
			<tr>
				<th scope="row">取り込み</th>
				<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> サイトから受注を取り込む</label></td>
			</tr>
			<tr>
				<th scope="row">公開商品</th>
				<td>
					<label><input type="checkbox" name="catalog" value="1" <?php checked( $s['catalog'] ); ?>> 「サイト公開」にした商品を、ホームページに載せる</label>
					<p class="description" style="margin:2px 0 0;">サービスの編集画面で「サイト公開」にした商品が対象です。</p>
				</td>
			</tr>
		</table>
		<p><button type="submit" class="button button-primary">保存</button></p>
	</form>

	<h3 style="margin:20px 0 6px;">3. 商品をホームページに表示する（任意）</h3>
	<p style="margin:0;">ホームページの固定ページ（または投稿）に、次のように書きます。「サイト公開」にした商品が、カードで並びます。</p>
	<p style="margin:6px 0;"><code>[ktp_dt_products]</code>　（WordPress 版と同じ <code>[ktpwp_public_products]</code> でも表示できます）</p>
	<p style="color:#6b7280;font-size:13px;margin:0;">列の数は <code>columns="2"</code>、カテゴリーの絞り込みは <code>category="カテゴリー名"</code>、特定の商品だけは <code>ids="3,5"</code>、1 列の一覧は <code>layout="cards"</code> を付けます。
	お客様が「申し込む」を押すと、ホームページに預けられ、このアプリが取りに行って受注（受付中）にします。商品の追加・値段・在庫・受付状況の変更は、15 分以内（「今すぐ取り込む」ならすぐ）にホームページへ反映されます。
	支払いのあとに請求する形です（Stripe の即時購入には対応していません）。</p>

	<h3 style="margin:20px 0 6px;">4. つながるか確かめる</h3>
	<p>
		<button type="button" class="button" id="ktp-dt-intake-test" <?php disabled( ! $s['enabled'] ); ?>>接続を確かめる</button>
		<button type="button" class="button" id="ktp-dt-intake-run" <?php disabled( ! $s['enabled'] ); ?>>今すぐ取り込む</button>
	</p>
	<p id="ktp-dt-intake-msg" role="status" style="display:none;font-weight:700;"></p>

	<h3 style="margin:20px 0 6px;">最近の取り込み</h3>
	<?php if ( ! $log ) : ?>
		<p style="color:#6b7280;">まだありません。</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:760px;">
			<thead><tr><th style="width:9em;">取り込んだ時刻</th><th>内容</th><th style="width:6em;">受注</th></tr></thead>
			<tbody>
			<?php foreach ( $log as $row ) : ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'm-d H:i', (int) ( $row['time'] ?? 0 ) ) ); ?></td>
					<td>
						<?php echo esc_html( (string) ( $row['label'] ?? '' ) ); ?>
						<?php if ( ! empty( $row['message'] ) ) : ?><br><span style="color:#6b7280;font-size:12px;"><?php echo esc_html( (string) $row['message'] ); ?></span><?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $row['order_id'] ) ) : ?>
							<a href="<?php echo esc_url( add_query_arg( array( 'tab_name' => 'order', 'order_id' => (int) $row['order_id'] ), home_url( '/' ) ) ); ?>">ID <?php echo (int) $row['order_id']; ?></a>
						<?php else : ?>—<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
<script>
(function(){
	var w=document.getElementById('ktp-dt-intake'),msg=document.getElementById('ktp-dt-intake-msg');
	function post(action,label,btn){
		var d=new URLSearchParams({action:action,nonce:w.dataset.nonce});
		msg.style.display='block';msg.style.color='#1f2937';msg.textContent=label;btn.disabled=true;
		fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d.toString()}).then(function(r){return r.json();}).then(function(j){
			msg.style.color=j.success?'#166534':'#b91c1c';
			msg.textContent=(j.data&&j.data.message)||(j.success?'完了しました。':'うまくいきませんでした。');
			btn.disabled=false;
			if(action==='ktp_dt_intake_run'&&j.data&&(j.data.imported||j.data.skipped)){setTimeout(function(){location.reload();},1500);}
		}).catch(function(){msg.style.color='#b91c1c';msg.textContent='うまくいきませんでした。';btn.disabled=false;});
	}
	var t=document.getElementById('ktp-dt-intake-test'),r=document.getElementById('ktp-dt-intake-run');
	t.onclick=function(){post('ktp_dt_intake_test','サイトに問い合わせています…',t);};
	r.onclick=function(){post('ktp_dt_intake_run','取り込んでいます…',r);};
})();
</script>
	<?php
	return (string) ob_get_clean();
}

/**
 * アプリの画面（受注一覧など）で、新しく届いた受注と、取り込めていない状態を知らせる。
 * 通知は KantanProEX 本体の右上の通知（showSuccessNotification / ktpShowErrorNotification）を使う。
 */
add_action(
	'wp_footer',
	function () {
		if ( ! ktp_dt_intake_can_manage() || ! ktp_dt_intake_settings()['enabled'] ) {
			return;
		}
		$unseen = (int) get_option( KTP_DT_INTAKE_UNSEEN, 0 );
		$state  = ktp_dt_intake_state();
		// 1 回の失敗（一時的に回線が切れたなど）では出さない。3 回（約 45 分）続いたら出す
		$error = ( (int) ( $state['fail_streak'] ?? 0 ) >= 3 && ! empty( $state['last_error'] ) ) ? (string) $state['last_error'] : '';
		// 画面を移るたびに出ないよう、取り込めていない知らせは 1 時間に 1 回まで
		if ( $error !== '' && get_transient( 'ktp_dt_intake_err_shown' ) ) {
			$error = '';
		}
		if ( ! $unseen && $error === '' ) {
			return;
		}
		if ( $unseen ) {
			delete_option( KTP_DT_INTAKE_UNSEEN );
		}
		if ( $error !== '' ) {
			set_transient( 'ktp_dt_intake_err_shown', 1, HOUR_IN_SECONDS );
		}
		$ok_msg  = $unseen ? 'ホームページから受注が ' . $unseen . ' 件届きました。' : '';
		$err_msg = $error !== '' ? 'ホームページからの受注を取り込めていません: ' . $error . '（KantanProEX 設定 → サイトからの受注）' : '';
		?>
<script id="ktp-dt-intake-notice">
(function(){
	var ok=<?php echo wp_json_encode( $ok_msg ); ?>,ng=<?php echo wp_json_encode( $err_msg ); ?>;
	function go(){
		if(ok&&typeof window.showSuccessNotification==='function'){window.showSuccessNotification(ok);}
		if(ng&&typeof window.ktpShowErrorNotification==='function'){setTimeout(function(){window.ktpShowErrorNotification(ng);},ok?600:0);}
	}
	if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',go);}else{go();}
})();
</script>
		<?php
	},
	100
);
