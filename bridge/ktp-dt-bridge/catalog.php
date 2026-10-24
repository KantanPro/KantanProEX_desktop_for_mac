<?php
/**
 * 公開商品（ホームページに商品を並べ、申し込みを受け付ける）。
 *
 * KantanProEX（DT）が「サイト公開」にしたサービスを、接続キー付きでこのサイトへ送ってくる（POST /catalog・/catalog-image）。
 * このプラグインは、受け取った商品をショートコードでカード表示し、申し込みを受付箱（kind=public_order）に預ける。
 * 申し込みを受注にするのは KantanProEX（DT）で、受付箱から取り込むときに行う（在庫・受付可否の判定もそちら）。
 *
 * ショートコード: [ktp_dt_products]（KantanProEX（WP）が無いサイトでは、WP 版と同じ [ktpwp_public_products] でも表示できる）
 *   属性: layout=grid|cards|table（cards・table は 1 列の一覧）、columns=1〜4、category=カテゴリー（カンマ区切り）、
 *         ids=商品 ID（カンマ区切り）、limit=件数、show_image / show_price / show_unit / show_category / show_memo / show_tax / show_filter=yes|no
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_BRIDGE_CAT_OPT     = 'ktp_dt_bridge_catalog';
const KTP_DT_BRIDGE_IMG_OPT     = 'ktp_dt_bridge_images';
const KTP_DT_BRIDGE_MAX_SERVICES = 300;
const KTP_DT_BRIDGE_MAX_IMAGE   = 3145728; // 3MB（縮小済みの画像が来る）

function ktp_dt_bridge_catalog() {
	$c = get_option( KTP_DT_BRIDGE_CAT_OPT, array() );
	return is_array( $c ) && isset( $c['services'] ) && is_array( $c['services'] ) ? $c : array( 'hash' => '', 'updated' => 0, 'services' => array() );
}

function ktp_dt_bridge_images() {
	$m = get_option( KTP_DT_BRIDGE_IMG_OPT, array() );
	return is_array( $m ) ? $m : array();
}

function ktp_dt_bridge_image_dir() {
	$u = wp_upload_dir( null, false );
	return array( trailingslashit( $u['basedir'] ) . 'ktp-dt-bridge/', trailingslashit( $u['baseurl'] ) . 'ktp-dt-bridge/' );
}

/** 画像ファイルが実在するか（あれば URL を返す） */
function ktp_dt_bridge_image_url( $service_id, $hash ) {
	$map = ktp_dt_bridge_images();
	$id  = (int) $service_id;
	if ( empty( $map[ $id ]['file'] ) || ( $map[ $id ]['hash'] ?? '' ) !== $hash ) {
		return '';
	}
	list( $dir, $url ) = ktp_dt_bridge_image_dir();
	$file = basename( (string) $map[ $id ]['file'] );
	return is_file( $dir . $file ) ? $url . $file : '';
}

function ktp_dt_bridge_remove_image_files( array $keep_ids ) {
	$map = ktp_dt_bridge_images();
	list( $dir ) = ktp_dt_bridge_image_dir();
	foreach ( $map as $id => $info ) {
		if ( ! in_array( (int) $id, $keep_ids, true ) ) {
			if ( ! empty( $info['file'] ) ) {
				@unlink( $dir . basename( (string) $info['file'] ) );
			}
			unset( $map[ $id ] );
		}
	}
	update_option( KTP_DT_BRIDGE_IMG_OPT, $map, false );
}

/** 画像が必要なのにまだ届いていない商品の ID（DT が取得応答で見て、足りない画像だけ送る） */
function ktp_dt_bridge_catalog_need_images() {
	$need = array();
	foreach ( ktp_dt_bridge_catalog()['services'] as $s ) {
		if ( '' !== $s['image_hash'] && '' === ktp_dt_bridge_image_url( $s['id'], $s['image_hash'] ) ) {
			$need[] = (int) $s['id'];
		}
	}
	return $need;
}

/** 受け取った 1 商品を、決まった形・文字だけにそろえる */
function ktp_dt_bridge_clean_service( $s ) {
	if ( ! is_array( $s ) || empty( $s['id'] ) ) {
		return null;
	}
	$rate = isset( $s['tax_rate'] ) && is_numeric( $s['tax_rate'] ) ? (float) $s['tax_rate'] : null;
	return array(
		'id'             => (int) $s['id'],
		'name'           => sanitize_text_field( (string) ( $s['name'] ?? '' ) ),
		'price'          => isset( $s['price'] ) ? (float) $s['price'] : 0.0,
		'tax_rate'       => ( null !== $rate && $rate >= 0 && $rate < 100 ) ? $rate : null,
		'unit'           => sanitize_text_field( (string) ( $s['unit'] ?? '' ) ),
		'category'       => sanitize_text_field( (string) ( $s['category'] ?? '' ) ),
		'memo'           => sanitize_textarea_field( (string) ( $s['memo'] ?? '' ) ),
		'public_html'    => wp_kses_post( (string) ( $s['public_html'] ?? '' ) ),
		'quantity_fixed' => ! empty( $s['quantity_fixed'] ),
		'open'           => ! empty( $s['open'] ),
		'status_label'   => sanitize_text_field( (string) ( $s['status_label'] ?? '' ) ),
		'image_hash'     => preg_match( '/^[a-f0-9]{32}$/', (string) ( $s['image_hash'] ?? '' ) ) ? (string) $s['image_hash'] : '',
	);
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			KTP_DT_BRIDGE_NS,
			'/catalog',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'ktp_dt_bridge_rest_auth',
				'callback'            => function ( WP_REST_Request $request ) {
					$in       = $request->get_json_params();
					$raw      = is_array( $in ) && isset( $in['services'] ) && is_array( $in['services'] ) ? $in['services'] : null;
					$hash     = is_array( $in ) && isset( $in['hash'] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $in['hash'] ) : '';
					if ( null === $raw || '' === $hash || count( $raw ) > KTP_DT_BRIDGE_MAX_SERVICES ) {
						return new WP_Error( 'ktp_dt_bridge_bad', '商品の形式が正しくありません。', array( 'status' => 400 ) );
					}
					$services = array();
					foreach ( $raw as $s ) {
						$c = ktp_dt_bridge_clean_service( $s );
						if ( $c && '' !== $c['name'] ) {
							$services[ $c['id'] ] = $c;
						}
					}
					$services = array_values( $services );
					// 画像: 同じものが届いていれば送り直さない。商品から外れた画像は消す
					$need = array();
					$keep = array();
					foreach ( $services as $s ) {
						if ( '' === $s['image_hash'] ) {
							continue;
						}
						$keep[] = $s['id'];
						if ( '' === ktp_dt_bridge_image_url( $s['id'], $s['image_hash'] ) ) {
							$need[] = $s['id'];
						}
					}
					ktp_dt_bridge_remove_image_files( $keep );
					update_option(
						KTP_DT_BRIDGE_CAT_OPT,
						array(
							'hash'     => $hash,
							'updated'  => time(),
							'services' => $services,
						),
						false
					);
					return rest_ensure_response(
						array(
							'hash'        => $hash,
							'count'       => count( $services ),
							'need_images' => $need,
						)
					);
				},
			)
		);
		register_rest_route(
			KTP_DT_BRIDGE_NS,
			'/catalog-image',
			array(
				'methods'             => 'POST',
				'permission_callback' => 'ktp_dt_bridge_rest_auth',
				'callback'            => function ( WP_REST_Request $request ) {
					$in   = $request->get_json_params();
					$id   = is_array( $in ) ? (int) ( $in['id'] ?? 0 ) : 0;
					$hash = is_array( $in ) ? (string) ( $in['hash'] ?? '' ) : '';
					$data = is_array( $in ) ? base64_decode( (string) ( $in['data'] ?? '' ), true ) : false;
					if ( $id <= 0 || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) || false === $data || '' === $data || strlen( $data ) > KTP_DT_BRIDGE_MAX_IMAGE ) {
						return new WP_Error( 'ktp_dt_bridge_bad', '画像の形式が正しくありません。', array( 'status' => 400 ) );
					}
					$info = @getimagesizefromstring( $data );
					if ( ! $info || IMAGETYPE_JPEG !== $info[2] || $info[0] > 4000 || $info[1] > 4000 ) {
						return new WP_Error( 'ktp_dt_bridge_bad', '画像は JPEG（4000px 以下）だけ受け付けます。', array( 'status' => 400 ) );
					}
					$cat = ktp_dt_bridge_catalog();
					$ids = wp_list_pluck( $cat['services'], 'id' );
					if ( ! in_array( $id, array_map( 'intval', $ids ), true ) ) {
						return new WP_Error( 'ktp_dt_bridge_bad', '商品が登録されていません。', array( 'status' => 400 ) );
					}
					list( $dir ) = ktp_dt_bridge_image_dir();
					wp_mkdir_p( $dir );
					if ( ! is_file( $dir . 'index.html' ) ) {
						file_put_contents( $dir . 'index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					}
					$file = 'p' . $id . '-' . substr( $hash, 0, 12 ) . '.jpg';
					if ( false === file_put_contents( $dir . $file, $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
						return new WP_Error( 'ktp_dt_bridge_write', '画像を保存できませんでした。', array( 'status' => 500 ) );
					}
					$map = ktp_dt_bridge_images();
					if ( ! empty( $map[ $id ]['file'] ) && $map[ $id ]['file'] !== $file ) {
						@unlink( $dir . basename( (string) $map[ $id ]['file'] ) );
					}
					$map[ $id ] = array( 'hash' => $hash, 'file' => $file );
					update_option( KTP_DT_BRIDGE_IMG_OPT, $map, false );
					return rest_ensure_response( array( 'ok' => true ) );
				},
			)
		);
	}
);

// ---- ショートコード ----

add_action(
	'init',
	function () {
		add_shortcode( 'ktp_dt_products', 'ktp_dt_bridge_render_products' );
		// KantanProEX（WP）が先に登録していれば、そちらを優先する（二重に出さない）
		if ( ! shortcode_exists( 'ktpwp_public_products' ) ) {
			add_shortcode( 'ktpwp_public_products', 'ktp_dt_bridge_render_products' );
		}
	},
	30
);

function ktp_dt_bridge_flag( $v ) {
	return in_array( strtolower( trim( (string) $v ) ), array( '1', 'true', 'yes', 'on' ), true );
}

function ktp_dt_bridge_money( $n ) {
	$n = (float) $n;
	return '¥' . ( floor( $n ) == $n ? number_format( $n ) : rtrim( rtrim( number_format( $n, 2 ), '0' ), '.' ) );
}

function ktp_dt_bridge_render_products( $atts = array() ) {
	static $instance = 0;
	++$instance;
	$a = shortcode_atts(
		array(
			'layout'        => 'grid',
			'columns'       => '3',
			'category'      => '',
			'ids'           => '',
			'limit'         => '0',
			'show_image'    => 'yes',
			'show_price'    => 'yes',
			'show_unit'     => 'yes',
			'show_category' => 'yes',
			'show_memo'     => 'yes',
			'show_tax'      => 'no',
			'show_filter'   => 'yes',
		),
		(array) $atts
	);
	$cat      = ktp_dt_bridge_catalog();
	$services = $cat['services'];
	$ids      = array_filter( array_map( 'absint', preg_split( '/\s*,\s*/', (string) $a['ids'] ) ) );
	$cats     = array_filter( array_map( 'sanitize_text_field', preg_split( '/\s*,\s*/', (string) $a['category'] ) ) );
	if ( $ids ) {
		$services = array_values( array_filter( $services, function ( $s ) use ( $ids ) { return in_array( (int) $s['id'], $ids, true ); } ) );
	}
	if ( $cats ) {
		$services = array_values( array_filter( $services, function ( $s ) use ( $cats ) { return in_array( $s['category'], $cats, true ); } ) );
	}
	$limit = max( 0, (int) $a['limit'] );
	if ( $limit ) {
		$services = array_slice( $services, 0, $limit );
	}
	if ( ! $services ) {
		return '<p class="ktp-dtp ktp-dtp--empty">公開中の商品がありません。</p>';
	}
	$cols   = max( 1, min( 4, (int) $a['columns'] ) );
	$layout = in_array( $a['layout'], array( 'cards', 'table' ), true ) ? 'list' : 'grid';
	if ( 'list' === $layout ) {
		$cols = 1;
	}
	$show = array();
	foreach ( array( 'image', 'price', 'unit', 'category', 'memo', 'tax', 'filter' ) as $k ) {
		$show[ $k ] = ktp_dt_bridge_flag( $a[ 'show_' . $k ] );
	}
	$categories = array();
	foreach ( $services as $s ) {
		if ( '' !== $s['category'] && ! in_array( $s['category'], $categories, true ) ) {
			$categories[] = $s['category'];
		}
	}
	$ts   = time();
	$uid  = 'ktp-dtp-' . $instance;
	$GLOBALS['ktp_dt_bridge_products_used'] = true;
	$html = '<div class="ktp-dtp ktp-dtp--' . esc_attr( $layout ) . '" id="' . esc_attr( $uid ) . '" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-ts="' . esc_attr( $ts . '-' . ktp_dt_bridge_sign_ts( $ts ) ) . '">';
	if ( $show['filter'] && count( $categories ) > 1 ) {
		$html .= '<div class="ktp-dtp__filter"><button type="button" class="ktp-dtp__chip is-active" data-cat="">すべて</button>';
		foreach ( $categories as $c ) {
			$html .= '<button type="button" class="ktp-dtp__chip" data-cat="' . esc_attr( $c ) . '">' . esc_html( $c ) . '</button>';
		}
		$html .= '</div>';
	}
	$html .= '<div class="ktp-dtp__list ktp-dtp__list--cols-' . (int) $cols . '">';
	foreach ( $services as $s ) {
		$img = $show['image'] && '' !== $s['image_hash'] ? ktp_dt_bridge_image_url( $s['id'], $s['image_hash'] ) : '';
		$html .= '<article class="ktp-dtp__item' . ( $s['open'] ? '' : ' is-closed' ) . '" data-cat="' . esc_attr( $s['category'] ) . '">';
		if ( $img ) {
			$html .= '<div class="ktp-dtp__image"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $s['name'] ) . '" loading="lazy">'
				. ( $s['open'] ? '' : '<span class="ktp-dtp__badge">' . esc_html( $s['status_label'] ?: '受付停止中' ) . '</span>' ) . '</div>';
		}
		$html .= '<div class="ktp-dtp__body"><h3 class="ktp-dtp__name">' . esc_html( $s['name'] ) . '</h3>';
		if ( $show['category'] && '' !== $s['category'] ) {
			$html .= '<p class="ktp-dtp__cat">' . esc_html( $s['category'] ) . '</p>';
		}
		if ( $show['price'] ) {
			$html .= '<p class="ktp-dtp__price">' . esc_html( ktp_dt_bridge_money( $s['price'] ) )
				. ( $show['unit'] && '' !== $s['unit'] ? ' <span class="ktp-dtp__unit">/ ' . esc_html( $s['unit'] ) . '</span>' : '' )
				. ( $show['tax'] && null !== $s['tax_rate'] ? ' <span class="ktp-dtp__unit">（税' . esc_html( rtrim( rtrim( number_format( $s['tax_rate'], 2 ), '0' ), '.' ) ) . '%）</span>' : '' )
				. '</p>';
		}
		if ( $show['memo'] && '' !== $s['memo'] ) {
			$html .= '<p class="ktp-dtp__memo">' . nl2br( esc_html( $s['memo'] ) ) . '</p>';
		}
		if ( '' !== $s['public_html'] ) {
			$html .= '<div class="ktp-dtp__html">' . $s['public_html'] . '</div>';
		}
		$html .= '</div>';
		if ( $s['open'] ) {
			$html .= '<button type="button" class="ktp-dtp__apply" data-id="' . (int) $s['id'] . '" data-name="' . esc_attr( $s['name'] ) . '" data-fixed="' . ( $s['quantity_fixed'] ? '1' : '0' ) . '">申し込む・問い合わせる</button>';
		} elseif ( ! $img ) {
			$html .= '<p class="ktp-dtp__closed">' . esc_html( $s['status_label'] ?: '受付停止中' ) . '</p>';
		}
		$html .= '</article>';
	}
	$html .= '<p class="ktp-dtp__none" hidden>該当する商品がありません。</p></div>';
	$html .= ktp_dt_bridge_products_form();
	$html .= '</div>';
	return $html;
}

/**
 * スタイルとスクリプトは、ページの末尾で 1 回だけ出す。
 * テーマによってはショートコードを説明文づくりなどで先に実行して出力を捨てるため、描画のときに出す形だと、
 * 実際のページには出ないことがある。
 */
add_action(
	'wp_footer',
	function () {
		if ( ! empty( $GLOBALS['ktp_dt_bridge_products_used'] ) ) {
			echo ktp_dt_bridge_products_css() . ktp_dt_bridge_products_js(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	},
	20
);

function ktp_dt_bridge_products_form() {
	return '<dialog class="ktp-dtp__dialog"><form class="ktp-dtp__form" method="dialog" novalidate>'
		. '<h3 class="ktp-dtp__dtitle">お申し込み・お問い合わせ</h3><p class="ktp-dtp__dname"></p>'
		. '<p class="ktp-dtp__hp" aria-hidden="true"><label>確認用（空のままにしてください）<input type="text" name="ktp_dt_hp" tabindex="-1" autocomplete="off"></label></p>'
		. '<label>会社名（任意）<input type="text" name="company_name" autocomplete="organization"></label>'
		. '<label>お名前<span class="ktp-dtp__req">必須</span><input type="text" name="contact_name" autocomplete="name" required></label>'
		. '<label>メールアドレス<span class="ktp-dtp__req">必須</span><input type="email" name="email" autocomplete="email" required></label>'
		. '<label>電話番号（任意）<input type="tel" name="phone" autocomplete="tel"></label>'
		. '<label class="ktp-dtp__qty">数量<input type="number" name="quantity" min="1" step="1" value="1"></label>'
		. '<label>ご要望・メッセージ（任意）<textarea name="message" rows="4"></textarea></label>'
		. '<p class="ktp-dtp__msg" role="status"></p>'
		. '<div class="ktp-dtp__actions"><button type="button" class="ktp-dtp__cancel">閉じる</button><button type="submit" class="ktp-dtp__send">送信する</button></div>'
		. '</form></dialog>';
}

function ktp_dt_bridge_products_css() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<style id="ktp-dtp-css">
.ktp-dtp{--ktp-bd:rgba(128,128,128,.35);--ktp-ac:#2563eb;--ktp-bg:#fff;--ktp-fg:#111827;margin:1.5em 0}
@media (prefers-color-scheme:dark){.ktp-dtp{--ktp-bg:#1f2937;--ktp-fg:#f3f4f6;--ktp-ac:#60a5fa}}
.ktp-dtp__filter{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 16px}
.ktp-dtp__chip{border:1px solid var(--ktp-bd);background:transparent;color:inherit;border-radius:999px;padding:4px 14px;cursor:pointer;font-size:14px}
.ktp-dtp__chip.is-active{background:var(--ktp-ac);border-color:var(--ktp-ac);color:#fff}
.ktp-dtp__list{display:grid;gap:20px;grid-template-columns:repeat(var(--cols,3),minmax(0,1fr))}
.ktp-dtp__list--cols-1{--cols:1}.ktp-dtp__list--cols-2{--cols:2}.ktp-dtp__list--cols-3{--cols:3}.ktp-dtp__list--cols-4{--cols:4}
@media (max-width:720px){.ktp-dtp__list{--cols:1!important}}
.ktp-dtp__item{border:1px solid var(--ktp-bd);border-radius:10px;overflow:hidden;display:flex;flex-direction:column}
.ktp-dtp__item[hidden]{display:none}
.ktp-dtp--list .ktp-dtp__item{flex-direction:row;align-items:stretch}
.ktp-dtp--list .ktp-dtp__image{width:200px;flex:none}
@media (max-width:720px){.ktp-dtp--list .ktp-dtp__item{flex-direction:column}.ktp-dtp--list .ktp-dtp__image{width:auto}}
.ktp-dtp__image{position:relative;background:rgba(128,128,128,.12)}
.ktp-dtp__image img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover}
.ktp-dtp__badge{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.55);color:#fff;font-weight:700;font-size:18px}
.ktp-dtp__body{padding:14px 16px;flex:1}
.ktp-dtp .ktp-dtp__name,.ktp-dtp .ktp-dtp__dtitle{margin:0 0 6px;padding:0;border:0;background:none;color:inherit;font-size:1.1em;line-height:1.4}
.ktp-dtp .ktp-dtp__name::before,.ktp-dtp .ktp-dtp__name::after{content:none}
.ktp-dtp__cat{margin:0 0 6px;font-size:12px;opacity:.7}
.ktp-dtp__price{margin:0 0 8px;font-weight:700;font-size:1.15em}
.ktp-dtp__unit{font-weight:400;font-size:.8em;opacity:.8}
.ktp-dtp__memo{margin:0 0 8px;font-size:14px;line-height:1.6}
.ktp-dtp__html{font-size:14px;line-height:1.6}
.ktp-dtp__apply{margin:0 16px 16px;padding:10px 14px;border:0;border-radius:8px;background:var(--ktp-ac);color:#fff;font-size:15px;cursor:pointer}
.ktp-dtp__closed{margin:0 16px 16px;font-weight:700;opacity:.7}
.ktp-dtp__dialog{border:1px solid var(--ktp-bd);border-radius:12px;padding:0;max-width:480px;width:calc(100% - 32px);max-height:90vh;overflow:auto;margin:auto!important;background:var(--ktp-bg);color:var(--ktp-fg)}
.ktp-dtp__dialog::backdrop{background:rgba(0,0,0,.5)}
.ktp-dtp__form{padding:20px}
.ktp-dtp__form label{display:block;margin:0 0 12px;font-size:14px}
.ktp-dtp__form input,.ktp-dtp__form textarea{font-family:inherit;display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:8px;border:1px solid var(--ktp-bd);border-radius:6px;background:transparent;color:inherit;font-size:16px}
.ktp-dtp__dtitle{margin:0 0 4px}.ktp-dtp__dname{margin:0 0 14px;font-weight:700}
.ktp-dtp__req{margin-left:6px;font-size:11px;color:#dc2626}
.ktp-dtp__hp{position:absolute;left:-9999px;height:0;overflow:hidden}
.ktp-dtp__msg{min-height:1.4em;margin:0 0 8px;font-size:14px}.ktp-dtp__msg.is-ng{color:#dc2626}.ktp-dtp__msg.is-ok{color:#16a34a}
.ktp-dtp__actions{display:flex;justify-content:flex-end;gap:10px}
.ktp-dtp__actions button{padding:9px 16px;border-radius:8px;font-size:15px;cursor:pointer;border:1px solid var(--ktp-bd);background:transparent;color:inherit}
.ktp-dtp__actions .ktp-dtp__send{background:var(--ktp-ac);border-color:var(--ktp-ac);color:#fff}
.ktp-dtp__actions button[disabled]{opacity:.6;cursor:default}
</style>';
}

function ktp_dt_bridge_products_js() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<script id="ktp-dtp-js">(function(){
function init(root){
var dlg=root.querySelector(".ktp-dtp__dialog"),form=root.querySelector(".ktp-dtp__form"),msg=root.querySelector(".ktp-dtp__msg");
if(!dlg||!form){return;}
var cur=0,sending=false;
root.querySelectorAll(".ktp-dtp__apply").forEach(function(b){b.addEventListener("click",function(){
cur=b.dataset.id;form.reset();msg.textContent="";msg.className="ktp-dtp__msg";
root.querySelector(".ktp-dtp__dname").textContent=b.dataset.name;
var q=form.querySelector(".ktp-dtp__qty");q.style.display=b.dataset.fixed==="1"?"none":"";form.quantity.value=1;
form.querySelector(".ktp-dtp__send").disabled=false;
if(dlg.showModal){dlg.showModal();}else{dlg.setAttribute("open","");}
});});
root.querySelector(".ktp-dtp__cancel").addEventListener("click",function(){dlg.close?dlg.close():dlg.removeAttribute("open");});
form.addEventListener("submit",function(e){
e.preventDefault();if(sending){return;}
if(!form.contact_name.value.trim()||!form.email.value.trim()){msg.className="ktp-dtp__msg is-ng";msg.textContent="お名前とメールアドレスを入力してください。";return;}
sending=true;var send=form.querySelector(".ktp-dtp__send");send.disabled=true;msg.className="ktp-dtp__msg";msg.textContent="送信しています…";
var fd=new FormData(form);fd.append("action","ktp_dt_order");fd.append("service_id",cur);fd.append("ktp_dt_ts",root.dataset.ts);
fetch(root.dataset.ajax,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json();}).then(function(j){
sending=false;
if(j&&j.success){msg.className="ktp-dtp__msg is-ok";msg.textContent=(j.data&&j.data.message)||"受け付けました。";}
else{msg.className="ktp-dtp__msg is-ng";msg.textContent=(j&&j.data&&j.data.message)||"送信できませんでした。";send.disabled=false;}
}).catch(function(){sending=false;send.disabled=false;msg.className="ktp-dtp__msg is-ng";msg.textContent="送信できませんでした。時間をおいてもう一度お試しください。";});
});
var chips=root.querySelectorAll(".ktp-dtp__chip");
chips.forEach(function(c){c.addEventListener("click",function(){
chips.forEach(function(x){x.classList.toggle("is-active",x===c);});
var n=0;root.querySelectorAll(".ktp-dtp__item").forEach(function(it){var show=!c.dataset.cat||it.dataset.cat===c.dataset.cat;it.hidden=!show;if(show){n++;}});
root.querySelector(".ktp-dtp__none").hidden=n>0;
});});
}
function go(){document.querySelectorAll(".ktp-dtp[data-ajax]").forEach(function(r){if(!r.dataset.ready){r.dataset.ready="1";init(r);}});}
if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",go);}else{go();}
})();</script>';
}

// ---- 申し込みの受け口（ログインしていない人が使う。admin-ajax） ----

function ktp_dt_bridge_order_fail( $message, $status = 400 ) {
	wp_send_json_error( array( 'message' => $message ), $status );
}

function ktp_dt_bridge_handle_order() {
	if ( ! ktp_dt_bridge_settings()['shop'] ) {
		ktp_dt_bridge_order_fail( '現在、お申し込みを受け付けておりません。', 403 );
	}
	// 同じ人からの連続送信を止める（10 分に 5 回まで）
	$limit_key = 'ktp_dt_bridge_ord_' . md5( ktp_dt_bridge_client_ip() );
	$count     = (int) get_transient( $limit_key );
	if ( $count >= 5 ) {
		ktp_dt_bridge_order_fail( '送信が続いたため、しばらくしてからもう一度お試しください。', 429 );
	}
	$spam = '送信内容を確認できませんでした。時間をおいて再度お試しください。';
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	if ( ! empty( $_POST['ktp_dt_hp'] ) ) {
		ktp_dt_bridge_order_fail( $spam );
	}
	$ts = isset( $_POST['ktp_dt_ts'] ) ? sanitize_text_field( wp_unslash( $_POST['ktp_dt_ts'] ) ) : '';
	$p  = explode( '-', $ts, 2 );
	if ( 2 !== count( $p ) || ! ctype_digit( $p[0] ) || ! hash_equals( ktp_dt_bridge_sign_ts( (int) $p[0] ), $p[1] ) || time() - (int) $p[0] < 3 ) {
		ktp_dt_bridge_order_fail( $spam );
	}
	$service_id = isset( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : 0;
	$company    = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
	$name       = isset( $_POST['contact_name'] ) ? sanitize_text_field( wp_unslash( $_POST['contact_name'] ) ) : '';
	$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
	$message    = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$quantity   = isset( $_POST['quantity'] ) ? (float) $_POST['quantity'] : 1;
	// phpcs:enable
	$found = null;
	foreach ( ktp_dt_bridge_catalog()['services'] as $s ) {
		if ( (int) $s['id'] === $service_id ) {
			$found = $s;
			break;
		}
	}
	if ( ! $found ) {
		ktp_dt_bridge_order_fail( '指定された商品は公開されていないか、存在しません。' );
	}
	if ( ! $found['open'] ) {
		ktp_dt_bridge_order_fail( '現在この商品はお申し込みを受け付けておりません。' );
	}
	if ( '' === $name ) {
		ktp_dt_bridge_order_fail( 'お名前を入力してください。' );
	}
	if ( '' === $email || ! is_email( $email ) ) {
		ktp_dt_bridge_order_fail( '有効なメールアドレスを入力してください。' );
	}
	foreach ( array( $company, $name, $phone, $message ) as $text ) {
		if ( preg_match( '/<\/?[a-zA-Z][a-zA-Z0-9]*(\s[^<>]*)?>/', $text ) === 1 ) {
			ktp_dt_bridge_order_fail( 'HTMLタグを含む内容は送信できません。' );
		}
		if ( preg_match_all( '#(https?://|www\.)[^\s]+#i', $text ) > 2 ) {
			ktp_dt_bridge_order_fail( 'URLの記載が多いため送信できません。' );
		}
	}
	if ( ktp_dt_bridge_settings()['ja_only'] && ktp_dt_bridge_detect_language( $message ) === 'other' ) {
		ktp_dt_bridge_order_fail( '現在、設定されている言語以外でのお問い合わせは受け付けておりません。' );
	}
	if ( function_exists( 'mb_substr' ) ) {
		$message = mb_substr( $message, 0, 3000 );
	}
	$quantity = $found['quantity_fixed'] ? 1 : max( 1, min( 9999, $quantity ) );
	set_transient( $limit_key, $count + 1, 10 * MINUTE_IN_SECONDS );
	$ok = ktp_dt_bridge_enqueue(
		'public_order',
		array(
			'service_id'   => $service_id,
			'service_name' => $found['name'],
			'form'         => array(
				'company_name' => $company,
				'contact_name' => $name,
				'email'        => $email,
				'phone'        => $phone,
				'message'      => $message,
				'quantity'     => $quantity,
			),
		)
	);
	if ( ! $ok ) {
		ktp_dt_bridge_order_fail( '受け付けに失敗しました。時間をおいてもう一度お試しください。', 500 );
	}
	wp_send_json_success( array( 'message' => 'お申し込みを受け付けました。担当者よりご連絡いたします。' ) );
}
add_action( 'wp_ajax_nopriv_ktp_dt_order', 'ktp_dt_bridge_handle_order' );
add_action( 'wp_ajax_ktp_dt_order', 'ktp_dt_bridge_handle_order' );
