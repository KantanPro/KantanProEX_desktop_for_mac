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
 * - ログインできるのは、親機のオーナーが「LAN で使う」を許可したスタッフだけ。管理者は常に不可
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

/** LAN で使ってよいユーザーか（管理者は不可。許可の印があるスタッフだけ） */
function ktp_dt_lan_user_ok( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->exists() ) {
		return false;
	}
	if ( in_array( 'administrator', (array) $user->roles, true ) || user_can( $user, 'manage_options' ) ) {
		return false;
	}
	return '1' === (string) get_user_meta( $user->ID, KTP_DT_LAN_META, true );
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

/** 一覧に出すスタッフ（管理者は出さない。LAN では使えない） */
function ktp_dt_lan_staff_users() {
	$out = array();
	foreach ( get_users( array( 'orderby' => 'ID' ) ) as $u ) {
		if ( ! in_array( 'administrator', (array) $u->roles, true ) ) {
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

/** 取り込みで作られたスタッフはランダムなパスワード。LAN 用に決めたかどうか */
function ktp_dt_lan_has_lan_password( $user ) {
	return '1' === (string) get_user_meta( $user->ID, 'ktp_dt_lan_pass_set', true );
}

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
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{3,40}$/', $login ) ) {
			wp_send_json_error( array( 'message' => 'ログイン名は半角の英数字（_ . - 可）で 3〜40 文字にしてください。' ) );
		}
		if ( username_exists( $login ) ) {
			wp_send_json_error( array( 'message' => 'そのログイン名はすでに使われています。' ) );
		}
		if ( ! ktp_dt_lan_check_password( $pass ) ) {
			wp_send_json_error( array( 'message' => 'パスワードは ' . KTP_DT_LAN_MIN_PASS . ' 文字以上にしてください。' ) );
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => $pass,
				'user_email'   => $login . '@invalid.example',
				'display_name' => '' !== $name ? $name : $login,
				'role'         => 'editor',
			)
		);
		if ( is_wp_error( $id ) ) {
			wp_send_json_error( array( 'message' => $id->get_error_message() ) );
		}
		update_user_meta( $id, KTP_DT_LAN_META, '1' );
		update_user_meta( $id, 'ktp_dt_lan_pass_set', '1' );
		wp_send_json_success( array( 'message' => 'スタッフを追加しました。' ) );
	}
);

add_filter(
	'ktp_desktop_custom_page',
	function ( $page ) {
		if ( null !== $page || ! isset( $_GET['ktp_dt_page'] ) || 'lan' !== $_GET['ktp_dt_page'] || ! ktp_dt_lan_can_manage() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $page;
		}
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

	<h3 style="margin:18px 0 6px;">はじめての接続</h3>
	<ol style="margin:0 0 12px 20px;">
		<li>他の PC のブラウザで、上のアドレスを開きます。</li>
		<li>「安全ではありません」などの警告が出ます（この PC が自分で作った証明書のためです）。「詳細」→「このまま進む（アクセスする）」を選びます。最初の 1 回だけです。</li>
		<li>スタッフのログイン名とパスワードでログインします。</li>
	</ol>
	<p style="color:#6b7280;font-size:12px;margin-top:-4px;">警告で表示される指紋が、上の「証明書の指紋」と同じなら、この PC につながっています。</p>

	<h3 style="margin:18px 0 6px;">LAN で使うスタッフ</h3>
	<p style="color:#6b7280;font-size:13px;">許可したスタッフだけが、他の PC からログインできます。オーナー（管理者）は、この PC の画面だけで使います。パスワードは <?php echo (int) KTP_DT_LAN_MIN_PASS; ?> 文字以上。パスワードの欄を空にして保存すると、いまのパスワードのままです。</p>
	<table style="width:100%;border-collapse:collapse;" id="lan-staff">
		<thead><tr style="text-align:left;border-bottom:1px solid #d1d5db;"><th style="padding:6px;">名前</th><th style="padding:6px;">ログイン名</th><th style="padding:6px;">LAN で使う</th><th style="padding:6px;">パスワード（変えるとき）</th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $staff as $u ) : ?>
			<tr data-user="<?php echo (int) $u->ID; ?>" style="border-bottom:1px solid #eee;">
				<td style="padding:6px;"><?php echo esc_html( $u->display_name ); ?></td>
				<td style="padding:6px;"><code><?php echo esc_html( $u->user_login ); ?></code></td>
				<td style="padding:6px;"><input type="checkbox" class="lan-allowed" <?php checked( ktp_dt_lan_user_ok( $u ) ); ?>></td>
				<td style="padding:6px;"><input type="password" class="lan-pass" autocomplete="new-password" placeholder="<?php echo ktp_dt_lan_has_lan_password( $u ) ? '設定済み' : '未設定'; ?>" style="padding:4px;width:100%;"></td>
				<td style="padding:6px;"><button type="button" class="lan-save" style="padding:3px 12px;">保存</button></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $staff ) : ?>
			<tr><td colspan="5" style="padding:8px;color:#6b7280;">スタッフはまだいません。下から追加してください。</td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<h3 style="margin:18px 0 6px;">スタッフを追加</h3>
	<p>
		<input type="text" id="lan-new-name" placeholder="表示名（例: 山田）" style="padding:5px;width:30%;">
		<input type="text" id="lan-new-login" placeholder="ログイン名（半角英数字）" autocapitalize="off" style="padding:5px;width:30%;">
		<input type="password" id="lan-new-pass" placeholder="パスワード" autocomplete="new-password" style="padding:5px;width:30%;">
	</p>
	<p><button type="button" id="lan-add" style="background:#2563eb;color:#fff;border:0;padding:6px 18px;border-radius:4px;">追加する</button></p>
	<p id="lan-msg" role="status" style="font-weight:600;"></p>

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
<script>
(function(){
	var w=document.getElementById('ktp-dt-lan'),msg=document.getElementById('lan-msg');
	function post(action,data,done){
		var d=new URLSearchParams(Object.assign({action:action,nonce:w.dataset.nonce},data));
		msg.style.color='';msg.textContent='保存しています…';
		fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d.toString()}).then(function(r){return r.json();}).then(function(j){
			msg.style.color=j.success?'#166534':'#b91c1c';
			msg.textContent=(j.data&&j.data.message)||(j.success?'保存しました。':'保存できませんでした。');
			if(j.success){done&&done();}
		}).catch(function(){msg.style.color='#b91c1c';msg.textContent='保存できませんでした。';});
	}
	w.querySelectorAll('#lan-staff .lan-save').forEach(function(b){b.onclick=function(){
		var tr=b.closest('tr');
		post('ktp_dt_lan_user_save',{user_id:tr.dataset.user,allowed:tr.querySelector('.lan-allowed').checked?'1':'0',password:tr.querySelector('.lan-pass').value},function(){
			var p=tr.querySelector('.lan-pass');if(p.value){p.placeholder='設定済み';}p.value='';
		});
	};});
	document.getElementById('lan-add').onclick=function(){
		post('ktp_dt_lan_user_add',{name:document.getElementById('lan-new-name').value,login:document.getElementById('lan-new-login').value,password:document.getElementById('lan-new-pass').value},function(){setTimeout(function(){location.reload();},700);});
	};
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
);
