<?php
/**
 * デザイン: ボディの背景画像と、ペインのすりガラス表示（KantanProEX 設定 → デザイン）。
 *
 * - 画面全体の後ろに「ボディ背景画像」を敷き、顧客リスト・顧客の詳細などのペインを
 *   すりガラス（半透明 + ぼかし）にして、画像がうっすら透けて見えるようにする
 * - 選んでいなければ標準の画像（images/body-bg-gears.jpg、薄いグレーの歯車）を敷く。
 *   ヘッダー・ボディとも「画像を削除」で画像なしの無地にできる（body_bg_none / header_bg_none）
 * - ペインの中身（行の縞模様・入力欄など）は本体が色をインラインで付けているので、色は変えずに「乗算」で重ねる。
 *   白い部分は透けて、色の付いた行は色だけが乗る（本体の「奇数行・偶数行の色」の設定もそのまま効く）
 * - ヘッダー背景画像は本体の設定（画像を変更）をそのまま使う。オリジナルの画像にしたときは、文字が読めるよう左右に薄い幕を敷き、
 *   ダークモードでは反転を打ち消して写真の色のまま暗く沈める（標準の空の画像は、反転した夜空の見た目のまま）
 * - 設定は ktp_dt_design（本体の ktp_design_settings とは別。本体の保存処理は知らない項目を捨てるため）。
 *   画像は添付ファイルの ID で持つ（起動のたびにポートが変わっても URL を組み直せる。uploads はバックアップにも入る）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_DESIGN_OPTION = 'ktp_dt_design';

/** 背景画像の見え方。veil = 画像に重ねる白い幕の濃さ、glass = ペインの白の濃さ */
function ktp_dt_design_strengths() {
	return array(
		'soft'   => array( 'label' => 'うっすら', 'veil' => 0.55, 'glass' => 0.78 ),
		'normal' => array( 'label' => 'ふつう', 'veil' => 0.35, 'glass' => 0.66 ),
		'strong' => array( 'label' => 'くっきり', 'veil' => 0.1, 'glass' => 0.52 ),
	);
}

/** body_bg_image = 選んだ画像（0 なら標準の画像）、body_bg_none / header_bg_none = 「画像を削除」で画像なしにした */
function ktp_dt_design() {
	$o         = get_option( KTP_DT_DESIGN_OPTION, array() );
	$o         = is_array( $o ) ? $o : array();
	$strengths = ktp_dt_design_strengths();
	return array(
		'body_bg_image'  => isset( $o['body_bg_image'] ) ? absint( $o['body_bg_image'] ) : 0,
		'body_bg_none'   => ! empty( $o['body_bg_none'] ),
		'header_bg_none' => ! empty( $o['header_bg_none'] ),
		'bg_strength'    => isset( $o['bg_strength'], $strengths[ $o['bg_strength'] ] ) ? $o['bg_strength'] : 'normal',
	);
}

function ktp_dt_sanitize_design( $input ) {
	$input     = is_array( $input ) ? $input : array();
	$id        = isset( $input['body_bg_image'] ) ? absint( $input['body_bg_image'] ) : 0;
	$strengths = ktp_dt_design_strengths();
	return array(
		'body_bg_image'  => $id && wp_attachment_is_image( $id ) ? $id : 0,
		'body_bg_none'   => ! empty( $input['body_bg_none'] ) ? 1 : 0,
		'header_bg_none' => ! empty( $input['header_bg_none'] ) ? 1 : 0,
		'bg_strength'    => isset( $input['bg_strength'], $strengths[ $input['bg_strength'] ] ) ? $input['bg_strength'] : 'normal',
	);
}

/** 標準のボディ背景画像（アプリに同梱） */
function ktp_dt_default_body_bg_url() {
	return WPMU_PLUGIN_URL . '/ktp-desktop/images/body-bg-gears.jpg';
}

/** 選んだ画像がなければ（消された場合も）標準の画像。「画像を削除」したときは空 */
function ktp_dt_body_bg_url() {
	$d = ktp_dt_design();
	if ( $d['body_bg_none'] ) {
		return '';
	}
	$url = $d['body_bg_image'] ? wp_get_attachment_image_url( $d['body_bg_image'], 'full' ) : '';
	return $url ? $url : ktp_dt_default_body_bg_url();
}

function ktp_dt_body_bg_is_default() {
	return ktp_dt_body_bg_url() === ktp_dt_default_body_bg_url();
}

/** 本体の設定でヘッダー背景にオリジナルの画像（添付ファイル）を選んでいれば、その URL */
function ktp_dt_custom_header_url() {
	if ( ktp_dt_design()['header_bg_none'] ) {
		return '';
	}
	$o  = get_option( 'ktp_design_settings', array() );
	$id = is_array( $o ) && isset( $o['header_bg_image'] ) && is_numeric( $o['header_bg_image'] ) ? absint( $o['header_bg_image'] ) : 0;
	$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
	return $url ? $url : '';
}

/** アプリ画面の <html> に付けるクラス（app-template.php） */
function ktp_dt_design_html_class() {
	$c = array();
	if ( ktp_dt_body_bg_url() ) {
		$c[] = 'ktp-dt-has-backdrop';
	}
	if ( ktp_dt_custom_header_url() ) {
		$c[] = 'ktp-dt-custom-header';
	}
	if ( ktp_dt_design()['header_bg_none'] ) {
		$c[] = 'ktp-dt-no-header-img';
	}
	return implode( ' ', $c );
}

/** アプリ画面の <head> に出す CSS（app-template.php） */
function ktp_dt_design_head() {
	$body      = ktp_dt_body_bg_url();
	$header    = ktp_dt_custom_header_url();
	$no_header = ktp_dt_design()['header_bg_none'];
	if ( ! $body && ! $header && ! $no_header ) {
		return;
	}
	$s = ktp_dt_design_strengths()[ ktp_dt_design()['bg_strength'] ];
	// すりガラスにするペイン: 顧客・サービス・協力会社のリストと詳細、仕事リスト、受注書の各ブロック、レポート、フッター
	$panes = '.ktp_data_list_box, .data_detail_box, .ktp-list-workflow, .ktp_work_list_box, .order_info_box, .order_invoice_box, .ktp-order-block, #ktp_report_inner_content, .ktp-footer';
	?>
<style id="ktp-dt-design">
	:root {
		--ktp-dt-veil: <?php echo esc_html( (string) $s['veil'] ); ?>;
		--ktp-dt-glass: <?php echo esc_html( (string) $s['glass'] ); ?>;
<?php if ( $body ) : ?>
		--ktp-dt-body-img: url("<?php echo esc_url( $body ); ?>");
<?php endif; ?>
<?php if ( $header ) : ?>
		--ktp-dt-header-img: url("<?php echo esc_url( $header ); ?>");
<?php endif; ?>
	}
<?php if ( $body ) : ?>
	/* 背景画像（z-index -1 で全部の後ろ）と、その上の白い幕。ダークモードでは画像だけ反転を打ち消して写真の色に戻し、幕は反転で暗い幕になる
	   （標準の画像は白とグレーだけなので打ち消さず、反転のまま黒地に暗いグレーの歯車にする） */
	html.ktp-dt-has-backdrop body { background: transparent; }
	.ktp-dt-backdrop { position: fixed; inset: 0; z-index: -1; overflow: hidden; pointer-events: none; }
	.ktp-dt-backdrop__img { position: absolute; inset: 0; background: var(--ktp-dt-body-img) center / cover no-repeat; }
	.ktp-dt-backdrop__veil { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(255, 255, 255, calc(var(--ktp-dt-veil) + 0.1)) 0%, rgba(255, 255, 255, var(--ktp-dt-veil)) 40%, rgba(255, 255, 255, calc(var(--ktp-dt-veil) + 0.05)) 100%); }
<?php if ( ! ktp_dt_body_bg_is_default() ) : ?>
	html.ktp-dark .ktp-dt-backdrop__img { filter: invert(1) hue-rotate(180deg); }
<?php endif; ?>
	/* 外枠の白は外して、背景が見えるようにする */
	html.ktp-dt-has-backdrop .tabs.ktp_plugin_container,
	html.ktp-dt-has-backdrop .tab_content,
	html.ktp-dt-has-backdrop #report_content { background: transparent !important; }
	/* ペインはすりガラス。ガラスは ::before に置く（ペイン自体に backdrop-filter を付けると、中の position: fixed の基準が変わるため） */
	html.ktp-dt-has-backdrop :is(<?php echo $panes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>) {
		position: relative; isolation: isolate; background: transparent !important; border-color: rgba(255, 255, 255, 0.5) !important;
		border-radius: 14px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12) !important;
	}
	html.ktp-dt-has-backdrop :is(<?php echo $panes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>)::before {
		content: ""; position: absolute; inset: 0; z-index: -1; border-radius: inherit; pointer-events: none;
		background: rgba(255, 255, 255, var(--ktp-dt-glass));
		-webkit-backdrop-filter: blur(16px) saturate(150%); backdrop-filter: blur(16px) saturate(150%);
		box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.6);
	}
	/* 中身は乗算で重ねる（白は透けて、色の付いた行・見出しは色だけが乗る） */
	html.ktp-dt-has-backdrop :is(<?php echo $panes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>) > * { mix-blend-mode: multiply; }
	@media print { .ktp-dt-backdrop { display: none !important; } }
<?php endif; ?>
<?php if ( $header ) : ?>
	/* オリジナルのヘッダー画像: 文字とボタンが読めるよう、左右に薄い幕を敷く（本体が ::before を透明な重ねとして用意している） */
	html.ktp-dt-custom-header div.ktp_header::before {
		background: linear-gradient(90deg, rgba(255, 255, 255, 0.7) 0%, rgba(255, 255, 255, 0.2) 40%, rgba(255, 255, 255, 0.2) 62%, rgba(255, 255, 255, 0.6) 100%) !important;
	}
	/* ダークモード: 反転を打ち消して写真の色のまま、暗い幕で沈める（幕ごと打ち消すので、黒い幕は黒いまま残る） */
	html.ktp-dark.ktp-dt-custom-header div.ktp_header { background-image: none !important; }
	html.ktp-dark.ktp-dt-custom-header div.ktp_header::before {
		background: linear-gradient(90deg, rgba(0, 0, 0, 0.7) 0%, rgba(0, 0, 0, 0.3) 40%, rgba(0, 0, 0, 0.3) 62%, rgba(0, 0, 0, 0.6) 100%), var(--ktp-dt-header-img) center / cover no-repeat !important;
		filter: invert(1) hue-rotate(180deg);
	}
<?php endif; ?>
<?php if ( $no_header ) : ?>
	/* ヘッダーの「画像を削除」: 画像なし（本体の設定は空にすると標準の空の画像に戻るので、こちらで消す） */
	html.ktp-dt-no-header-img div.ktp_header { background-image: none !important; }
<?php endif; ?>
</style>
	<?php
}

/** 背景画像の層（<body> の最初、app-template.php） */
function ktp_dt_design_backdrop() {
	if ( ktp_dt_body_bg_url() ) {
		echo '<div class="ktp-dt-backdrop" aria-hidden="true"><div class="ktp-dt-backdrop__img"></div><div class="ktp-dt-backdrop__veil"></div></div>';
	}
}

// 設定 → デザイン に「ボディ背景画像」「背景画像の見え方」を足す（本体の項目の登録 = admin_init 10 のあと）
add_action(
	'admin_init',
	function () {
		register_setting( 'ktp_design_group', KTP_DT_DESIGN_OPTION, array( 'sanitize_callback' => 'ktp_dt_sanitize_design' ) );
		add_settings_field( 'ktp_dt_body_bg_image', 'ボディ背景画像', 'ktp_dt_body_bg_image_field', 'ktp-design', 'design_setting_section' );
		add_settings_field( 'ktp_dt_bg_strength', '背景画像の見え方', 'ktp_dt_bg_strength_field', 'ktp-design', 'design_setting_section' );

		// 「ヘッダー背景画像」のすぐ下に並べる
		global $wp_settings_fields;
		if ( isset( $wp_settings_fields['ktp-design']['design_setting_section']['header_bg_image'] ) ) {
			$fields = $wp_settings_fields['ktp-design']['design_setting_section'];
			$mine   = array(
				'ktp_dt_body_bg_image' => $fields['ktp_dt_body_bg_image'],
				'ktp_dt_bg_strength'   => $fields['ktp_dt_bg_strength'],
			);
			unset( $fields['ktp_dt_body_bg_image'], $fields['ktp_dt_bg_strength'] );
			$sorted = array();
			foreach ( $fields as $key => $field ) {
				$sorted[ $key ] = $field;
				if ( 'header_bg_image' === $key ) {
					$sorted += $mine;
				}
			}
			$wp_settings_fields['ktp-design']['design_setting_section'] = $sorted;
		}
	},
	20
);

// 本体の「デフォルトに戻す」で、こちらの設定も一緒に戻す。
// 本体は admin_init 10 で戻したあとすぐ画面を移動して exit するので、それより先（5）に消しておく
add_action(
	'admin_init',
	function () {
		if ( isset( $_POST['action'], $_POST['ktp_reset_to_default_nonce'] ) && 'reset_to_default' === $_POST['action']
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ktp_reset_to_default_nonce'] ) ), 'ktp_reset_to_default' )
			&& current_user_can( 'manage_options' ) ) {
			delete_option( KTP_DT_DESIGN_OPTION );
		}
	},
	5
);

function ktp_dt_body_bg_image_field() {
	$d       = ktp_dt_design();
	$url     = ktp_dt_body_bg_url();
	$custom  = $url && ! ktp_dt_body_bg_is_default();
	$name    = KTP_DT_DESIGN_OPTION;
	?>
	<div class="ktp-dt-image-field" data-default-url="<?php echo esc_url( ktp_dt_default_body_bg_url() ); ?>">
		<input type="hidden" class="ktp-dt-body-id" name="<?php echo esc_attr( $name ); ?>[body_bg_image]" value="<?php echo esc_attr( $custom ? (string) $d['body_bg_image'] : '0' ); ?>">
		<input type="hidden" class="ktp-dt-body-none" name="<?php echo esc_attr( $name ); ?>[body_bg_none]" value="<?php echo $d['body_bg_none'] ? '1' : '0'; ?>">
		<input type="hidden" id="ktp_dt_header_bg_none" name="<?php echo esc_attr( $name ); ?>[header_bg_none]" value="<?php echo $d['header_bg_none'] ? '1' : '0'; ?>">
		<div class="ktp-dt-image-preview" style="margin-bottom:10px;">
			<img src="<?php echo esc_url( $url ); ?>" alt="" style="max-width:300px;max-height:200px;border:1px solid #ddd;border-radius:4px;<?php echo $url ? '' : 'display:none;'; ?>">
			<span class="ktp-dt-image-note" style="display:block;color:#666;font-size:12px;"><?php echo esc_html( $url ? ( $custom ? '' : '元の画像' ) : 'なし（白い背景）' ); ?></span>
		</div>
		<div class="ktp-dt-image-buttons">
			<button type="button" class="button ktp-dt-pick-image">画像を選ぶ</button>
			<button type="button" class="button ktp-dt-default-image">元の画像に戻す</button>
			<button type="button" class="button ktp-dt-remove-body">画像を削除</button>
		</div>
		<div style="font-size:12px;color:#555;margin-top:4px;">※ 画面全体の背景に敷く画像です。顧客リスト・顧客の詳細などの欄はすりガラスのように透けて、画像がうっすら見えます。横長の写真（1920×1080px くらい）がおすすめです。</div>
		<div style="font-size:12px;color:#555;margin-top:2px;">※「画像を削除」で画像なしの白い背景になります（アップロードした画像はメディアに残ります）。どのボタンも、押したあと「設定を保存」で反映されます。</div>
	</div>
	<?php
}

function ktp_dt_bg_strength_field() {
	$d = ktp_dt_design();
	foreach ( ktp_dt_design_strengths() as $key => $s ) {
		printf(
			'<label style="margin-right:18px;"><input type="radio" name="%1$s[bg_strength]" value="%2$s"%3$s> %4$s</label>',
			esc_attr( KTP_DT_DESIGN_OPTION ),
			esc_attr( $key ),
			checked( $d['bg_strength'], $key, false ),
			esc_html( $s['label'] )
		);
	}
	echo '<div style="font-size:12px;color:#555;margin-top:4px;">※ ボディ背景画像の見え方です。「くっきり」ほど画像がはっきり見えます。</div>';
}

// デザインの画面: ボディ背景画像を選ぶ・元の画像に戻す・削除。ヘッダー背景画像には「元の画像に戻す」「画像を削除」を足す
add_action(
	'admin_footer',
	function () {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'ktp-design-settings' !== $page ) {
			return;
		}
		?>
<script id="ktp-dt-design-fields">
jQuery(function ($) {
	function bodyPreview(field, url, note) {
		field.find('.ktp-dt-image-preview img').attr('src', url || '').toggle(!!url);
		field.find('.ktp-dt-image-note').text(note);
	}
	$(document).on('click', '.ktp-dt-pick-image', function (e) {
		e.preventDefault();
		var field = $(this).closest('.ktp-dt-image-field');
		var frame = wp.media({ title: 'ボディ背景画像を選択', button: { text: '画像を選択' }, multiple: false, library: { type: 'image' } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first().toJSON();
			field.find('.ktp-dt-body-id').val(a.id);
			field.find('.ktp-dt-body-none').val('0');
			bodyPreview(field, a.url, '');
		});
		frame.open();
	});
	$(document).on('click', '.ktp-dt-default-image', function (e) {
		e.preventDefault();
		var field = $(this).closest('.ktp-dt-image-field');
		field.find('.ktp-dt-body-id').val('0');
		field.find('.ktp-dt-body-none').val('0');
		bodyPreview(field, field.data('default-url'), '元の画像');
	});
	// ヘッダー背景画像
	var header = $('#header_bg_image');
	var headerNone = $('#ktp_dt_header_bg_none');
	var headerPreview = $('#header_bg_image_preview');
	var headerNote = $('<span style="display:none;color:#666;font-size:12px;">なし（画像なし）</span>');
	headerPreview.after(headerNote);
	// 本体の CSS がプレビュー画像に display: block !important を付けているので、クラスで隠す
	// ボタンは3つとも同じ幅で並べる（本体は「画像を変更」だけに右の余白を付けている）
	$('<style>.ktp-admin-wrap #header_bg_image_preview.ktp-dt-hidden { display: none !important; }'
		+ '.ktp-dt-image-buttons { display: inline-grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }'
		+ '.ktp-admin-wrap .ktp-dt-image-buttons .button { margin: 0 !important; text-align: center; }</style>').appendTo('head');
	function showHeaderNone(none) {
		headerPreview.toggleClass('ktp-dt-hidden', none);
		headerNote.toggle(none);
	}
	showHeaderNone(headerNone.val() === '1');
	// ボディの画像を削除: 画像なし（標準の画像も出さない）
	$(document).on('click', '.ktp-dt-remove-body', function (e) {
		e.preventDefault();
		var field = $(this).closest('.ktp-dt-image-field');
		field.find('.ktp-dt-body-id').val('0');
		field.find('.ktp-dt-body-none').val('1');
		bodyPreview(field, '', 'なし（白い背景）');
	});
	if (header.length) {
		// 標準の空の画像に戻す（本体には「デフォルトに戻す」で全部戻すしかない）
		var change = header.closest('.ktp-image-upload-field').find('.ktp-upload-image');
		var reset = $('<button type="button" class="button">元の画像に戻す</button>');
		var removeHeader = $('<button type="button" class="button ktp-dt-remove-header">画像を削除</button>');
		$('<div class="ktp-dt-image-buttons"></div>').insertBefore(change).append(change, reset, removeHeader);
		reset.on('click', function (e) {
			e.preventDefault();
			header.val('images/default/header_bg_image.png');
			headerPreview.attr('src', header.data('default-url'));
			headerNone.val('0');
			showHeaderNone(false);
		});
		removeHeader.on('click', function (e) {
			e.preventDefault();
			headerNone.val('1');
			showHeaderNone(true);
		});
		// 「画像を変更」「元の画像に戻す」でプレビューが変わったら、画像なしをやめる（本体の選択は値を変えるだけで change を出さない）
		new MutationObserver(function () {
			headerNone.val('0');
			showHeaderNone(false);
		}).observe(headerPreview[0], { attributes: true, attributeFilter: ['src'] });
	}
});
</script>
		<?php
	}
);
