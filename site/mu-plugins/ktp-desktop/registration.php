<?php
/**
 * ユーザー登録（任意）。登録するとメール署名の後ろの宣伝文が入らなくなる。
 *
 * ライセンス管理ではない。登録しなくても機能の制限はなく、検証は行わない。
 * 登録すると、メールアドレスを kantanpro.com のニュースレター（MailPoet「KantanProEX（DT）」）に裏で登録する。
 * 登録していない人のデータは一切送らない。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DESKTOP_REG_OPTION = 'ktp_desktop_registration';

// ニュースレター登録の送り先（kantanpro.com の kp-download-gate が受ける）。
// リストは MailPoet の「KantanProEX（DT）」。秘密はこのリストにしか登録できない。
const KTP_DESKTOP_NEWSLETTER_ENDPOINT = 'https://www.kantanpro.com/wp-json/kpdg/v1/';
const KTP_DESKTOP_PENDING_UNSUB       = 'ktp_desktop_registration_unsub'; // 解除したが、まだ kantanpro.com に伝えられていないメールアドレス
const KTP_DESKTOP_NEWSLETTER_LIST_ID  = 7;
// ビルド（scripts/build-site.sh）で実際の値に差し替える。ソースには秘密を置かない。
const KTP_DESKTOP_NEWSLETTER_SECRET   = '__KTP_DESKTOP_REG_SECRET__';

/**
 * kantanpro.com に登録／解除を伝える（ニュースレターの登録・解除と、お礼・解除のお知らせメールの送信は向こう側で行う）。
 * この送信のあいだだけ、kantanpro.com への通信を許可する（mu-plugin の遮断の例外）。
 *
 * @param string $action 'subscribe' または 'unsubscribe'。
 */
function ktp_desktop_newsletter_request( $action, $email ) {
	if ( KTP_DESKTOP_NEWSLETTER_LIST_ID <= 0 || '' === KTP_DESKTOP_NEWSLETTER_SECRET || false !== strpos( KTP_DESKTOP_NEWSLETTER_SECRET, '__KTP_DESKTOP_REG_SECRET__' ) ) {
		return false;
	}
	$GLOBALS['ktp_desktop_allow_newsletter'] = true;
	$res = wp_remote_post(
		KTP_DESKTOP_NEWSLETTER_ENDPOINT . $action,
		array(
			'timeout' => 15,
			'body'    => array(
				'email'  => $email,
				'list'   => KTP_DESKTOP_NEWSLETTER_LIST_ID,
				'secret' => KTP_DESKTOP_NEWSLETTER_SECRET,
				'source' => 'KantanProEX-DT',
			),
		)
	);
	unset( $GLOBALS['ktp_desktop_allow_newsletter'] );
	return ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
}

/** 未送信の登録・解除を kantanpro.com に送る（登録直後・オフラインだったとき用の再送）。 */
function ktp_desktop_sync_newsletter() {
	$pending = get_option( KTP_DESKTOP_PENDING_UNSUB );
	if ( is_string( $pending ) && '' !== $pending && ktp_desktop_newsletter_request( 'unsubscribe', $pending ) ) {
		delete_option( KTP_DESKTOP_PENDING_UNSUB );
	}
	$saved = get_option( KTP_DESKTOP_REG_OPTION );
	if ( is_array( $saved ) && ! empty( $saved['email'] ) && empty( $saved['newsletter'] ) && ktp_desktop_newsletter_request( 'subscribe', $saved['email'] ) ) {
		$saved['newsletter'] = true;
		update_option( KTP_DESKTOP_REG_OPTION, $saved, false );
	}
}
add_action( 'ktp_desktop_newsletter_retry', 'ktp_desktop_sync_newsletter' );

/** 登録済みなら登録したメールアドレス、未登録なら空文字。 */
function ktp_desktop_registered_email() {
	$saved = get_option( KTP_DESKTOP_REG_OPTION );
	$email = is_array( $saved ) && ! empty( $saved['email'] ) ? sanitize_email( $saved['email'] ) : '';
	return is_email( $email ) ? $email : '';
}

/** 未登録のときだけ、署名の後ろに宣伝文を足す（登録済みなら署名はそのまま）。 */
add_filter(
	'ktpwp_mail_signature',
	function ( $signature ) {
		if ( ktp_desktop_registered_email() !== '' ) {
			return $signature;
		}
		return $signature . "\n\n――\nこのメールは「" . KANTANPRO_PLUGIN_NAME . "」で作成しています。\nhttps://www.kantanpro.com/";
	}
);

add_action(
	'admin_menu',
	function () {
		add_submenu_page( 'ktp-settings', 'ユーザー登録', 'ユーザー登録', 'manage_options', 'ktp-desktop-register', 'ktp_desktop_render_register_page' );
	},
	PHP_INT_MAX
);

function ktp_desktop_render_register_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$message  = '';
	$is_error = false;
	if ( isset( $_POST['ktp_desktop_reg_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ktp_desktop_reg_nonce'] ) ), 'ktp_desktop_reg' ) ) {
		if ( isset( $_POST['ktp_desktop_reg_clear'] ) ) {
			$old = get_option( KTP_DESKTOP_REG_OPTION );
			delete_option( KTP_DESKTOP_REG_OPTION );
			if ( is_array( $old ) && ! empty( $old['email'] ) ) {
				// ニュースレターからの削除と解除のお知らせメールは kantanpro.com 側で行う。通信できなければ後で再送する。
				update_option( KTP_DESKTOP_PENDING_UNSUB, $old['email'], false );
				ktp_desktop_sync_newsletter();
			}
			$message = '登録を解除しました。';
		} else {
			$email = isset( $_POST['ktp_desktop_reg_email'] ) ? sanitize_email( wp_unslash( $_POST['ktp_desktop_reg_email'] ) ) : '';
			if ( is_email( $email ) ) {
				// 宣伝文を消す登録は、通信できなくてもその場で完了させる。ニュースレターへの登録は裏で行い、失敗したら後で再送する。
				// 同じメールアドレスの解除が送信待ちのまま再登録されたら、解除は取り消す。
				if ( get_option( KTP_DESKTOP_PENDING_UNSUB ) === $email ) {
					delete_option( KTP_DESKTOP_PENDING_UNSUB );
				}
				update_option( KTP_DESKTOP_REG_OPTION, array( 'email' => $email, 'newsletter' => false ), false );
				ktp_desktop_sync_newsletter();
				$message = '登録しました。以後、メールの署名の後ろに宣伝文は入りません。';
			} else {
				$message  = 'メールアドレスを正しく入力してください。';
				$is_error = true;
			}
		}
	}
	$registered = ktp_desktop_registered_email();
	?>
	<div class="wrap">
		<h1>ユーザー登録</h1>
		<?php if ( $message ) : ?>
			<div class="notice <?php echo $is_error ? 'notice-error' : 'notice-success'; ?>"><p><?php echo esc_html( $message ); ?></p></div>
		<?php endif; ?>
		<p>ユーザー登録は任意です。登録しなくても、すべての機能をそのまま使えます。</p>
		<p>ユーザー登録をすると、次のメリットがあります。</p>
		<ul style="list-style:disc;margin-left:1.5em">
			<li>顧客・協力会社へのメールの署名の後ろに入る「このメールは <?php echo esc_html( KANTANPRO_PLUGIN_NAME ); ?> で作成しています」の宣伝文が、最初から入らなくなります（毎回消さなくて済みます）。</li>
			<li>アップデート情報や、便利な活用方法などをメールでお届けします（配信の停止は、届いたメールのリンクからいつでもできます）。</li>
		</ul>
		<form method="post">
			<?php wp_nonce_field( 'ktp_desktop_reg', 'ktp_desktop_reg_nonce' ); ?>
			<?php if ( $registered !== '' ) : ?>
				<p><strong>登録済み：</strong><?php echo esc_html( $registered ); ?></p>
				<p><button type="submit" name="ktp_desktop_reg_clear" value="1" class="button">登録を解除する</button></p>
			<?php else : ?>
				<p><input type="email" name="ktp_desktop_reg_email" class="regular-text" placeholder="メールアドレス" required></p>
				<p><button type="submit" class="button button-primary">登録する</button></p>
			<?php endif; ?>
		</form>
	</div>
	<?php
}

add_action(
	'init',
	function () {
		if ( ! wp_next_scheduled( 'ktp_desktop_newsletter_retry' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'ktp_desktop_newsletter_retry' );
		}
	}
);

/** メール本文ポップアップに、登録で宣伝文が入らなくなることを小さく案内する（未登録のときだけ）。 */
add_action(
	'wp_footer',
	function () {
		if ( ktp_desktop_registered_email() !== '' ) {
			return;
		}
		$url = admin_url( 'admin.php?page=ktp-desktop-register' );
		?>
<script id="ktp-desktop-reg-hint">
(function () {
	var url = <?php echo wp_json_encode( $url ); ?>;
	function add() {
		var ta = document.getElementById('email-body');
		if (!ta || document.getElementById('ktp-desktop-reg-hint-note')) { return; }
		var p = document.createElement('p');
		p.id = 'ktp-desktop-reg-hint-note';
		p.style.cssText = 'margin:6px 0 0;font-size:12px;color:#666';
		p.innerHTML = '署名の後ろの宣伝文は、ユーザー登録で入らなくなります（アップデート情報もお届け）→ <a href="' + url + '" style="color:#0073aa">登録する</a>';
		ta.parentNode.appendChild(p);
	}
	new MutationObserver(add).observe(document.body, { childList: true, subtree: true });
})();
</script>
		<?php
	},
	99
);
