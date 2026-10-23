<?php
/**
 * LAN 共有（事務所の他の PC から、スタッフがブラウザで使う）。
 *
 * 既定はオフ。オンにすると Electron（lib/lan.js）が LAN 向けの https の窓口を立て、PHP へ中継する。
 * 窓口経由の要求は、wp-config.php が起動ごとの合言葉を確かめたときだけ KTP_DESKTOP_LAN_REQUEST を定義する
 * （https・接続元の IP も引き継ぐ）。このファイルは「LAN から来た要求」と「親機の画面」とを分けて扱う。
 *
 * LAN から来た要求:
 * - 自動ログインは使えない。パスワードでのログインが必要
 * - ログインできるのは、「スタッフ管理」でオンにしていて、親機のオーナーが「LAN で使う」を許可したスタッフだけ。管理者は、オーナー許可（既定オフ）をオンにし LAN 用パスワードを決めたときだけ
 * - 失敗は IP・ログイン名ごとに 5 回で 15 分止める
 * - 設定（manage_options の画面）・WordPress の管理画面は開けない。開けるのは自分のプロフィール（パスワード変更）だけ
 * 親機の画面（127.0.0.1）は今までどおり、自動ログイン。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_LAN_NONCE   = 'ktp_dt_lan';
const KTP_DT_LAN_META    = 'ktp_dt_lan_allowed';
const KTP_DT_LAN_MAX_FAIL = 5;
const KTP_DT_LAN_LOCK_SEC = 900;
const KTP_DT_LAN_MIN_PASS = 10;
const KTP_DT_LAN_PAGE    = 'ktp-dt-lan';
const KTP_DT_LAN_OWNER_OPT = 'ktp_dt_lan_allow_owner';
const KTP_DT_LAN_OWNER_MIN_PASS = 12;

/** この要求は、LAN 共有の窓口を通って来たものか */
function ktp_dt_is_lan() {
	return defined( 'KTP_DESKTOP_LAN_REQUEST' ) && KTP_DESKTOP_LAN_REQUEST;
}

/** 窓口の状態（Electron が userData/lan-status.json に書く） */
function ktp_dt_lan_status() {
	$file = defined( 'KTP_DESKTOP_TOKEN_FILE' ) ? dirname( KTP_DESKTOP_TOKEN_FILE ) . '/lan-status.json' : '';
	$raw  = ( $file !== '' && is_readable( $file ) ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	return is_array( $raw ) ? $raw : array( 'enabled' => false );
}

/** オーナー（管理者）の LAN 利用を許可しているか（既定はオフ） */
function ktp_dt_lan_owner_enabled() {
	return '1' === (string) get_option( KTP_DT_LAN_OWNER_OPT, '0' );
}

/** LAN で使ってよいユーザーか（許可の印があるスタッフ。管理者は、オーナー許可がオンで、LAN 用パスワードを決めてあるときだけ） */
function ktp_dt_lan_user_ok( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
		return false;
	}
	if ( in_array( 'administrator', (array) $user->roles, true ) || user_can( $user, 'manage_options' ) ) {
		return ktp_dt_lan_owner_enabled()
			&& '1' === (string) get_user_meta( $user->ID, KTP_DT_LAN_META, true )
			&& '1' === (string) get_user_meta( $user->ID, 'ktp_dt_lan_pass_set', true );
	}
	// 「スタッフ管理」でオフ（KantanPro 利用権限なし）のスタッフは、LAN の許可が残っていても入れない
	return user_can( $user, 'ktpwp_access' ) && '1' === (string) get_user_meta( $user->ID, KTP_DT_LAN_META, true );
}

function ktp_dt_lan_generic_error() {
	return new WP_Error( 'ktp_lan_denied', 'ログイン名またはパスワードが違います。' );
}

// ---------------------------------------------------------------------------
// LAN から来た要求の制限
// ---------------------------------------------------------------------------

function ktp_dt_lan_fail_keys( $login ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	return array( 'ktp_lan_f_ip_' . md5( $ip ), 'ktp_lan_f_u_' . md5( strtolower( (string) $login ) ) );
}

function ktp_dt_lan_is_locked( $login ) {
	foreach ( ktp_dt_lan_fail_keys( $login ) as $key ) {
		if ( (int) get_transient( $key ) >= KTP_DT_LAN_MAX_FAIL ) {
			return true;
		}
	}
	return false;
}

/** 最後に判定する（優先度 99）。パスワードが合っていても、止められている・許可されていないなら通さない */
add_filter(
	'authenticate',
	function ( $user, $login ) {
		if ( ! ktp_dt_is_lan() || '' === (string) $login ) {
			return $user;
		}
		if ( ktp_dt_lan_is_locked( $login ) ) {
			return new WP_Error( 'ktp_lan_locked', '失敗が続いたため、しばらく止めています。15 分ほど待ってからやり直してください。' );
		}
		if ( $user instanceof WP_User ) {
			return ktp_dt_lan_user_ok( $user ) ? $user : ktp_dt_lan_generic_error();
		}
		// 理由（ユーザー名が無い／パスワード違い）を教えない
		return is_wp_error( $user ) ? ktp_dt_lan_generic_error() : $user;
	},
	99,
	2
);

add_action(
	'wp_login_failed',
	function ( $login ) {
		if ( ! ktp_dt_is_lan() || '' === (string) $login ) {
			return;
		}
		foreach ( ktp_dt_lan_fail_keys( $login ) as $key ) {
			set_transient( $key, (int) get_transient( $key ) + 1, KTP_DT_LAN_LOCK_SEC );
		}
	}
);

add_action(
	'wp_login',
	function ( $login ) {
		if ( ktp_dt_is_lan() ) {
			foreach ( ktp_dt_lan_fail_keys( $login ) as $key ) {
				delete_transient( $key );
			}
		}
	}
);

/** 許可が外された・管理者になった人の、すでにあるログインは切る */
add_action(
	'init',
	function () {
		if ( ktp_dt_is_lan() && is_user_logged_in() && ! ktp_dt_lan_user_ok( wp_get_current_user() ) ) {
			wp_clear_auth_cookie();
			wp_set_current_user( 0 );
		}
	},
	2
);

/** ログインしていなければ WordPress のログイン画面へ（親機の「入り直し」転送の代わり） */
add_action(
	'template_redirect',
	function () {
		if ( ktp_dt_is_lan() && ! is_user_logged_in() && ! wp_doing_ajax() ) {
			wp_safe_redirect( wp_login_url( home_url( add_query_arg( array() ) ) ) );
			exit;
		}
	},
	0
);

/** 未ログインの REST は使わせない（ユーザー名の一覧が見えてしまうため） */
add_filter(
	'rest_authentication_errors',
	function ( $result ) {
		if ( ktp_dt_is_lan() && ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', 'ログインが必要です。', array( 'status' => 401 ) );
		}
		return $result;
	},
	99
);

/** 管理画面は自分のプロフィール（パスワード変更）と ajax だけ。設定などは親機の画面でだけ開ける */
add_action(
	'admin_init',
	function () {
		if ( ! ktp_dt_is_lan() ) {
			return;
		}
		global $pagenow;
		if ( ! in_array( $pagenow, array( 'profile.php', 'admin-ajax.php', 'admin-post.php' ), true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
	},
	0
);

/**
 * LAN からは、管理者向けの危険な操作（データ消去・復元・エクスポート・取り込み・ライセンス・設定の書き換え）を実行させない。
 * 業務の操作（受注・顧客・書類のダウンロードなど）は通す。管理者が LAN から入れるようにしたため、親機の画面でだけ許す操作を拒否リストで止める。
 */
function ktp_dt_lan_blocked_action( $action ) {
	$action = (string) $action;
	return (bool) preg_match(
		'/^(ktpwp_(clear_|create_dummy|toggle_dev|verify_license|get_license|run_|export_data|restore_data|check_update|link_|relink_|sync_)|ktp_fm_|ktp_service_(export|import)|ktp_dt_(fax_settings|nl_|lan_))/',
		$action
	);
}

add_action(
	'admin_init',
	function () {
		if ( ! ktp_dt_is_lan() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $action && ktp_dt_lan_blocked_action( $action ) ) {
			wp_die( 'この操作は、親機の画面でだけできます。', 'KantanProEX', array( 'response' => 403 ) );
		}
	},
	-1
);

add_filter(
	'login_redirect',
	function ( $to ) {
		return ktp_dt_is_lan() ? home_url( '/' ) : $to;
	},
	99
);

add_filter(
	'allow_password_reset',
	function ( $allow ) {
		return ktp_dt_is_lan() ? false : $allow;
	}
);

/** ログイン画面を DT 用に整える（ロゴ・「パスワードを忘れた」・サイトへ戻るリンクを隠す） */
add_action(
	'login_head',
	function () {
		if ( ! ktp_dt_is_lan() ) {
			return;
		}
		echo '<link rel="apple-touch-icon" href="' . esc_url( WPMU_PLUGIN_URL . '/ktp-desktop/apple-touch-icon.png' ) . '"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-title" content="KantanProEX">';
			echo '<style id="ktp-lan-login">#login h1,#nav,#backtoblog,.language-switcher,.privacy-policy-page-link{display:none!important}'
			. 'body.login{background:#fff}#login{padding-top:8vh}#loginform{box-shadow:0 1px 6px rgba(0,0,0,.12)}'
			. '.ktp-lan-title{font-size:22px;font-weight:700;text-align:center;margin:0 0 18px;color:#1f2937;font-family:-apple-system,"Hiragino Sans","Yu Gothic",sans-serif}</style>';
	}
);
add_filter(
	'login_message',
	function ( $message ) {
		return ktp_dt_is_lan() ? '<p class="ktp-lan-title">KantanProEX ログイン</p>' . $message : $message;
	}
);
add_filter(
	'login_title',
	function ( $title ) {
		return ktp_dt_is_lan() ? 'ログイン ‹ KantanProEX' : $title;
	}
);

// ---------------------------------------------------------------------------
// 親機の設定画面（?ktp_dt_page=lan）とスタッフの管理（ajax）
// ---------------------------------------------------------------------------

function ktp_dt_lan_can_manage() {
	return ! ktp_dt_is_lan() && is_user_logged_in() && current_user_can( 'manage_options' );
}

/** 一覧に出すスタッフ（「スタッフ管理」でオンにしてある人だけ。管理者は出さない） */
function ktp_dt_lan_staff_users() {
	$out = array();
	foreach ( get_users( array( 'orderby' => 'ID' ) ) as $u ) {
		if ( ! in_array( 'administrator', (array) $u->roles, true ) && user_can( $u, 'ktpwp_access' ) ) {
			$out[] = $u;
		}
	}
	return $out;
}

function ktp_dt_lan_check_password( $pass ) {
	return strlen( (string) $pass ) >= KTP_DT_LAN_MIN_PASS;
}

add_action(
	'wp_ajax_ktp_dt_lan_user_save',
	function () {
		check_ajax_referer( KTP_DT_LAN_NONCE, 'nonce' );
		if ( ! ktp_dt_lan_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		$user = get_user_by( 'id', (int) ( $_POST['user_id'] ?? 0 ) );
		if ( ! $user || in_array( 'administrator', (array) $user->roles, true ) ) {
			wp_send_json_error( array( 'message' => 'このユーザーは設定できません。' ) );
		}
		if ( ! user_can( $user, 'ktpwp_access' ) ) {
			wp_send_json_error( array( 'message' => 'このスタッフは「スタッフ管理」でオフになっています。先にスタッフ管理でオンにしてください。' ) );
		}
		$allowed = ! empty( $_POST['allowed'] ) && '0' !== $_POST['allowed'];
		$pass    = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( $allowed && '' === $pass && ! ktp_dt_lan_has_lan_password( $user ) ) {
			wp_send_json_error( array( 'message' => '許可するときは、パスワードも設定してください（' . KTP_DT_LAN_MIN_PASS . ' 文字以上）。' ) );
		}
		if ( '' !== $pass ) {
			if ( ! ktp_dt_lan_check_password( $pass ) ) {
				wp_send_json_error( array( 'message' => 'パスワードは ' . KTP_DT_LAN_MIN_PASS . ' 文字以上にしてください。' ) );
			}
			wp_set_password( $pass, $user->ID );
			update_user_meta( $user->ID, 'ktp_dt_lan_pass_set', '1' );
		}
		if ( $allowed ) {
			update_user_meta( $user->ID, KTP_DT_LAN_META, '1' );
		} else {
			delete_user_meta( $user->ID, KTP_DT_LAN_META );
		}
		wp_send_json_success( array( 'message' => '保存しました。' ) );
	}
);

/** オーナーの LAN 利用の許可と、LAN 用のパスワード（親機の画面からだけ） */
add_action(
	'wp_ajax_ktp_dt_lan_owner_save',
	function () {
		check_ajax_referer( KTP_DT_LAN_NONCE, 'nonce' );
		if ( ! ktp_dt_lan_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		$user = wp_get_current_user();
		$on   = ! empty( $_POST['allowed'] ) && '0' !== $_POST['allowed'];
		$pass = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		if ( $on && '' === $pass && ! ktp_dt_lan_has_lan_password( $user ) ) {
			wp_send_json_error( array( 'message' => '許可するときは、LAN 用のパスワードも決めてください（' . KTP_DT_LAN_OWNER_MIN_PASS . ' 文字以上）。' ) );
		}
		if ( '' !== $pass ) {
			if ( strlen( $pass ) < KTP_DT_LAN_OWNER_MIN_PASS ) {
				wp_send_json_error( array( 'message' => 'オーナーのパスワードは ' . KTP_DT_LAN_OWNER_MIN_PASS . ' 文字以上にしてください。' ) );
			}
			// 親機は自動ログインなので、パスワードを変えても親機の使い方は変わらない。ただしこの画面のログインは一度切れる
			wp_set_password( $pass, $user->ID );
			update_user_meta( $user->ID, 'ktp_dt_lan_pass_set', '1' );
			// wp_set_password は、そのユーザーの全セッションを切る。親機の画面が「セッションの有効期限が切れました」にならないよう、すぐ入り直させる
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
		}
		update_option( KTP_DT_LAN_OWNER_OPT, $on ? '1' : '0', false );
		if ( $on ) {
			update_user_meta( $user->ID, KTP_DT_LAN_META, '1' );
		} else {
			delete_user_meta( $user->ID, KTP_DT_LAN_META );
		}
		wp_send_json_success( array( 'message' => '保存しました。' . ( '' !== $pass ? '' : '' ) ) );
	}
);

/** 取り込みで作られたスタッフはランダムなパスワード。LAN 用に決めたかどうか */
function ktp_dt_lan_has_lan_password( $user ) {
	return '1' === (string) get_user_meta( $user->ID, 'ktp_dt_lan_pass_set', true );
}

/** LAN のスタッフを削除する（管理者・自分自身は削除できない。そのスタッフが書いた投稿などは、オーナーに引き継ぐ） */
add_action(
	'wp_ajax_ktp_dt_lan_user_delete',
	function () {
		check_ajax_referer( KTP_DT_LAN_NONCE, 'nonce' );
		if ( ! ktp_dt_lan_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		$user = get_user_by( 'id', (int) ( $_POST['user_id'] ?? 0 ) );
		if ( ! $user || in_array( 'administrator', (array) $user->roles, true ) || get_current_user_id() === (int) $user->ID ) {
			wp_send_json_error( array( 'message' => 'このユーザーは削除できません。' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/user.php';
		if ( ! wp_delete_user( (int) $user->ID, get_current_user_id() ) ) {
			wp_send_json_error( array( 'message' => '削除できませんでした。' ) );
		}
		wp_send_json_success( array( 'message' => 'スタッフ「' . $user->display_name . '」を削除しました。' ) );
	}
);

/**
 * 追加したスタッフへの案内メール。パスワードは書かない（オーナーが直接伝える）。
 * 本体の通知（wp_login_url 宛て・パスワードのリセット案内）は、親機の中だけで動くデスクトップ版では使えないため、別に作る
 */
function ktp_dt_staff_welcome_mail( WP_User $user ) {
	$smtp      = get_option( 'ktp_smtp_settings', array() );
	$from      = ! empty( $smtp['email_address'] ) ? sanitize_email( $smtp['email_address'] ) : '';
	$from_name = ! empty( $smtp['smtp_from_name'] ) ? sanitize_text_field( $smtp['smtp_from_name'] ) : get_bloginfo( 'name' );
	if ( '' === $from ) {
		return false; // 送信元が決まっていない（メール・SMTP設定がまだ）
	}
	return wp_mail(
		$user->user_email,
		'[' . $from_name . '] スタッフに登録されました',
		ktp_dt_staff_welcome_body( $user, $from_name ),
		array( 'From: ' . $from_name . ' <' . $from . '>' )
	);
}

/** 案内メールの本文（追加したとき・スタッフ管理でオンにしたときの共通） */
function ktp_dt_staff_welcome_body( WP_User $user, $from_name ) {
	$st   = ktp_dt_lan_status();
	$urls = ! empty( $st['enabled'] ) && isset( $st['urls'] ) && is_array( $st['urls'] ) ? $st['urls'] : array();
	$name = '' !== $user->display_name ? $user->display_name : $user->user_login;

	$body  = $name . " 様\n\n";
	$body .= $from_name . ' の業務管理（KantanProEX）のスタッフに登録されました。' . "\n\n";
	$body .= "ログイン名: " . $user->user_login . "\n";
	$body .= "パスワード: 管理者から直接お知らせします（このメールには書いていません）\n\n";
	if ( $urls ) {
		$body .= "事務所のネットワーク（Wi-Fi）につないだパソコン・iPad・iPhone のブラウザで、次のアドレスを開いてください。\n";
		$body .= implode( "\n", $urls ) . "\n\n";
	} else {
		$body .= "開くアドレスは、管理者からお知らせします。\n\n";
	}
	$body .= "使い方: https://www.kantanpro.com/docs/lan-staff\n\n";
	$body .= "※ このメールは自動送信されています。\n";
	return $body;
}

/**
 * スタッフ管理でオンにしたときの本体の通知は、親機の 127.0.0.1 のログイン URL とパスワードのリセットを案内してしまう。
 * デスクトップ版では使えないので、本文を案内メールと同じものに差し替える（オフにしたときの通知はそのまま）
 */
add_filter(
	'wp_mail',
	function ( $atts ) {
		if ( ! is_string( $atts['subject'] ?? null ) || ! preg_match( '/スタッフ権限が付与されました$/u', $atts['subject'] ) ) {
			return $atts;
		}
		$user = get_user_by( 'email', is_array( $atts['to'] ) ? (string) reset( $atts['to'] ) : (string) $atts['to'] );
		if ( $user ) {
			$smtp            = get_option( 'ktp_smtp_settings', array() );
			$from_name       = ! empty( $smtp['smtp_from_name'] ) ? sanitize_text_field( $smtp['smtp_from_name'] ) : get_bloginfo( 'name' );
			$atts['subject'] = '[' . $from_name . '] スタッフに登録されました';
			$atts['message'] = ktp_dt_staff_welcome_body( $user, $from_name );
		}
		return $atts;
	}
);

/** 取り込み・旧版で作ったスタッフのダミーアドレス（@invalid.example）宛てには送らない（本体のオン/オフ通知など） */
add_filter(
	'pre_wp_mail',
	function ( $short, $atts ) {
		$to = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
		foreach ( $to as $addr ) {
			if ( preg_match( '/@invalid\.example>?\s*$/i', trim( $addr ) ) ) {
				$GLOBALS['ktp_dt_mail_skipped_dummy'] = true;
				return false;
			}
		}
		return $short;
	},
	10,
	2
);

add_action(
	'wp_ajax_ktp_dt_lan_user_add',
	function () {
		check_ajax_referer( KTP_DT_LAN_NONCE, 'nonce' );
		if ( ! ktp_dt_lan_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		$login = isset( $_POST['login'] ) ? trim( (string) wp_unslash( $_POST['login'] ) ) : '';
		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$pass  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'メールアドレスを正しく入れてください。' ) );
		}
		if ( email_exists( $email ) ) {
			wp_send_json_error( array( 'message' => 'そのメールアドレスは、ほかのスタッフで使われています。' ) );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{3,40}$/', $login ) ) {
			wp_send_json_error( array( 'message' => 'ログイン名は半角の英数字（_ . - 可）で 3〜40 文字にしてください。' ) );
		}
		if ( username_exists( $login ) ) {
			wp_send_json_error( array( 'message' => 'そのログイン名はすでに使われています。' ) );
		}
		if ( ! ktp_dt_lan_check_password( $pass ) ) {
			wp_send_json_error( array( 'message' => 'パスワードは ' . KTP_DT_LAN_MIN_PASS . ' 文字以上にしてください。' ) );
		}
		if ( class_exists( 'KTPWP_Edition' ) && ! KTPWP_Edition::can_add_staff() ) {
			wp_send_json_error( array( 'message' => KTPWP_Edition::get_staff_limit_reached_message() ) );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $pass,
				'user_email'   => $email,
				'display_name' => '' !== $name ? $name : $login,
				'role'         => 'editor',
			)
		);
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		( new WP_User( $id ) )->add_cap( 'ktpwp_access' ); // 「スタッフ管理」でもオンになる
		update_user_meta( $id, KTP_DT_LAN_META, '1' );
		update_user_meta( $id, 'ktp_dt_lan_pass_set', '1' );
		if ( ktp_dt_staff_welcome_mail( new WP_User( $id ) ) ) {
			wp_send_json_success( array( 'message' => 'スタッフを追加し、' . $email . ' に案内メールを送りました。' ) );
		}
		wp_send_json_success( array( 'message' => 'スタッフを追加しました。案内メールは送れませんでした（「メール・SMTP設定」を確認してください）。ログイン名は本人に直接伝えてください。' ) );
	}
);

/** 設定メニューの下の「LAN 共有」画面（親機でだけ開ける）の中身 */
function ktp_dt_lan_page_html() {
		$st      = ktp_dt_lan_status();
		$on      = ! empty( $st['enabled'] );
		$staff   = ktp_dt_lan_staff_users();
		$toggle  = home_url( '/?ktp_desktop_lan=' . ( $on ? 'off' : 'on' ) );
		$urls    = isset( $st['urls'] ) && is_array( $st['urls'] ) ? $st['urls'] : array();
		ob_start();
		?>
<div id="ktp-dt-lan" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( KTP_DT_LAN_NONCE ) ); ?>" style="max-width:760px;margin:0 auto;font-size:14px;line-height:1.7;">
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">← アプリに戻る</a></p>
	<h2 style="margin:6px 0 12px;">LAN 共有（事務所の他の PC から使う）</h2>
	<p style="color:#6b7280;font-size:13px;">この PC（親機）にあるデータを、同じ事務所のネットワーク（LAN）につながった他の PC のブラウザから、スタッフが使えるようにします。データは親機の中にだけあり、インターネット上には置きません。</p>

	<div style="border:1px solid <?php echo $on ? '#86efac' : '#d1d5db'; ?>;background:<?php echo $on ? '#f0fdf4' : '#f9fafb'; ?>;padding:12px 16px;border-radius:6px;margin:12px 0;">
		<p style="margin:0 0 6px;font-weight:700;">現在: <?php echo $on ? 'オン（他の PC から入れます）' : 'オフ（この PC だけで使っています）'; ?></p>
		<?php if ( ! empty( $st['error'] ) ) : ?>
			<p style="color:#b91c1c;margin:0 0 6px;">開始できませんでした: <?php echo esc_html( (string) $st['error'] ); ?></p>
		<?php endif; ?>
		<?php if ( $on ) : ?>
			<?php if ( $urls ) : ?>
				<p style="margin:0 0 4px;">他の PC のブラウザで、次のアドレスを開いてもらいます:</p>
				<ul style="margin:0 0 6px 18px;">
				<?php foreach ( $urls as $u ) : ?>
					<li><code style="font-size:15px;"><?php echo esc_html( (string) $u ); ?></code></li>
				<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p style="color:#b91c1c;margin:0 0 6px;">この PC の LAN のアドレスが見つかりません。Wi-Fi・有線につながっているか確認してください。</p>
			<?php endif; ?>
			<?php if ( ! empty( $st['fingerprint'] ) ) : ?>
				<p style="margin:0 0 6px;font-size:12px;color:#6b7280;">証明書の指紋（SHA-256）: <code style="word-break:break-all;"><?php echo esc_html( (string) $st['fingerprint'] ); ?></code></p>
			<?php endif; ?>
		<?php endif; ?>
		<p style="margin:6px 0 0;"><a href="<?php echo esc_url( $toggle ); ?>" style="display:inline-block;background:<?php echo $on ? '#6b7280' : '#2563eb'; ?>;color:#fff;padding:6px 18px;border-radius:4px;text-decoration:none;"><?php echo $on ? 'LAN 共有をオフにする' : 'LAN 共有をオンにする'; ?></a></p>
	</div>

	<h3 style="margin:18px 0 6px;">iPad・iPhone をつなぐ（最初の 1 回だけ）</h3>
	<?php $profiles = isset( $st['profileUrls'] ) && is_array( $st['profileUrls'] ) ? $st['profileUrls'] : array(); ?>
	<?php if ( $on && $urls ) : ?>
	<?php foreach ( $urls as $i => $u ) : ?>
	<div style="display:flex;gap:24px;flex-wrap:wrap;margin:6px 0 10px;">
		<div><div class="lan-qr" data-text="<?php echo esc_attr( (string) ( $profiles[ $i ] ?? '' ) ); ?>" style="width:150px;height:150px;"></div><div style="font-size:12px;text-align:center;">① 証明書のプロファイル</div></div>
		<div><div class="lan-qr" data-text="<?php echo esc_attr( (string) $u ); ?>" style="width:150px;height:150px;"></div><div style="font-size:12px;text-align:center;">② アプリを開く</div></div>
		<div style="align-self:center;font-size:12px;color:#6b7280;">接続先: <code><?php echo esc_html( (string) $u ); ?></code></div>
	</div>
	<?php endforeach; ?>
	<?php endif; ?>
	<ol style="margin:0 0 12px 20px;">
		<li>iPad のカメラで「① 証明書のプロファイル」の QR を読み取り、Safari で開きます。「構成プロファイルをダウンロードしますか？」に「許可」を選びます。</li>
		<li>iPad の「設定」→ 上に出る「プロファイルがダウンロードされました」→「インストール」。パスコードを聞かれたら入力します。</li>
		<li>「設定」→「一般」→「情報」→ 一番下の「証明書信頼設定」で、「KantanProEX LAN 認証局」をオンにします（ここを忘れると警告が出続けます）。</li>
		<li>「② アプリを開く」の QR を読み取ります。ログイン画面が警告なしで開きます。Safari の共有ボタン →「ホーム画面に追加」で、アプリのように使えます。</li>
	</ol>
	<p style="color:#6b7280;font-size:12px;">この証明書は、この事務所の KantanProEX 専用です。他のサイトの通信には使われません。使わなくなったら、「設定」→「一般」→「VPN とデバイス管理」で削除できます。</p>

	<h3 style="margin:18px 0 6px;">パソコンをつなぐ</h3>
	<ol style="margin:0 0 12px 20px;">
		<li>他の PC のブラウザで、上のアドレスを開きます。</li>
		<li>「安全ではありません」などの警告が出ます（この PC が自分で作った証明書のためです）。「詳細」→「このまま進む（アクセスする）」を選びます。最初の 1 回だけです。警告を出したくないときは、<code>/ktp-lan/ca.crt</code> を同じアドレスの後ろに付けてダウンロードし、「信頼されたルート証明機関」に入れます。</li>
		<li>スタッフのログイン名とパスワードでログインします。</li>
	</ol>
	<p style="color:#6b7280;font-size:12px;margin-top:-4px;">警告で表示される指紋が、上の「証明書の指紋」と同じなら、この PC につながっています。</p>

	<h3 style="margin:18px 0 6px;">オーナー（あなた）も iPad などから使う</h3>
	<p style="color:#6b7280;font-size:13px;">オンにすると、オーナー（管理者）も他の端末からログインできます。業務の画面（受注・顧客・レポートなど）だけが使え、設定・LAN 共有の設定・バックアップ・取り込みは、この PC の画面でだけ操作できます。スタッフより長い <?php echo (int) KTP_DT_LAN_OWNER_MIN_PASS; ?> 文字以上の、専用のパスワードを決めてください。</p>
	<p id="lan-owner">
		<label><input type="checkbox" id="lan-owner-on" <?php checked( ktp_dt_lan_owner_enabled() ); ?>> オーナーも LAN から使う</label>
		<input type="password" id="lan-owner-pass" autocomplete="new-password" placeholder="<?php echo ktp_dt_lan_has_lan_password( wp_get_current_user() ) ? 'LAN 用パスワード（設定済み）' : 'LAN 用パスワード（未設定）'; ?>" style="padding:4px;width:260px;">
		<button type="button" id="lan-owner-save" style="padding:3px 12px;">保存</button>
	</p>
	<p style="color:#6b7280;font-size:12px;margin-top:-6px;">ログイン名は、オーナーのログイン名（<code><?php echo esc_html( wp_get_current_user()->user_login ); ?></code>）です。</p>

	<h3 style="margin:18px 0 6px;">LAN で使うスタッフ</h3>
	<p style="color:#6b7280;font-size:13px;">ここに出るのは、<a href="<?php echo esc_url( admin_url( 'admin.php?page=ktp-staff' ) ); ?>">「スタッフ管理」</a>でオンにしているスタッフだけです。スタッフ管理でオフにすると、LAN からも入れなくなります。許可したスタッフだけが、他の PC からログインできます。オーナー（管理者）は、上の設定をオンにしたときだけ使えます。パスワードは <?php echo (int) KTP_DT_LAN_MIN_PASS; ?> 文字以上。パスワードの欄を空にして保存すると、いまのパスワードのままです。</p>
	<table style="width:100%;border-collapse:collapse;" id="lan-staff">
		<thead><tr style="text-align:left;border-bottom:1px solid #d1d5db;"><th style="padding:6px;">名前</th><th style="padding:6px;">ログイン名</th><th style="padding:6px;">LAN で使う</th><th style="padding:6px;">パスワード（変えるとき）</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $staff as $u ) : ?>
			<tr data-user="<?php echo (int) $u->ID; ?>" style="border-bottom:1px solid #eee;">
				<td style="padding:6px;"><?php echo esc_html( $u->display_name ); ?></td>
				<td style="padding:6px;"><code><?php echo esc_html( $u->user_login ); ?></code></td>
				<td style="padding:6px;"><input type="checkbox" class="lan-allowed" <?php checked( ktp_dt_lan_user_ok( $u ) ); ?>></td>
				<td style="padding:6px;"><input type="password" class="lan-pass" autocomplete="new-password" placeholder="<?php echo ktp_dt_lan_has_lan_password( $u ) ? '設定済み' : '未設定'; ?>" style="padding:4px;width:100%;"></td>
				<td style="padding:6px;white-space:nowrap;"><button type="button" class="lan-save" style="padding:3px 12px;">保存</button></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $staff ) : ?>
			<tr><td colspan="5" style="padding:8px;color:#6b7280;">LAN で使えるスタッフはまだいません。「スタッフ管理」で追加するか、オンにしてください。</td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<p style="color:#6b7280;font-size:13px;">スタッフの追加・削除は、<a href="<?php echo esc_url( admin_url( 'admin.php?page=ktp-staff' ) ); ?>">「スタッフ管理」</a>で行います。</p>
	<p id="lan-msg" role="status" style="display:none;position:fixed;left:50%;bottom:24px;transform:translateX(-50%);max-width:90%;margin:0;padding:10px 18px;border-radius:6px;background:#fff;border:2px solid currentColor;box-shadow:0 4px 16px rgba(0,0,0,.25);font-weight:700;z-index:99999;"></p>

	<h3 style="margin:18px 0 6px;">安全のために</h3>
	<ul style="margin:0 0 12px 20px;">
		<li>インターネットには公開しません。ルーターの「ポート転送」「ポートの開放」は設定しないでください（設定しても、事務所の外からの接続は受け付けません）。</li>
		<li>来客用の Wi-Fi と事務所の Wi-Fi は分けてください。同じ Wi-Fi につながる端末からは、ログイン画面が見えます。</li>
		<li>通信は暗号化されます。ログインに 5 回失敗すると、15 分間そのログイン名・端末からは入れません。</li>
		<li>この PC が眠る・終了すると、他の PC から使えなくなります。オンの間は、この PC が自動で眠らないようにしています。</li>
		<li>同時に使う人が多いと、画面の反応が遅くなることがあります（特に Windows）。</li>
		<li>パスワードは、使い回しのない、他人に推測されにくいものにしてください。</li>
	</ul>
</div>
<script><?php echo file_get_contents( __DIR__ . '/qrcode.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions ?></script>
<script>
(function(){
	var w=document.getElementById('ktp-dt-lan'),msg=document.getElementById('lan-msg');
	function post(action,data,done){
		var d=new URLSearchParams(Object.assign({action:action,nonce:w.dataset.nonce},data));
		msg.style.display='block';msg.style.color='#1f2937';msg.textContent='保存しています…';
		fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d.toString()}).then(function(r){return r.json();}).then(function(j){
			msg.style.color=j.success?'#166534':'#b91c1c';
			msg.textContent=(j.data&&j.data.message)||(j.success?'保存しました。':'保存できませんでした。');
			if(j.success){done&&done();}
			clearTimeout(post.t);post.t=setTimeout(function(){msg.style.display='none';},j.success?4000:12000);
		}).catch(function(){msg.style.color='#b91c1c';msg.textContent='保存できませんでした。';});
	}
	w.querySelectorAll('.lan-qr').forEach(function(el){
		if(!el.dataset.text||typeof qrcode==='undefined'){return;}
		var q=qrcode(0,'M');q.addData(el.dataset.text);q.make();
		el.innerHTML=q.createSvgTag({cellSize:4,margin:16});
		var sv=el.querySelector('svg');if(sv){sv.style.width='100%';sv.style.height='100%';sv.style.background='#fff';}
	});
	w.querySelectorAll('#lan-staff .lan-save').forEach(function(b){b.onclick=function(){
		var tr=b.closest('tr');
		post('ktp_dt_lan_user_save',{user_id:tr.dataset.user,allowed:tr.querySelector('.lan-allowed').checked?'1':'0',password:tr.querySelector('.lan-pass').value},function(){
			var p=tr.querySelector('.lan-pass');if(p.value){p.placeholder='設定済み';}p.value='';
		});
	};});
	document.getElementById('lan-owner-save').onclick=function(){
		var p=document.getElementById('lan-owner-pass');
		post('ktp_dt_lan_owner_save',{allowed:document.getElementById('lan-owner-on').checked?'1':'0',password:p.value},function(){if(p.value){p.placeholder='LAN 用パスワード（設定済み）';}p.value='';});
	};
})();
</script>
		<?php
		return (string) ob_get_clean();
}

/** サイドバーの設定メニュー（KantanProEX 設定）の下に置く。LAN から来た要求には出さない */
add_action(
	'admin_menu',
	function () {
		if ( ktp_dt_is_lan() ) {
			return;
		}
		add_submenu_page(
			'ktp-settings',
			'LAN 共有',
			'LAN 共有',
			'manage_options',
			KTP_DT_LAN_PAGE,
			function () {
				if ( ! ktp_dt_lan_can_manage() ) {
					return;
				}
				echo '<div class="wrap">' . ktp_dt_lan_page_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
	},
	PHP_INT_MAX
);

/** 以前の画面のアドレス（?ktp_dt_page=lan）は、設定メニューの画面へ転送する */
add_action(
	'init',
	function () {
		if ( isset( $_GET['ktp_dt_page'] ) && 'lan' === $_GET['ktp_dt_page'] && ktp_dt_lan_can_manage() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( admin_url( 'admin.php?page=' . KTP_DT_LAN_PAGE ) );
			exit;
		}
	},
	20
);

/** 「スタッフ管理」にスタッフを追加する欄（デスクトップ版は WP のユーザー追加画面が無く、メール招待もできないため） */
add_action(
	'admin_footer',
	function () {
		if ( ! ktp_dt_lan_can_manage() || ! isset( $_GET['page'] ) || 'ktp-staff' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		?>
<div id="ktp-dt-staff-add" class="ktp-settings-section" style="display:none;" data-me="<?php echo (int) get_current_user_id(); ?>" data-admins="<?php echo esc_attr( implode( ',', get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) ) ); ?>" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( KTP_DT_LAN_NONCE ) ); ?>">
	<h2>スタッフを追加</h2>
	<p style="color:#555;font-size:13px;">追加すると、入れたメールアドレスに、ログイン名と使い方の案内メールが届きます（「メール・SMTP設定」が必要です）。<strong>パスワードはメールに書かないので、本人に直接伝えてください。</strong>他のパソコン・iPad・iPhone から使うには、「LAN 共有」をオンにします。</p>
	<p>
		<input type="text" id="sa-name" placeholder="表示名（例: 山田）" style="padding:5px;width:22%;">
		<input type="email" id="sa-email" placeholder="メールアドレス" autocapitalize="off" style="padding:5px;width:24%;">
		<input type="text" id="sa-login" placeholder="ログイン名（半角英数字）" autocapitalize="off" style="padding:5px;width:22%;">
		<input type="password" id="sa-pass" placeholder="パスワード（<?php echo (int) KTP_DT_LAN_MIN_PASS; ?>文字以上）" autocomplete="new-password" style="padding:5px;width:22%;">
	</p>
	<p><button type="button" id="sa-add" class="button button-primary">追加する</button> <span id="sa-msg" role="status" style="margin-left:10px;font-weight:700;"></span></p>
</div>
<script>
(function(){
	var box=document.getElementById('ktp-dt-staff-add'),host=document.querySelector('.ktp-settings-container');
	if(!box||!host){return;}
	host.appendChild(box);box.style.display='';
	var nt=host.querySelector('.notice-info, div[style*="e7f3ff"]');
	if(nt){nt.innerHTML='<strong>メール通知について：</strong>スタッフを追加したとき、オン・オフを切り替えたときに、そのスタッフへメールが届きます（「メール・SMTP設定」が必要です）。メールアドレス欄が「…@invalid.example」のスタッフ（取り込み・旧版で作った人）には送られません。';}
	<?php if ( ! empty( $GLOBALS['ktp_dt_mail_skipped_dummy'] ) ) : ?>
	document.querySelectorAll('.notice-warning p').forEach(function(p){
		if(/通知メール送信に失敗/.test(p.textContent)){p.textContent='このスタッフはメールアドレスがダミー（…@invalid.example）のため、通知メールは送っていません。';p.closest('.notice').className='notice notice-info is-dismissible';}
	});
	<?php endif; ?>
	var msg=document.getElementById('sa-msg');
	function post(data,busy,done){
		var d=new URLSearchParams(Object.assign({nonce:box.dataset.nonce},data));
		msg.style.color='#1f2937';msg.textContent=busy;
		fetch(box.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d.toString()}).then(function(r){return r.json();}).then(function(j){
			msg.style.color=j.success?'#166534':'#b91c1c';
			msg.textContent=(j.data&&j.data.message)||(j.success?'完了しました。':'できませんでした。');
			if(j.success){done();}
		}).catch(function(){msg.style.color='#b91c1c';msg.textContent='できませんでした。';});
	}
	document.getElementById('sa-add').onclick=function(){
		post({action:'ktp_dt_lan_user_add',name:document.getElementById('sa-name').value,email:document.getElementById('sa-email').value,login:document.getElementById('sa-login').value,password:document.getElementById('sa-pass').value},'追加しています…',function(){setTimeout(function(){location.reload();},2500);});
	};
	// 一覧の各行に「削除」（管理者・自分自身は出さない）
	var me=String(box.dataset.me),admins=String(box.dataset.admins).split(',');
	var table=host.querySelector('.ktp-staff-table');
	if(table){
		var th=document.createElement('th');th.textContent='削除';table.querySelector('thead tr').appendChild(th);
		table.querySelectorAll('tbody tr').forEach(function(tr){
			var td=document.createElement('td'),inp=tr.querySelector('input[name="ktpwp_access_user"]'),uid=inp?inp.value:'';
			tr.appendChild(td);
			if(!uid||uid===me||admins.indexOf(uid)>=0){return;}
			var b=document.createElement('button');b.type='button';b.className='button button-link-delete';b.textContent='削除';
			b.onclick=function(){
				var name=tr.children[0].textContent.trim();
				if(!window.confirm('スタッフ「'+name+'」を削除します。元に戻せません。\n（しばらく使わせないだけなら、削除せずにオフにしてください）\nよろしいですか？')){return;}
				post({action:'ktp_dt_lan_user_delete',user_id:uid},'削除しています…',function(){tr.parentNode.removeChild(tr);});
			};
			td.appendChild(b);
		});
	}
})();
</script>
		<?php
	}
);
