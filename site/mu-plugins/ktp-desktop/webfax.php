<?php
/**
 * Web-FAX 送信（KantanBiz の WebFaxService / EmailToFaxEnvelope 相当）。
 *
 * 受注書の書類（進捗に応じた見積書・受注書・請求書など）を、画面上で PDF にして、
 * 設定済みの SMTP から Web-FAX ゲートウェイ（eFax / jFax / MOVFAX）へメールの添付で送る。
 * PDF は画面側（html2canvas + jsPDF）で作り、サーバーは宛先の組み立てとメール送信だけを行う。
 * 設定画面は独自画面（?ktp_dt_page=webfax）。事前にメール（SMTP）の設定が要る。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_FAX_OPTION = 'ktp_dt_webfax';
const KTP_DT_FAX_NONCE  = 'ktp_dt_webfax';
const KTP_DT_FAX_MAX_PDF_BYTES = 10 * 1024 * 1024;

function ktp_dt_fax_providers() {
	return array(
		'efax'   => array( 'label' => 'eFax', 'url' => 'https://www.efax.co.jp/' ),
		'jfax'   => array( 'label' => 'jFax', 'url' => 'https://www.jfax.com/' ),
		'movfax' => array( 'label' => 'MOVFAX', 'url' => 'https://movfax.jp/' ),
	);
}

function ktp_dt_fax_can() {
	return is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'ktpwp_access' ) );
}

function ktp_dt_fax_settings() {
	$s = get_option( KTP_DT_FAX_OPTION, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'enabled'         => ! empty( $s['enabled'] ),
		'provider'        => isset( $s['provider'], ktp_dt_fax_providers()[ $s['provider'] ] ) ? $s['provider'] : 'efax',
		'movfax_user_id'  => (string) ( $s['movfax_user_id'] ?? '' ),
		'movfax_password' => (string) ( $s['movfax_password'] ?? '' ),
	);
}

function ktp_dt_fax_smtp_ready() {
	$smtp = get_option( 'ktp_smtp_settings', array() );
	return is_array( $smtp ) && ! empty( $smtp['smtp_host'] ) && ! empty( $smtp['email_address'] );
}

function ktp_dt_fax_configured() {
	$s = ktp_dt_fax_settings();
	if ( ! $s['enabled'] || ! ktp_dt_fax_smtp_ready() ) {
		return false;
	}
	return 'movfax' !== $s['provider'] || ( '' !== $s['movfax_user_id'] && '' !== $s['movfax_password'] );
}

/** 半角数字だけ（国内表記）。81 始まりは 0 始まりに直す */
function ktp_dt_fax_digits( $value ) {
	$d = preg_replace( '/\D/u', '', (string) $value );
	if ( null === $d || '' === $d ) {
		return '';
	}
	if ( 0 === strpos( $d, '81' ) && strlen( $d ) >= 11 ) {
		$d = '0' . substr( $d, 2 );
	}
	return $d;
}

function ktp_dt_fax_valid_number( $value ) {
	$n = strlen( ktp_dt_fax_digits( $value ) );
	return $n >= 10 && $n <= 15;
}

function ktp_dt_fax_international( $digits ) {
	$d = ktp_dt_fax_digits( $digits );
	if ( '' === $d ) {
		return '';
	}
	if ( 0 === strpos( $d, '0' ) ) {
		return '81' . substr( $d, 1 );
	}
	return 0 === strpos( $d, '81' ) ? $d : '81' . $d;
}

/**
 * ゲートウェイ宛先・件名・本文を組み立てる。
 *
 * @return array{to:string,subject:string,body:string}|WP_Error
 */
function ktp_dt_fax_envelope( $fax_number, $recipient_name = '' ) {
	if ( ! ktp_dt_fax_configured() ) {
		return new WP_Error( 'not_configured', 'Web-FAX の設定が完了していません。' );
	}
	$digits = ktp_dt_fax_digits( $fax_number );
	if ( ! ktp_dt_fax_valid_number( $digits ) ) {
		return new WP_Error( 'bad_number', 'FAX番号が正しくありません。' );
	}
	$s = ktp_dt_fax_settings();
	if ( 'movfax' === $s['provider'] ) {
		$body = '[PASSWD]' . $s['movfax_password'] . "\n[FAX]" . $digits;
		$name = trim( (string) $recipient_name );
		if ( '' !== $name ) {
			$body .= "\n[RNAME]" . $name;
		}
		return array( 'to' => 'movfax@mail01.lcloud.jp', 'subject' => $s['movfax_user_id'], 'body' => $body );
	}
	$domain = 'jfax' === $s['provider'] ? 'jfaxsend.com' : 'efaxsend.com';
	return array( 'to' => ktp_dt_fax_international( $digits ) . '@' . $domain, 'subject' => '', 'body' => '{nocoverpage}' );
}

add_action(
	'wp_ajax_ktp_dt_fax_send',
	function () {
		if ( ! ktp_dt_fax_can() ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		check_ajax_referer( KTP_DT_FAX_NONCE, 'nonce' );
		$number = isset( $_POST['fax_number'] ) ? sanitize_text_field( wp_unslash( $_POST['fax_number'] ) ) : '';
		$name   = isset( $_POST['recipient_name'] ) ? sanitize_text_field( wp_unslash( $_POST['recipient_name'] ) ) : '';
		$fname  = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : 'document.pdf';
		$b64    = isset( $_POST['pdf'] ) ? (string) wp_unslash( $_POST['pdf'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$env    = ktp_dt_fax_envelope( $number, $name );
		if ( is_wp_error( $env ) ) {
			wp_send_json_error( array( 'message' => $env->get_error_message() ), 422 );
		}
		$pdf = base64_decode( $b64, true );
		if ( false === $pdf || '' === $pdf || 0 !== strpos( $pdf, '%PDF' ) ) {
			wp_send_json_error( array( 'message' => 'PDF を作れませんでした。' ), 422 );
		}
		if ( strlen( $pdf ) > KTP_DT_FAX_MAX_PDF_BYTES ) {
			wp_send_json_error( array( 'message' => '書類が大きすぎます（10MBまで）。' ), 422 );
		}
		if ( '.pdf' !== strtolower( substr( $fname, -4 ) ) ) {
			$fname .= '.pdf';
		}
		$dir = trailingslashit( get_temp_dir() ) . 'ktp-dt-fax-' . wp_generate_password( 10, false );
		wp_mkdir_p( $dir );
		$file = $dir . '/' . $fname;
		file_put_contents( $file, $pdf );
		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$ok = wp_mail( $env['to'], $env['subject'], $env['body'], array( 'Content-Type: text/plain; charset=UTF-8' ), array( $file ) );
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'FAX の送信メールを送れませんでした。メール（SMTP）の設定を確認してください。' ), 500 );
		}
		wp_send_json_success( array( 'message' => 'FAX を送信しました（' . $number . '）。ゲートウェイから結果のメールが届く場合があります。' ) );
	}
);

add_action(
	'wp_ajax_ktp_dt_fax_settings',
	function () {
		if ( ! ktp_dt_fax_can() ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		check_ajax_referer( KTP_DT_FAX_NONCE, 'nonce' );
		$old      = ktp_dt_fax_settings();
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : 'efax';
		if ( ! isset( ktp_dt_fax_providers()[ $provider ] ) ) {
			wp_send_json_error( array( 'message' => 'サービスが正しくありません。' ), 422 );
		}
		$password = isset( $_POST['movfax_password'] ) ? trim( (string) wp_unslash( $_POST['movfax_password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		update_option(
			KTP_DT_FAX_OPTION,
			array(
				'enabled'         => ! empty( $_POST['enabled'] ) && '0' !== $_POST['enabled'],
				'provider'        => $provider,
				'movfax_user_id'  => isset( $_POST['movfax_user_id'] ) ? sanitize_text_field( wp_unslash( $_POST['movfax_user_id'] ) ) : '',
				// 空欄のままなら保存済みのパスワードを残す
				'movfax_password' => '' !== $password ? $password : $old['movfax_password'],
			),
			false
		);
		wp_send_json_success( array( 'message' => '保存しました。', 'configured' => ktp_dt_fax_configured() ) );
	}
);

/** 設定の独自画面（?ktp_dt_page=webfax） */
add_filter(
	'ktp_desktop_custom_page',
	function ( $page ) {
		if ( null !== $page || ! isset( $_GET['ktp_dt_page'] ) || 'webfax' !== $_GET['ktp_dt_page'] || ! ktp_dt_fax_can() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $page;
		}
		$s    = ktp_dt_fax_settings();
		$back = home_url( '/' );
		ob_start();
		?>
<div id="ktp-dt-faxset" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( KTP_DT_FAX_NONCE ) ); ?>" style="max-width:680px;margin:0 auto;font-size:14px;line-height:1.7;">
	<p><a href="<?php echo esc_url( $back ); ?>">← アプリに戻る</a></p>
	<h2 style="margin:6px 0 12px;">Web-FAX 設定</h2>
	<p style="color:#6b7280;font-size:13px;">契約している Web-FAX サービスの「メールで FAX を送る」機能を使います。受注書の画面の「FAX送信」から、書類の PDF を設定済みの SMTP でゲートウェイへ送ります。</p>
	<?php if ( ! ktp_dt_fax_smtp_ready() ) : ?>
		<p style="color:#b91c1c;font-weight:600;">先に「設定」→ メール（SMTP）の設定をしてください。</p>
	<?php endif; ?>
	<p><label><input type="checkbox" id="fx-enabled" <?php checked( $s['enabled'] ); ?>> Web-FAX を使う</label></p>
	<p>サービス：
	<?php foreach ( ktp_dt_fax_providers() as $key => $prov ) : ?>
		<label style="margin-right:12px;"><input type="radio" name="provider" value="<?php echo esc_attr( $key ); ?>" <?php checked( $s['provider'], $key ); ?>> <?php echo esc_html( $prov['label'] ); ?></label>
	<?php endforeach; ?>
	</p>
	<div id="fx-mov" style="<?php echo 'movfax' === $s['provider'] ? '' : 'display:none;'; ?>">
		<p><label>MOVFAX のユーザーID<br><input type="text" id="fx-uid" value="<?php echo esc_attr( $s['movfax_user_id'] ); ?>" style="padding:5px;width:100%;"></label></p>
		<p><label>MOVFAX のパスワード（<?php echo '' !== $s['movfax_password'] ? '保存済み。変えるときだけ入力' : '未設定'; ?>）<br><input type="password" id="fx-pw" autocomplete="new-password" style="padding:5px;width:100%;"></label></p>
		<p style="color:#6b7280;font-size:12px;">パスワードは、FAX を送るメールの本文に入れてゲートウェイへ送られます（サービスの仕様です）。このアプリの設定として Mac 内に保存します。</p>
	</div>
	<p><button type="button" id="fx-save" style="background:#2563eb;color:#fff;border:0;padding:6px 18px;border-radius:4px;">保存</button></p>
	<p id="fx-msg" role="status" style="font-weight:600;"></p>
</div>
<script>
(function(){
	var w=document.getElementById('ktp-dt-faxset'),msg=document.getElementById('fx-msg');
	w.querySelectorAll('input[name=provider]').forEach(function(r){r.addEventListener('change',function(){document.getElementById('fx-mov').style.display=r.value==='movfax'&&r.checked?'':'none';});});
	document.getElementById('fx-save').onclick=function(){
		var d=new URLSearchParams({action:'ktp_dt_fax_settings',nonce:w.dataset.nonce,enabled:document.getElementById('fx-enabled').checked?'1':'0',provider:w.querySelector('input[name=provider]:checked').value,movfax_user_id:document.getElementById('fx-uid').value,movfax_password:document.getElementById('fx-pw').value});
		msg.style.color='';msg.textContent='保存しています…';
		fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d.toString()}).then(function(r){return r.json();}).then(function(j){
			msg.style.color=j.success?'#166534':'#b91c1c';
			msg.textContent=j.success?(j.data.message+(j.data.configured?'':'（まだ送信できる状態ではありません。上の設定を確認してください）')):((j.data&&j.data.message)||'保存できませんでした。');
		});
	};
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! ktp_dt_fax_can() ) {
			return;
		}
		wp_enqueue_script( 'ktp-desktop-webfax', WPMU_PLUGIN_URL . '/ktp-desktop/webfax.js', array(), (string) @filemtime( WPMU_PLUGIN_DIR . '/ktp-desktop/webfax.js' ), true );
		wp_localize_script(
			'ktp-desktop-webfax',
			'ktpDtFax',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( KTP_DT_FAX_NONCE ),
				'configured' => ktp_dt_fax_configured(),
				'settingsUrl' => add_query_arg( 'ktp_dt_page', 'webfax', home_url( '/' ) ),
				'html2canvas' => defined( 'KANTANPRO_PLUGIN_URL' ) ? KANTANPRO_PLUGIN_URL . 'js/lib/html2canvas.min.js' : '',
				'jspdf'       => defined( 'KANTANPRO_PLUGIN_URL' ) ? KANTANPRO_PLUGIN_URL . 'js/lib/jspdf.umd.min.js' : '',
			)
		);
	}
);

/** 受注書の「受注書印刷」ボタンの隣に FAX 送信ボタンを出す */
add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) || ! ktp_dt_fax_can() ) {
			return $output;
		}
		if ( ! preg_match( '#(<button[^>]*id="orderPreviewButton"[^>]*data-order-id="(\d+)"[^>]*>.*?</button>)#s', $output, $m ) ) {
			return $output;
		}
		$btn = '<button type="button" id="ktpDtFaxButton" class="button" data-order-id="' . (int) $m[2] . '" title="Web-FAX で送る">FAX送信</button>';
		return str_replace( $m[1], $m[1] . $btn, $output );
	},
	14,
	2
);
