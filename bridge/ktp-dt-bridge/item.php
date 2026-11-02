<?php
/**
 * 公開商品の個別ページと、Google ショッピング用のフィード。
 *
 * 商品ごとのページ: https://サイト/item/12/（パーマリンクが基本設定のサイトは https://サイト/?ktp_item=12）
 *   SNS・ブログに貼るとカード表示されるよう OGP を出し、検索向けに構造化データ（Product）を付ける。
 *   テーマの header / footer の中に出し、申し込みフォームは一覧（catalog.php）のものをそのまま使う。
 * フィード: https://サイト/?ktp_feed=google（Google Merchant Center の「スケジュールされた取得」に登録する）
 *
 * 商品は catalog.php が option に持っているものだけを使う（DT から送る中身は変えない）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_BRIDGE_ITEM_BASE     = 'item';
const KTP_DT_BRIDGE_FLUSH_OPT     = 'ktp_dt_bridge_flush_rules'; // transient（ルールを書き直した印。1 時間）
const KTP_DT_BRIDGE_LIST_PAGE_OPT = 'ktp_dt_bridge_list_page';

function ktp_dt_bridge_find_service( $id ) {
	$id = (int) $id;
	foreach ( ktp_dt_bridge_catalog()['services'] as $s ) {
		if ( (int) $s['id'] === $id ) {
			return $s;
		}
	}
	return null;
}

/** 商品ページの URL（カード・管理画面・フィード・サイトマップは、すべてここで作る） */
function ktp_dt_bridge_item_url( $id ) {
	if ( get_option( 'permalink_structure' ) ) {
		return home_url( user_trailingslashit( KTP_DT_BRIDGE_ITEM_BASE . '/' . (int) $id ) );
	}
	return add_query_arg( 'ktp_item', (int) $id, home_url( '/' ) );
}

/** DT に渡す URL の形（sprintf 用。%d が商品 ID） */
function ktp_dt_bridge_item_url_template() {
	return str_replace( '987654321', '%d', ktp_dt_bridge_item_url( 987654321 ) );
}

function ktp_dt_bridge_feed_url() {
	return add_query_arg( 'ktp_feed', 'google', home_url( '/' ) );
}

/**
 * 一覧（ショートコード）を置いたページを覚えておく（商品ページの「商品一覧へ」のリンク先）。
 * 複数のページに置いてあっても書き込みが往復しないよう、覚えたページが公開中のあいだは変えない。
 */
function ktp_dt_bridge_remember_list_url() {
	if ( is_admin() || ! is_singular() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$id = (int) get_queried_object_id();
	if ( $id <= 0 || 'publish' !== get_post_status( $id ) ) {
		return;
	}
	$saved = (int) get_option( KTP_DT_BRIDGE_LIST_PAGE_OPT, 0 );
	if ( $saved !== $id && ( $saved <= 0 || 'publish' !== get_post_status( $saved ) ) ) {
		update_option( KTP_DT_BRIDGE_LIST_PAGE_OPT, $id, false );
	}
}

function ktp_dt_bridge_list_url() {
	$id = (int) get_option( KTP_DT_BRIDGE_LIST_PAGE_OPT, 0 );
	return ( $id > 0 && 'publish' === get_post_status( $id ) ) ? (string) get_permalink( $id ) : '';
}

/** 説明文（メモ → 公開用 HTML の文字 → 商品名の順に使う） */
function ktp_dt_bridge_item_description( array $s, $max ) {
	$text = '' !== $s['memo'] ? $s['memo'] : wp_strip_all_tags( $s['public_html'] );
	$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	if ( '' === $text ) {
		$text = $s['name'];
	}
	return function_exists( 'mb_substr' ) && mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
}

/**
 * 税込の値段（Google の日本向けは税込が必須）。設定で「値段は税抜」にしてあれば税率を掛けて切り捨てる。
 * 税率が入っていない商品は 10% で計算する。
 */
function ktp_dt_bridge_price_with_tax( array $s ) {
	$price = (float) $s['price'];
	if ( ! ktp_dt_bridge_settings()['tax_excluded'] ) {
		return $price;
	}
	$rate = null !== $s['tax_rate'] ? (float) $s['tax_rate'] : 10.0;
	return floor( round( $price * ( 100 + $rate ) / 100, 6 ) );
}

// ---- URL（/item/12/）とクエリ変数 ----

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = 'ktp_item';
		$vars[] = 'ktp_feed';
		return $vars;
	}
);

add_action(
	'init',
	function () {
		$regex = '^' . KTP_DT_BRIDGE_ITEM_BASE . '/([0-9]+)/?$';
		add_rewrite_rule( $regex, 'index.php?ktp_item=$matches[1]', 'top' );
		// 保存されているルールにこの URL が無ければ書き直す（有効化・更新の直後など）。
		// 更新のときに 1 回だけ書き直す形にしたら、kantanpro.com の更新ではルールが入らなかった（2026-11-02）ので、毎回確かめる。
		// ほかのプラグインがルールを消していても毎回書き直さないよう、1 時間に 1 回まで
		$rules = get_option( 'rewrite_rules' );
		if ( get_option( 'permalink_structure' ) && is_array( $rules ) && ! isset( $rules[ $regex ] ) && ! get_transient( KTP_DT_BRIDGE_FLUSH_OPT ) ) {
			set_transient( KTP_DT_BRIDGE_FLUSH_OPT, 1, HOUR_IN_SECONDS );
			flush_rewrite_rules( false );
		}
	},
	20
);

// ---- 商品ページ ----

/** いま表示している商品（商品ページでなければ null） */
function ktp_dt_bridge_current_item() {
	$id = absint( get_query_var( 'ktp_item' ) );
	return $id > 0 ? ktp_dt_bridge_find_service( $id ) : null;
}

add_action(
	'template_redirect',
	function () {
		global $wp_query;
		if ( '' === (string) get_query_var( 'ktp_item' ) ) {
			return;
		}
		$s = ktp_dt_bridge_current_item();
		if ( ! $s ) {
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}
		// トップページとして扱われないようにする（テーマや SEO プラグインが、トップの説明・画像を出さないように）
		$wp_query->is_home = false;
		remove_action( 'template_redirect', 'redirect_canonical' );
		ktp_dt_bridge_item_quiet_others();
		add_filter( 'pre_get_document_title', 'ktp_dt_bridge_item_title', 999 );
		add_action( 'wp_head', 'ktp_dt_bridge_item_head', 1 );
		add_filter(
			'body_class',
			function ( $classes ) {
				$classes[] = 'ktp-dtp-item-page';
				return $classes;
			}
		);
	},
	0
);

add_filter(
	'template_include',
	function ( $template ) {
		return ktp_dt_bridge_current_item() ? __DIR__ . '/templates/item.php' : $template;
	},
	99
);

function ktp_dt_bridge_item_title() {
	$s = ktp_dt_bridge_current_item();
	return $s ? $s['name'] . ' – ' . get_bloginfo( 'name', 'display' ) : '';
}

/**
 * テーマ・SEO プラグインが出す canonical・説明・OGP・構造化データを止める（このページの分はこちらで出す）。
 * 止めないと、トップページの内容で同じタグが二重に出る。
 */
function ktp_dt_bridge_item_quiet_others() {
	// Yoast SEO・All in One SEO・Jetpack
	add_filter( 'wpseo_frontend_presenters', '__return_empty_array', 999 );
	add_filter( 'aioseo_disable', '__return_true' );
	add_filter( 'jetpack_enable_open_graph', '__return_false', 999 );
	add_action(
		'wp_head',
		function () {
			// Rank Math
			remove_all_actions( 'rank_math/head' );
			// Cocoon（OGP・Twitter カード・canonical・説明・キーワード・サムネイル・JSON-LD）と WordPress 本体の canonical
			$fns = array( 'the_facebook_ogp_tag', 'the_twitter_card_tag', 'generate_canonical_tag', 'generate_meta_description_tag', 'generate_meta_keywords_tag', 'generate_meta_thumbnail_tag', 'the_json_ld_tag', 'rel_canonical' );
			foreach ( $fns as $fn ) {
				$prio = has_action( 'wp_head', $fn );
				if ( false !== $prio ) {
					remove_action( 'wp_head', $fn, $prio );
				}
			}
		},
		0
	);
}

function ktp_dt_bridge_item_head() {
	$s = ktp_dt_bridge_current_item();
	if ( ! $s ) {
		return;
	}
	$url   = ktp_dt_bridge_item_url( $s['id'] );
	$img   = '' !== $s['image_hash'] ? ktp_dt_bridge_image_url( $s['id'], $s['image_hash'] ) : '';
	$desc  = ktp_dt_bridge_item_description( $s, 120 );
	$site  = get_bloginfo( 'name', 'display' );
	$tags  = '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	$tags .= '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	$meta  = array(
		'og:type'        => 'product',
		'og:title'       => $s['name'],
		'og:description' => $desc,
		'og:url'         => $url,
		'og:site_name'   => $site,
		'og:image'       => $img,
	);
	foreach ( $meta as $k => $v ) {
		if ( '' !== $v ) {
			$tags .= '<meta property="' . esc_attr( $k ) . '" content="' . esc_attr( $v ) . '">' . "\n";
		}
	}
	$tags .= '<meta name="twitter:card" content="' . ( $img ? 'summary_large_image' : 'summary' ) . '">' . "\n";
	// 構造化データ（Google の商品の検索結果）
	$ld = array(
		'@context'    => 'https://schema.org',
		'@type'       => 'Product',
		'name'        => $s['name'],
		'description' => ktp_dt_bridge_item_description( $s, 5000 ),
		'url'         => $url,
		'brand'       => array( '@type' => 'Brand', 'name' => $site ),
	);
	if ( $img ) {
		$ld['image'] = $img;
	}
	if ( '' !== $s['category'] ) {
		$ld['category'] = $s['category'];
	}
	if ( $s['price'] > 0 ) {
		$ld['offers'] = array(
			'@type'         => 'Offer',
			'price'         => ktp_dt_bridge_price_with_tax( $s ),
			'priceCurrency' => 'JPY',
			'availability'  => $s['open'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'url'           => $url,
		);
	}
	$tags .= '<script type="application/ld+json">' . wp_json_encode( $ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>' . "\n";
	// スタイルは先に出す（一覧はページ末尾で出すが、ここでは本文の見た目が崩れて見えないように）
	$tags .= ktp_dt_bridge_products_css();
	echo $tags; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/** 商品ページの本文（templates/item.php から呼ぶ） */
function ktp_dt_bridge_render_item( array $s ) {
	$GLOBALS['ktp_dt_bridge_products_used'] = true; // ページ末尾で申し込みフォームの JS を出す
	$ts   = time();
	$img  = '' !== $s['image_hash'] ? ktp_dt_bridge_image_url( $s['id'], $s['image_hash'] ) : '';
	$list = ktp_dt_bridge_list_url();
	$html = '<div class="ktp-dtp ktp-dtp--item" data-ajax="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-ts="' . esc_attr( $ts . '-' . ktp_dt_bridge_sign_ts( $ts ) ) . '">';
	if ( $list ) {
		$html .= '<p class="ktp-dtp__back"><a href="' . esc_url( $list ) . '">← 商品一覧へ</a></p>';
	}
	$html .= '<article class="ktp-dtp__pg' . ( $img ? '' : ' ktp-dtp__pg--noimg' ) . ( $s['open'] ? '' : ' is-closed' ) . '">';
	if ( $img ) {
		$html .= '<div class="ktp-dtp__pg-image"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $s['name'] ) . '">'
			. ( $s['open'] ? '' : '<span class="ktp-dtp__badge">' . esc_html( $s['status_label'] ?: '受付停止中' ) . '</span>' ) . '</div>';
	}
	$html .= '<div class="ktp-dtp__pg-body"><h1 class="ktp-dtp__pg-title">' . esc_html( $s['name'] ) . '</h1>';
	if ( '' !== $s['category'] ) {
		$html .= '<p class="ktp-dtp__cat">' . esc_html( $s['category'] ) . '</p>';
	}
	$price = ktp_dt_bridge_price_html( $s, array( 'unit' => true, 'tax' => false ) );
	if ( $s['price'] > 0 && ktp_dt_bridge_settings()['tax_excluded'] ) {
		// 値段が税抜のサイトは税込も添える（フィード・構造化データの税込の値段と、ページの表示を合わせる）
		$price = str_replace( '</p>', ' <span class="ktp-dtp__unit">（税込 ' . esc_html( ktp_dt_bridge_money( ktp_dt_bridge_price_with_tax( $s ) ) ) . '）</span></p>', $price );
	}
	$html .= $price . ktp_dt_bridge_action_html( $s, false );
	if ( '' !== $s['memo'] ) {
		$html .= '<p class="ktp-dtp__memo">' . nl2br( esc_html( $s['memo'] ) ) . '</p>';
	}
	if ( '' !== $s['public_html'] ) {
		$html .= '<div class="ktp-dtp__html">' . $s['public_html'] . '</div>';
	}
	$html .= '</div></article>' . ktp_dt_bridge_products_form() . '</div>';
	return $html;
}

// ---- Google ショッピング用フィード ----

add_action(
	'template_redirect',
	function () {
		if ( 'google' !== get_query_var( 'ktp_feed' ) ) {
			return;
		}
		$site = get_bloginfo( 'name', 'display' );
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>' . "\n"
			. '<title>' . esc_xml( $site ) . '</title><link>' . esc_xml( home_url( '/' ) ) . '</link><description>' . esc_xml( get_bloginfo( 'description', 'display' ) ?: $site ) . '</description>' . "\n";
		foreach ( ktp_dt_bridge_catalog()['services'] as $s ) {
			$img = '' !== $s['image_hash'] ? ktp_dt_bridge_image_url( $s['id'], $s['image_hash'] ) : '';
			// 画像は Google の必須項目。0 円と定期契約（毎月など）は通常の商品として載せられないので外す
			if ( '' === $img || $s['price'] <= 0 || '' !== ( $s['billing_cycle_label'] ?? '' ) ) {
				continue;
			}
			$fields = array(
				'g:id'                => 'ktp-' . (int) $s['id'],
				'g:title'             => function_exists( 'mb_substr' ) ? mb_substr( $s['name'], 0, 150 ) : $s['name'],
				'g:description'       => ktp_dt_bridge_item_description( $s, 5000 ),
				'g:link'              => ktp_dt_bridge_item_url( $s['id'] ),
				'g:image_link'        => $img,
				'g:price'             => ktp_dt_bridge_price_with_tax( $s ) . ' JPY',
				'g:availability'      => $s['open'] ? 'in_stock' : 'out_of_stock',
				'g:condition'         => 'new',
				'g:brand'             => $site,
				'g:identifier_exists' => 'no',
				'g:product_type'      => $s['category'],
			);
			$xml .= '<item>';
			foreach ( $fields as $tag => $v ) {
				if ( '' !== (string) $v ) {
					$xml .= '<' . $tag . '>' . esc_xml( (string) $v ) . '</' . $tag . '>';
				}
			}
			$xml .= '</item>' . "\n";
		}
		$xml .= '</channel></rss>' . "\n";
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	},
	0
);

// ---- WordPress のサイトマップ（wp-sitemap.xml）に商品ページを足す ----

add_action(
	'init',
	function () {
		if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
			return;
		}
		if ( ! class_exists( 'KTP_DT_Bridge_Sitemap_Provider' ) ) {
			// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
			class KTP_DT_Bridge_Sitemap_Provider extends WP_Sitemaps_Provider {
				public function __construct() {
					$this->name        = 'ktpitems';
					$this->object_type = 'ktpitems';
				}

				public function get_url_list( $page_num, $object_subtype = '' ) {
					if ( (int) $page_num > 1 ) {
						return array();
					}
					return array_map(
						function ( $s ) {
							return array( 'loc' => ktp_dt_bridge_item_url( $s['id'] ) );
						},
						ktp_dt_bridge_catalog()['services']
					);
				}

				public function get_max_num_pages( $object_subtype = '' ) {
					return ktp_dt_bridge_catalog()['services'] ? 1 : 0;
				}
			}
		}
		wp_register_sitemap_provider( 'ktpitems', new KTP_DT_Bridge_Sitemap_Provider() );
	}
);
