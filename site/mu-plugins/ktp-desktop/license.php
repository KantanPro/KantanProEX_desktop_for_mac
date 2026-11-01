<?php
/**
 * 無料版と有料版（ライセンス）。
 *
 * アプリは 1 本のまま、ライセンスキーで有料版かどうかを決める。
 * - paid   : 有効なライセンスキーが入っている（買い切り・期限なし）
 * - legacy : 無料版に対応する前から入っていたインストール（既存の購入者）。有料版と同じ扱い
 * - trial  : 新規インストールから 30 日間は全機能が使える
 * - free   : 試用期間が終わった。一人で毎日使う機能（顧客・受注・書類・バックアップなど）は無料のまま
 *
 * キーは「base64url(JSON) . base64url(署名)」。署名は ECDSA P-256 + SHA-256（同梱 PHP には sodium が無く openssl はあるため）。
 * 秘密鍵はリポジトリに置かない（~/.kantanpro/dt-license/private.pem、scripts/issue-license.php で発行する）。
 * アプリに入っているのは公開鍵だけで、確認はこのパソコンの中だけで済む（外部と通信しない）。
 *
 * 注意: EX 本体の KTPWP_Edition（エディション切替）は 127.0.0.1 で動くと常に有効になるので、判定には使わない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_LICENSE_OPT        = 'ktp_dt_license';      // array( origin, trial_start, key )
const KTP_DT_LICENSE_SEEN_OPT   = 'ktp_dt_license_seen'; // 最後に見た時刻（時計を戻しての延長対策）
const KTP_DT_LICENSE_PAGE       = 'ktp-dt-license';
const KTP_DT_LICENSE_NONCE      = 'ktp_dt_license';
const KTP_DT_TRIAL_DAYS         = 30;
const KTP_DT_BUY_URL            = 'https://www.kantanpro.com/?p=19249';
const KTP_DT_AD_TEXT            = 'KantanProEX（DT）で作成 — 無料で使える業務管理アプリ kantanpro.com';

// 公開鍵（ECDSA P-256）。対になる秘密鍵は ~/.kantanpro/dt-license/private.pem にだけある
const KTP_DT_LICENSE_PUBKEY = '-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEqltgtKeFK0DQ6ap7YMv4NFhQoNa0
Mnb5//MkgE/godgEXId0IvniSbnu00EWz3kAIs0ck5I+51U1cQth9csqIg==
-----END PUBLIC KEY-----
';

// ---------------------------------------------------------------------------
// 時刻
// ---------------------------------------------------------------------------

/**
 * 判定に使う現在時刻。
 * - 自動テスト用: KTP_TEST_LICENSE_NOW_OFFSET_DAYS（日数をずらす）。KTP_TEST_USER_DATA が指定されているテスト起動のときだけ効く
 * - 時計を戻されても、最後に見た時刻より前には戻さない
 */
function ktp_dt_license_now() {
	static $now = null;
	if ( null !== $now ) {
		return $now;
	}
	$now      = time();
	$offset   = (float) getenv( 'KTP_TEST_LICENSE_NOW_OFFSET_DAYS' );
	$is_test  = '' !== (string) getenv( 'KTP_TEST_USER_DATA' );
	if ( $is_test && 0.0 !== $offset ) {
		$now = (int) round( $now + $offset * DAY_IN_SECONDS );
		return $now;
	}
	if ( wp_installing() ) {
		return $now;
	}
	$seen = (int) get_option( KTP_DT_LICENSE_SEEN_OPT, 0 );
	if ( $seen > $now ) {
		$now = $seen; // 時計が戻されている
	} elseif ( $now - $seen > HOUR_IN_SECONDS ) {
		update_option( KTP_DT_LICENSE_SEEN_OPT, $now, false );
	}
	return $now;
}

// ---------------------------------------------------------------------------
// キー
// ---------------------------------------------------------------------------

function ktp_dt_b64url_decode( $s ) {
	$s   = strtr( (string) $s, '-_', '+/' );
	$pad = strlen( $s ) % 4;
	if ( $pad ) {
		$s .= str_repeat( '=', 4 - $pad );
	}
	$out = base64_decode( $s, true );
	return false === $out ? '' : $out;
}

/**
 * ライセンスキーを確かめる。正しければ { email, id, issued }、正しくなければ false
 */
function ktp_dt_license_verify_key( $key ) {
	$key = preg_replace( '/\s+/', '', (string) $key );
	if ( ! function_exists( 'openssl_verify' ) || ! preg_match( '/^([A-Za-z0-9_-]{8,2000})\.([A-Za-z0-9_-]{8,400})$/', $key, $m ) ) {
		return false;
	}
	$payload = ktp_dt_b64url_decode( $m[1] );
	$sig     = ktp_dt_b64url_decode( $m[2] );
	if ( '' === $payload || '' === $sig ) {
		return false;
	}
	if ( 1 !== openssl_verify( $payload, $sig, KTP_DT_LICENSE_PUBKEY, OPENSSL_ALGO_SHA256 ) ) {
		return false;
	}
	$d = json_decode( $payload, true );
	if ( ! is_array( $d ) || 'ktp-dt' !== ( $d['p'] ?? '' ) ) {
		return false;
	}
	return array(
		'email'  => (string) ( $d['e'] ?? '' ),
		'id'     => (string) ( $d['i'] ?? '' ),
		'issued' => (int) ( $d['t'] ?? 0 ),
	);
}

// ---------------------------------------------------------------------------
// 記録（DB の option と、アプリのデータ置き場の license.json の二重持ち）
// バックアップの復元で DB が古い状態に戻っても、legacy / paid が free に落ちないようにする
// ---------------------------------------------------------------------------

function ktp_dt_license_userdata_dir() {
	return defined( 'KTP_DESKTOP_TOKEN_FILE' ) ? dirname( KTP_DESKTOP_TOKEN_FILE ) : '';
}

function ktp_dt_license_file() {
	$dir = ktp_dt_license_userdata_dir();
	return '' === $dir ? '' : $dir . '/license.json';
}

/** 新規インストールの目印（lib/site.js が初回セットアップの最初に書く）。無ければ既存のインストール */
function ktp_dt_license_fresh_marker() {
	$dir = ktp_dt_license_userdata_dir();
	if ( '' === $dir || ! is_readable( $dir . '/data/fresh-install.json' ) ) {
		return null;
	}
	$d = json_decode( (string) file_get_contents( $dir . '/data/fresh-install.json' ), true );
	return is_array( $d ) ? $d : array();
}

function ktp_dt_license_read_file() {
	$f = ktp_dt_license_file();
	if ( '' === $f || ! is_readable( $f ) ) {
		return array();
	}
	$d = json_decode( (string) file_get_contents( $f ), true );
	return is_array( $d ) ? $d : array();
}

/**
 * 記録（origin = 'legacy' か 'trial'、trial_start、key）。初めて呼ばれたときに作る
 */
function ktp_dt_license_record() {
	static $rec = null;
	if ( null !== $rec ) {
		return $rec;
	}
	$db   = get_option( KTP_DT_LICENSE_OPT, array() );
	$db   = is_array( $db ) ? $db : array();
	$file = ktp_dt_license_read_file();

	// 初回セットアップの最中（DB がまだ無い）は、何も保存せずに試用として扱う
	if ( wp_installing() ) {
		return array( 'origin' => 'trial', 'trial_start' => time(), 'key' => '' );
	}

	$origin = 'trial';
	if ( ! $db && ! $file ) {
		$marker = ktp_dt_license_fresh_marker();
		if ( null === $marker ) {
			$origin = 'legacy'; // 目印が無い = 無料版に対応する前から入っていた
		}
		$start = null !== $marker && ! empty( $marker['at'] ) ? (int) $marker['at'] : time();
		$rec   = array( 'origin' => $origin, 'trial_start' => $start, 'key' => '' );
	} else {
		// どちらかが legacy なら legacy。試用の開始は早いほう。キーは正しいほう
		$origin = ( 'legacy' === ( $db['origin'] ?? '' ) || 'legacy' === ( $file['origin'] ?? '' ) ) ? 'legacy' : 'trial';
		$starts = array_filter( array( (int) ( $db['trial_start'] ?? 0 ), (int) ( $file['trial_start'] ?? 0 ) ) );
		$key    = '';
		foreach ( array( (string) ( $db['key'] ?? '' ), (string) ( $file['key'] ?? '' ) ) as $k ) {
			if ( '' !== $k && ktp_dt_license_verify_key( $k ) ) {
				$key = $k;
				break;
			}
		}
		$rec = array( 'origin' => $origin, 'trial_start' => $starts ? min( $starts ) : time(), 'key' => $key );
	}
	if ( $db !== $rec ) {
		update_option( KTP_DT_LICENSE_OPT, $rec, true );
	}
	return $rec;
}

/** 記録を書き換える（キーを入れたときなど）。ファイルにも書く */
function ktp_dt_license_save_record( array $rec ) {
	update_option( KTP_DT_LICENSE_OPT, $rec, true );
	ktp_dt_license_write_file( $rec, true );
}

function ktp_dt_license_write_file( array $rec, $force = false ) {
	$f = ktp_dt_license_file();
	if ( '' === $f || wp_installing() ) {
		return;
	}
	$out = array(
		'origin'      => $rec['origin'],
		'trial_start' => (int) $rec['trial_start'],
		'key'         => (string) $rec['key'],
		// Electron（main.js）が LAN 共有・音声操作を始めてよいか見るための情報。until: null = 期限なし、数値 = 試用の終わり（UNIX 時刻）、0 = 無料版
		'state'       => ktp_dt_license_state_of( $rec ),
		'until'       => ktp_dt_license_until( $rec ),
	);
	$json = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	if ( $force || ! is_readable( $f ) || (string) file_get_contents( $f ) !== $json ) {
		@file_put_contents( $f, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions
	}
}

// ---------------------------------------------------------------------------
// 判定
// ---------------------------------------------------------------------------

function ktp_dt_license_trial_end( array $rec ) {
	return (int) $rec['trial_start'] + KTP_DT_TRIAL_DAYS * DAY_IN_SECONDS;
}

function ktp_dt_license_state_of( array $rec ) {
	if ( '' !== $rec['key'] ) {
		return 'paid';
	}
	if ( 'legacy' === $rec['origin'] ) {
		return 'legacy';
	}
	return ktp_dt_license_now() < ktp_dt_license_trial_end( $rec ) ? 'trial' : 'free';
}

function ktp_dt_license_until( array $rec ) {
	$s = ktp_dt_license_state_of( $rec );
	if ( 'paid' === $s || 'legacy' === $s ) {
		return null;
	}
	return 'trial' === $s ? ktp_dt_license_trial_end( $rec ) : 0;
}

/** 'paid' | 'legacy' | 'trial' | 'free' */
function ktp_dt_license_state() {
	static $state = null;
	if ( null !== $state ) {
		return $state;
	}
	$rec = ktp_dt_license_record();
	if ( wp_installing() ) {
		return 'trial';
	}
	ktp_dt_license_write_file( $rec );
	$state = ktp_dt_license_state_of( $rec );
	return $state;
}

/** 全機能が使えるか（paid / legacy / 試用中） */
function ktp_dt_is_paid() {
	return 'free' !== ktp_dt_license_state();
}

/** 有料版か（paid か legacy）。書類・メールの宣伝の 1 行を消す判定に使う（試用中は宣伝は出す） */
function ktp_dt_has_paid_license() {
	return in_array( ktp_dt_license_state(), array( 'paid', 'legacy' ), true );
}

/** 試用の残り日数（試用中でなければ 0） */
function ktp_dt_trial_days_left() {
	$rec = ktp_dt_license_record();
	if ( 'trial' !== ktp_dt_license_state() ) {
		return 0;
	}
	return max( 0, (int) ceil( ( ktp_dt_license_trial_end( $rec ) - ktp_dt_license_now() ) / DAY_IN_SECONDS ) );
}

/** 有効なキーの持ち主（paid のとき） */
function ktp_dt_license_key_info() {
	$rec = ktp_dt_license_record();
	return '' !== $rec['key'] ? ktp_dt_license_verify_key( $rec['key'] ) : false;
}

/** 有料機能を使えない状態か */
function ktp_dt_feature_locked() {
	return ! ktp_dt_is_paid();
}

/** 書類・メールの宣伝の 1 行を入れるか（有料版でなく、ユーザー登録もしていない） */
function ktp_dt_show_ad() {
	return ! ktp_dt_has_paid_license() && ( ! function_exists( 'ktp_desktop_registered_email' ) || '' === ktp_desktop_registered_email() );
}

// ---------------------------------------------------------------------------
// 有料版だけの機能（案内画面の文）
// ---------------------------------------------------------------------------

function ktp_dt_license_features() {
	return array(
		'lan'        => array( 'LAN 共有・スタッフ・iPad / iPhone から使う', '事務所のほかのパソコンや iPad・iPhone から、スタッフがブラウザでログインして同じデータを使えます。人が増えても、データは親機の中だけに置けます。' ),
		'intake'     => array( 'サイトからの受注・Stripe 即時購入', 'ホームページのお問い合わせ（Contact Form 7）や WooCommerce の注文を 15 分ごとに自動で受注に取り込みます。Stripe のカード払いも、入金済みの受注として入ります。' ),
		'duplicate'  => array( '受注書・サービスの複製', '登録済みの受注書・サービスを、ボタン 1 つでそっくり複製できます。似た案件や商品をすばやく作れます。' ),
		'contract'   => array( '契約・定期請求（継続請求）', '毎月の請求を自動で作ります。契約の管理、請求書メールの自動送信、支払いの催促も任せられます。' ),
		'report'     => array( 'レポート', '月別の売上・粗利・顧客別の取引実績・仕入と支払・資金繰り予定などを、表とグラフで見られます。' ),
		'newsletter' => array( '顧客・協力会社への一斉メール', 'お知らせやご案内を、顧客・協力会社のメールアドレス宛てにまとめて送れます（宛名の差し込み、カテゴリーでの絞り込みつき）。' ),
		'voice'      => array( '音声操作', '「へいカンタンプロ、今月の売上は」のように、話しかけて売上や予定を調べられます（KoeType 連携）。' ),
		'webfax'     => array( 'Web-FAX 送信', '受注書の画面から、見積書・請求書の PDF を Web-FAX サービス経由で FAX 送信できます。' ),
		'design'     => array( 'デザイン設定（背景画像）', '画面全体の背景に、好きな画像を敷けます。ダークモードの切り替えは無料版でも使えます。' ),
	);
}

/** 有料版でできること（ライセンス画面の一覧に使う。上の機能 + 宣伝を消す） */
function ktp_dt_license_benefits() {
	$list = array();
	foreach ( ktp_dt_license_features() as $f ) {
		$list[] = $f[0];
	}
	$list[] = '書類（印刷・PDF）の下端と、メール署名の宣伝の 1 行を消す';
	return $list;
}

function ktp_dt_license_page_url() {
	return admin_url( 'admin.php?page=' . KTP_DT_LICENSE_PAGE );
}

function ktp_dt_license_can_manage() {
	return ! defined( 'KTP_DESKTOP_LAN_REQUEST' ) && is_user_logged_in() && current_user_can( 'manage_options' );
}

/**
 * 制限した機能の案内画面（共通の部品）。
 * 色は明るい色だけで書く（ダークモードは画面全体の反転で暗くなる、ktp_desktop_dark_css）
 */
function ktp_dt_locked_screen_html( $feature, $back_url = '' ) {
	$features = ktp_dt_license_features();
	$f        = $features[ $feature ] ?? array( '有料版の機能', '' );
	$state    = ktp_dt_license_state();
	$why      = 'free' === $state ? '無料版です（30 日の試用期間は終わりました）。' : '';
	ob_start();
	?>
<div class="ktp-dt-locked" style="max-width:640px;margin:32px auto;padding:28px 32px;background:#fff;border:1px solid #d1d5db;border-radius:10px;box-shadow:0 1px 6px rgba(0,0,0,.06);font-size:14px;line-height:1.8;color:#1f2937;">
	<p style="margin:0 0 4px;color:#b45309;font-weight:700;font-size:13px;">有料版の機能</p>
	<h2 style="margin:0 0 12px;font-size:20px;line-height:1.4;">この機能は有料版で使えます</h2>
	<div style="background:#f3f4f6;border-radius:8px;padding:12px 16px;margin:0 0 14px;">
		<p style="margin:0 0 4px;font-weight:700;"><?php echo esc_html( $f[0] ); ?></p>
		<p style="margin:0;color:#4b5563;font-size:13px;"><?php echo esc_html( $f[1] ); ?></p>
	</div>
	<?php if ( '' !== $why ) : ?>
		<p style="margin:0 0 10px;color:#4b5563;font-size:13px;"><?php echo esc_html( $why ); ?>顧客・サービス・受注・書類の作成と印刷・PDF・バックアップなどは、これまでどおり無料でお使いいただけます。データも消えません。</p>
	<?php endif; ?>
	<p style="margin:14px 0 0;">
		<?php if ( ktp_dt_license_can_manage() ) : ?>
			<a href="<?php echo esc_url( ktp_dt_license_page_url() ); ?>" style="display:inline-block;padding:8px 18px;border:1px solid #2563eb;color:#2563eb;border-radius:6px;text-decoration:none;font-weight:600;margin-right:8px;">ライセンスを入力</a>
		<?php endif; ?>
		<a href="<?php echo esc_url( KTP_DT_BUY_URL ); ?>" target="_blank" rel="noopener" style="display:inline-block;padding:8px 18px;background:#2563eb;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">購入する</a>
	</p>
	<?php if ( '' !== $back_url ) : ?>
		<p style="margin:16px 0 0;font-size:13px;"><a href="<?php echo esc_url( $back_url ); ?>">← 戻る</a></p>
	<?php endif; ?>
</div>
	<?php
	return (string) ob_get_clean();
}

// ---------------------------------------------------------------------------
// 設定のライセンス画面（KantanProEX 設定 → ライセンス）
// ---------------------------------------------------------------------------

add_action(
	'admin_menu',
	function () {
		if ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) ) {
			return;
		}
		add_submenu_page( 'ktp-settings', 'ライセンス', 'ライセンス', 'manage_options', KTP_DT_LICENSE_PAGE, 'ktp_dt_license_render_page' );
	},
	PHP_INT_MAX
);

add_action(
	'admin_post_ktp_dt_license_save',
	function () {
		if ( ! ktp_dt_license_can_manage() ) {
			wp_die( '親機の画面でオーナーだけが操作できます。' );
		}
		check_admin_referer( KTP_DT_LICENSE_NONCE );
		$key  = isset( $_POST['license_key'] ) ? preg_replace( '/\s+/', '', (string) wp_unslash( $_POST['license_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$info = ktp_dt_license_verify_key( $key );
		if ( ! $info ) {
			wp_safe_redirect( add_query_arg( 'ktp_dt_msg', 'bad', ktp_dt_license_page_url() ) );
			exit;
		}
		$rec        = ktp_dt_license_record();
		$rec['key'] = $key;
		ktp_dt_license_save_record( $rec );
		wp_safe_redirect( add_query_arg( 'ktp_dt_msg', 'ok', ktp_dt_license_page_url() ) );
		exit;
	}
);

function ktp_dt_license_render_page() {
	if ( ! ktp_dt_license_can_manage() ) {
		return;
	}
	$state = ktp_dt_license_state();
	$msg   = isset( $_GET['ktp_dt_msg'] ) ? sanitize_key( wp_unslash( $_GET['ktp_dt_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$info  = ktp_dt_license_key_info();
	$days  = ktp_dt_trial_days_left();
	$labels = array(
		'paid'   => array( '有料版', '#166534', '#f0fdf4', '#86efac' ),
		'legacy' => array( '有料版（ご購入済み）', '#166534', '#f0fdf4', '#86efac' ),
		'trial'  => array( '試用期間中（全機能が使えます）', '#1e40af', '#eff6ff', '#93c5fd' ),
		'free'   => array( '無料版', '#92400e', '#fffbeb', '#fcd34d' ),
	);
	$l = $labels[ $state ];
	?>
<div class="wrap">
<div style="max-width:760px;font-size:14px;line-height:1.8;">
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">← アプリに戻る</a></p>
	<h1 style="margin:6px 0 12px;">ライセンス</h1>
	<?php if ( 'ok' === $msg ) : ?>
		<div class="notice notice-success"><p>ライセンスキーを確認しました。有料版として使えます。ありがとうございます。</p></div>
	<?php elseif ( 'bad' === $msg ) : ?>
		<div class="notice notice-error"><p>ライセンスキーを確認できませんでした。メールに書かれたキーを、改行や空白を含めずそのまま貼り付けてください。</p></div>
	<?php endif; ?>

	<div style="border:1px solid <?php echo esc_attr( $l[3] ); ?>;background:<?php echo esc_attr( $l[2] ); ?>;color:<?php echo esc_attr( $l[1] ); ?>;padding:12px 16px;border-radius:6px;margin:12px 0;">
		<p style="margin:0;font-weight:700;">現在: <?php echo esc_html( $l[0] ); ?></p>
		<?php if ( 'trial' === $state ) : ?>
			<p style="margin:4px 0 0;">試用期間はあと <strong><?php echo (int) $days; ?> 日</strong>です。終わると無料版になります（データは消えません）。</p>
		<?php elseif ( 'free' === $state ) : ?>
			<p style="margin:4px 0 0;">試用期間（<?php echo (int) KTP_DT_TRIAL_DAYS; ?> 日）は終わりました。一人で毎日使う機能はこれまでどおり無料です。有料版の機能を使うには、ライセンスキーを入れてください。</p>
		<?php elseif ( 'legacy' === $state ) : ?>
			<p style="margin:4px 0 0;">ご購入ありがとうございます。このアプリは有料版として使えます。別のパソコンに入れるときは、購入時のメールのライセンスキーが必要です。</p>
		<?php elseif ( $info ) : ?>
			<p style="margin:4px 0 0;">ご購入ありがとうございます。登録メール: <?php echo esc_html( $info['email'] ); ?><?php echo '' !== $info['id'] ? '（' . esc_html( $info['id'] ) . '）' : ''; ?></p>
		<?php endif; ?>
	</div>

	<?php if ( 'paid' !== $state ) : ?>
	<h2 style="margin:20px 0 8px;font-size:16px;">ライセンスキーを入れる</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="ktp_dt_license_save">
		<?php wp_nonce_field( KTP_DT_LICENSE_NONCE ); ?>
		<p><textarea name="license_key" rows="4" style="width:100%;font-family:monospace;font-size:12px;" placeholder="購入後にメールでお送りするライセンスキーを貼り付けます" required></textarea></p>
		<p><button type="submit" class="button button-primary">キーを確認して有料版にする</button></p>
	</form>
	<?php endif; ?>

	<h2 style="margin:24px 0 8px;font-size:16px;">有料版でできること</h2>
	<ul style="list-style:disc;margin-left:1.5em;">
		<?php foreach ( ktp_dt_license_benefits() as $b ) : ?>
			<li><?php echo esc_html( $b ); ?></li>
		<?php endforeach; ?>
	</ul>
	<p style="color:#4b5563;font-size:13px;">無料版でも、顧客・サービス・受注・仕事リスト・書類の作成と印刷・PDF・請求書メールの送信・バックアップと復元・CSV 書き出し・取り込み・ダークモードはずっと使えます。</p>
	<?php if ( 'paid' !== $state && 'legacy' !== $state ) : ?>
	<p><a href="<?php echo esc_url( KTP_DT_BUY_URL ); ?>" target="_blank" rel="noopener" class="button button-primary button-hero">購入する</a></p>
	<?php endif; ?>
</div>
</div>
	<?php
}

// ---------------------------------------------------------------------------
// 画面の小さな表示（試用期間・無料版）
// ---------------------------------------------------------------------------

add_action(
	'wp_footer',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$state = ktp_dt_license_state();
		if ( 'trial' !== $state && 'free' !== $state ) {
			return;
		}
		$text = 'trial' === $state ? '試用期間 あと' . ktp_dt_trial_days_left() . '日' : '無料版';
		$url  = ktp_dt_license_can_manage() ? ktp_dt_license_page_url() : '';
		?>
<script id="ktp-desktop-license-badge">
(function () {
	var text = <?php echo wp_json_encode( $text ); ?>, url = <?php echo wp_json_encode( $url ); ?>, free = <?php echo wp_json_encode( 'free' === $state ); ?>;
	var nav = document.querySelector('.ktp_header .navigation-links');
	if (!nav || nav.querySelector('.ktp-dt-license-badge')) { return; }
	var a = document.createElement(url ? 'a' : 'span');
	if (url) { a.href = url; a.title = 'ライセンス画面を開く'; }
	a.className = 'ktp-dt-license-badge';
	a.textContent = text;
	a.style.cssText = 'display:inline-flex;align-items:center;font-size:11px;line-height:1;padding:4px 8px;border-radius:10px;text-decoration:none;white-space:nowrap;margin-right:6px;'
		+ (free ? 'background:#fffbeb;color:#92400e;border:1px solid #fcd34d;' : 'background:#eff6ff;color:#1e40af;border:1px solid #93c5fd;');
	nav.insertBefore(a, nav.firstChild);
})();
</script>
		<?php
	},
	98
);

// ---------------------------------------------------------------------------
// 無料版での制限
// ---------------------------------------------------------------------------

/** LAN 共有: 試用が切れたら、LAN からのログイン・アクセスを止める（設定値は消さない。親機はそのまま使える） */
add_action(
	'init',
	function () {
		if ( defined( 'KTP_DESKTOP_LAN_REQUEST' ) && ktp_dt_feature_locked() ) {
			wp_die( 'LAN 共有は有料版の機能です。親機のオーナーが、ライセンスキーを入れると使えるようになります。', 'KantanProEX', array( 'response' => 403 ) );
		}
	},
	0
);

/** 契約・レポートなど、本体の ajax は無料版では受け付けない（本体は変えず、入口で止める） */
add_action(
	'admin_init',
	function () {
		if ( ! wp_doing_ajax() || ! ktp_dt_feature_locked() ) {
			return;
		}
		$action  = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$blocked = array(
			// 契約・定期請求
			'ktp_save_contract', 'ktp_get_contract', 'ktp_delete_contract', 'ktp_create_contract_setup_checkout',
			'ktp_generate_contract_order', 'ktp_generate_all_contract_orders', 'ktp_send_contract_reminder', 'ktp_send_pending_contract_reminders',
			'ktp_get_order_contract_draft', 'ktp_convert_order_to_contract',
			// レポート
			'ktp_get_report_data', 'ktpwp_get_report_data',
		);
		// 複製（受注書・サービス）
		if ( in_array( $action, array( 'ktp_duplicate_order', 'ktp_duplicate_service' ), true ) ) {
			wp_send_json_error( array( 'message' => '複製は有料版の機能です。' ), 403 );
		}
		if ( in_array( $action, $blocked, true ) ) {
			wp_send_json_error( array( 'message' => 'この機能は有料版で使えます（KantanProEX 設定 → ライセンス）。' ), 403 );
		}
	},
	0
);

/**
 * レポートタブへの書き込み（POST）と、複製（query_post=duplicate / duplication）は無料版では処理しない。
 * 複製を断ったときは目印を立て、画面側で案内を出す（データは何も変えない）
 */
add_action(
	'init',
	function () {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! ktp_dt_feature_locked() ) {
			return;
		}
		$tab  = isset( $_POST['tab_name'] ) && is_string( $_POST['tab_name'] ) ? sanitize_key( wp_unslash( $_POST['tab_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$qp   = isset( $_POST['query_post'] ) && is_string( $_POST['query_post'] ) ? sanitize_key( wp_unslash( $_POST['query_post'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( in_array( $qp, array( 'duplicate', 'duplication' ), true ) ) {
			// 本体は POST で空のまま来るとナンス検査で止まるので、何もしない GET として表示させる
			$GLOBALS['ktp_dt_duplicate_blocked'] = true;
			$_POST                               = array();
			$_REQUEST                            = array_diff_key( $_REQUEST, array( 'query_post' => 1, 'data_id' => 1 ) );
			$_SERVER['REQUEST_METHOD']           = 'GET';
			return;
		}
		if ( 'report' === $tab ) {
			$_POST = array();
		}
	},
	0
);

/** 契約の毎日の自動処理（請求書メール・催促メール）は無料版では動かさない（予約は残す） */
add_action(
	'init',
	function () {
		if ( ktp_dt_feature_locked() ) {
			remove_all_actions( 'ktpwp_contract_invoice_mail_daily' );
			remove_all_actions( 'ktpwp_contract_reminder_daily' );
		}
	},
	999
);

/**
 * タブの中身を案内画面に差し替える（レポート・定期請求リスト）。
 * 本体のタブの作りは、各パネルが <div class="tab_content" id="◯◯_content"> で並ぶ。本体は変えず、出来上がった HTML の該当パネルだけ差し替える
 */
function ktp_dt_replace_tab_panel( $output, $panel, $next, $html ) {
	$pos = strpos( $output, 'id="' . $panel . '_content"' );
	if ( false === $pos ) {
		return null;
	}
	$open_end = strpos( $output, '>', $pos );
	if ( false === $open_end ) {
		return null;
	}
	$open_end++;
	if ( '' !== $next ) {
		$e = strpos( $output, '<div class="tab_content" id="' . $next . '_content"', $open_end );
		if ( false === $e ) {
			return null;
		}
		$end = strrpos( substr( $output, 0, $e ), '</div>' );
	} else {
		$e = strpos( $output, '<div class="ktp-footer">', $open_end );
		if ( false === $e ) {
			return null;
		}
		$tabs_close = strrpos( substr( $output, 0, $e ), '</div>' );
		$end        = false === $tabs_close ? false : strrpos( substr( $output, 0, $tabs_close ), '</div>' );
	}
	if ( false === $end || $end < $open_end ) {
		return null;
	}
	return substr( $output, 0, $open_end ) . $html . substr( $output, $end );
}

add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) || ! ktp_dt_feature_locked() ) {
			return $output;
		}
		$tab = isset( $_GET['tab_name'] ) ? sanitize_key( wp_unslash( $_GET['tab_name'] ) ) : ( isset( $_POST['tab_name'] ) ? sanitize_key( wp_unslash( $_POST['tab_name'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$back = add_query_arg( 'tab_name', 'list', home_url( '/' ) );
		if ( 'report' === $tab ) {
			$feature = 'report';
			$panel   = array( 'report', '' );
		} elseif ( 'list' === ( '' === $tab ? 'list' : $tab ) && isset( $_GET['recurring_billing'] ) && '1' === (string) $_GET['recurring_billing'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$feature = 'contract';
			$panel   = array( 'list', 'order' );
		} elseif ( ! empty( $GLOBALS['ktp_dt_duplicate_blocked'] ) ) {
			// 複製の POST を断った。元の画面の上に案内を出す
			return ktp_dt_locked_screen_html( 'duplicate', '' ) . $output;
		} else {
			return $output;
		}
		$html = ktp_dt_locked_screen_html( $feature, $back );
		$new  = ktp_dt_replace_tab_panel( $output, $panel[0], $panel[1], $html );
		// 差し替えの場所が見つからなかったら、安全側で画面ごと案内にする
		return null === $new ? $html : $new;
	},
	13,
	2
);

// ---------------------------------------------------------------------------
// 書類の下端の宣伝（印刷・PDF の元になる書類の HTML に 1 行足すだけ。印刷・PDF の経路は変えない）
// ---------------------------------------------------------------------------

/** 受注書・見積書・納品書・請求書の HTML（ajax ktp_get_order_preview の応答）の下端に宣伝の 1 行を足す */
function ktp_dt_ad_html() {
	return '<div class="ktp-dt-ad" style="text-align:center;font-size:10px;color:#888;margin-top:10px;">' . esc_html( KTP_DT_AD_TEXT ) . '</div>';
}

function ktp_dt_ad_insert_into_document( $html ) {
	if ( ! is_string( $html ) || false !== strpos( $html, 'class="ktp-dt-ad"' ) ) {
		return $html;
	}
	$tail = '</div></body></html>';
	$pos  = strrpos( $html, $tail );
	if ( false === $pos ) {
		return $html;
	}
	return substr( $html, 0, $pos ) . ktp_dt_ad_html() . substr( $html, $pos );
}

add_action(
	'admin_init',
	function () {
		if ( ! wp_doing_ajax() ) {
			return;
		}
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'ktp_get_order_preview' !== $action || ! ktp_dt_show_ad() ) {
			return;
		}
		ob_start(
			function ( $buffer ) {
				$json = json_decode( $buffer, true );
				if ( ! is_array( $json ) || empty( $json['success'] ) || empty( $json['data']['preview_html'] ) ) {
					return $buffer;
				}
				$json['data']['preview_html'] = ktp_dt_ad_insert_into_document( $json['data']['preview_html'] );
				$out                          = wp_json_encode( $json );
				return false === $out ? $buffer : $out;
			}
		);
	},
	0
);

/**
 * 複製ボタン（受注書・サービス）は、無料版では押すと案内を出す。
 * 本体のクリック処理より先に（capture 段階で）止める。行追加で前の行をコピーする動きは複製機能ではないので対象外
 */
add_action(
	'wp_footer',
	function () {
		if ( ! is_user_logged_in() || ! ktp_dt_feature_locked() ) {
			return;
		}
		$html = ktp_dt_locked_screen_html( 'duplicate', '' );
		?>
<script id="ktp-desktop-duplicate-lock">
(function () {
	var html = <?php echo wp_json_encode( $html ); ?>;
	function show() {
		var o = document.getElementById('ktp-dt-dup-overlay');
		if (o) { o.style.display = 'flex'; return; }
		o = document.createElement('div');
		o.id = 'ktp-dt-dup-overlay';
		o.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;overflow:auto;';
		o.innerHTML = html;
		var box = o.firstElementChild;
		if (box) { box.style.margin = '0 auto'; box.style.position = 'relative'; }
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = '閉じる';
		close.style.cssText = 'margin-left:8px;padding:8px 18px;border:1px solid #9ca3af;background:#fff;color:#374151;border-radius:6px;font-weight:600;cursor:pointer;';
		var p = box ? box.querySelector('a[target=_blank]').parentNode : null;
		if (p) { p.appendChild(close); }
		close.addEventListener('click', function () { o.style.display = 'none'; });
		o.addEventListener('click', function (e) { if (e.target === o) { o.style.display = 'none'; } });
		document.body.appendChild(o);
	}
	window.addEventListener('click', function (e) {
		var t = e.target;
		if (!t || !t.closest) { return; }
		var b = t.closest('#orderDuplicateButton, .ktp-service-duplicate-btn');
		if (!b || b.disabled) { return; }
		e.preventDefault();
		e.stopImmediatePropagation();
		show();
	}, true);
})();
</script>
		<?php
	},
	99
);
