<?php
/**
 * 表示言語（日本語 / English）。
 *
 * 言語はアプリ（Electron）の設定で決まり、環境変数 KTP_DESKTOP_LANG（ja / en）で届く（lib/i18n.js・lib/php.js）。
 * 英語のときは次の 3 段で訳す。訳はすべて lang/en.js（日本語の原文 → 英語。中身は JSON）の 1 か所に置く。
 *
 * 1. WordPress のロケールを en_US にする（WordPress 本体は英語。KantanProEX 本体の languages/ktpwp-en_US.mo も読まれる）
 * 2. gettext（__() など）の結果が日本語のまま（.mo に無い・訳が日本語のまま）なら、lang/en.js で訳す。
 *    sprintf の書式（%s・%d）のまま訳せるので、数字や名前が入る文はここで訳すのが確実
 * 3. 画面の文字（翻訳関数を通っていない日本語・JS が後から出す文言・alert/confirm）は、
 *    画面側のスクリプト（i18n.js）が文字のかたまりごとに完全一致で訳す。顧客名などの入力したデータは、
 *    ラベルと完全に同じ文字でない限り変わらない（部分一致の置き換えはしない。「株式会社」が「株set会社」になる事故を避ける）
 *
 * KantanProEX 本体の「英語ロケールならページ全体を部分一致で置き換える」出力バッファ（KTPWP_I18n）は止める。
 * 部分一致で「表示件数」→「Show件数」のように半端に変わり、3 の完全一致で訳せなくなるため。
 * 本体の固定辞書の訳は lang/en.js に取り込み済み。
 *
 * 音声操作（voice.php）の聞き取りと読み上げは日本語のまま（話し言葉の解釈が日本語前提のため）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 今の表示言語（'ja' / 'en'） */
function ktp_dt_lang() {
	return 'en' === getenv( 'KTP_DESKTOP_LANG' ) ? 'en' : 'ja';
}

function ktp_dt_is_en() {
	return 'en' === ktp_dt_lang();
}

/**
 * 商取引の国（KantanProEX の設定。未設定なら通貨から推定。既存の利用者は日本）。辞書を作る時点では本体が読まれていないことがあるので option を直接見る
 *
 * @return string
 */
function ktp_dt_business_country() {
	if ( class_exists( 'KTPWP_Settings' ) && method_exists( 'KTPWP_Settings', 'get_business_country' ) ) {
		return KTPWP_Settings::get_business_country();
	}
	$o    = get_option( 'ktp_general_settings', array() );
	$code = is_array( $o ) && ! empty( $o['business_country'] ) ? strtoupper( (string) $o['business_country'] ) : '';
	if ( '' !== $code ) {
		return $code;
	}
	$map = array( 'JPY' => 'JP', 'USD' => 'US', 'GBP' => 'GB', 'EUR' => 'EU', 'CAD' => 'CA', 'AUD' => 'AU' );
	$cur = is_array( $o ) && ! empty( $o['currency_code'] ) ? strtoupper( (string) $o['currency_code'] ) : 'JPY';
	return isset( $map[ $cur ] ) ? $map[ $cur ] : 'JP';
}

/**
 * 英語の訳の「Consumption tax（消費税）」を国の税の名前に置き換える表。日本は空。
 *
 * @return array<string,string>
 */
function ktp_dt_country_tax_words() {
	$labels = array( 'US' => array( 'Sales Tax', 'Sales tax', 'sales tax' ), 'GB' => array( 'VAT', 'VAT', 'VAT' ), 'EU' => array( 'VAT', 'VAT', 'VAT' ), 'CA' => array( 'GST/HST', 'GST/HST', 'GST/HST' ), 'AU' => array( 'GST', 'GST', 'GST' ), 'OTHER' => array( 'Tax', 'Tax', 'tax' ) );
	$c      = ktp_dt_business_country();
	if ( ! isset( $labels[ $c ] ) ) {
		return array();
	}
	return array( 'Consumption Tax' => $labels[ $c ][0], 'Consumption tax' => $labels[ $c ][1], 'consumption tax' => $labels[ $c ][2] );
}

/**
 * 英語の辞書（日本語の原文 => 英語）。英語のときだけ読む。
 *
 * @return array<string,string>
 */
function ktp_dt_i18n_dictionary() {
	static $dict = null;
	if ( null === $dict ) {
		$dict = array();
		if ( ktp_dt_is_en() ) {
			// lang/en.js は画面もそのまま読む JS（window.ktpDtI18nDict={…};）。中身の { … } は JSON
			$raw = @file_get_contents( __DIR__ . '/lang/en.js' ); // phpcs:ignore
			$arr = null;
			if ( is_string( $raw ) && false !== ( $p = strpos( $raw, '{' ) ) && false !== ( $q = strrpos( $raw, '}' ) ) ) {
				$arr = json_decode( substr( $raw, $p, $q - $p + 1 ), true );
			}
			if ( is_array( $arr ) ) {
				$dict = $arr;
				$words = ktp_dt_country_tax_words();
				if ( $words ) {
					foreach ( $dict as $k => $v ) {
						$dict[ $k ] = strtr( (string) $v, $words );
					}
				}
			}
		}
	}
	return $dict;
}

/** 日本語を含むか */
function ktp_dt_has_ja( $text ) {
	return is_string( $text ) && 1 === preg_match( '/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $text );
}

/**
 * デスクトップ版の文言を今の言語にする（日本語が原文。辞書に無ければ日本語のまま）。
 *
 * @param string $ja 日本語の原文
 * @return string
 */
function ktp_dt__( $ja ) {
	if ( ! ktp_dt_is_en() ) {
		return $ja;
	}
	$dict = ktp_dt_i18n_dictionary();
	return isset( $dict[ $ja ] ) ? $dict[ $ja ] : $ja;
}

function ktp_dt_esc_html__( $ja ) {
	return esc_html( ktp_dt__( $ja ) );
}

if ( ktp_dt_is_en() ) {
	// 1. ロケール（WordPress の初期設定では WPLANG=ja にしている。lib/site.js）
	add_filter( 'pre_determine_locale', function () {
		return 'en_US';
	} );
	add_filter( 'locale', function () {
		return 'en_US';
	}, PHP_INT_MAX );
	// 日付の表示（初期設定の「Y年n月j日」は日本語の書式）
	add_filter( 'pre_option_date_format', function () {
		return 'M j, Y';
	} );

	// 2. 原文が辞書にあれば辞書の訳にする（本体の .mo より優先。辞書は .po を元にしているので、違うのは直した訳だけ）。
	//    辞書に無く、結果が日本語のままのときも、訳のほうで辞書を引く
	$ktp_dt_gettext = function ( $translation, $text ) {
		$dict = ktp_dt_i18n_dictionary();
		if ( isset( $dict[ $text ] ) ) {
			return $dict[ $text ];
		}
		if ( ! ktp_dt_has_ja( $translation ) ) {
			return $translation;
		}
		if ( $translation !== $text && isset( $dict[ $translation ] ) ) {
			return $dict[ $translation ];
		}
		return $translation;
	};
	add_filter( 'gettext', $ktp_dt_gettext, 20, 2 );
	add_filter(
		'gettext_with_context',
		function ( $translation, $text ) use ( $ktp_dt_gettext ) {
			return $ktp_dt_gettext( $translation, $text );
		},
		20,
		2
	);
	add_filter(
		'ngettext',
		function ( $translation, $single, $plural, $number ) use ( $ktp_dt_gettext ) {
			return $ktp_dt_gettext( $translation, 1 === (int) $number ? $single : $plural );
		},
		20,
		4
	);

	// 本体の固定ラベル（ktpwp_translate_fixed_text）も同じ辞書で訳す。本体のページ全体の置き換えは止める（冒頭の説明）
	add_action(
		'plugins_loaded',
		function () {
			if ( class_exists( 'KTPWP_I18n' ) ) {
				$inst = KTPWP_I18n::get_instance();
				remove_action( 'template_redirect', array( $inst, 'start_frontend_buffer' ), 0 );
				remove_action( 'admin_init', array( $inst, 'start_admin_buffer' ), 0 );
			}
		},
		PHP_INT_MAX
	);

	// 3. 画面側の訳（i18n.js）。どの画面でも最初に読む（読み込みながら訳すので、日本語が一瞬見えることがない）
	$ktp_dt_i18n_head = function () {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		$base = WPMU_PLUGIN_URL . '/ktp-desktop/';
		$ver  = function ( $rel ) {
			return (string) @filemtime( WPMU_PLUGIN_DIR . '/ktp-desktop/' . $rel ); // phpcs:ignore
		};
		// 辞書（大きいので別ファイルにしてブラウザに覚えさせる）→ 訳す処理の順。どちらも同期で読む
		echo '<script src="' . esc_url( $base . 'lang/en.js?v=' . $ver( 'lang/en.js' ) ) . '"></script>' . "\n"; // phpcs:ignore
		// 国の税の名前（Sales tax・VAT など）に置き換えてから訳す
		$words = ktp_dt_country_tax_words();
		if ( $words ) {
			echo '<script>(function(d,w){if(!d)return;var k,r;for(k in d){r=d[k];if(typeof r!=="string")continue;for(var f in w){r=r.split(f).join(w[f]);}d[k]=r;}})(window.ktpDtI18nDict,' . wp_json_encode( $words ) . ');</script>' . "\n"; // phpcs:ignore
		}
		echo '<script src="' . esc_url( $base . 'i18n.js?v=' . $ver( 'i18n.js' ) ) . '"></script>' . "\n"; // phpcs:ignore
	};
	add_action( 'wp_head', $ktp_dt_i18n_head, -1000 );
	add_action( 'admin_head', $ktp_dt_i18n_head, -1000 );
	add_action( 'login_head', $ktp_dt_i18n_head, -1000 );
}
