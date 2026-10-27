<?php
/**
 * 公開商品の即時購入（Stripe 決済）。
 *
 * WP 版は、サイトで「購入する」を押されたときにサイトの秘密鍵で Checkout Session を作り、Webhook で入金を受ける。
 * デスクトップ版は外から受け付けないので、次の形にする（Stripe の秘密鍵はこのパソコンの中だけに置き、サイトには渡さない）。
 *
 * 1. 「即時購入」にした公開商品ごとに、このアプリが Stripe の Payment Link（決済ページ）を作る
 *    （商品名・値段・数量固定が変わったら作り直し、前のリンクは止める。対象外になったら止める）
 * 2. リンクの URL を公開商品と一緒にサイトへ送る（intake.php の公開商品の同期）。サイトの「購入する」はそのリンクへ進む
 * 3. 取り込みのたび（15 分ごと・起動時・「今すぐ取り込む」）に、Stripe から完了した決済を取りに行き、
 *    KantanProEX 本体の処理（submit_order → sync_public_checkout_session_for_order）で、入金済みの受注にする。
 *    WP 版で Stripe の入金を受けたときと同じ進捗（受注）・入金日・前払い・通知メールになる
 *
 * 定期契約のサービスは Stripe のサブスクリプション（毎回の自動引き落とし）にする。最初の購入は受注＋契約（subscription ID 付き）にし、
 * 2 回目以降の引き落としと解約・停止は、取り込みのたびに Stripe と見比べて反映する（ktp_dt_stripe_sync_subscriptions）。
 * 契約に subscription ID が入っていると、本体の毎月の請求（受注の自動生成・請求メール）は止まるので二重請求にならない。
 *
 * 対象外: 値段が 1 円単位でない・50 円未満のもの（Stripe の制約）。
 * 対象外の商品は、これまでどおり「申し込む」で受け付ける。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_STRIPE_OPT        = 'ktp_dt_stripe';
const KTP_DT_STRIPE_LINKS      = 'ktp_dt_stripe_links';
const KTP_DT_STRIPE_OLD_LINKS  = 'ktp_dt_stripe_old_links';
const KTP_DT_STRIPE_DONE       = 'ktp_dt_stripe_done';
const KTP_DT_STRIPE_NONCE      = 'ktp_dt_stripe';
const KTP_DT_STRIPE_MIN_AMOUNT = 50;   // Stripe の日本円の最低金額
const KTP_DT_STRIPE_OLD_DAYS   = 3;    // 止めたリンクの決済も、この日数は取りに行く（止める直前に払われた分）
const KTP_DT_STRIPE_KEEP       = 2000; // 取り込んだ決済の印を覚えておく件数

/**
 * Stripe API の入口。自動テストのときだけ、このパソコン内の模擬サーバー（127.0.0.1）へ向けられる
 * （環境変数 KTP_DT_STRIPE_API_BASE。外部ホストは受け付けず、秘密鍵が別の宛先へ出ないようにする）。
 */
function ktp_dt_stripe_api_base() {
	$base = (string) getenv( 'KTP_DT_STRIPE_API_BASE' );
	if ( $base !== '' && in_array( wp_parse_url( $base, PHP_URL_HOST ), array( '127.0.0.1', 'localhost' ), true ) ) {
		return trailingslashit( $base );
	}
	return 'https://api.stripe.com/v1/';
}

function ktp_dt_stripe_settings() {
	$s = get_option( KTP_DT_STRIPE_OPT, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'key' => isset( $s['key'] ) ? (string) $s['key'] : '',
	);
}

/** 秘密鍵が入っていて、サイトからの受注もオンのとき */
function ktp_dt_stripe_enabled() {
	return ktp_dt_stripe_settings()['key'] !== '' && function_exists( 'ktp_dt_intake_settings' ) && ktp_dt_intake_settings()['enabled'];
}

function ktp_dt_stripe_is_test() {
	return 1 === preg_match( '/^(sk|rk)_test_/', ktp_dt_stripe_settings()['key'] );
}

/** 通信を許すホスト（ktp-desktop.php の遮断から外す） */
function ktp_dt_stripe_host() {
	return ktp_dt_stripe_enabled() ? 'api.stripe.com' : '';
}

function ktp_dt_stripe_valid_key( $key ) {
	return 1 === preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/', (string) $key );
}

/**
 * Stripe API を呼ぶ（SDK は使わず HTTP で。応答はオブジェクトにして本体の処理へそのまま渡せる形にする）。
 *
 * @return object|WP_Error
 */
function ktp_dt_stripe_request( $method, $path, array $params = array() ) {
	$key = ktp_dt_stripe_settings()['key'];
	if ( $key === '' ) {
		return new WP_Error( 'ktp_dt_stripe_conf', 'Stripe の秘密鍵が入っていません。' );
	}
	$url  = ktp_dt_stripe_api_base() . ltrim( $path, '/' );
	$args = array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array( 'Authorization' => 'Bearer ' . $key ),
	);
	if ( $params ) {
		if ( 'GET' === $method ) {
			$url .= '?' . http_build_query( $params, '', '&' );
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body']                    = http_build_query( $params, '', '&' );
		}
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'ktp_dt_stripe_net', 'Stripe につながりませんでした（' . $res->get_error_message() . '）。' );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( (string) wp_remote_retrieve_body( $res ) );
	if ( 401 === $code ) {
		return new WP_Error( 'ktp_dt_stripe_key', 'Stripe の秘密鍵が正しくありません。' );
	}
	if ( 403 === $code ) {
		return new WP_Error( 'ktp_dt_stripe_perm', '制限付きキーの権限が足りません（Products・Prices・Payment Links・Subscriptions の書き込みと、Checkout Sessions・Invoices の読み取りが要ります）。' );
	}
	if ( $code < 200 || $code >= 300 || ! is_object( $json ) ) {
		$msg = is_object( $json ) && isset( $json->error->message ) ? (string) $json->error->message : 'HTTP ' . $code;
		return new WP_Error( 'ktp_dt_stripe_http', 'Stripe からエラーが返りました（' . $msg . '）。' );
	}
	return $json;
}

/** 決済ページ（Payment Link）の現在の対応表: サービス ID → link, url, sig, active */
function ktp_dt_stripe_links() {
	$m = get_option( KTP_DT_STRIPE_LINKS, array() );
	return is_array( $m ) ? $m : array();
}

/** サイトに載せる「購入する」の行き先（無ければ空） */
function ktp_dt_stripe_buy_url( $service_id ) {
	if ( ! ktp_dt_stripe_enabled() ) {
		return '';
	}
	$m = ktp_dt_stripe_links();
	$l = $m[ (int) $service_id ] ?? null;
	return ( is_array( $l ) && ! empty( $l['active'] ) && ( $l['mode'] ?? '' ) === ktp_dt_stripe_mode() ) ? (string) $l['url'] : '';
}

function ktp_dt_stripe_mode() {
	return ktp_dt_stripe_is_test() ? 'test' : 'live';
}

/**
 * 即時購入にできるか。できなければ理由を返す。
 *
 * @param object $row   サービス行（公開中のもの）。
 * @param array  $avail 受付状況（KTPWP_Contract_Service_Public_Availability）。
 * @return string 空なら対象。
 */
function ktp_dt_stripe_ineligible_reason( $row, array $avail ) {
	if ( ! class_exists( 'KTPWP_Service_DB' ) || ! KTPWP_Service_DB::is_public_instant_purchase( $row ) ) {
		return 'off';
	}
	$rec = ktp_dt_stripe_recurring_amounts( $row );
	if ( $rec ) {
		if ( ! empty( $rec['provisional'] ) ) {
			return '初回に請求しない定期請求項目があるため、即時購入できません';
		}
		if ( $rec['recurring'] < KTP_DT_STRIPE_MIN_AMOUNT ) {
			return 'Stripe で決済できるのは毎回 50 円からです（定期請求項目の合計）';
		}
		return empty( $avail['acceptance_open'] ) ? '受付停止中（完売・保留）' : '';
	}
	$price = (float) $row->price;
	if ( floor( $price ) != $price ) {
		return '値段が 1 円単位でないため、即時購入できません';
	}
	if ( $price < KTP_DT_STRIPE_MIN_AMOUNT ) {
		return 'Stripe で決済できるのは 50 円からです';
	}
	if ( empty( $avail['acceptance_open'] ) ) {
		return '受付停止中（完売・保留）';
	}
	return '';
}

/**
 * 定期契約のサービスの毎回の金額・初回だけの金額・周期。定期契約でなければ null。
 * 受注の明細を作る KTPWP_Order_Items::insert_invoice_item_from_public_product() と同じ数え方にする
 * （取り込み後の金額チェックと一致させるため）。
 *
 * @param object $row サービス行。
 * @return array{interval: string, interval_count: int, recurring: int, initial: int, provisional: bool, label: string}|null
 */
function ktp_dt_stripe_recurring_amounts( $row ) {
	if ( ! class_exists( 'KTPWP_Contract_Billing_Cycle' ) || ! KTPWP_Contract_Billing_Cycle::is_recurring( $row->contract_billing_cycle ?? 'none' ) ) {
		return null;
	}
	if ( ! class_exists( 'KTPWP_Order_Contract_Draft_Resolver' ) && defined( 'KTPWP_PLUGIN_DIR' ) ) {
		require_once KTPWP_PLUGIN_DIR . 'includes/class-ktpwp-order-contract-draft-resolver.php';
	}
	$resolver    = class_exists( 'KTPWP_Order_Contract_Draft_Resolver' ) ? KTPWP_Order_Contract_Draft_Resolver::get_instance() : null;
	$items       = $resolver ? $resolver->default_recurring_items_for_service( (int) $row->id ) : array();
	$recurring   = 0;
	$provisional = false;
	if ( $items ) {
		foreach ( $items as $item ) {
			if ( trim( (string) ( $item['item_name'] ?? '' ) ) === '' ) {
				continue;
			}
			$recurring += (int) round( (float) ( $item['amount'] ?? 0 ) );
			if ( empty( $item['bill_on_first_invoice'] ) ) {
				$provisional = true; // 初回の請求に載らない項目がある（毎回の金額と初回の請求が合わなくなる）
			}
		}
	} else {
		$recurring = (int) round( (float) $row->price );
	}
	$initial = 0;
	if ( $resolver ) {
		foreach ( $resolver->default_initial_fees_for_service( (int) $row->id ) as $fee ) {
			if ( trim( (string) ( $fee['fee_name'] ?? '' ) ) !== '' ) {
				$initial += (int) round( (float) ( $fee['amount'] ?? 0 ) );
			}
		}
	}
	$cycle  = (string) $row->contract_billing_cycle;
	$months = KTPWP_Contract_Billing_Cycle::get_interval_months( $cycle );
	return array(
		'interval'       => $months >= 12 ? 'year' : 'month',
		'interval_count' => $months >= 12 ? 1 : max( 1, $months ),
		'recurring'      => $recurring,
		'initial'        => $initial,
		'provisional'    => $provisional,
		'label'          => KTPWP_Contract_Billing_Cycle::get_label( $cycle ),
	);
}

/**
 * 公開商品と決済ページを合わせる（作る・作り直す・止める）。公開商品の同期の前に呼ぶ。
 *
 * @return string エラー（無ければ空）。
 */
function ktp_dt_stripe_sync_links() {
	if ( ! ktp_dt_stripe_enabled() || ! class_exists( 'KTPWP_Service_DB' ) ) {
		return '';
	}
	$db    = KTPWP_Service_DB::get_instance();
	$rows  = $db->get_services( 'service', array( 'is_public' => true, 'limit' => 0, 'order_by' => 'id', 'order' => 'DESC' ) );
	$links = ktp_dt_stripe_links();
	$mode  = ktp_dt_stripe_mode();
	$want  = array();
	$notes = array();
	foreach ( (array) $rows as $row ) {
		$avail  = class_exists( 'KTPWP_Contract_Service_Public_Availability' )
			? KTPWP_Contract_Service_Public_Availability::get_public_availability( (int) $row->id, $row )
			: array( 'acceptance_open' => true );
		$reason = ktp_dt_stripe_ineligible_reason( $row, $avail );
		if ( 'off' === $reason ) {
			continue;
		}
		if ( '' !== $reason ) {
			$notes[ (int) $row->id ] = $reason;
			continue;
		}
		$want[ (int) $row->id ] = $row;
	}
	update_option( 'ktp_dt_stripe_notes', $notes, false );

	$error = '';
	// 対象から外れたもの、テスト用・本番用のキーを切り替えたものは止める
	foreach ( $links as $id => $l ) {
		if ( ! empty( $l['active'] ) && ( ! isset( $want[ (int) $id ] ) || ( $l['mode'] ?? '' ) !== $mode ) ) {
			if ( ( $l['mode'] ?? '' ) === $mode ) {
				$r = ktp_dt_stripe_request( 'POST', 'payment_links/' . rawurlencode( $l['link'] ), array( 'active' => 'false' ) );
				if ( is_wp_error( $r ) ) {
					$error = $r->get_error_message();
					continue;
				}
			}
			$links[ $id ]['active'] = false;
			ktp_dt_stripe_retire( $l, (int) $id );
			if ( ( $l['mode'] ?? '' ) !== $mode ) {
				unset( $links[ $id ] );
			}
		}
	}
	foreach ( $want as $id => $row ) {
		$fixed = KTPWP_Service_DB::is_public_quantity_fixed( $row );
		$name  = trim( (string) $row->service_name );
		$rec   = ktp_dt_stripe_recurring_amounts( $row );
		$sig   = md5( $name . '|' . (int) $row->price . '|' . ( $fixed ? 1 : 0 ) . '|' . (string) $row->unit . ( $rec ? '|rec:' . $rec['interval'] . ':' . $rec['interval_count'] . ':' . $rec['recurring'] . ':' . $rec['initial'] : '' ) );
		$cur   = $links[ $id ] ?? null;
		if ( is_array( $cur ) && $cur['sig'] === $sig && ( $cur['mode'] ?? '' ) === $mode ) {
			if ( empty( $cur['active'] ) ) {
				$r = ktp_dt_stripe_request( 'POST', 'payment_links/' . rawurlencode( $cur['link'] ), array( 'active' => 'true' ) );
				if ( is_wp_error( $r ) ) {
					$error = $r->get_error_message();
					continue;
				}
				$links[ $id ]['active'] = true;
			}
			continue;
		}
		$made = ktp_dt_stripe_create_link( $row, $name, $fixed );
		if ( is_wp_error( $made ) ) {
			$error = '「' . $name . '」の決済ページを作れませんでした: ' . $made->get_error_message();
			continue;
		}
		if ( is_array( $cur ) && ! empty( $cur['active'] ) && ( $cur['mode'] ?? '' ) === $mode ) {
			// 値段などが変わった。前のリンクは止める（止められなくても新しいリンクに切り替える）
			ktp_dt_stripe_request( 'POST', 'payment_links/' . rawurlencode( $cur['link'] ), array( 'active' => 'false' ) );
			ktp_dt_stripe_retire( $cur, $id );
		}
		$links[ $id ] = array(
			'link'    => (string) $made->id,
			'url'     => (string) $made->url,
			'sig'     => $sig,
			'mode'    => $mode,
			'active'  => true,
			'created' => time(),
		);
		// 作った直後に保存する（このあとの失敗で、作ったリンクを見失わないように）
		update_option( KTP_DT_STRIPE_LINKS, $links, false );
	}
	update_option( KTP_DT_STRIPE_LINKS, $links, false );
	return $error;
}

/** 止めたリンクも、しばらくは決済を取りに行くので控えておく */
function ktp_dt_stripe_retire( array $l, $service_id ) {
	$old = get_option( KTP_DT_STRIPE_OLD_LINKS, array() );
	$old = is_array( $old ) ? $old : array();
	$old[ (string) $l['link'] ] = array(
		'service_id' => (int) $service_id,
		'mode'       => (string) ( $l['mode'] ?? '' ),
		'created'    => (int) ( $l['created'] ?? 0 ),
		'retired'    => time(),
	);
	foreach ( $old as $k => $o ) {
		if ( (int) $o['retired'] < time() - KTP_DT_STRIPE_OLD_DAYS * DAY_IN_SECONDS ) {
			unset( $old[ $k ] );
		}
	}
	update_option( KTP_DT_STRIPE_OLD_LINKS, $old, false );
}

/**
 * 決済ページを作る（価格 → Payment Link）。
 *
 * @return object|WP_Error Payment Link。
 */
function ktp_dt_stripe_create_link( $row, $name, $fixed ) {
	$rec   = ktp_dt_stripe_recurring_amounts( $row );
	$short = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 200 ) : substr( $name, 0, 200 );
	$meta  = array( 'ktp_service_id' => (string) (int) $row->id );
	$pp    = array(
		'currency'     => 'jpy',
		'unit_amount'  => $rec ? $rec['recurring'] : (int) $row->price,
		'product_data' => array( 'name' => $short, 'metadata' => $meta ),
		'metadata'     => $meta,
	);
	if ( $rec ) {
		$pp['recurring'] = array( 'interval' => $rec['interval'], 'interval_count' => $rec['interval_count'] );
	}
	$price = ktp_dt_stripe_request( 'POST', 'prices', $pp );
	if ( is_wp_error( $price ) ) {
		return $price;
	}
	$item  = array(
		'price'    => (string) $price->id,
		'quantity' => 1,
	);
	$items = array( $item );
	if ( $rec && $rec['initial'] > 0 ) {
		// 初回費用は買い切りの価格として足す（Stripe は最初の請求にだけ載せる）
		$fee = ktp_dt_stripe_request(
			'POST',
			'prices',
			array(
				'currency'     => 'jpy',
				'unit_amount'  => $rec['initial'],
				'product_data' => array( 'name' => mb_substr( $short, 0, 190 ) . '（初回費用）', 'metadata' => $meta ),
				'metadata'     => $meta,
			)
		);
		if ( is_wp_error( $fee ) ) {
			return $fee;
		}
		$items[] = array( 'price' => (string) $fee->id, 'quantity' => 1 );
	}
	if ( ! $fixed && ! $rec ) {
		$items[0]['adjustable_quantity'] = array(
			'enabled' => 'true',
			'minimum' => 1,
			'maximum' => 999,
		);
	}
	$params = array(
			'line_items'              => $items,
			'phone_number_collection' => array( 'enabled' => 'true' ),
			// 受注に入れるお名前・会社名・ご要望（WP 版の購入フォームと同じ項目）
			'custom_fields'           => array(
				array(
					'key'   => 'contactname',
					'label' => array( 'type' => 'custom', 'custom' => 'お名前' ),
					'type'  => 'text',
					'text'  => array( 'maximum_length' => 100 ),
				),
				array(
					'key'      => 'companyname',
					'label'    => array( 'type' => 'custom', 'custom' => '会社名（任意）' ),
					'type'     => 'text',
					'optional' => 'true',
					'text'     => array( 'maximum_length' => 100 ),
				),
				array(
					'key'      => 'message',
					'label'    => array( 'type' => 'custom', 'custom' => 'ご要望（任意）' ),
					'type'     => 'text',
					'optional' => 'true',
					'text'     => array( 'maximum_length' => 255 ),
				),
			),
			'after_completion'        => array(
				'type'                => 'hosted_confirmation',
				'hosted_confirmation' => array(
					// サイト名はこのアプリの名前（KantanProEX）なので入れない
					'custom_message' => $rec
						? 'お申し込みありがとうございます。毎回のご請求は自動で行われます。担当者より確認のご連絡をいたします。'
						: 'ご購入ありがとうございます。担当者より確認のご連絡をいたします。',
				),
			),
			'metadata'                => array(
				'ktp_service_id' => (string) (int) $row->id,
				'ktp_dt'         => '1',
			),
	);
	if ( $rec ) {
		$params['subscription_data'] = array( 'metadata' => $meta ); // サブスクリプションでは customer_creation が使えない
	} else {
		$params['customer_creation'] = 'if_required';
	}
	return ktp_dt_stripe_request( 'POST', 'payment_links', $params );
}

function ktp_dt_stripe_done() {
	$d = get_option( KTP_DT_STRIPE_DONE, array() );
	return is_array( $d ) ? $d : array();
}

function ktp_dt_stripe_mark_done( $session_id, $order_id ) {
	$d                = ktp_dt_stripe_done();
	$d[ $session_id ] = array( 'order_id' => (int) $order_id, 'time' => time() );
	if ( count( $d ) > KTP_DT_STRIPE_KEEP ) {
		uasort( $d, function ( $a, $b ) { return $b['time'] <=> $a['time']; } );
		$d = array_slice( $d, 0, KTP_DT_STRIPE_KEEP, true );
	}
	update_option( KTP_DT_STRIPE_DONE, $d, false );
}

/** Checkout Session の custom_fields から値を取り出す */
function ktp_dt_stripe_custom_field( $session, $key ) {
	foreach ( (array) ( $session->custom_fields ?? array() ) as $f ) {
		if ( is_object( $f ) && ( $f->key ?? '' ) === $key && isset( $f->text->value ) ) {
			return trim( (string) $f->text->value );
		}
	}
	return '';
}

/**
 * 完了した決済を取りに行き、入金済みの受注にする。
 *
 * @return array{imported: int, failed: int, attention: int, error: string} attention は、入金済みなのに受注を作れなかった件数
 */
function ktp_dt_stripe_import_paid() {
	$out = array( 'imported' => 0, 'failed' => 0, 'attention' => 0, 'error' => '' );
	if ( ! ktp_dt_stripe_enabled() ) {
		return $out;
	}
	$mode    = ktp_dt_stripe_mode();
	$targets = array(); // link ID → service ID, created
	foreach ( ktp_dt_stripe_links() as $id => $l ) {
		if ( ( $l['mode'] ?? '' ) === $mode ) {
			$targets[ (string) $l['link'] ] = array( (int) $id, (int) ( $l['created'] ?? 0 ) );
		}
	}
	$old = get_option( KTP_DT_STRIPE_OLD_LINKS, array() );
	foreach ( is_array( $old ) ? $old : array() as $link => $o ) {
		if ( ( $o['mode'] ?? '' ) === $mode && (int) $o['retired'] >= time() - KTP_DT_STRIPE_OLD_DAYS * DAY_IN_SECONDS ) {
			$targets[ (string) $link ] = array( (int) $o['service_id'], (int) $o['created'] );
		}
	}
	if ( ! $targets ) {
		return $out;
	}
	$done = ktp_dt_stripe_done();
	foreach ( $targets as $link => $t ) {
		list( $service_id, $created ) = $t;
		$after = null;
		// 1 回に 100 件ずつ、最大 5 回まで（新しい順に返る）
		for ( $page = 0; $page < 5; $page++ ) {
			$q = array(
				'payment_link' => $link,
				'status'       => 'complete',
				'limit'        => 100,
			);
			if ( $created ) {
				$q['created'] = array( 'gte' => $created - HOUR_IN_SECONDS );
			}
			if ( $after ) {
				$q['starting_after'] = $after;
			}
			$list = ktp_dt_stripe_request( 'GET', 'checkout/sessions', $q );
			if ( is_wp_error( $list ) ) {
				$out['error'] = $list->get_error_message();
				return $out;
			}
			$data = isset( $list->data ) && is_array( $list->data ) ? $list->data : array();
			foreach ( $data as $session ) {
				$sid = (string) ( $session->id ?? '' );
				if ( $sid === '' || isset( $done[ $sid ] ) ) {
					continue;
				}
				$r = ktp_dt_stripe_import_session( $session, $service_id );
				if ( 'wait' === $r['result'] ) {
					continue;
				}
				if ( 'retry' === $r['result'] ) {
					$out['failed']++;
					continue;
				}
				ktp_dt_stripe_mark_done( $sid, $r['order_id'] );
				$done[ $sid ] = true;
				if ( $r['order_id'] ) {
					$out['imported']++;
				} else {
					$out['attention']++;
				}
			}
			if ( empty( $list->has_more ) || ! $data ) {
				break;
			}
			$after = (string) end( $data )->id;
		}
	}
	return $out;
}

/**
 * 1 件の決済を受注にする。
 *
 * @return array{result: string, order_id: int} result は ok / skip（受注にしない。記録に残す）/ retry（次回もう一度）/ wait（入金待ち）
 */
function ktp_dt_stripe_import_session( $session, $service_id ) {
	$sid = (string) $session->id;
	// 支払いがまだ終わっていないもの（コンビニ払いなど、あとから入金されるもの）は、失敗には数えず次回もう一度見る
	if ( ( $session->payment_status ?? '' ) !== 'paid' ) {
		return array( 'result' => 'wait', 'order_id' => 0 );
	}
	$items = ktp_dt_stripe_request( 'GET', 'checkout/sessions/' . rawurlencode( $sid ) . '/line_items', array( 'limit' => 10 ) );
	if ( is_wp_error( $items ) ) {
		return array( 'result' => 'retry', 'order_id' => 0 );
	}
	$is_sub   = ( $session->mode ?? '' ) === 'subscription';
	$quantity = ( ! $is_sub && isset( $items->data[0]->quantity ) ) ? max( 1, (int) $items->data[0]->quantity ) : 1;
	$cd       = $session->customer_details ?? null;
	$email    = sanitize_email( (string) ( $cd->email ?? ( $session->customer_email ?? '' ) ) );
	$name     = ktp_dt_stripe_custom_field( $session, 'contactname' );
	if ( $name === '' ) {
		$name = (string) ( $cd->name ?? '' );
	}
	// Stripe の電話番号は国際形式（+819012345678）。日本の番号は 0 始まりに戻す
	$phone = (string) ( $cd->phone ?? '' );
	if ( 0 === strpos( $phone, '+81' ) ) {
		$phone = '0' . substr( $phone, 3 );
	}
	$form   = array(
		'company_name' => ktp_dt_stripe_custom_field( $session, 'companyname' ),
		'contact_name' => $name !== '' ? $name : $email,
		'email'        => $email,
		'phone'        => $phone,
		'message'      => ktp_dt_stripe_custom_field( $session, 'message' ),
		'quantity'     => $quantity,
	);
	$amount = (int) ( $session->amount_total ?? 0 );
	$paid   = '¥' . number_format( $amount );
	$who    = sprintf( '購入した人: %s %s / %s / %s（Stripe %s）', $form['company_name'], $form['contact_name'], $email, $form['phone'], $sid );
	$api    = class_exists( 'KTPWP_Public_Product_Order' ) ? KTPWP_Public_Product_Order::get_instance() : null;
	$svc    = $api ? $api->get_public_service( $service_id ) : null;
	global $wpdb;
	$sname  = $svc ? (string) $svc->service_name : (string) $wpdb->get_var( $wpdb->prepare( "SELECT service_name FROM {$wpdb->prefix}ktp_service WHERE id = %d", $service_id ) );
	$label  = '即時購入（' . sanitize_text_field( $sname !== '' ? $sname : 'ID ' . $service_id ) . '・' . $paid . '）';
	if ( ! $api || ! class_exists( 'KTPWP_Stripe_Billing' ) ) {
		return array( 'result' => 'retry', 'order_id' => 0 );
	}

	$r = $svc ? $api->submit_order( $service_id, $form, array( 'defer_admin_notification' => true ) ) : array(
		'success' => false,
		'message' => '購入のあとで、商品の公開が止められています',
	);
	if ( empty( $r['success'] ) || empty( $r['order_id'] ) ) {
		$msg = (string) ( $r['message'] ?? '' );
		if ( false !== strpos( $msg, '失敗しました' ) ) {
			return array( 'result' => 'retry', 'order_id' => 0 ); // 一時的な保存の失敗。次回もう一度試す
		}
		// 入金は済んでいるので、受注を作れなかったことをはっきり残す
		ktp_dt_intake_log(
			array(
				'label'       => $label,
				'order_id'    => 0,
				'received_at' => (int) ( $session->created ?? 0 ),
				'message'     => '入金済みですが、受注を作れませんでした（' . $msg . '）。Stripe の管理画面で確かめ、返金するか、手で受注を入れてください。' . $who,
			)
		);
		return array( 'result' => 'skip', 'order_id' => 0 );
	}
	$order_id = (int) $r['order_id'];

	// 入金の反映は本体の処理に任せる（進捗→受注、入金日、前払い、管理者・購入者への通知メール）。
	// 通知に使う内容は、WP 版で購入フォームを送ったときと同じ置き場所（transient）に置く
	set_transient(
		'ktpwp_instant_purchase_ctx_' . $order_id,
		array(
			'client_email' => $email,
			'service_name' => $svc ? sanitize_text_field( (string) $svc->service_name ) : '',
		),
		DAY_IN_SECONDS
	);
	$sub_note = '';
	if ( $is_sub ) {
		// 契約を作り、subscription ID を契約・受注に残す（これで本体の毎月の請求が止まり、二重請求にならない）
		$sub_note = ktp_dt_stripe_start_subscription( $order_id, $session );
	}
	$billing = KTPWP_Stripe_Billing::get_instance();
	$billing->save_order_stripe_invoice( $order_id, $sid, '' );
	$billing->sync_public_checkout_session_for_order( $order_id, $session );

	$note     = '';
	$expected = (int) $billing->compute_expected_invoice_total_cents( $order_id );
	if ( $expected !== $amount ) {
		$note = '決済額 ' . $paid . ' と受注の金額 ¥' . number_format( $expected ) . ' が違います（購入のあとで値段を変えた、など）。受注の明細を確かめてください。';
	}
	ktp_dt_intake_log(
		array(
			'label'       => $label,
			'order_id'    => $order_id,
			'received_at' => (int) ( $session->created ?? 0 ),
			'message'     => trim( 'Stripe で入金済み。' . ( $is_sub ? '毎回の自動引き落としで契約しました。' : '' ) . $sub_note . $note ),
		)
	);
	return array( 'result' => 'ok', 'order_id' => $order_id );
}

/** Stripe の応答の「ID か展開済みオブジェクト」から ID を取る */
function ktp_dt_stripe_id_of( $v ) {
	return is_object( $v ) ? (string) ( $v->id ?? '' ) : (string) $v;
}

/**
 * 定期購入の最初の決済から、定期契約を作って subscription ID を残す。
 *
 * @return string 問題があったときの説明（無ければ空）。
 */
function ktp_dt_stripe_start_subscription( $order_id, $session ) {
	$sub_id = ktp_dt_stripe_id_of( $session->subscription ?? '' );
	if ( $sub_id === '' ) {
		return '定期契約を作れませんでした（Stripe の応答に subscription がありません）。手で契約を作ると二重請求になる恐れがあるので、Stripe の管理画面で確かめてください。';
	}
	if ( ! class_exists( 'KTPWP_Order_Contract_Conversion' ) ) {
		return '定期契約を作れませんでした（契約機能がありません）。';
	}
	global $wpdb;
	$conv  = KTPWP_Order_Contract_Conversion::get_instance();
	$draft = $conv->get_draft( $order_id );
	if ( ! $draft ) {
		return '定期契約を作れませんでした（受注から契約の下書きを作れません）。';
	}
	$day    = (int) wp_date( 'j' );
	$result = $conv->convert(
		$order_id,
		array(
			'client_id'          => (int) $draft['client_id'],
			'service_id'         => (int) $draft['service_id'],
			'contract_name'      => (string) $draft['contract_name'],
			'amount'             => (float) $draft['amount'],
			'billing_cycle'      => (string) $draft['billing_cycle'],
			'billing_day'        => $day > 28 ? 99 : $day,
			'payment_due_mode'   => 'contract',
			'start_date'         => wp_date( 'Y-m-d' ),
			'end_date'           => '',
			'status'             => 'active',
			'send_reminder_mail' => 0, // 請求予定メールは subscription を見ないので止めておく
			'memo'               => 'Stripe の定期購入（' . $sub_id . '）',
		),
		(array) $draft['initial_fees'],
		(array) $draft['recurring_items'],
		true,
		null,
		array( 'skip_subscription_start' => true ) // Stripe SDK が無いので、開始の処理は本体に任せない
	);
	if ( is_wp_error( $result ) ) {
		return '定期契約を作れませんでした（' . $result->get_error_message() . '）。Stripe では定期購入が始まっているので、手で契約を作り、subscription ID（' . $sub_id . '）を控えてください。';
	}
	$contract_id = (int) $result;
	$ok          = $wpdb->update( $wpdb->prefix . 'ktp_contract', array( 'stripe_subscription_id' => $sub_id ), array( 'id' => $contract_id ), array( '%s' ), array( '%d' ) );
	$wpdb->update( $wpdb->prefix . 'ktp_order', array( 'stripe_subscription_id' => $sub_id ), array( 'id' => (int) $order_id ), array( '%s' ), array( '%d' ) );
	$inv = ktp_dt_stripe_id_of( $session->invoice ?? '' );
	if ( $inv !== '' ) {
		ktp_dt_stripe_mark_done( $inv, $order_id ); // 最初の請求は取り込み済み（毎回の取り込みで二重にしない）
	}
	if ( false === $ok ) {
		return '契約に subscription ID を保存できませんでした（' . $sub_id . '）。';
	}
	return '';
}

/**
 * 契約のうち subscription ID を持つもの。
 *
 * @return array<string, object> subscription ID → 契約行。
 */
function ktp_dt_stripe_contracts() {
	global $wpdb;
	$table = $wpdb->prefix . 'ktp_contract';
	if ( ! in_array( 'stripe_subscription_id', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`" ), true ) ) {
		return array();
	}
	$out = array();
	foreach ( (array) $wpdb->get_results( "SELECT id, client_id, contract_name, status, stripe_subscription_id FROM `{$table}` WHERE stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> ''" ) as $c ) {
		$out[ (string) $c->stripe_subscription_id ] = $c;
	}
	return $out;
}

function ktp_dt_stripe_flag_get( $name ) {
	$v = get_option( $name, array() );
	return is_array( $v ) ? $v : array();
}

function ktp_dt_stripe_flag_set( $name, $key, $on ) {
	$v = ktp_dt_stripe_flag_get( $name );
	if ( $on ) {
		$v[ $key ] = time();
	} else {
		unset( $v[ $key ] );
	}
	update_option( $name, $v, false );
}

/**
 * 定期購入の 2 回目以降の引き落としと、解約・停止を DT に反映する。
 *
 * 1. 毎回の入金: 払われた請求書を取り、本体の handle_subscription_invoice_paid に渡す（SDK が無いので Stripe への問い合わせは省かれ、
 *    期間の受注を作って入金済みにするところまでを DB だけで行う）
 * 2. Stripe 側の状態 → DT: 解約されたら契約を「解約」に。引き落としに失敗中なら記録に残す
 * 3. DT 側の操作 → Stripe: DT で解約したら期間の終わりで止める。一時停止・再開も同様
 *
 * @return array{imported: int, failed: int, error: string}
 */
function ktp_dt_stripe_sync_subscriptions() {
	$out = array( 'imported' => 0, 'failed' => 0, 'error' => '' );
	if ( ! ktp_dt_stripe_enabled() || ! class_exists( 'KTPWP_Stripe_Subscription' ) ) {
		return $out;
	}
	$subs = ktp_dt_stripe_contracts();
	if ( ! $subs ) {
		return $out;
	}
	global $wpdb;
	$mode_note = '';

	// 1. 毎回の入金
	$since = (int) get_option( 'ktp_dt_stripe_sub_since', 0 );
	$gte   = ( $since ? $since : time() - 40 * DAY_IN_SECONDS ) - DAY_IN_SECONDS;
	$start = time();
	$paid  = array();
	$after = null;
	for ( $page = 0; $page < 5; $page++ ) {
		$q = array( 'status' => 'paid', 'limit' => 100, 'created' => array( 'gte' => $gte ) );
		if ( $after ) {
			$q['starting_after'] = $after;
		}
		$list = ktp_dt_stripe_request( 'GET', 'invoices', $q );
		if ( is_wp_error( $list ) ) {
			$out['error'] = $list->get_error_message();
			break;
		}
		$data = isset( $list->data ) && is_array( $list->data ) ? $list->data : array();
		foreach ( $data as $inv ) {
			$paid[] = $inv;
		}
		if ( empty( $list->has_more ) || ! $data ) {
			break;
		}
		$after = (string) end( $data )->id;
	}
	$done = ktp_dt_stripe_done();
	foreach ( array_reverse( $paid ) as $inv ) { // 古い順に
		$iid    = (string) ( $inv->id ?? '' );
		$sub_id = ktp_dt_stripe_id_of( $inv->subscription ?? ( $inv->parent->subscription_details->subscription ?? '' ) );
		if ( $iid === '' || $sub_id === '' || ! isset( $subs[ $sub_id ] ) || isset( $done[ $iid ] ) ) {
			continue;
		}
		if ( ( $inv->billing_reason ?? '' ) === 'subscription_create' ) {
			continue; // 最初の請求は、購入の取り込みで済んでいる
		}
		$contract = $subs[ $sub_id ];
		// 本体の処理が読む形に詰め替える（新しい API バージョンでは subscription が parent の中にある）
		$inv->subscription = $sub_id;
		if ( ! isset( $inv->metadata ) || ! is_object( $inv->metadata ) ) {
			$inv->metadata = new stdClass();
		}
		$inv->metadata->ktp_contract_id = (string) (int) $contract->id;
		$line_start = $inv->lines->data[0]->period->start ?? 0;
		if ( $line_start ) {
			$inv->period_start = (int) $line_start; // 請求書自体の period_start は前の期間を指すことがあるので、明細の期間を使う
		}
		KTPWP_Stripe_Subscription::get_instance()->handle_subscription_invoice_paid( $inv );
		$oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ktp_order WHERE stripe_invoice_id = %s LIMIT 1", $iid ) );
		ktp_dt_stripe_mark_done( $iid, $oid ); // 取り込めなかった請求も印を付ける（15 分ごとに同じ失敗を繰り返さない。記録に残す）
		$label = '定期契約の引き落とし（' . sanitize_text_field( (string) $contract->contract_name ) . '・¥' . number_format( (int) ( $inv->amount_paid ?? 0 ) ) . '）';
		if ( $oid ) {
			$out['imported']++;
			ktp_dt_intake_log( array( 'label' => $label, 'order_id' => $oid, 'received_at' => (int) ( $inv->created ?? 0 ), 'message' => 'Stripe で入金済み。今回分の受注を作りました。' ) );
		} else {
			$out['failed']++;
			ktp_dt_intake_log( array( 'label' => $label, 'order_id' => 0, 'received_at' => (int) ( $inv->created ?? 0 ), 'message' => '入金済みですが、今回分の受注を作れませんでした（契約が解約・一時停止中、請求月の対象外、など）。Stripe の請求書 ' . $iid . ' を確かめ、手で受注を入れてください。' ) );
		}
	}
	if ( '' === $out['error'] ) {
		update_option( 'ktp_dt_stripe_sub_since', $start, false );
	}

	// 2・3. 契約ごとに、Stripe の状態と DT の状態を合わせる
	$cancel_set = ktp_dt_stripe_flag_get( 'ktp_dt_stripe_sub_cancel_set' );
	$pause_set  = ktp_dt_stripe_flag_get( 'ktp_dt_stripe_sub_pause_set' );
	$warned     = ktp_dt_stripe_flag_get( 'ktp_dt_stripe_sub_warned' );
	foreach ( $subs as $sub_id => $c ) {
		$sub = ktp_dt_stripe_request( 'GET', 'subscriptions/' . rawurlencode( $sub_id ) );
		if ( is_wp_error( $sub ) ) {
			if ( false === strpos( $sub->get_error_message(), 'No such' ) && '' === $out['error'] ) {
				$out['error'] = $sub->get_error_message();
			}
			continue; // 別モード（テスト／本番）のものは見えないので触らない
		}
		$st = (string) ( $sub->status ?? '' );
		if ( in_array( $st, array( 'canceled', 'incomplete_expired' ), true ) ) {
			if ( 'cancelled' !== $c->status && class_exists( 'KTPWP_Contract_DB' ) ) {
				$db      = KTPWP_Contract_DB::get_instance();
				$payload = $db->get_contract_payload( (int) $c->id );
				if ( $payload ) {
					$ended               = (int) ( $sub->ended_at ?? ( $sub->canceled_at ?? 0 ) );
					$payload['status']   = 'cancelled';
					$payload['end_date'] = wp_date( 'Y-m-d', $ended ? $ended : time() );
					$saved               = $db->save_contract( $payload, (array) $payload['initial_fees'], (array) $payload['recurring_items'] );
					ktp_dt_intake_log(
						array(
							'label'       => '定期契約の解約（' . sanitize_text_field( (string) $c->contract_name ) . '）',
							'order_id'    => 0,
							'received_at' => time(),
							'message'     => is_wp_error( $saved ) ? 'Stripe で解約されましたが、契約を「解約」にできませんでした（' . $saved->get_error_message() . '）。' : 'Stripe で解約されたため、契約を「解約」にしました。',
						)
					);
				}
			}
			continue;
		}
		if ( in_array( $st, array( 'past_due', 'unpaid' ), true ) ) {
			$key = $sub_id . ':' . wp_date( 'Y-m-d' );
			if ( empty( $warned[ $key ] ) ) {
				$warned = array( $key => time() ) + array_slice( $warned, 0, 50, true );
				update_option( 'ktp_dt_stripe_sub_warned', $warned, false );
				ktp_dt_intake_log( array( 'label' => '定期契約の引き落とし失敗（' . sanitize_text_field( (string) $c->contract_name ) . '）', 'order_id' => 0, 'received_at' => time(), 'message' => '引き落としが失敗しています（Stripe が自動で再試行します）。お客様にカードの確認をお願いしてください。' ) );
			}
		}
		// DT で解約 → 支払い済みの期間の終わりで止める。有効に戻したら予約を取り消す（DT が予約したものだけ）
		$cancelling = ! empty( $sub->cancel_at_period_end );
		if ( 'cancelled' === $c->status && ! $cancelling ) {
			$r = ktp_dt_stripe_request( 'POST', 'subscriptions/' . rawurlencode( $sub_id ), array( 'cancel_at_period_end' => 'true' ) );
			if ( ! is_wp_error( $r ) ) {
				ktp_dt_stripe_flag_set( 'ktp_dt_stripe_sub_cancel_set', $sub_id, true );
			}
		} elseif ( 'cancelled' !== $c->status && $cancelling && isset( $cancel_set[ $sub_id ] ) ) {
			$r = ktp_dt_stripe_request( 'POST', 'subscriptions/' . rawurlencode( $sub_id ), array( 'cancel_at_period_end' => 'false' ) );
			if ( ! is_wp_error( $r ) ) {
				ktp_dt_stripe_flag_set( 'ktp_dt_stripe_sub_cancel_set', $sub_id, false );
			}
		}
		// DT で一時停止 → 引き落としを止める（請求しない）。有効に戻したら再開
		$pausing = ! empty( $sub->pause_collection );
		if ( 'paused' === $c->status && ! $pausing ) {
			$r = ktp_dt_stripe_request( 'POST', 'subscriptions/' . rawurlencode( $sub_id ), array( 'pause_collection' => array( 'behavior' => 'void' ) ) );
			if ( ! is_wp_error( $r ) ) {
				ktp_dt_stripe_flag_set( 'ktp_dt_stripe_sub_pause_set', $sub_id, true );
			}
		} elseif ( 'active' === $c->status && $pausing && isset( $pause_set[ $sub_id ] ) ) {
			$r = ktp_dt_stripe_request( 'POST', 'subscriptions/' . rawurlencode( $sub_id ), array( 'pause_collection' => '' ) );
			if ( ! is_wp_error( $r ) ) {
				ktp_dt_stripe_flag_set( 'ktp_dt_stripe_sub_pause_set', $sub_id, false );
			}
		}
	}
	return $out;
}

// ---- 設定（「サイトからの受注」の画面の中に出す） ----

add_action(
	'admin_post_ktp_dt_stripe_save',
	function () {
		if ( ! function_exists( 'ktp_dt_intake_can_manage' ) || ! ktp_dt_intake_can_manage() ) {
			wp_die( '親機の画面でオーナーだけが操作できます。' );
		}
		check_admin_referer( KTP_DT_STRIPE_NONCE );
		$old    = ktp_dt_stripe_settings();
		$key    = isset( $_POST['stripe_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['stripe_key'] ) ) ) : '';
		$remove = ! empty( $_POST['stripe_remove'] );
		$msg    = 'saved';
		if ( $remove ) {
			$key = '';
		} elseif ( $key === '' ) {
			$key = $old['key'];
		} elseif ( ! ktp_dt_stripe_valid_key( $key ) ) {
			set_transient( 'ktp_dt_intake_notice', 'Stripe の秘密鍵は sk_live_・sk_test_（または制限付きキーの rk_live_・rk_test_）で始まるものを入れてください。公開可能キー（pk_）ではありません。', 60 );
			$key = $old['key'];
			$msg = 'error';
		}
		if ( $remove && $old['key'] !== '' ) {
			// 鍵を外す前に、作った決済ページを止める（サイトのボタンは次の同期で「申し込む」に戻る）
			foreach ( ktp_dt_stripe_links() as $l ) {
				if ( ! empty( $l['active'] ) ) {
					ktp_dt_stripe_request( 'POST', 'payment_links/' . rawurlencode( $l['link'] ), array( 'active' => 'false' ) );
				}
			}
			delete_option( KTP_DT_STRIPE_LINKS );
		}
		update_option( KTP_DT_STRIPE_OPT, array( 'key' => $key ), false );
		// 次の画面表示で、公開商品（「購入する」ボタン）をすぐサイトへ反映する
		if ( defined( 'KTP_DT_CATALOG_DIRTY' ) ) {
			update_option( KTP_DT_CATALOG_DIRTY, 1, false );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . KTP_DT_INTAKE_PAGE . '&ktp_dt_msg=' . $msg . '#ktp-dt-stripe' ) );
		exit;
	}
);

add_action(
	'wp_ajax_ktp_dt_stripe_test',
	function () {
		check_ajax_referer( KTP_DT_INTAKE_NONCE, 'nonce' );
		if ( ! ktp_dt_intake_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		if ( ! ktp_dt_stripe_enabled() ) {
			wp_send_json_error( array( 'message' => '先に「サイトから受注を取り込む」をオンにして、Stripe の秘密鍵を保存してください。' ) );
		}
		$r = ktp_dt_stripe_request( 'GET', 'payment_links', array( 'limit' => 1 ) );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => 'Stripe につながりました（' . ( ktp_dt_stripe_is_test() ? 'テストモード' : '本番' ) . '）。' ) );
	}
);

/** 設定画面の「即時購入（Stripe 決済）」の欄 */
function ktp_dt_stripe_section_html() {
	$s     = ktp_dt_stripe_settings();
	$links = ktp_dt_stripe_links();
	$notes = get_option( 'ktp_dt_stripe_notes', array() );
	$notes = is_array( $notes ) ? $notes : array();
	$names = array();
	if ( class_exists( 'KTPWP_Service_DB' ) && ( $links || $notes ) ) {
		foreach ( (array) KTPWP_Service_DB::get_instance()->get_services( 'service', array( 'is_public' => true, 'limit' => 0 ) ) as $row ) {
			$names[ (int) $row->id ] = (string) $row->service_name;
		}
	}
	ob_start();
	?>
	<h3 id="ktp-dt-stripe" style="margin:20px 0 6px;">5. 即時購入（Stripe 決済）（任意）</h3>
	<p style="margin:0;">サービスの編集画面で「即時購入（Stripe決済）」にチェックした公開商品は、ホームページのボタンが「購入する」になり、お客様は Stripe の決済ページでそのままカード払いできます。
	入金された分は、このアプリが取り込みのときに Stripe から受け取り、<strong>受注（入金済み）</strong>にします（WordPress 版の即時購入と同じ扱い）。</p>
	<p style="color:#6b7280;font-size:13px;margin:6px 0 0;">Stripe の秘密鍵はこのパソコンの中だけに置き、ホームページには渡しません（ホームページには決済ページのアドレスだけを送ります）。
	パソコンが止まっている間に売れた分は、次に起動したときに取り込みます。値段が 1 円単位でない・50 円未満の商品は対象外です（「申し込む」で受け付けます）。
	<strong>定期契約のサービスは、毎回自動で引き落とされます</strong>（初回費用があれば最初の請求に足されます）。契約の「解約」「一時停止」「有効」を変えると、取り込みのときに Stripe にも反映されます（解約は支払い済みの期間の終わりで止まります）。Stripe 側で解約した場合も、契約が「解約」になります。
	受付プラグインは 1.2.0 以降が必要です（定期契約の「毎月」表示は 1.4.0 以降）。</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ktp_dt_stripe_save">
		<?php wp_nonce_field( KTP_DT_STRIPE_NONCE ); ?>
		<table class="form-table" role="presentation" style="margin-top:0;">
			<tr>
				<th scope="row"><label for="ktp-dt-stripe-key">Stripe の秘密鍵</label></th>
				<td>
					<input type="password" id="ktp-dt-stripe-key" name="stripe_key" class="regular-text" autocomplete="off" placeholder="<?php echo $s['key'] !== '' ? esc_attr( '設定済み（' . ( ktp_dt_stripe_is_test() ? 'テストモード' : '本番' ) . '）・変えるときだけ入れる' ) : 'sk_live_ / sk_test_ / rk_live_ で始まるキー'; ?>">
					<p class="description" style="margin:2px 0 0;">Stripe の管理画面「開発者」→「API キー」のシークレットキー。制限付きキーを使う場合は、Products・Prices・Payment Links・Subscriptions の書き込みと、Checkout Sessions・Invoices の読み取りを許可してください。</p>
					<?php if ( $s['key'] !== '' ) : ?>
						<label style="display:block;margin-top:6px;"><input type="checkbox" name="stripe_remove" value="1"> 秘密鍵を外して、即時購入をやめる</label>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<p>
			<button type="submit" class="button button-primary">Stripe の設定を保存</button>
			<button type="button" class="button" id="ktp-dt-stripe-test" <?php disabled( ! ktp_dt_stripe_enabled() ); ?>>Stripe につながるか確かめる</button>
		</p>
	</form>
	<?php if ( $links || $notes ) : ?>
		<table class="widefat striped" style="max-width:760px;margin-top:6px;">
			<thead><tr><th>即時購入にした商品</th><th style="width:16em;">状態</th></tr></thead>
			<tbody>
			<?php foreach ( $links as $id => $l ) : ?>
				<?php if ( empty( $l['active'] ) ) { continue; } ?>
				<tr><td><?php echo esc_html( $names[ (int) $id ] ?? ( 'ID ' . (int) $id ) ); ?></td><td>販売中（<a href="<?php echo esc_url( $l['url'] ); ?>" target="_blank" rel="noopener">決済ページ</a>）</td></tr>
			<?php endforeach; ?>
			<?php foreach ( $notes as $id => $why ) : ?>
				<tr><td><?php echo esc_html( $names[ (int) $id ] ?? ( 'ID ' . (int) $id ) ); ?></td><td style="color:#b45309;"><?php echo esc_html( $why ); ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<p id="ktp-dt-stripe-msg" role="status" style="display:none;font-weight:700;"></p>
	<script>
	(function(){
		var b=document.getElementById('ktp-dt-stripe-test'),m=document.getElementById('ktp-dt-stripe-msg'),w=document.getElementById('ktp-dt-intake');
		if(!b||!w){return;}
		b.onclick=function(){
			m.style.display='block';m.style.color='#1f2937';m.textContent='Stripe に問い合わせています…';b.disabled=true;
			fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({action:'ktp_dt_stripe_test',nonce:w.dataset.nonce}).toString()}).then(function(r){return r.json();}).then(function(j){
				m.style.color=j.success?'#166534':'#b91c1c';m.textContent=(j.data&&j.data.message)||'うまくいきませんでした。';b.disabled=false;
			}).catch(function(){m.style.color='#b91c1c';m.textContent='うまくいきませんでした。';b.disabled=false;});
		};
	})();
	</script>
	<?php
	return (string) ob_get_clean();
}
