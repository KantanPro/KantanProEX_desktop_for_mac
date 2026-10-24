<?php
/**
 * Plugin Name: KantanProEX Desktop
 * Description: デスクトップ版（Electron 同梱）用の調整。プラグイン本体には手を入れず、ここで差し替える。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// DB の構造を守る（列を消すマイグレーションを止め、消えた列を戻す）。プラグインより先に読み込む
require_once __DIR__ . '/ktp-desktop/schema-guard.php';
// 一度だけ行うデータの手直し（取り込みで会社情報に入った <br /> など）
require_once __DIR__ . '/ktp-desktop/fixups.php';

// 画面ヘッダー・フッターなどに出るアプリ名（EX 本体は未定義のときだけ 'KantanProEX' を定義する）
if ( ! defined( 'KANTANPRO_PLUGIN_NAME' ) ) {
	// Electron が実行中の OS（Mac / Windows）を環境変数で渡す
	define( 'KANTANPRO_PLUGIN_NAME', 'KantanProEX（DT）for ' . ( 'Windows' === getenv( 'KTP_DESKTOP_OS' ) ? 'Windows' : 'Mac' ) );
}

/**
 * 画面に出る版数は、中の KantanProEX 本体の番号（1.4.x）ではなく、デスクトップ版の番号（1.0.0 など）にする。
 * 本体の定数 KANTANPRO_PLUGIN_VERSION は、規約の再同意・DB の更新判定・キャッシュ対策に使われるので変えず、表示だけを置き換える。
 * KTP_DESKTOP_VERSION は Electron（lib/php.js）が渡す。
 */
function ktp_desktop_app_version() {
	return (string) getenv( 'KTP_DESKTOP_VERSION' );
}

/**
 * 利用規約の文中の「プラグイン」「WordPress」を、デスクトップ版（アプリ）に合わせた言葉に置き換える。
 * 本体の規約は WordPress プラグイン向けの文なので、画面に出す直前に言葉だけ直す（意味は変えない）。
 * 規約の本文が入っているページ（第1条・Article 1 がある HTML）にだけ当てる
 */
function ktp_desktop_replace_terms_wording( $html ) {
	if ( ! is_string( $html ) || ( false === strpos( $html, '第1条（適用）' ) && false === strpos( $html, 'Article 1' ) ) ) {
		return $html;
	}
	return str_replace(
		array(
			'プラグイン（以下「本プラグイン」）', '本プラグイン', 'KantanProプラグイン',
			'WordPress環境での利用を前提としています', 'WordPressの管理者メールアドレス', 'WordPress管理者（以下「管理者」）', 'WordPressユーザー情報',
			' plugin (the &quot;Plugin&quot;)', ' plugin (the "Plugin")', 'The Plugin', 'the Plugin', 'Plugin',
			'WordPress administrators', 'WordPress environment', 'WordPress user information',
		),
		array(
			'アプリ（以下「本アプリ」）', '本アプリ', 'KantanProアプリ',
			'お使いのパソコン上での利用を前提としています', '管理者メールアドレス', 'オーナー（以下「管理者」）', 'ユーザー情報',
			' app (the &quot;App&quot;)', ' app (the "App")', 'The App', 'the App', 'App',
			'the owner (administrator)', 'your computer', 'user information',
		),
		$html
	);
}

/** 規約の本文は、購入時に同意する kantanpro.com の利用規約ページ（WooCommerce の規約ページ）にある */
const KTP_DESKTOP_TERMS_URL = 'https://www.kantanpro.com/riyoukiyaku';

/**
 * アプリの中では規約への同意を求めない（同意は購入時に kantanpro.com で取り、注文に記録する）。
 * 本体は、管理者が未同意だと画面の下に同意ダイアログを出す。出す側の登録を、描画の直前に外す
 */
add_action(
	'wp_footer',
	function () {
		if ( class_exists( 'KTPWP_Terms_Of_Service' ) ) {
			remove_action( 'wp_footer', array( KTPWP_Terms_Of_Service::get_instance(), 'display_terms_dialog' ) );
		}
	},
	0
);

/** 本体の版数が出ている表示を、デスクトップ版の版数に置き換える */
function ktp_desktop_replace_version_text( $html ) {
	// フッターの「利用規約」は、アプリ内の規約画面ではなく kantanpro.com の規約ページ（普段のブラウザで開く）
	if ( is_string( $html ) && false !== strpos( $html, 'page=ktp-terms' ) ) {
		$html = preg_replace( '#href="[^"]*page=ktp-terms[^"]*"#', 'href="' . KTP_DESKTOP_TERMS_URL . '"', $html );
	}
	$html = ktp_desktop_replace_terms_wording( $html );
	$app = ktp_desktop_app_version();
	if ( '' === $app || ! defined( 'KANTANPRO_PLUGIN_VERSION' ) || ! is_string( $html ) || '' === $html ) {
		return $html;
	}
	$ver  = esc_html( KANTANPRO_PLUGIN_VERSION );
	$name = esc_html( KANTANPRO_PLUGIN_NAME );
	$new  = esc_html( $app );
	return str_replace(
		array(
			'<div class="version">v' . $ver . '</div>',
			' v' . $ver . '</span>',
			'<div class="system-name">' . $name . '</div>',
			$name . $ver . '利用規約',
			'</strong> ' . $ver . '</p>',
		),
		array(
			'<div class="version">v' . $new . '</div>',
			' v' . $new . '</span>',
			// ヘッダーのアプリ名の横に、デスクトップ版の版数を小さく出す
			'<div class="system-name">' . $name . '<span class="ktp-dt-app-version">v' . $new . '</span></div>',
			$name . ' v' . $new . ' 利用規約',
			'</strong> ' . $new . '</p>',
		),
		$html
	);
}

// 規約ダイアログの見出し（本体は英語の書式を使う）。版数はデスクトップ版の番号にして、日本語にする
add_filter(
	'gettext',
	function ( $translated, $text, $domain ) {
		if ( 'ktpwp' === $domain && '%1$s %2$s Terms of Service' === $text && '' !== ktp_desktop_app_version() ) {
			return '%1$s v' . str_replace( '%', '%%', ktp_desktop_app_version() ) . ' 利用規約';
		}
		return $translated;
	},
	10,
	3
);

// 管理画面（KantanProEX の設定・規約・ダミーデータなど）の表示も置き換える。ダウンロード・ajax は触らない
add_action(
	'init',
	function () {
		if ( ! is_admin() || wp_doing_ajax() || 'GET' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || '' === ktp_desktop_app_version() ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 0 !== strpos( $page, 'ktp' ) ) {
			return;
		}
		ob_start(
			function ( $buffer ) {
				foreach ( headers_list() as $h ) {
					if ( 0 === stripos( $h, 'Content-Disposition' ) ) {
						return $buffer;
					}
				}
				return ktp_desktop_replace_version_text( $buffer );
			}
		);
	},
	0
);

/**
 * 画面はテーマを通さず、アプリ専用の素のテンプレートで出す。
 *
 * ブロックテーマはテンプレート全体に wptexturize をかけるため、ショートコード出力内の
 * インライン script の `&&` が `&#038;&#038;` になり構文エラーになる（顧客タブで実際に発生）。
 * デスクトップではテーマのヘッダー・フッターも不要なので、本文のショートコードだけを描画する。
 */
add_filter(
	'template_include',
	function ( $template ) {
		if ( is_admin() || ! is_singular() ) {
			return $template;
		}
		$post = get_queried_object();
		if ( $post && has_shortcode( (string) $post->post_content, 'ktpwp_all_tab' ) ) {
			return __DIR__ . '/ktp-desktop/app-template.php';
		}
		return $template;
	},
	99
);

/**
 * 外部への HTTP 通信は原則すべて遮断する（GitHub の更新確認・バナー・規約同意の送信など）。
 *
 * 例外は、本人が設定画面で有効にして API キーを入れた機能だけ（日本郵便の郵便番号 API・サイトからの受注）。
 * WP_HTTP_BLOCK_EXTERNAL / WP_ACCESSIBLE_HOSTS は定数なので「設定したら通す」ができず、ここで判定する。
 * 遮断はタイムアウトを待たず即座に WP_Error を返すので、オフラインでも画面が待たされない。
 */
function ktp_desktop_allowed_hosts() {
	$hosts = array();
	// ユーザー登録でニュースレターを選んだ、その1回の送信のあいだだけ
	if ( ! empty( $GLOBALS['ktp_desktop_allow_newsletter'] ) ) {
		$hosts[] = 'www.kantanpro.com';
	}
	if ( class_exists( 'KTPWP_Japanpost_Address_API' ) && KTPWP_Japanpost_Address_API::is_enabled() ) {
		$hosts[] = (string) wp_parse_url( KTPWP_Japanpost_Address_API::get_base_url(), PHP_URL_HOST );
	}
	// サイトからの受注（intake.php）をオンにして、アドレスを入れたホームページ
	if ( function_exists( 'ktp_dt_intake_host' ) ) {
		$hosts[] = ktp_dt_intake_host();
	}
	// 即時購入（stripe-purchase.php）で Stripe の秘密鍵を入れたとき
	if ( function_exists( 'ktp_dt_stripe_host' ) ) {
		$hosts[] = ktp_dt_stripe_host();
	}
	return array_filter( $hosts );
}

add_filter(
	'pre_http_request',
	function ( $preempt, $args, $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' || in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return $preempt; // 自分自身（wp-cron など）は通す
		}
		if ( in_array( $host, ktp_desktop_allowed_hosts(), true ) ) {
			return $preempt;
		}
		return new WP_Error( 'ktp_desktop_offline', 'デスクトップ版では外部への通信を行いません: ' . $host );
	},
	1,
	3
);

/**
 * 利用規約の同意通知は、HTTP を遮断すると本体がメール（kantanpro22@gmail.com 宛て）で送り直そうとする。
 * オーナーの名前とメールアドレスが外へ出てしまうので、送ったことにして捨てる（false だと再送の列に積まれ、30 回まで再試行される）
 */
add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		return ( 'KantanPro利用規約同意通知' === ( $atts['subject'] ?? '' ) ) ? true : $short;
	},
	1,
	2
);

/**
 * プロフィールでメールアドレスを変えたとき、WordPress は新しいアドレスへ確認リンクを送り、押すまで保留する。
 * リンクは http://127.0.0.1:<起動ごとに変わる番号> なので、メールから開いても入れず、アプリを開き直すと二度と使えない。
 * デスクトップ版では確認を飛ばし、保存した時点で変える（形式・重複のチェックは保存処理の edit_user() に残る）。
 * 保留のまま残った変更（_new_email）も消して、「保留中」の案内が出続けないようにする
 */
add_action(
	'admin_init',
	function () {
		remove_action( 'personal_options_update', 'send_confirmation_on_profile_email' );
		if ( is_user_logged_in() && get_user_meta( get_current_user_id(), '_new_email', true ) ) {
			delete_user_meta( get_current_user_id(), '_new_email' );
		}
	}
);

/**
 * プラグイン本体の自動更新確認を止める（デスクトップ版はアプリごと更新する）。
 * cron の予約も外して、毎日の無駄な実行をなくす。
 */
add_action(
	'init',
	function () {
		$ts = wp_next_scheduled( 'ktpwp_daily_update_check' );
		if ( $ts ) {
			wp_unschedule_event( $ts, 'ktpwp_daily_update_check' );
		}
	}
);
add_filter( 'pre_schedule_event', function ( $pre, $event ) {
	return ( is_object( $event ) && $event->hook === 'ktpwp_daily_update_check' ) ? false : $pre;
}, 10, 2 );
add_filter( 'automatic_updater_disabled', '__return_true' );

/**
 * 自動ログイン。
 *
 * Electron が起動ごとに作る使い捨てトークン（KTP_DESKTOP_TOKEN_FILE）付きで来たときだけ、
 * 管理者としてログインさせる。トークンは1回使ったら消す。127.0.0.1 からの要求に限る。
 */
add_action(
	'init',
	function () {
		if ( ! isset( $_GET['ktp_desktop_login'] ) || ! defined( 'KTP_DESKTOP_TOKEN_FILE' ) ) {
			return;
		}
		// LAN 共有の窓口経由では自動ログインを使わせない（窓口でも止めているが、こちらでも止める）
		if ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ) {
			wp_die( 'この画面は、親機でだけ開けます。', 'KantanProEX', array( 'response' => 403 ) );
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$given  = (string) wp_unslash( $_GET['ktp_desktop_login'] );
		$saved  = is_readable( KTP_DESKTOP_TOKEN_FILE ) ? trim( (string) file_get_contents( KTP_DESKTOP_TOKEN_FILE ) ) : '';
		if ( ! in_array( $remote, array( '127.0.0.1', '::1' ), true ) || $saved === '' || strlen( $given ) < 32 || ! hash_equals( $saved, $given ) ) {
			wp_die( 'ログインできませんでした。アプリを開き直してください。', 'KantanProEX', array( 'response' => 403 ) );
		}
		@unlink( KTP_DESKTOP_TOKEN_FILE );
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
		if ( $admins ) {
			wp_set_current_user( $admins[0]->ID );
			wp_set_auth_cookie( $admins[0]->ID, true );
		}
		// 戻り先（ログインが切れて入り直したとき）。このアプリの中の URL だけ
		$back = isset( $_GET['redirect_to'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ), home_url( '/' ) ) : home_url( '/' );
		wp_safe_redirect( $back );
		exit;
	},
	1
);

/** 画面上部の WordPress の管理バーは出さない（アプリの見た目にする） */
add_filter( 'show_admin_bar', '__return_false' );

/**
 * 画面から外部のサーバーを読みに行かない。
 * WordPress 標準の絵文字画像（s.w.org）と Gravatar のアイコン（secure.gravatar.com）を使わない。
 * 絵文字は Mac の標準フォントで出る。アイコンは同梱の画像（mu-plugins/ktp-desktop/avatar.svg）にする。
 * （Electron 側でも 127.0.0.1 以外への読み込みは止めているので、ここは画像が欠けて見えないようにするため）
 */
add_action(
	'init',
	function () {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	}
);
add_filter( 'emoji_svg_url', '__return_false' );
add_filter( 'wp_resource_hints', function ( $urls, $relation ) {
	return $relation === 'dns-prefetch' ? array() : $urls;
}, 10, 2 );

add_filter(
	'pre_get_avatar_data',
	function ( $args, $id_or_email ) {
		// 本人が選んだ画像（なければ同梱の人型）。data: URL は esc_url() が許可せず空になるのでファイルを指す
		$user_id = 0;
		if ( is_numeric( $id_or_email ) ) {
			$user_id = (int) $id_or_email;
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Post ) {
			$user_id = (int) $id_or_email->post_author;
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user_id = (int) $id_or_email->user_id;
		} elseif ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$u       = get_user_by( 'email', $id_or_email );
			$user_id = $u ? $u->ID : 0;
		}
		$args['url']          = ktp_desktop_avatar_url_for( $user_id );
		$args['found_avatar'] = true;
		return $args;
	},
	10,
	2
);

/**
 * アプリ画面ではテーマ（Twenty Twenty-Five）とブロックのスタイルを読み込まない。
 * 入ると本文が 22px になり、見出しのフォントなども変わる（WP 版の Cocoon と見た目がずれる）。
 * 代わりの基本スタイルは ktp-desktop/app-template.php に持つ。
 */
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( is_admin() ) {
			return;
		}
		foreach ( array( 'global-styles', 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'core-block-supports', 'core-block-supports-duotone', 'twentytwentyfive-style', 'wp-img-auto-sizes-contain' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	},
	1000
);
remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
add_filter( 'should_load_separate_core_block_assets', '__return_false' );

/**
 * 管理画面は KantanProEX の設定だけにする（WordPress 標準のメニューは出さない）。
 *
 * - 左メニューは「← アプリに戻る」と KantanProEX だけ
 * - 上部の WordPress バー・フッターの WordPress 表記・ヘルプタブは隠す
 * - ダッシュボードや投稿など WordPress 標準の画面を直接開いたらアプリへ戻す
 *   （プロフィール・ユーザー編集はプラグインがリンクしているので開けるまま残す）
 */
add_action(
	'admin_menu',
	function () {
		global $menu;
		foreach ( (array) $menu as $item ) {
			$slug = isset( $item[2] ) ? (string) $item[2] : '';
			if ( $slug !== '' && $slug !== 'ktp-settings' ) {
				remove_menu_page( $slug );
			}
		}
		add_menu_page( 'アプリに戻る', 'アプリに戻る', 'read', 'ktp-desktop-back', '__return_null', 'dashicons-arrow-left-alt', 0 );
		// 自分のプロフィール（名前・アイコン画像）。WordPress の「ユーザー」メニューを外したので入口を作る
		add_menu_page( 'プロフィール', 'プロフィール', 'read', 'profile.php', '', 'dashicons-admin-users', 1 );
		remove_submenu_page( 'ktp-settings', 'ktp-developer-settings' );
	},
	PHP_INT_MAX
);

add_action(
	'admin_init',
	function () {
		if ( wp_doing_ajax() ) {
			return;
		}
		global $pagenow;
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $page === 'ktp-desktop-back' ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$wp_screens = array( 'index.php', 'edit.php', 'post.php', 'post-new.php', 'edit-tags.php', 'term.php', 'upload.php', 'media-new.php', 'edit-comments.php', 'comment.php', 'themes.php', 'site-editor.php', 'customize.php', 'widgets.php', 'nav-menus.php', 'plugins.php', 'plugin-install.php', 'plugin-editor.php', 'theme-editor.php', 'tools.php', 'import.php', 'export.php', 'site-health.php', 'export-personal-data.php', 'erase-personal-data.php', 'options-writing.php', 'options-reading.php', 'options-discussion.php', 'options-media.php', 'options-permalink.php', 'options-privacy.php', 'update-core.php', 'about.php' );
		$is_bare_options = ( $pagenow === 'options-general.php' && $page === '' );
		if ( $page === '' && ( in_array( $pagenow, $wp_screens, true ) || $is_bare_options ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	}
);

add_action(
	'admin_head',
	function () {
		echo '<style id="ktp-desktop-admin">'
			. '#wpadminbar{display:none!important}html.wp-toolbar{padding-top:0!important}'
			. '#contextual-help-link-wrap,#screen-options-link-wrap,#wpfooter,.update-nag,#wp-admin-bar-wp-logo{display:none!important}'
			. '#adminmenu .toplevel_page_ktp-desktop-back .wp-menu-name{font-weight:600}'
			// FileMaker 版データ取り込みは、上部のファイルメニュー（各種取り込み）から開く。ページは残し、左メニューの項目だけ隠す
			. '#adminmenu a[href$="page=ktp-fm-import"]{display:none!important}'
			. '</style>';
	}
);
add_filter( 'admin_footer_text', '__return_empty_string', PHP_INT_MAX );
add_filter( 'update_footer', '__return_empty_string', PHP_INT_MAX );
add_filter(
	'admin_title',
	function ( $admin_title, $title ) {
		return $title . ' ‹ KantanProEX';
	},
	10,
	2
);

/**
 * デスクトップ版では使わない設定を隠す（設定値は消さない。表示しないだけ）。
 *
 * - ショートコード関連: 業務画面はアプリの1画面に固定なので、ページの指定・ページ幅・ショートコード一覧は不要
 * - 外部サイトが前提の機能: Contact Form 7 からの受注、Stripe 請求連携、公開商品ページ、問い合わせブロック
 *   （デスクトップ版には外から受け付けるサイトがなく、外部とも通信しない。
 *    Contact Form 7・WooCommerce の受注は、ホームページの受付プラグイン経由の「サイトからの受注」（intake.php）で取り込む）
 * 郵便番号 API と SMTP は、ネットにつないだときに使えるので残す。
 */
function ktp_desktop_hidden_settings() {
	return array(
		'sections' => array( 'ショートコード', '問い合わせフォーム（Contact Form 7）設定', 'Stripe 請求連携', 'プラグイン削除時のデータ保持設定' ),
		'rows'     => array( '業務画面ページ', '公開商品カードの背景色', '固定ページの幅' ),
	);
}

add_action(
	'admin_menu',
	function () {
		remove_submenu_page( 'ktp-settings', 'ktp-inquiry-block-list' );
	},
	PHP_INT_MAX
);

add_action(
	'admin_footer',
	function () {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( strpos( $page, 'ktp' ) !== 0 ) {
			return;
		}
		?>
<script id="ktp-desktop-hide-settings">
(function () {
	var conf = <?php echo wp_json_encode( ktp_desktop_hidden_settings() ); ?>;
	var root = document.getElementById('wpbody-content');
	if (!root) { return; }
	// 見出し（h2）ごと、次の見出しまでを隠す
	root.querySelectorAll('h2').forEach(function (h) {
		if (conf.sections.indexOf(h.textContent.trim()) === -1) { return; }
		var el = h;
		// 次の見出し、または保存ボタンを含む要素（.ktp-submit-button / p.submit）の手前で止める
		var stop = function (n) { return !n || n.tagName === 'H2' || n.querySelector('h2') || n.matches('[type=submit], .submit, .ktp-submit-button') || n.querySelector('[type=submit]'); };
		do { el.style.display = 'none'; el = el.nextElementSibling; } while (!stop(el));
	});
	// 表の行（項目名が一致する tr）を隠す
	root.querySelectorAll('tr').forEach(function (tr) {
		var th = tr.querySelector('th');
		if (th && conf.rows.indexOf(th.textContent.trim()) !== -1) { tr.style.display = 'none'; }
	});
})();
</script>
		<?php
	}
);

/**
 * プロフィール画面で自分のアイコン画像を選べるようにする（Gravatar の代わり。画像は Mac の中だけに保存）。
 * 画像は uploads/ktp-desktop-avatars/ に 128px 角で保存するので、アプリのバックアップにも入る。
 */
// 保存するのはファイル名だけ（ポート番号は起動ごとに変わるので URL は持たない）
function ktp_desktop_avatar_file_for( $user_id ) {
	$name = basename( (string) get_user_meta( (int) $user_id, 'ktp_desktop_avatar', true ) );
	if ( $name === '' ) {
		return '';
	}
	$uploads = wp_upload_dir( null, false );
	$path    = trailingslashit( $uploads['basedir'] ) . 'ktp-desktop-avatars/' . $name;
	return is_file( $path ) ? $path : '';
}

function ktp_desktop_avatar_url_for( $user_id ) {
	$path = ktp_desktop_avatar_file_for( $user_id );
	if ( $path === '' ) {
		return WPMU_PLUGIN_URL . '/ktp-desktop/avatar.svg';
	}
	$uploads = wp_upload_dir( null, false );
	return trailingslashit( $uploads['baseurl'] ) . 'ktp-desktop-avatars/' . basename( $path );
}

add_action( 'user_edit_form_tag', function () {
	echo ' enctype="multipart/form-data"';
} );

add_filter(
	'user_profile_picture_description',
	function ( $description, $profileuser ) {
		$has = ktp_desktop_avatar_file_for( $profileuser->ID ) !== '';
		ob_start();
		?>
<p><input type="file" name="ktp_desktop_avatar" accept="image/png,image/jpeg,image/gif,image/webp"></p>
<p class="description">画像を選んで「プロフィールを更新」を押すと、画面右上のアイコンになります。画像はこの Mac の中だけに保存されます。</p>
		<?php if ( $has ) : ?>
<p><label><input type="checkbox" name="ktp_desktop_avatar_remove" value="1"> 画像を外す（人型のアイコンに戻す）</label></p>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	},
	10,
	2
);

function ktp_desktop_save_avatar( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	$old_file = ktp_desktop_avatar_file_for( $user_id );
	$remove   = ! empty( $_POST['ktp_desktop_avatar_remove'] );
	$has_new  = ! empty( $_FILES['ktp_desktop_avatar']['name'] ) && (int) $_FILES['ktp_desktop_avatar']['error'] === UPLOAD_ERR_OK;
	if ( ! $remove && ! $has_new ) {
		return;
	}
	if ( $old_file !== '' && is_file( $old_file ) ) {
		@unlink( $old_file );
	}
	delete_user_meta( $user_id, 'ktp_desktop_avatar' );
	if ( ! $has_new ) {
		return;
	}

	$tmp  = $_FILES['ktp_desktop_avatar']['tmp_name'];
	$type = wp_check_filetype_and_ext( $tmp, $_FILES['ktp_desktop_avatar']['name'] );
	if ( ! in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' ), true ) ) {
		return;
	}
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'ktp-desktop-avatars';
	wp_mkdir_p( $dir );
	$editor = wp_get_image_editor( $tmp );
	if ( is_wp_error( $editor ) ) {
		return;
	}
	$editor->resize( 128, 128, true );
	$name  = 'user-' . (int) $user_id . '-' . time() . '.png';
	$saved = $editor->save( $dir . '/' . $name, 'image/png' );
	if ( is_wp_error( $saved ) || empty( $saved['path'] ) ) {
		return;
	}
	update_user_meta( $user_id, 'ktp_desktop_avatar', basename( $saved['path'] ) );
}
add_action( 'personal_options_update', 'ktp_desktop_save_avatar' );
add_action( 'edit_user_profile_update', 'ktp_desktop_save_avatar' );

// 「プロフィール写真」の行を出すため、アバター表示は常に有効にしておく
add_filter( 'pre_option_show_avatars', function () {
	return '1';
} );

// プロフィール画面の WordPress 特有の項目は隠す（名前・メール・アイコン・パスワードは残す）
add_action(
	'admin_head-profile.php',
	'ktp_desktop_profile_css'
);
add_action(
	'admin_head-user-edit.php',
	'ktp_desktop_profile_css'
);
function ktp_desktop_profile_css() {
	echo '<style id="ktp-desktop-profile">'
		. '.user-rich-editing-wrap,.user-syntax-highlighting-wrap,.user-admin-color-wrap,.user-comment-shortcuts-wrap,.show-admin-bar,.user-language-wrap,'
		. '.user-url-wrap,.user-description-wrap,#application-passwords-section,.user-generate-reset-link-wrap{display:none!important}'
		. '</style>';
	echo '<script>document.addEventListener("DOMContentLoaded",function(){'
		// 中身がすべて隠れた見出し（個人設定など）も隠す
		. 'document.querySelectorAll("#your-profile h2").forEach(function(h){var t=h.nextElementSibling;'
		. 'while(t&&t.tagName!=="TABLE"&&t.tagName!=="H2"){t=t.nextElementSibling;}'
		. 'if(!t||t.tagName!=="TABLE"){return;}'
		. 'var rows=[].filter.call(t.querySelectorAll("tr"),function(r){return getComputedStyle(r).display!=="none";});'
		. 'if(rows.length===0){h.style.display="none";t.style.display="none";}});'
		// 自動ログインなのでパスワード関連の見出しは丸ごと隠す（次の見出しまで）
		. 'var drop=' . ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ? '["アプリケーションパスワード"]' : '["アカウント管理","アプリケーションパスワード"]' ) . ';'
		. 'document.querySelectorAll("#your-profile h2").forEach(function(h){if(drop.indexOf(h.textContent.trim())===-1){return;}'
		. 'var e=h;do{e.style.display="none";e=e.nextElementSibling;}while(e&&e.tagName!=="H2"&&!e.matches(".submit, [type=submit]")&&!e.querySelector("[type=submit]"));});'
		. '});</script>';
}

/**
 * WP 版のプラグイン運用（インストール・更新・無料版からの引き継ぎ）向けの通知は出さない。
 * デスクトップ版はアプリごと入れ替えるので「正常にインストールされました」「プラグイン一覧へ」は意味がない。
 * 失敗の通知（ktpwp_*_error）は残す。
 */
foreach ( array( 'ktpwp_activation_success', 'ktpwp_reactivation_success', 'ktpwp_new_installation_success', 'ktpwp_show_update_complete_guide', 'ktpwp_activation_message', 'ktpwp_reactivation_message', 'ktpwp_new_installation_message', 'ktpwp_upgrade_message' ) as $ktp_desktop_transient ) {
	// false を返すと「割り込まない」扱いで保存値を読みに行くので、空文字（値なし扱い）を返す
	add_filter( 'pre_transient_' . $ktp_desktop_transient, '__return_empty_string' );
}
unset( $ktp_desktop_transient );
add_action(
	'admin_init',
	function () {
		remove_action( 'admin_notices', array( 'KTPWP_EX_Free_Data_Import', 'admin_notices' ) );
		remove_action( 'admin_notices', 'ktpwp_ex_render_auto_deactivated_notice' );
		remove_action( 'admin_notices', 'ktpwp_show_update_complete_guide', 1 );
	}
);

/**
 * ダミーデータ作成のスクリプトは配布物に入らない（開発・検証用）。無いときはメニューを出さない。
 * （プラグイン本体にも同じ修正を入れてあるが、本体の修正が入る前の版でも出ないようにここでも外す）
 */
add_action(
	'admin_menu',
	function () {
		if ( defined( 'KTPWP_PLUGIN_DIR' ) && ! file_exists( KTPWP_PLUGIN_DIR . 'create_dummy_data.php' ) ) {
			remove_submenu_page( 'ktp-settings', 'ktpwp-dummy-data' );
		}
	},
	PHP_INT_MAX
);

/**
 * ヘッダーのログアウトボタンを「アプリを終了」にする。
 *
 * デスクトップ版は起動時の自動ログインだけで入っていて、パスワードを使わない。
 * ログアウトするとログイン画面から戻れなくなるので、ログアウトの URL を終了の合図に差し替える。
 * Electron（main.js）がこの URL への移動を止めて、確認のうえアプリを終了する。
 * ボタンの見た目（電源マーク・「アプリを終了」）は ktp-desktop/app-template.php で差し替える。
 */
add_filter(
	'logout_url',
	function ( $logout_url ) {
		// LAN から使うスタッフは、普通のログアウト（アプリを終了させない）
		return defined( 'KTP_DESKTOP_LAN_REQUEST' ) ? $logout_url : home_url( '/?ktp_desktop_quit=1' );
	},
	PHP_INT_MAX
);
add_action(
	'init',
	function () {
		// Electron 以外から開かれたとき（念のため）はアプリの画面に戻すだけ
		if ( isset( $_GET['ktp_desktop_quit'] ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	},
	0
);

/**
 * WordPress のログイン画面は出さない（ロゴ入りで、パスワードも誰も知らない）。
 *
 * ログイン画面、またはログインが切れた状態でアプリの画面に来たら、「入り直し」の合図
 * （/?ktp_desktop_relogin=1）へ転送する。Electron（main.js）がそれを止めて、新しい使い捨ての鍵で
 * 自動ログインし直す。Electron 以外から開かれたときは、案内の文だけを出す。
 */
function ktp_desktop_relogin_url( $redirect_to = '' ) {
	$args = array( 'ktp_desktop_relogin' => '1' );
	if ( $redirect_to !== '' ) {
		$args['redirect_to'] = rawurlencode( $redirect_to );
	}
	return add_query_arg( $args, home_url( '/' ) );
}

add_action(
	'login_init',
	function () {
		if ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ) {
			return; // LAN からは WordPress のログイン画面を使う（lan.php）
		}
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		wp_safe_redirect( ktp_desktop_relogin_url( $redirect_to ) );
		exit;
	},
	0
);

add_action(
	'template_redirect',
	function () {
		if ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ) {
			return; // LAN からの未ログインは lan.php がログイン画面へ送る
		}
		if ( isset( $_GET['ktp_desktop_relogin'] ) ) {
			// Electron がこの URL への移動を止めるので、ここに来るのはアプリの外から開かれたときだけ
			status_header( 200 );
			header( 'Content-Type: text/html; charset=utf-8' );
			echo '<!doctype html><html lang="ja"><meta charset="utf-8"><title>KantanProEX</title>'
				. '<body style="font-family:-apple-system,\'Hiragino Sans\',sans-serif;background:#fff;color:#333;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
				. '<p>KantanProEX のアプリを開き直してください。</p></body></html>';
			exit;
		}
		if ( ! is_user_logged_in() && ! wp_doing_ajax() ) {
			wp_safe_redirect( ktp_desktop_relogin_url( home_url( add_query_arg( array() ) ) ) );
			exit;
		}
	},
	0
);

// ユーザー登録（任意。登録するとメール署名の後ろの宣伝文が入らなくなる）
require_once __DIR__ . '/ktp-desktop/registration.php';
require_once __DIR__ . '/ktp-desktop/order-tasks.php';
require_once __DIR__ . '/ktp-desktop/invoice-undo.php';
require_once __DIR__ . '/ktp-desktop/work-list-sort.php';
require_once __DIR__ . '/ktp-desktop/orders-csv.php';
require_once __DIR__ . '/ktp-desktop/reports-extra.php';
require_once __DIR__ . '/ktp-desktop/newsletter.php';
require_once __DIR__ . '/ktp-desktop/webfax.php';
require_once __DIR__ . '/ktp-desktop/lan.php';
require_once __DIR__ . '/ktp-desktop/intake.php';
require_once __DIR__ . '/ktp-desktop/stripe-purchase.php';
