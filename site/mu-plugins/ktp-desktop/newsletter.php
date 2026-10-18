<?php
/**
 * 顧客・協力会社へのニュースレター送信（KantanBiz の ClientNewsletterController / SupplierNewsletterController 相当）。
 *
 * 顧客または協力会社のメールアドレス宛てに、本人が設定した SMTP で一斉に送る（外部の配信サービスは使わない）。
 * 宛先は「対象外」を除いた顧客（または協力会社）の代表メールと、顧客は部署のメール。重複アドレスは 1 通だけ。
 * 本文の {会社名} {担当者} などは宛先ごとに置き換え、末尾に署名（ログインしている人ごとに保存）が付く。
 * 画面はアプリの独自画面（?ktp_dt_page=newsletter&target=client|supplier）。顧客・協力会社タブから入る。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_NEWSLETTER_NONCE = 'ktp_dt_newsletter';
const KTP_DT_NEWSLETTER_SIG   = 'ktp_dt_newsletter_signature';

function ktp_dt_nl_can() {
	return is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'ktpwp_access' ) );
}

function ktp_dt_nl_clean( $s ) {
	return trim( str_replace( array( "\0", "\r", "\n", "\t" ), '', (string) $s ) );
}

function ktp_dt_nl_issuer_name() {
	$name = trim( (string) get_option( 'ktp_pdf_issuer_name', '' ) );
	return '' !== $name ? $name : wp_strip_all_tags( (string) get_bloginfo( 'name' ) );
}

function ktp_dt_nl_subject_prefix() {
	return '【' . ktp_dt_nl_issuer_name() . '】';
}

function ktp_dt_nl_subject( $subject ) {
	$prefix = ktp_dt_nl_subject_prefix();
	$t      = trim( (string) $subject );
	if ( '' === $t ) {
		return $prefix;
	}
	return 0 === strpos( $t, $prefix ) ? $t : $prefix . $t;
}

/** 署名（保存した人ごと。未保存なら会社情報から作る） */
function ktp_dt_nl_signature() {
	$saved = trim( (string) get_user_meta( get_current_user_id(), KTP_DT_NEWSLETTER_SIG, true ) );
	if ( '' !== $saved ) {
		return $saved;
	}
	$info = '';
	if ( class_exists( 'KTPWP_Settings' ) ) {
		$info = trim( wp_strip_all_tags( str_replace( array( '<br />', '<br>', '<br/>' ), "\n", (string) KTPWP_Settings::get_company_info() ) ) );
	}
	return '' !== $info ? preg_replace( "/\n{3,}/", "\n\n", $info ) : ktp_dt_nl_issuer_name();
}

function ktp_dt_nl_email( $v ) {
	$v = ktp_dt_nl_clean( $v );
	return ( '' !== $v && is_email( $v ) ) ? $v : '';
}

/**
 * 宛先の一覧（重複アドレスは先に出た相手を採用）。
 *
 * @return list<array{email:string, row:object, depts:array}>
 */
function ktp_dt_nl_recipients( $target, $audience, $category ) {
	global $wpdb;
	$p     = $wpdb->prefix;
	$table = 'supplier' === $target ? $p . 'ktp_supplier' : $p . 'ktp_client';
	$where = '1=1';
	$args  = array();
	if ( 'category' === $audience ) {
		if ( '__uncategorized__' === $category ) {
			$where .= " AND (category IS NULL OR category = '')";
		} else {
			$where .= ' AND category = %s';
			$args[] = $category;
		}
	}
	$sql  = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY id"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$depts = array();
	if ( 'client' === $target ) {
		foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$p}ktp_department` ORDER BY id" ) as $d ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$depts[ (int) $d->client_id ][] = $d;
		}
	}
	$map = array();
	foreach ( $rows as $r ) {
		if ( 'client' === $target ? ( '対象外' === trim( (string) $r->client_status ) ) : ( '対象外' === trim( (string) $r->category ) ) ) {
			continue;
		}
		$emails = array();
		$main   = ktp_dt_nl_email( $r->email );
		if ( '' === $main && 'client' === $target ) {
			$main = ktp_dt_nl_email( $r->representative_name );
		}
		if ( '' !== $main ) {
			$emails[] = $main;
		}
		foreach ( $depts[ (int) $r->id ] ?? array() as $d ) {
			$e = ktp_dt_nl_email( $d->email );
			if ( '' !== $e ) {
				$emails[] = $e;
			}
		}
		foreach ( $emails as $e ) {
			$key = mb_strtolower( $e );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = array( 'email' => $e, 'row' => $r, 'depts' => $depts[ (int) $r->id ] ?? array() );
			}
		}
	}
	return array_values( $map );
}

function ktp_dt_nl_dept_addressee( $name ) {
	$name = ktp_dt_nl_clean( $name );
	$pos  = mb_strpos( $name, ': ' );
	return false !== $pos ? trim( mb_substr( $name, 0, $pos ) ) : $name;
}

/** 本文の {会社名} などを宛先ごとに置き換える */
function ktp_dt_nl_personalize( $target, $text, array $rec ) {
	$r       = $rec['row'];
	$company = ktp_dt_nl_clean( $r->company_name );
	if ( 'supplier' === $target ) {
		$out = str_replace( array( '{会社名}', '{担当者}', '{部署}' ), array( $company, ktp_dt_nl_clean( $r->name ), '' ), $text );
	} else {
		$rep = ktp_dt_nl_clean( $r->representative_name );
		$rep = ( '' !== $rep && ! is_email( $rep ) ) ? $rep : '';
		$dep = '';
		foreach ( $rec['depts'] as $d ) {
			if ( mb_strtolower( ktp_dt_nl_clean( $d->email ) ) === mb_strtolower( $rec['email'] ) ) {
				$dep = ktp_dt_nl_dept_addressee( $d->department_name );
				break;
			}
		}
		$lines = array();
		foreach ( $rec['depts'] as $d ) {
			$dn = ktp_dt_nl_dept_addressee( $d->department_name );
			$cp = ktp_dt_nl_clean( $d->contact_person );
			if ( '' !== $dn && '' !== $cp ) {
				$lines[] = $dn . '　' . $cp . ' 様';
			} elseif ( '' !== $dn ) {
				$lines[] = $dn . '　ご担当者様';
			} elseif ( '' !== $cp ) {
				$lines[] = $cp . ' 様';
			}
		}
		$listing = implode( "\n", $lines );
		$out     = str_replace(
			array( '{会社名}', '{担当者}', '{部署}', '{部署担当一覧}', '{部署リスト宛先ブロック}', '{ニュースレター宛先ブロック}' ),
			array( $company, $rep, $dep, $listing, $listing, $listing ),
			$text
		);
	}
	return implode( "\n", array_map( 'rtrim', preg_split( "/\r\n|\r|\n/", $out ) ) );
}

function ktp_dt_nl_compose( $body_personalized ) {
	return rtrim( $body_personalized ) . "\n\n--\n" . ktp_dt_nl_signature();
}

/** 宛先の選択を検証して返す */
function ktp_dt_nl_request() {
	if ( ! ktp_dt_nl_can() ) {
		wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
	}
	check_ajax_referer( KTP_DT_NEWSLETTER_NONCE, 'nonce' );
	$target   = isset( $_POST['target'] ) && 'supplier' === $_POST['target'] ? 'supplier' : 'client';
	$audience = isset( $_POST['audience'] ) && 'category' === $_POST['audience'] ? 'category' : 'all';
	$category = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
	$subject  = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
	$body     = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
	if ( 'category' === $audience && '' === $category ) {
		wp_send_json_error( array( 'message' => 'カテゴリーを選んでください。' ), 422 );
	}
	return array( $target, $audience, $category, $subject, $body );
}

function ktp_dt_nl_need_content( $subject, $body ) {
	if ( '' === trim( $subject ) || '' === trim( $body ) ) {
		wp_send_json_error( array( 'message' => '件名と本文を入力してください。' ), 422 );
	}
}

add_action(
	'wp_ajax_ktp_dt_nl_preview',
	function () {
		list( $target, $audience, $category, $subject, $body ) = ktp_dt_nl_request();
		ktp_dt_nl_need_content( $subject, $body );
		$recs   = ktp_dt_nl_recipients( $target, $audience, $category );
		$sample = $recs ? ktp_dt_nl_personalize( $target, $body, $recs[0] ) : $body;
		$names  = array();
		foreach ( $recs as $r ) {
			$names[ (int) $r['row']->id ] = true;
		}
		wp_send_json_success(
			array(
				'subject'       => ktp_dt_nl_subject( $subject ),
				'mail_count'    => count( $recs ),
				'company_count' => count( $names ),
				'body'          => ktp_dt_nl_compose( $sample ),
				'has_sample'    => (bool) $recs,
			)
		);
	}
);

add_action(
	'wp_ajax_ktp_dt_nl_test',
	function () {
		list( $target, $audience, $category, $subject, $body ) = ktp_dt_nl_request();
		ktp_dt_nl_need_content( $subject, $body );
		$to = isset( $_POST['test_to'] ) ? sanitize_email( wp_unslash( $_POST['test_to'] ) ) : '';
		if ( '' === $to || ! is_email( $to ) ) {
			wp_send_json_error( array( 'message' => 'テスト送信先のメールアドレスを入力してください。' ), 422 );
		}
		$recs = ktp_dt_nl_recipients( $target, $audience, $category );
		$text = $recs ? ktp_dt_nl_personalize( $target, $body, $recs[0] ) : $body;
		$ok   = wp_mail( $to, '[テスト] ' . ktp_dt_nl_subject( $subject ), ktp_dt_nl_compose( $text ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => '送信できませんでした。設定画面のメール（SMTP）の設定を確認してください。' ), 500 );
		}
		wp_send_json_success( array( 'message' => 'テストメールを ' . $to . ' 宛に送信しました。' ) );
	}
);

add_action(
	'wp_ajax_ktp_dt_nl_send',
	function () {
		list( $target, $audience, $category, $subject, $body ) = ktp_dt_nl_request();
		ktp_dt_nl_need_content( $subject, $body );
		$recs = ktp_dt_nl_recipients( $target, $audience, $category );
		if ( ! $recs ) {
			wp_send_json_error( array( 'message' => '送信できるメールアドレスがありません。「対象外」の相手は除かれ、メールアドレスが登録されている相手だけに送られます。' ), 422 );
		}
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$subject = ktp_dt_nl_subject( $subject );
		$sent    = 0;
		$failed  = array();
		foreach ( $recs as $rec ) {
			$ok = wp_mail( $rec['email'], $subject, ktp_dt_nl_compose( ktp_dt_nl_personalize( $target, $body, $rec ) ), array( 'Content-Type: text/plain; charset=UTF-8' ) );
			if ( $ok ) {
				++$sent;
			} else {
				$failed[] = $rec['email'];
			}
		}
		wp_send_json_success(
			array(
				'sent'    => $sent,
				'failed'  => $failed,
				'message' => $sent . ' 件に送信しました。' . ( $failed ? '送れなかった宛先が ' . count( $failed ) . ' 件あります: ' . implode( ', ', array_slice( $failed, 0, 5 ) ) : '' ),
			)
		);
	}
);

add_action(
	'wp_ajax_ktp_dt_nl_signature',
	function () {
		if ( ! ktp_dt_nl_can() ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		check_ajax_referer( KTP_DT_NEWSLETTER_NONCE, 'nonce' );
		$sig = isset( $_POST['signature'] ) ? sanitize_textarea_field( wp_unslash( $_POST['signature'] ) ) : '';
		update_user_meta( get_current_user_id(), KTP_DT_NEWSLETTER_SIG, trim( $sig ) );
		wp_send_json_success( array( 'message' => '署名を保存しました。' ) );
	}
);

/** 独自画面（?ktp_dt_page=newsletter&target=client|supplier） */
add_filter(
	'ktp_desktop_custom_page',
	function ( $page ) {
		if ( null !== $page || ! isset( $_GET['ktp_dt_page'] ) || 'newsletter' !== $_GET['ktp_dt_page'] || ! ktp_dt_nl_can() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $page;
		}
		global $wpdb;
		$target = isset( $_GET['target'] ) && 'supplier' === $_GET['target'] ? 'supplier' : 'client'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$label  = 'supplier' === $target ? '協力会社' : '顧客';
		$table  = $wpdb->prefix . ( 'supplier' === $target ? 'ktp_supplier' : 'ktp_client' );
		$cats   = $wpdb->get_col( "SELECT DISTINCT category FROM `{$table}` WHERE category IS NOT NULL AND category != '' ORDER BY category" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_un = (bool) $wpdb->get_var( "SELECT id FROM `{$table}` WHERE category IS NULL OR category = '' LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$smtp   = get_option( 'ktp_smtp_settings', array() );
		$test   = is_array( $smtp ) && ! empty( $smtp['email_address'] ) ? $smtp['email_address'] : '';
		$back   = add_query_arg( 'tab_name', $target, home_url( '/' ) );
		$default_body = 'supplier' === $target ? "{会社名}\n{担当者} 様\n" : "{会社名}\n{担当者} 様\n{部署リスト宛先ブロック}\n";
		ob_start();
		?>
<div id="ktp-dt-nl" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( KTP_DT_NEWSLETTER_NONCE ) ); ?>" data-target="<?php echo esc_attr( $target ); ?>" style="max-width:860px;margin:0 auto;font-size:14px;line-height:1.7;">
	<p><a href="<?php echo esc_url( $back ); ?>">← <?php echo esc_html( $label ); ?>に戻る</a></p>
	<h2 style="margin:6px 0 12px;"><?php echo esc_html( $label ); ?>へのニュースレター</h2>
	<p style="color:#6b7280;font-size:13px;">設定したメール（SMTP）から、<?php echo esc_html( $label ); ?>のメールアドレス宛てに送ります。「対象外」の相手には送りません。件名の先頭には <?php echo esc_html( ktp_dt_nl_subject_prefix() ); ?> が付きます。</p>
	<div style="margin:12px 0;">宛先：
		<label><input type="radio" name="audience" value="all" checked> すべて</label>
		<label style="margin-left:12px;"><input type="radio" name="audience" value="category"> カテゴリーで絞る</label>
		<select id="nl-category" style="margin-left:6px;">
			<?php foreach ( $cats as $c ) : ?><option value="<?php echo esc_attr( $c ); ?>"><?php echo esc_html( $c ); ?></option><?php endforeach; ?>
			<?php if ( $has_un ) : ?><option value="__uncategorized__">（未分類）</option><?php endif; ?>
		</select>
	</div>
	<div style="margin:12px 0;"><label>件名<br><input type="text" id="nl-subject" maxlength="255" style="width:100%;padding:6px;font-size:14px;"></label></div>
	<div style="margin:12px 0;"><label>本文（<?php echo 'supplier' === $target ? '{会社名} {担当者}' : '{会社名} {担当者} {部署} {部署リスト宛先ブロック}'; ?> は宛先ごとに置き換わります）<br>
		<textarea id="nl-body" rows="12" maxlength="50000" style="width:100%;padding:6px;font-size:14px;"><?php echo esc_textarea( $default_body ); ?></textarea></label></div>
	<div style="margin:12px 0;"><label>署名（メール末尾。あなた用に保存されます）<br>
		<textarea id="nl-signature" rows="4" style="width:100%;padding:6px;font-size:14px;"><?php echo esc_textarea( ktp_dt_nl_signature() ); ?></textarea></label>
		<button type="button" id="nl-save-sig" style="margin-top:4px;">署名を保存</button></div>
	<div style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
		<button type="button" id="nl-preview">プレビュー</button>
		<span>テスト送信先：<input type="email" id="nl-test-to" value="<?php echo esc_attr( $test ); ?>" style="padding:4px;"></span>
		<button type="button" id="nl-test">テスト送信</button>
		<button type="button" id="nl-send" style="background:#2563eb;color:#fff;border:0;padding:6px 16px;border-radius:4px;">送信する</button>
	</div>
	<p id="nl-msg" role="status" style="font-weight:600;"></p>
	<pre id="nl-preview-box" style="display:none;white-space:pre-wrap;background:#f9fafb;border:1px solid #e5e7eb;padding:10px;border-radius:6px;"></pre>
</div>
<script>
(function(){
	var w=document.getElementById('ktp-dt-nl'),msg=document.getElementById('nl-msg'),box=document.getElementById('nl-preview-box');
	function $(id){return document.getElementById(id);}
	function data(action,extra){
		var aud=w.querySelector('input[name=audience]:checked').value;
		var d={action:action,nonce:w.dataset.nonce,target:w.dataset.target,audience:aud,category:$('nl-category').value,subject:$('nl-subject').value,body:$('nl-body').value};
		for(var k in (extra||{})){d[k]=extra[k];}
		return new URLSearchParams(d).toString();
	}
	function post(action,extra){
		msg.style.color='';msg.textContent='処理しています…';
		return fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:data(action,extra)}).then(function(r){return r.json();}).then(function(j){
			if(j&&j.success){msg.style.color='#166534';return j.data;}
			msg.style.color='#b91c1c';msg.textContent=(j&&j.data&&j.data.message)||'失敗しました。';return null;
		}).catch(function(){msg.style.color='#b91c1c';msg.textContent='通信に失敗しました。';return null;});
	}
	$('nl-preview').onclick=function(){post('ktp_dt_nl_preview').then(function(d){if(!d)return;msg.textContent=d.mail_count+' 件のメールアドレス（'+d.company_count+' 社）に送ります。';box.style.display='block';box.textContent='件名: '+d.subject+'\n\n'+d.body;});};
	$('nl-test').onclick=function(){post('ktp_dt_nl_test',{test_to:$('nl-test-to').value}).then(function(d){if(d)msg.textContent=d.message;});};
	$('nl-send').onclick=function(){
		post('ktp_dt_nl_preview').then(function(d){
			if(!d)return;
			if(!window.confirm(d.mail_count+' 件のメールアドレスにニュースレターを送ります。よろしいですか？\n件名: '+d.subject)){msg.textContent='送信をやめました。';return;}
			post('ktp_dt_nl_send').then(function(r){if(r)msg.textContent=r.message;});
		});
	};
	$('nl-save-sig').onclick=function(){
		msg.textContent='保存しています…';
		fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({action:'ktp_dt_nl_signature',nonce:w.dataset.nonce,signature:$('nl-signature').value}).toString()}).then(function(r){return r.json();}).then(function(j){msg.style.color=j.success?'#166534':'#b91c1c';msg.textContent=j.success?j.data.message:'保存できませんでした。';});
	};
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
);

/** 顧客・協力会社タブにニュースレターへの入口を出す */
add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) || ! ktp_dt_nl_can() ) {
			return $output;
		}
		$tab = isset( $_GET['tab_name'] ) ? sanitize_key( wp_unslash( $_GET['tab_name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $tab, array( 'client', 'supplier' ), true ) ) {
			return $output;
		}
		$pos = strpos( $output, '<div class="controller' );
		if ( false === $pos ) {
			return $output;
		}
		$url  = add_query_arg( array( 'ktp_dt_page' => 'newsletter', 'target' => $tab ), home_url( '/' ) );
		$link = '<div style="text-align:right;margin:0 0 6px;font-size:13px;"><a href="' . esc_url( $url ) . '">' . ( 'supplier' === $tab ? '協力会社' : '顧客' ) . 'へニュースレターを送る</a></div>';
		return substr( $output, 0, $pos ) . $link . substr( $output, $pos );
	},
	13,
	2
);
