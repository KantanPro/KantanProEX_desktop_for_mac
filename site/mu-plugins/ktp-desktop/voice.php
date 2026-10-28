<?php
/**
 * 音声操作（KoeType などの音声入力アプリや Mac のショートカットから命令を送る）。段階1: 読み上げと画面を開く（データは変えない）。
 *
 * 既定はオフ。オンにすると Electron（lib/voice.js）が 127.0.0.1:47331 の窓口を立て、PHP の /?ktp_dt_voice=1 へ中継する。
 * 窓口経由の要求は、wp-config.php が起動ごとの合言葉を確かめたときだけ KTP_DESKTOP_VOICE_REQUEST を定義する
 * （LAN 経由とは排他）。合言葉なしで /?ktp_dt_voice=1 を直接叩いても、ここで 403 にする。
 *
 * 入口は JSON {"text": "今日の予定"}（規則ベースで intent+args に直す）か {"intent": "schedule", "args": {"period": "today"}}
 * （呼び出し側が LLM などで解釈した結果をそのまま実行）、{"schema": true} で使える intent の一覧を返す。出口は {"speech": "...", "confirm": null}。
 * 画面を開く命令は、返事に "open": "/?tab_name=list&progress=1" のような相対パスを足す
 * （Electron の lib/voice.js が検査して、ウィンドウを前面に出して読み込む。窓口はクライアントへ返す JSON から open を除く）。
 * "focus": true はウィンドウを前面に出すだけ（今の画面のまま）。
 * 文の解釈は規則ベース（キーワード一致 ktp_dt_voice_parse）で、命令処理は意図ごとの関数に分け、ktp_dt_voice_run_intent() の 1 か所から呼ぶ。
 * DT 版は LLM を呼ばない（外部通信なし）。顧客データも呼び出し側には渡らない（返すのは読み上げる文だけ）。
 * 設定画面の「試し文」も同じ関数を呼ぶ。将来の入口（Alexa など）もここへつなぐ。
 * confirm は段階2（確認を挟む操作）のための欄で、段階1では常に null。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/reports-extra.php'; // ktp_dt_period_range（売上レポートの期間）
require_once __DIR__ . '/lan.php'; // ktp_dt_lan_can_manage / ktp_dt_is_lan

const KTP_DT_VOICE_NONCE = 'ktp_dt_voice';
const KTP_DT_VOICE_PAGE  = 'ktp-dt-voice';
const KTP_DT_VOICE_PORT  = 47331;

/** この要求は、音声窓口を通って来たものか */
function ktp_dt_is_voice() {
	return defined( 'KTP_DESKTOP_VOICE_REQUEST' ) && KTP_DESKTOP_VOICE_REQUEST;
}

/** 窓口の状態（Electron が userData/voice-status.json に書く） */
function ktp_dt_voice_status() {
	$file = defined( 'KTP_DESKTOP_TOKEN_FILE' ) ? dirname( KTP_DESKTOP_TOKEN_FILE ) . '/voice-status.json' : '';
	$raw  = ( $file !== '' && is_readable( $file ) ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	return is_array( $raw ) ? $raw : array( 'enabled' => false );
}

// ---------------------------------------------------------------------------
// 読み上げ向けの整形
// ---------------------------------------------------------------------------

/**
 * 金額を読み上げ向けにする。5万円 / 120万3000円 / 8500円 / 997万6474円。
 * 端数は四捨五入して円にそろえる。億・万だけで区切り、各区切りの中は数字のまま渡す
 * （「1十1円」のように千・百・十で区切ると、Siri が「いちじゅういち」と読んでしまう）。
 */
function ktp_dt_voice_yen( $amount ) {
	$n    = (int) round( (float) $amount );
	$sign = '';
	if ( $n < 0 ) {
		$sign = 'マイナス';
		$n    = -$n;
	}
	if ( 0 === $n ) {
		return '0円';
	}
	$out  = '';
	$oku  = intdiv( $n, 100000000 );
	$man  = intdiv( $n % 100000000, 10000 );
	$rest = $n % 10000;
	if ( $oku > 0 ) {
		$out .= $oku . '億';
	}
	if ( $man > 0 ) {
		$out .= $man . '万';
	}
	if ( $rest > 0 ) {
		$out .= $rest;
	}
	return $sign . $out . '円';
}

/** 読み上げる名前を整える（タグ・改行を除き、長すぎる名前は切る） */
function ktp_dt_voice_clean( $text, $max = 30 ) {
	$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
	return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '…' : $text;
}

// ---------------------------------------------------------------------------
// 命令の中身
// ---------------------------------------------------------------------------

function ktp_dt_voice_help() {
	return '言える命令は、「今日の予定」「未請求」「入金待ち」「今月の売上」「受付中は何件」「受付中の受注を見せて」「〇〇の電話番号」「〇〇さんを開いて」などです。';
}

/** 進捗の番号と呼び名（EX の進捗: 1受付中 2見積中 3受注 4完了 5請求済 6入金済） */
function ktp_dt_voice_progress_names() {
	return array(
		1 => '受付中',
		2 => '見積中',
		3 => '受注',
		4 => '完了',
		5 => '請求済',
		6 => '入金済',
	);
}

/**
 * 画面を開く先（相対パス）。実際の画面のリンクと同じ形:
 *   タブ: ?tab_name=list|client|service|supplier|report（class-ktpwp-view-tab.php のタブ切替）
 *   仕事リストの進捗: ?tab_name=list&progress=N（class-ktpwp-tab-list.php の進捗ボタン）
 *   顧客の表示: ?tab_name=client&data_id=ID（class-ktpwp-client.php の一覧の行リンク）
 */
function ktp_dt_voice_path( array $args ) {
	return '/?' . http_build_query( $args, '', '&' );
}

/**
 * 予定: 納品予定日・納期1〜3 が期間内（WP のタイムゾーン）で、進捗が受付中〜受注（1〜3）の案件。
 * 完了（4）以降はもう納品済みなので数えない。期間は today=今日 / tomorrow=明日 / week=今日〜その週の日曜。
 */
function ktp_dt_voice_schedule( $when = 'today' ) {
	global $wpdb;
	$o     = $wpdb->prefix . 'ktp_order';
	$today = new DateTimeImmutable( 'today', wp_timezone() );
	$label = '今日';
	$from  = $today;
	$to    = $today;
	if ( 'tomorrow' === $when ) {
		$label = '明日';
		$from  = $today->modify( '+1 day' );
		$to    = $from;
	} elseif ( 'week' === $when ) {
		$label = '今週';
		$to    = $today->modify( '+' . ( 7 - (int) $today->format( 'N' ) ) . ' days' ); // N: 月=1〜日=7。日曜なら今日だけ
	}
	$from = $from->format( 'Y-m-d' );
	$to   = $to->format( 'Y-m-d' );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, customer_name, client_company, project_name FROM `{$o}`
			WHERE progress BETWEEN 1 AND 3
			AND ( DATE(expected_delivery_date) BETWEEN %s AND %s OR DATE(delivery_date_1) BETWEEN %s AND %s OR DATE(delivery_date_2) BETWEEN %s AND %s OR DATE(delivery_date_3) BETWEEN %s AND %s )
			ORDER BY id", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$from,
			$to,
			$from,
			$to,
			$from,
			$to,
			$from,
			$to
		)
	);
	$rows  = is_array( $rows ) ? $rows : array();
	$count = count( $rows );
	if ( 0 === $count ) {
		return $label . 'の予定はありません。';
	}
	$speech = $label . 'の予定は' . $count . '件です。' . ktp_dt_voice_order_names( $rows ) . '。';
	if ( $count > 3 ) {
		$speech .= 'ほか' . ( $count - 3 ) . '件です。';
	}
	return $speech;
}

/** 案件の先頭 3 件を「お客様名 案件名、…」にする（予定・進捗ごとの件数で共通） */
function ktp_dt_voice_order_names( array $rows ) {
	$parts = array();
	foreach ( array_slice( $rows, 0, 3 ) as $r ) {
		$client  = ktp_dt_voice_clean( '' !== trim( (string) $r->customer_name ) ? $r->customer_name : $r->client_company );
		$project = ktp_dt_voice_clean( $r->project_name );
		$parts[] = trim( $client . ( '' !== $client && '' !== $project ? ' ' : '' ) . $project );
	}
	return implode( '、', $parts );
}

/** 進捗ごとの案件（仕事リストのその進捗ボタンと同じ条件。ブロック中顧客の案件は本体と同じく除く） */
function ktp_dt_voice_progress_rows( $progress ) {
	global $wpdb;
	$o       = $wpdb->prefix . 'ktp_order';
	$exclude = class_exists( 'KTPWP_Inquiry_Block' ) ? KTPWP_Inquiry_Block::sql_exclude_blocked_client_orders( "{$o}.client_id" ) : '';
	$rows    = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, customer_name, client_company, project_name, total_amount, (SELECT SUM(ii.amount) FROM `{$wpdb->prefix}ktp_order_invoice_items` ii WHERE ii.order_id = {$o}.id) AS items_total
			FROM `{$o}` WHERE progress = %d{$exclude} ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $progress
		)
	);
	return is_array( $rows ) ? $rows : array();
}

/** 案件の金額の合計。請求項目の合計、請求項目がなければ受注の合計金額（未請求・入金待ちで共通） */
function ktp_dt_voice_rows_total( array $rows ) {
	$total = 0.0;
	foreach ( $rows as $r ) {
		$total += ( null !== $r->items_total && (float) $r->items_total > 0 ) ? (float) $r->items_total : (float) $r->total_amount;
	}
	return $total;
}

/** 進捗ごとの件数: 「受付中は3件です。A社 案件、…。ほか2件です。」 */
function ktp_dt_voice_progress_count( $progress ) {
	$names = ktp_dt_voice_progress_names();
	$label = $names[ $progress ];
	$rows  = ktp_dt_voice_progress_rows( $progress );
	$count = count( $rows );
	if ( 0 === $count ) {
		return $label . 'の案件はありません。';
	}
	$speech = $label . 'は' . $count . '件です。' . ktp_dt_voice_order_names( $rows ) . '。';
	if ( $count > 3 ) {
		$speech .= 'ほか' . ( $count - 3 ) . '件です。';
	}
	return $speech;
}

/** 入金待ち: 請求済（progress 5）で、まだ入金済（6）にしていない案件の件数と金額（未請求と同じ金額の取り方） */
function ktp_dt_voice_awaiting_payment() {
	$rows  = ktp_dt_voice_progress_rows( 5 );
	$count = count( $rows );
	if ( 0 === $count ) {
		return '入金待ちの案件はありません。';
	}
	return '入金待ちは' . $count . '件、合わせて' . ktp_dt_voice_yen( ktp_dt_voice_rows_total( $rows ) ) . 'です。';
}

/**
 * 未請求: 完了（progress 4）で、まだ請求済（5）にしていない案件の件数と金額。
 * 請求済にする対象の条件は EX 本体の一括請求（class-ktpwp-ajax.php の progress = 4）と同じ。
 * 金額は請求項目の合計、請求項目がなければ受注の合計金額。
 */
function ktp_dt_voice_unbilled() {
	global $wpdb;
	$o    = $wpdb->prefix . 'ktp_order';
	$i    = $wpdb->prefix . 'ktp_order_invoice_items';
	$rows = $wpdb->get_results(
		"SELECT o.id, o.total_amount, (SELECT SUM(ii.amount) FROM `{$i}` ii WHERE ii.order_id = o.id) AS items_total
		FROM `{$o}` o WHERE o.progress = 4" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
	$rows  = is_array( $rows ) ? $rows : array();
	$count = count( $rows );
	if ( 0 === $count ) {
		return '未請求の案件はありません。';
	}
	$total = 0.0;
	foreach ( $rows as $r ) {
		$total += ( null !== $r->items_total && (float) $r->items_total > 0 ) ? (float) $r->items_total : (float) $r->total_amount;
	}
	return '未請求は' . $count . '件、合わせて' . ktp_dt_voice_yen( $total ) . 'です。';
}

/**
 * 売上: レポートの「総売上」「案件数」と同じ条件。
 * EX 本体 class-ktpwp-tab-report.php の render_sales_summary と同じく、請求済以降（progress >= 5、ボツ 7 は除く）で
 * 完了日（completion_date）が期間内の案件。金額は請求項目（ktp_order_invoice_items.amount）の合計、件数は案件数。
 * 期間の切り方は reports-extra.php の ktp_dt_period_range（WP のタイムゾーン）。本体は MySQL の CURDATE() を使うため、
 * 日付の境目だけは WP の「今日」で揃えている。
 */
function ktp_dt_voice_sales( $period ) {
	global $wpdb;
	$o     = $wpdb->prefix . 'ktp_order';
	$i     = $wpdb->prefix . 'ktp_order_invoice_items';
	$names = array(
		'today'      => '今日',
		'this_month' => '今月',
		'last_month' => '先月',
		'this_year'  => '今年',
		'last_year'  => '昨年',
	);
	if ( 'today' === $period ) {
		$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		$range = array( $today, $today );
	} else {
		$range = ktp_dt_period_range( $period );
	}
	$label = isset( $names[ $period ] ) ? $names[ $period ] : '今月';
	$base  = "o.progress >= 5 AND o.progress != 7 AND o.completion_date IS NOT NULL AND DATE(o.completion_date) >= %s AND DATE(o.completion_date) <= %s";
	$total = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT SUM(ii.amount) FROM `{$o}` o LEFT JOIN `{$i}` ii ON o.id = ii.order_id WHERE ii.amount IS NOT NULL AND {$base}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$range[0],
			$range[1]
		)
	);
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM `{$o}` o WHERE {$base}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$range[0],
			$range[1]
		)
	);
	if ( 0 === $count ) {
		return $label . 'の売上は、まだありません。';
	}
	return $label . 'の売上は' . ktp_dt_voice_yen( (float) $total ) . '、' . $count . '件です。';
}

/** 「開いて」「見せて」など、画面を開く言い方 */
function ktp_dt_voice_has_open_verb( $t ) {
	return (bool) preg_match( '/開い|開け|開く|開き|ひらい|ひらく|見せ|みせ|見たい|みたい|表示|出して|だして|出す/u', $t );
}

/** 「何件」「教えて」など、読み上げを求める言い方（開く言い方と一緒に来たら読み上げを優先する） */
function ktp_dt_voice_has_ask_word( $t ) {
	return (bool) preg_match( '/何件|なんけん|いくつ|教えて|おしえて|読んで|よんで|聞かせて/u', $t );
}

/** 返事 1 件分 */
function ktp_dt_voice_reply( $speech, array $extra = array() ) {
	return array_merge( array( 'speech' => $speech, 'confirm' => null ), $extra );
}

/** 顧客名の部分を取り出す（「山田商事さんを開いて」→「山田商事」）。取り出せなければ空文字 */
function ktp_dt_voice_client_term( $t ) {
	$t = preg_replace( '/[。、，．,.!?！？「」『』]/u', '', $t );
	// 後ろの「を開いて」「の顧客を見せて」など
	$t = preg_replace( '/(の顧客情報|の顧客|の画面|の情報|の詳細|を|の)?(開いて|開け|開く|開き|ひらいて|ひらく|見せて|みせて|見たい|みたい|表示して|表示|出して|だして)(ください|下さい|くれ|ほしい|お願い|おねがい)?(ます|ませ|たい)?.*$/u', '', $t );
	$t = preg_replace( '/(さん|様|さま|殿|の顧客|顧客|を|の)+$/u', '', $t );
	$t = preg_replace( '/^(お客様|お客さん|顧客)/u', '', $t );
	return is_string( $t ) ? trim( $t ) : '';
}

/** 検索用の正規化（空白・全角空白を除く） */
function ktp_dt_voice_norm_sql( $col ) {
	return "REPLACE(REPLACE(`{$col}`, ' ', ''), '　', '')";
}

/**
 * 顧客を会社名・担当者名（name）・代表者名の部分一致で探す。法人格（株式会社など）は付けても付けなくても当たるようにする。
 * 戻り値: 当たった顧客の配列（id, company_name, name）
 */
function ktp_dt_voice_find_clients( $term ) {
	global $wpdb;
	$c      = $wpdb->prefix . 'ktp_client';
	$bare   = preg_replace( '/株式会社|有限会社|合同会社|\(株\)|（株）|㈱|㈲/u', '', $term );
	$bare   = is_string( $bare ) && '' !== trim( $bare ) ? trim( $bare ) : $term;
	$tries  = array_values( array_unique( array( $term, $bare ) ) );
	$exact  = array();
	foreach ( $tries as $try ) {
		$like = '%' . $wpdb->esc_like( $try ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, company_name, name, representative_name, phone, email, postal_code, prefecture, city, address, building FROM `{$c}` WHERE " . ktp_dt_voice_norm_sql( 'company_name' ) . ' LIKE %s OR ' . ktp_dt_voice_norm_sql( 'name' ) . ' LIKE %s OR ' . ktp_dt_voice_norm_sql( 'representative_name' ) . ' LIKE %s ORDER BY id', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$like,
				$like,
				$like
			)
		);
		if ( is_array( $rows ) && $rows ) {
			// 複数当たっても、名前がぴったり同じ 1 件があればそれにする
			foreach ( $rows as $r ) {
				$cn = preg_replace( '/\s+/u', '', (string) $r->company_name );
				$nm = preg_replace( '/\s+/u', '', (string) $r->name );
				$rp = preg_replace( '/\s+/u', '', (string) $r->representative_name );
				if ( $cn === $try || $nm === $try || $rp === $try ) {
					$exact[] = $r;
				}
			}
			return 1 === count( $exact ) ? $exact : $rows;
		}
	}
	return array();
}

/** 顧客の呼び名（会社名 担当者名） */
function ktp_dt_voice_client_label( $r ) {
	$company = ktp_dt_voice_clean( $r->company_name );
	$person  = ktp_dt_voice_clean( $r->name );
	return trim( $company . ( '' !== $company && '' !== $person ? ' ' : '' ) . $person );
}

/** 顧客を 1 件に絞る。戻り値 [ 顧客 or null, 絞れなかったときの返事 or null ]（0 件・複数件は返事を作る） */
function ktp_dt_voice_pick_client( $name ) {
	$name = trim( (string) $name );
	$rows = '' === $name ? array() : ktp_dt_voice_find_clients( $name );
	$n    = count( $rows );
	if ( 1 === $n ) {
		return array( $rows[0], null );
	}
	$shown = '「' . ktp_dt_voice_clean( $name ) . '」';
	if ( 0 === $n ) {
		return array( null, ktp_dt_voice_reply( $shown . 'は見つかりませんでした。' ) );
	}
	$names = array();
	foreach ( array_slice( $rows, 0, 3 ) as $r ) {
		$names[] = ktp_dt_voice_client_label( $r );
	}
	$speech = $shown . 'に当てはまる顧客が' . $n . '件あります。' . implode( '、', $names ) . '。';
	if ( $n > 3 ) {
		$speech .= 'ほか' . ( $n - 3 ) . '件です。';
	}
	return array( null, ktp_dt_voice_reply( $speech . 'もう少し詳しく言ってください。' ) );
}

// ---------------------------------------------------------------------------
// 意図（intent）ごとの命令。入口は ktp_dt_voice_run_intent() の 1 か所
// ---------------------------------------------------------------------------

/** 状態名（呼び出し側が使う英語）→ 進捗の番号 */
function ktp_dt_voice_status_map() {
	return array(
		'received'   => 1,
		'estimating' => 2,
		'ordered'    => 3,
		'completed'  => 4,
		'invoiced'   => 5,
		'paid'       => 6,
	);
}

/**
 * 使える意図の一覧（説明つき）。呼び出し側（KoeType のスクリプト）が LLM のツール定義に変換して使う。
 * 引数は type（string）、enum（許可値）、required、max（文字列の長さの上限）で検証する（ktp_dt_voice_run_intent）。
 */
function ktp_dt_voice_intents() {
	$status = array( 'received', 'estimating', 'ordered', 'completed', 'invoiced', 'paid' );
	$status_doc = '進捗。received=受付中、estimating=見積中、ordered=受注、completed=完了（まだ請求していない）、invoiced=請求済（入金待ち）、paid=入金済';
	return array(
		'schedule'         => array(
			'description' => '納期が来る案件（受付中〜受注のもの）の予定を、件数と先頭3件のお客様名・案件名で答える。「今日の予定」「明日の予定」「今週の予定」。',
			'args'        => array( 'period' => array( 'type' => 'string', 'enum' => array( 'today', 'tomorrow', 'this_week' ), 'required' => true, 'description' => '期間。today=今日、tomorrow=明日、this_week=今日からその週の日曜まで' ) ),
		),
		'sales'            => array(
			'description' => '売上（売上レポートの総売上と案件数と同じ条件）を答える。「今月の売上」「先月いくら売れた」。',
			'args'        => array( 'period' => array( 'type' => 'string', 'enum' => array( 'today', 'this_month', 'last_month', 'this_year', 'last_year' ), 'required' => true, 'description' => '期間。今日／今月／先月／今年／昨年' ) ),
		),
		'unbilled'         => array(
			'description' => '未請求（仕事が完了していて、まだ請求済にしていない案件）の件数と合計金額を答える。「未請求はいくら」。',
			'args'        => array(),
		),
		'unpaid'           => array(
			'description' => '入金待ち（請求済で、まだ入金済にしていない案件）の件数と合計金額を答える。「入金待ち」「未入金」。',
			'args'        => array(),
		),
		'orders_by_status' => array(
			'description' => '進捗ごとの受注の件数と、先頭3件のお客様名・案件名を答える（画面は開かない）。「受付中は何件」「見積中の案件を教えて」。',
			'args'        => array( 'status' => array( 'type' => 'string', 'enum' => $status, 'required' => true, 'description' => $status_doc ) ),
		),
		'client_info'      => array(
			'description' => '顧客（会社名・担当者名・代表者名の部分一致）の連絡先などを読み上げる。「松本小学校の電話番号」「山田商事の住所」。1件に絞れたときだけ項目を答え、複数なら候補を3件まで読む。',
			'args'        => array(
				'name'  => array( 'type' => 'string', 'required' => true, 'max' => 100, 'description' => '顧客の名前（会社名・担当者名・代表者名の一部でよい。「さん」「様」は付けない）' ),
				'field' => array( 'type' => 'string', 'enum' => array( 'phone', 'address', 'email', 'contact', 'all' ), 'required' => false, 'description' => '知りたい項目。phone=電話番号、address=住所、email=メールアドレス、contact=担当者・代表者、all=まとめて。省略は all' ),
			),
		),
		'client_orders'    => array(
			'description' => 'ある顧客の進行中（受付中〜完了）の受注の件数と、先頭3件の案件名・進捗を答える。「山田商事の案件は」。',
			'args'        => array( 'name' => array( 'type' => 'string', 'required' => true, 'max' => 100, 'description' => '顧客の名前（会社名・担当者名・代表者名の一部でよい）' ) ),
		),
		'open_screen'      => array(
			'description' => 'アプリの画面を開く（アプリを前面に出して切り替える）。「アプリを開いて」「受付中の受注を見せて」「レポートを開いて」。',
			'args'        => array(
				'screen' => array( 'type' => 'string', 'enum' => array( 'app', 'orders', 'clients', 'services', 'suppliers', 'report' ), 'required' => true, 'description' => '開く画面。app=今の画面のままアプリを前面へ、orders=仕事リスト（受注一覧）、clients=顧客、services=サービス、suppliers=協力会社、report=レポート' ),
				'status' => array( 'type' => 'string', 'enum' => $status, 'required' => false, 'description' => 'orders のときだけ。絞り込む進捗（' . $status_doc . '）' ),
			),
		),
		'open_client'      => array(
			'description' => '顧客の画面を開く。「山田商事を開いて」。1件に絞れたときだけ開き、複数なら候補を読むだけで開かない。',
			'args'        => array( 'name' => array( 'type' => 'string', 'required' => true, 'max' => 100, 'description' => '顧客の名前（会社名・担当者名・代表者名の一部でよい）' ) ),
		),
		'help'             => array(
			'description' => '言える命令の例を読み上げる。「ヘルプ」「何ができる」。',
			'args'        => array(),
		),
	);
}

/** schema の返事（呼び出し側が LLM のツール定義にする。input_schema は JSON Schema の形） */
function ktp_dt_voice_schema() {
	$out = array();
	foreach ( ktp_dt_voice_intents() as $name => $def ) {
		$args  = array();
		$props = array();
		$req   = array();
		foreach ( $def['args'] as $an => $ad ) {
			$args[] = array_merge( array( 'name' => $an ), $ad );
			$prop   = array( 'type' => $ad['type'], 'description' => $ad['description'] );
			if ( isset( $ad['enum'] ) ) {
				$prop['enum'] = $ad['enum'];
			}
			if ( isset( $ad['max'] ) ) {
				$prop['maxLength'] = $ad['max'];
			}
			$props[ $an ] = $prop;
			if ( ! empty( $ad['required'] ) ) {
				$req[] = $an;
			}
		}
		$out[] = array(
			'name'         => $name,
			'description'  => $def['description'],
			'args'         => $args,
			'input_schema' => array( 'type' => 'object', 'properties' => (object) $props, 'required' => $req, 'additionalProperties' => false ),
		);
	}
	return $out;
}

/** 引数の検証。戻り値: 検証済みの引数 or null（不正） */
function ktp_dt_voice_check_args( array $defs, $args ) {
	if ( null === $args ) {
		$args = array();
	}
	if ( ! is_array( $args ) || ( $args && array_keys( $args ) === range( 0, count( $args ) - 1 ) ) ) {
		return null; // オブジェクトでない
	}
	$ok = array();
	foreach ( $defs as $name => $d ) {
		if ( ! array_key_exists( $name, $args ) || null === $args[ $name ] || '' === $args[ $name ] ) {
			if ( ! empty( $d['required'] ) ) {
				return null;
			}
			continue;
		}
		$v = $args[ $name ];
		if ( 'string' !== $d['type'] || ! is_string( $v ) ) {
			return null;
		}
		$v = trim( preg_replace( '/[\x00-\x1f\x7f]/u', '', $v ) );
		if ( '' === $v || mb_strlen( $v ) > ( $d['max'] ?? 50 ) ) {
			return null;
		}
		if ( isset( $d['enum'] ) && ! in_array( $v, $d['enum'], true ) ) {
			return null;
		}
		$ok[ $name ] = $v;
	}
	return $ok; // 定義にない引数は捨てる
}

/** 意図と引数から答える。不明な意図・不正な引数でも例外は出さず、わかりませんでした系の返事にする */
function ktp_dt_voice_run_intent( $intent, $args = array() ) {
	$intents = ktp_dt_voice_intents();
	if ( ! is_string( $intent ) || ! isset( $intents[ $intent ] ) ) {
		return ktp_dt_voice_reply( 'すみません、わかりませんでした。' . ktp_dt_voice_help() );
	}
	$a = ktp_dt_voice_check_args( $intents[ $intent ]['args'], $args );
	if ( null === $a ) {
		return ktp_dt_voice_reply( 'すみません、命令の内容がわかりませんでした。' . ktp_dt_voice_help() );
	}
	$fn = 'ktp_dt_voice_intent_' . $intent;
	return $fn( $a );
}

function ktp_dt_voice_intent_help( $a ) {
	return ktp_dt_voice_reply( ktp_dt_voice_help() );
}

function ktp_dt_voice_intent_schedule( $a ) {
	$map = array( 'today' => 'today', 'tomorrow' => 'tomorrow', 'this_week' => 'week' );
	return ktp_dt_voice_reply( ktp_dt_voice_schedule( $map[ $a['period'] ] ) );
}

function ktp_dt_voice_intent_sales( $a ) {
	return ktp_dt_voice_reply( ktp_dt_voice_sales( $a['period'] ) );
}

function ktp_dt_voice_intent_unbilled( $a ) {
	return ktp_dt_voice_reply( ktp_dt_voice_unbilled() );
}

function ktp_dt_voice_intent_unpaid( $a ) {
	return ktp_dt_voice_reply( ktp_dt_voice_awaiting_payment() );
}

function ktp_dt_voice_intent_orders_by_status( $a ) {
	$map = ktp_dt_voice_status_map();
	return ktp_dt_voice_reply( ktp_dt_voice_progress_count( $map[ $a['status'] ] ) );
}

function ktp_dt_voice_intent_open_screen( $a ) {
	if ( 'app' === $a['screen'] ) {
		return ktp_dt_voice_reply( '開きました。', array( 'focus' => true ) );
	}
	if ( 'orders' === $a['screen'] ) {
		if ( isset( $a['status'] ) ) {
			$progress = ktp_dt_voice_status_map()[ $a['status'] ];
			$names    = ktp_dt_voice_progress_names();
			$n        = count( ktp_dt_voice_progress_rows( $progress ) );
			return ktp_dt_voice_reply( ( 3 === $progress ? '受注' : $names[ $progress ] . 'の受注' ) . 'は' . $n . '件です。一覧を開きました。', array( 'open' => ktp_dt_voice_path( array( 'tab_name' => 'list', 'progress' => $progress ) ) ) );
		}
		return ktp_dt_voice_reply( '仕事リストを開きました。', array( 'open' => ktp_dt_voice_path( array( 'tab_name' => 'list' ) ) ) );
	}
	$tabs = array(
		'clients'   => array( 'client', '顧客' ),
		'services'  => array( 'service', 'サービス' ),
		'suppliers' => array( 'supplier', '協力会社' ),
		'report'    => array( 'report', 'レポート' ),
	);
	$tab = $tabs[ $a['screen'] ];
	return ktp_dt_voice_reply( $tab[1] . 'を開きました。', array( 'open' => ktp_dt_voice_path( array( 'tab_name' => $tab[0] ) ) ) );
}

function ktp_dt_voice_intent_open_client( $a ) {
	list( $client, $reply ) = ktp_dt_voice_pick_client( $a['name'] );
	if ( ! $client ) {
		return $reply;
	}
	return ktp_dt_voice_reply( ktp_dt_voice_client_label( $client ) . 'を開きました。', array( 'open' => ktp_dt_voice_path( array( 'tab_name' => 'client', 'data_id' => (int) $client->id ) ) ) );
}

/** 電話番号などを読み上げ向けに整える（タグ・改行を除く。番号はそのまま） */
function ktp_dt_voice_client_info_part( $client, $field ) {
	$one = function ( $v ) {
		return trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );
	};
	switch ( $field ) {
		case 'phone':
			return array( '電話番号', $one( $client->phone ) );
		case 'email':
			return array( 'メールアドレス', $one( $client->email ) );
		case 'address':
			$addr = $one( $client->prefecture . $client->city . $client->address . ' ' . $client->building );
			return array( '住所', $addr );
		default: // contact
			$parts = array_filter( array( $one( $client->name ), '' !== $one( $client->representative_name ) ? '代表者' . $one( $client->representative_name ) : '' ), 'strlen' );
			return array( '担当者', implode( '、', $parts ) );
	}
}

function ktp_dt_voice_intent_client_info( $a ) {
	list( $client, $reply ) = ktp_dt_voice_pick_client( $a['name'] );
	if ( ! $client ) {
		return $reply;
	}
	$label = ktp_dt_voice_client_label( $client );
	$field = $a['field'] ?? 'all';
	if ( 'all' !== $field ) {
		list( $title, $value ) = ktp_dt_voice_client_info_part( $client, $field );
		return ktp_dt_voice_reply( '' === $value ? $label . 'の' . $title . 'は登録されていません。' : $label . 'の' . $title . 'は、' . $value . 'です。' );
	}
	$speech = $label . '。';
	foreach ( array( 'phone', 'address', 'email', 'contact' ) as $f ) {
		list( $title, $value ) = ktp_dt_voice_client_info_part( $client, $f );
		if ( '' !== $value ) {
			$speech .= $title . '、' . $value . '。';
		}
	}
	return ktp_dt_voice_reply( $speech );
}

function ktp_dt_voice_intent_client_orders( $a ) {
	global $wpdb;
	list( $client, $reply ) = ktp_dt_voice_pick_client( $a['name'] );
	if ( ! $client ) {
		return $reply;
	}
	$o     = $wpdb->prefix . 'ktp_order';
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, project_name, progress FROM `{$o}` WHERE client_id = %d AND progress BETWEEN 1 AND 4 ORDER BY id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			(int) $client->id
		)
	);
	$rows  = is_array( $rows ) ? $rows : array();
	$label = ktp_dt_voice_client_label( $client );
	$count = count( $rows );
	if ( 0 === $count ) {
		return ktp_dt_voice_reply( $label . 'の進行中の案件はありません。' );
	}
	$names = ktp_dt_voice_progress_names();
	$parts = array();
	foreach ( array_slice( $rows, 0, 3 ) as $r ) {
		$project = ktp_dt_voice_clean( $r->project_name );
		$parts[] = ( '' !== $project ? $project : '案件名なし' ) . '、' . ( $names[ (int) $r->progress ] ?? '' );
	}
	$speech = $label . 'の進行中の案件は' . $count . '件です。' . implode( '。', $parts ) . '。';
	if ( $count > 3 ) {
		$speech .= 'ほか' . ( $count - 3 ) . '件です。';
	}
	return ktp_dt_voice_reply( $speech );
}

// ---------------------------------------------------------------------------
// 話した文（text）→ 意図と引数（規則ベース。LLM を使う呼び出し側は intent を直接送る）
// ---------------------------------------------------------------------------

/** 話した文を [ 意図, 引数 ] に直す。解釈できなければ null */
function ktp_dt_voice_parse( $text ) {
	// 全角の英数字・スペースをそろえ、空白は除く（「今日の 予定」「ヘルプ」どちらも拾う）
	$t = preg_replace( '/\s+/u', '', mb_convert_kana( (string) $text, 'asKV' ) );
	$t = is_string( $t ) ? $t : '';
	$open = ktp_dt_voice_has_open_verb( $t ) && ! ktp_dt_voice_has_ask_word( $t );
	$has_open = ktp_dt_voice_has_open_verb( $t );
	$statuses = array_flip( ktp_dt_voice_status_map() ); // 進捗の番号 → 状態名

	if ( '' === $t || preg_match( '/ヘルプ|へるぷ|使い方|つかいかた|何ができ|なにができ|help/iu', $t ) ) {
		return array( 'help', array() );
	}
	// 「〇〇の電話番号」「〇〇の住所」: 項目の語があり、その前に名前が残るとき
	if ( preg_match( '/^(.+?)の?(電話番号|電話|でんわ|TEL|住所|じゅうしょ|メールアドレス|メアド|メール|担当者|担当|代表者)(?:は|を|って|教えて|おしえて|何|なに|ですか|は?\?)*/iu', $t, $m ) && '' !== trim( ktp_dt_voice_client_term( $m[1] ) ) && mb_strlen( $m[1] ) <= 40 ) {
		$field = 'all';
		if ( preg_match( '/電話|でんわ|TEL/iu', $m[2] ) ) {
			$field = 'phone';
		} elseif ( preg_match( '/住所|じゅうしょ/u', $m[2] ) ) {
			$field = 'address';
		} elseif ( preg_match( '/メール|メアド/u', $m[2] ) ) {
			$field = 'email';
		} else {
			$field = 'contact';
		}
		return array( 'client_info', array( 'name' => ktp_dt_voice_client_term( $m[1] ), 'field' => $field ) );
	}
	if ( preg_match( '/未請求|みせいきゅう/u', $t ) ) {
		return $open ? array( 'open_screen', array( 'screen' => 'orders', 'status' => 'completed' ) ) : array( 'unbilled', array() );
	}
	if ( preg_match( '/売上|売り上げ|うりあげ/u', $t ) ) {
		$period = 'this_month';
		if ( preg_match( '/今日|本日|きょう/u', $t ) ) {
			$period = 'today';
		} elseif ( preg_match( '/先月|せんげつ|前月/u', $t ) ) {
			$period = 'last_month';
		} elseif ( preg_match( '/昨年|去年|前年|きょねん/u', $t ) ) {
			$period = 'last_year';
		} elseif ( preg_match( '/今年|ことし|本年/u', $t ) ) {
			$period = 'this_year';
		}
		return array( 'sales', array( 'period' => $period ) );
	}
	if ( preg_match( '/予定|よてい|スケジュール/u', $t ) ) {
		$period = 'today';
		if ( preg_match( '/明日|あした|あす/u', $t ) ) {
			$period = 'tomorrow';
		} elseif ( preg_match( '/今週|こんしゅう/u', $t ) ) {
			$period = 'this_week';
		}
		return array( 'schedule', array( 'period' => $period ) );
	}
	if ( preg_match( '/入金待ち|入金まち|未入金|みにゅうきん/u', $t ) ) {
		return $open ? array( 'open_screen', array( 'screen' => 'orders', 'status' => 'invoiced' ) ) : array( 'unpaid', array() );
	}

	// ここから先は、固定の語（アプリ・タブ・進捗）を先に判定し、どれでもなければ顧客名として探す
	if ( $has_open && preg_match( '/仕事リスト|しごとリスト|案件一覧|受注一覧|受注リスト/u', $t ) ) {
		return array( 'open_screen', array( 'screen' => 'orders' ) );
	}
	$term = ktp_dt_voice_client_term( $t );
	$tabs = array(
		array( '/協力会社|仕入先|外注先|きょうりょくがいしゃ/u', 'suppliers' ),
		array( '/サービス|商品/u', 'services' ),
		array( '/レポート|報告|集計/u', 'report' ),
	);
	// 「顧客を開いて」はタブ。「山田商事を開いて」「山田商事の顧客を開いて」は顧客名。タブの語しか残らないときだけタブにする
	foreach ( $tabs as $tab ) {
		if ( $has_open && preg_match( $tab[0], $t ) && '' === trim( preg_replace( $tab[0], '', $term ) ) ) {
			return array( 'open_screen', array( 'screen' => $tab[1] ) );
		}
	}
	if ( $has_open && ( '' === $term || preg_match( '/^(顧客|お客様|お客さん|顧客一覧|顧客リスト|顧客タブ|顧客管理)$/u', $term ) ) && preg_match( '/顧客|お客様|お客さん|こきゃく/u', $t ) ) {
		return array( 'open_screen', array( 'screen' => 'clients' ) );
	}
	// 進捗（受付中・見積中・受注・完了・請求済・入金済）。「入金済み」は請求済ではなく入金済
	$progress = 0;
	if ( preg_match( '/受付中|受付|うけつけ/u', $t ) ) {
		$progress = 1;
	} elseif ( preg_match( '/見積中|見積もり中|見積り中|見積|みつもり/u', $t ) ) {
		$progress = 2;
	} elseif ( preg_match( '/入金済|入金ずみ/u', $t ) ) {
		$progress = 6;
	} elseif ( preg_match( '/請求済|請求ずみ/u', $t ) ) {
		$progress = 5;
	} elseif ( preg_match( '/完了|かんりょう/u', $t ) ) {
		$progress = 4;
	} elseif ( preg_match( '/受注|じゅちゅう/u', $t ) ) {
		$progress = 3;
	}
	// 顧客名つきの「〇〇の受注を見せて」を進捗と取り違えないよう、進捗の語を除いて何も残らないときだけ進捗にする
	if ( $progress > 0 ) {
		$rest = preg_replace( '/受付中|受付|うけつけ|見積もり中|見積り中|見積中|見積|みつもり|入金済み|入金済|入金ずみ|請求済み|請求済|請求ずみ|完了|かんりょう|受注|じゅちゅう|案件|一覧|リスト|仕事|の|は|を|も|何件|なんけん|いくつ|教えて|おしえて|読んで|よんで|聞かせて|です|ますか|か|ある|あります|件|数/u', '', preg_replace( '/[。、，．,.!?！？]/u', '', $has_open ? $term : $t ) );
		if ( '' === trim( (string) $rest ) ) {
			return $open ? array( 'open_screen', array( 'screen' => 'orders', 'status' => $statuses[ $progress ] ) ) : array( 'orders_by_status', array( 'status' => $statuses[ $progress ] ) );
		}
	}
	if ( $has_open ) {
		// アプリ自体を前面に出す（今の画面のまま）
		if ( '' === trim( preg_replace( '/アプリ|あぷり|カンタンプロ|かんたんぷろ|KantanProEX|KantanPro|画面|がめん|ウィンドウ|窓|の|を|も/iu', '', $term ) ) ) {
			return array( 'open_screen', array( 'screen' => 'app' ) );
		}
		return array( 'open_client', array( 'name' => $term ) );
	}
	return null;
}

/** 話した文に答える（設定画面の試し文、text だけが来た窓口の要求） */
function ktp_dt_voice_handle( $text ) {
	$parsed = ktp_dt_voice_parse( $text );
	if ( ! $parsed ) {
		return ktp_dt_voice_reply( 'すみません、わかりませんでした。' . ktp_dt_voice_help() );
	}
	return ktp_dt_voice_run_intent( $parsed[0], $parsed[1] );
}

// ---------------------------------------------------------------------------
// 窓口からの要求
// ---------------------------------------------------------------------------

function ktp_dt_voice_json( $code, array $body ) {
	status_header( $code );
	nocache_headers();
	header( 'Content-Type: application/json; charset=utf-8' );
	echo wp_json_encode( $body, JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * /?ktp_dt_voice=1 の処理。音声窓口の合言葉が合った要求（KTP_DESKTOP_VOICE_REQUEST）だけ受け、それ以外は 403。
 * 自動ログインと同じく管理者として実行する（Cookie は発行しない。この要求の中だけ）。
 */
add_action(
	'init',
	function () {
		if ( ! isset( $_GET['ktp_dt_voice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! ktp_dt_is_voice() || ktp_dt_is_lan() ) {
			ktp_dt_voice_json( 403, array( 'speech' => 'この要求は受け付けられません。', 'confirm' => null ) );
		}
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			ktp_dt_voice_json( 405, array( 'speech' => 'POST で送ってください。', 'confirm' => null ) );
		}
		$raw  = file_get_contents( 'php://input', false, null, 0, 8193 );
		$data = is_string( $raw ) && strlen( $raw ) <= 8192 ? json_decode( $raw, true ) : null;
		$is_schema = is_array( $data ) && isset( $data['schema'] ) && true === $data['schema'];
		$is_intent = is_array( $data ) && isset( $data['intent'] );
		$is_text   = is_array( $data ) && isset( $data['text'] ) && is_string( $data['text'] );
		if ( ! $is_schema && ! $is_intent && ! $is_text ) {
			ktp_dt_voice_json( 400, array( 'speech' => '話した内容を受け取れませんでした。', 'confirm' => null ) );
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID' ) );
		if ( ! $admins ) {
			ktp_dt_voice_json( 500, array( 'speech' => '管理者が見つかりません。', 'confirm' => null ) );
		}
		wp_set_current_user( $admins[0]->ID );
		if ( $is_schema ) {
			ktp_dt_voice_json( 200, array( 'intents' => ktp_dt_voice_schema() ) );
		}
		// intent があれば（LLM などで解釈した呼び出し側から）そのまま実行。text だけなら規則ベースで直してから同じ関数へ
		if ( $is_intent ) {
			ktp_dt_voice_json( 200, ktp_dt_voice_run_intent( $data['intent'], $data['args'] ?? array() ) );
		}
		ktp_dt_voice_json( 200, ktp_dt_voice_handle( $data['text'] ) );
	},
	5
);

// ---------------------------------------------------------------------------
// 設定画面（設定メニューの下の「音声操作」）
// ---------------------------------------------------------------------------

/** 試し文: 画面上で同じ命令処理を呼ぶ（通常の管理画面の要求。nonce と親機の管理者が必要） */
add_action(
	'wp_ajax_ktp_dt_voice_try',
	function () {
		check_ajax_referer( KTP_DT_VOICE_NONCE, 'nonce' );
		if ( ! ktp_dt_lan_can_manage() ) {
			wp_send_json_error( array( 'message' => '親機の画面でオーナーだけが操作できます。' ), 403 );
		}
		$res = ktp_dt_voice_handle( isset( $_POST['text'] ) ? (string) wp_unslash( $_POST['text'] ) : '' );
		wp_send_json_success( array( 'speech' => $res['speech'] ) );
	}
);

function ktp_dt_voice_page_html() {
	$st     = ktp_dt_voice_status();
	$on     = ! empty( $st['enabled'] );
	$toggle = home_url( '/?ktp_desktop_voice=' . ( $on ? 'off' : 'on' ) );
	$port   = (int) ( $st['port'] ?? KTP_DT_VOICE_PORT );
	ob_start();
	?>
<div id="ktp-dt-voice" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( KTP_DT_VOICE_NONCE ) ); ?>" style="max-width:760px;margin:0 auto;font-size:14px;line-height:1.7;">
	<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>">← アプリに戻る</a></p>
	<h2 style="margin:6px 0 12px;">音声操作</h2>
	<p style="color:#6b7280;font-size:13px;">KoeType などの音声入力アプリや、Mac の「ショートカット」から命令を送れます。「今日の予定」「未請求」「今月の売上」などを聞いたり、画面を開いたりできます。この Mac の中だけで動き、インターネットや LAN には出しません（待ち受けるのは 127.0.0.1 の <?php echo (int) KTP_DT_VOICE_PORT; ?> 番だけです）。アプリを起動している間だけ使えます。</p>

	<div style="border:1px solid <?php echo $on ? '#86efac' : '#d1d5db'; ?>;background:<?php echo $on ? '#f0fdf4' : '#f9fafb'; ?>;padding:12px 16px;border-radius:6px;margin:12px 0;">
		<p style="margin:0 0 6px;font-weight:700;">現在: <?php echo $on ? '待ち受け中（127.0.0.1:' . (int) $port . '）' : 'オフ'; ?></p>
		<?php if ( ! empty( $st['error'] ) ) : ?>
			<p style="color:#b91c1c;margin:0 0 6px;">開始できませんでした: <?php echo esc_html( (string) $st['error'] ); ?></p>
		<?php endif; ?>
		<p style="margin:6px 0 0;"><a href="<?php echo esc_url( $toggle ); ?>" style="display:inline-block;background:<?php echo $on ? '#6b7280' : '#2563eb'; ?>;color:#fff;padding:6px 18px;border-radius:4px;text-decoration:none;"><?php echo $on ? '音声操作をオフにする' : '音声操作をオンにする'; ?></a></p>
	</div>

	<h3 style="margin:18px 0 6px;">言える命令</h3>
	<p style="margin:0 0 4px;font-weight:700;">聞く（読み上げます）</p>
	<ul style="margin:0 0 8px 20px;">
		<li>「今日の予定」「明日の予定」「今週の予定」: 納期が来る案件（件数と、3 件までのお客様名・案件名）</li>
		<li>「未請求」: 完了していて、まだ請求済にしていない案件の件数と金額</li>
		<li>「入金待ち」「未入金」: 請求済で、まだ入金済にしていない案件の件数と金額</li>
		<li>「今日の売上」「今月の売上」「先月の売上」「今年の売上」「昨年の売上」: 売上レポートと同じ条件の売上と件数（期間なしは今月）</li>
		<li>「受付中は何件」「見積中は？」「受注」「完了」「請求済み」「入金済み」: 進捗ごとの件数と、3 件までの案件</li>
		<li>「〇〇の電話番号」「〇〇の住所」「〇〇のメール」「〇〇の担当者」: 顧客（会社名・担当者名・代表者名の一部）の連絡先。名前が複数に当てはまるときは候補を読むだけです</li>
		<li>「ヘルプ」: 言える命令の例</li>
	</ul>
	<p style="margin:0 0 4px;font-weight:700;">開く（アプリを前に出して、その画面に切り替えます）</p>
	<ul style="margin:0 0 12px 20px;">
		<li>「アプリを開いて」「カンタンプロを開いて」: 今の画面のまま、アプリを前に出す</li>
		<li>「受付中の受注を見せて」「見積中を開いて」「請求済みを見せて」など: 仕事リストをその進捗で絞って開く</li>
		<li>「顧客を開いて」「サービスを開いて」「協力会社を開いて」「レポートを開いて」: 各タブ</li>
		<li>「〇〇を開いて」「〇〇さんを開いて」: 顧客を名前で探し、1 件ならその顧客の画面を開く（複数なら候補を読むだけ、0 件なら「見つかりませんでした」）</li>
	</ul>
	<p style="color:#6b7280;font-size:12px;">自然な言い方は、呼び出し側の音声入力アプリが命令の種類（intent）に直してから送ることもできます（<code>{"intent":"schedule","args":{"period":"today"}}</code> の形。<code>{"schema":true}</code> で使える一覧が返ります）。このアプリ自体は、外部の AI や通信を使いません。</p>

	<h3 style="margin:18px 0 6px;">KoeType で使う（おすすめ）</h3>
	<ol style="margin:0 0 8px 20px;">
		<li>音声入力アプリ <a href="https://www.kantanpro.com/?p=19035" target="_blank" rel="noopener">KoeType</a>（1.0.3 以降・Mac）を入れ、メニューバーの 🎙 から「KantanPro 連携（「へいカンタンプロ、〜」）」にチェックを入れます。</li>
		<li>このページの「音声操作をオンにする」を押します。</li>
		<li>KoeType の録音キーを押しながら「へいカンタンプロ、今月の売上」のように話すと、答えを読み上げます。「松本小学校の電話番号」「見積もり出してるのって何件あったっけ」「受付中の受注を見せて」のような、ふだんの言い方でも通じます。</li>
	</ol>
	<p style="color:#6b7280;font-size:12px;">ふだんの言い方を命令に直すのは KoeType 側の AI です。AI に送るのは話した文だけで、このアプリのデータ（顧客の連絡先や金額など）は送りません。答えはこのアプリの中で作り、この Mac の中だけでやり取りします。</p>

	<h3 style="margin:18px 0 6px;">Mac の「ショートカット」から使う（その他）</h3>
	<ol style="margin:0 0 12px 20px;">
		<li>Mac の「ショートカット」アプリで新規ショートカットを作り、名前を「カンタンプロ」にします（この名前を Siri に言います）。</li>
		<li>アクション「テキストを要求」を追加します（質問は「何をしますか？」など）。</li>
		<li>アクション「URL の内容を取得」を追加し、URL に <code>http://127.0.0.1:<?php echo (int) KTP_DT_VOICE_PORT; ?>/voice</code> を入れて、詳細で次のように設定します。
			<ul style="margin:2px 0 2px 18px;">
				<li>メソッド: <code>POST</code></li>
				<li>ヘッダー: <code>X-KTP-Voice</code> = <code>1</code></li>
				<li>本文を要求: <code>JSON</code>、フィールド <code>text</code>（テキスト）に「テキストを要求」の結果を入れます</li>
			</ul>
		</li>
		<li>アクション「辞書の値を取得」を追加し、キーに <code>speech</code> を入れます。</li>
		<li>アクション「テキストを読み上げる」を追加し、上の結果を読み上げます。</li>
		<li>Siri に「カンタンプロ」と言い、聞かれたら「今日の予定」と話します（Siri を使わなくても、ショートカットのアプリから実行できます）。</li>
	</ol>
	<p style="color:#6b7280;font-size:12px;">命令を増やしても、ショートカットは作り直さなくて大丈夫です（話した文はそのままアプリに送り、解釈はアプリがします）。Windows には Siri がないため、ショートカットでの利用は Mac だけです。</p>

	<h3 style="margin:18px 0 6px;">試してみる</h3>
	<p>
		<input type="text" id="voice-try-text" value="今日の予定" style="padding:4px;width:300px;max-width:100%;">
		<button type="button" id="voice-try-go" style="padding:3px 12px;">試す</button>
	</p>
	<p id="voice-try-result" role="status" style="min-height:1.7em;margin:0 0 12px;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb;color:#1f2937;">（ここに返事が出ます）</p>
	<p style="color:#6b7280;font-size:12px;">この試し文は、オン・オフに関わらず使えます（画面の中で同じ命令処理を呼ぶだけです）。</p>

	<h3 style="margin:18px 0 6px;">安全のために</h3>
	<ul style="margin:0 0 12px 20px;">
		<li>受け付けるのは、この Mac 上のアプリからの <code>POST</code> だけです。Web ページ（ブラウザ）からは呼べません。</li>
		<li>いまの段階では、聞く・画面を開くだけです。データの追加・変更・メール送信はしません。</li>
		<li>ポート <?php echo (int) KTP_DT_VOICE_PORT; ?> が他のアプリで使われているときは起動できません。上の「現在」にエラーが出ます。</li>
	</ul>
</div>
<script>
(function(){
	var w=document.getElementById('ktp-dt-voice'),out=document.getElementById('voice-try-result'),inp=document.getElementById('voice-try-text');
	function go(){
		out.textContent='確認しています…';
		var d=new URLSearchParams({action:'ktp_dt_voice_try',nonce:w.dataset.nonce,text:inp.value});
		fetch(w.dataset.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:d.toString()}).then(function(r){return r.json();}).then(function(j){
			out.style.color=j.success?'#1f2937':'#b91c1c';
			out.textContent=j.success?j.data.speech:((j.data&&j.data.message)||'できませんでした。');
		}).catch(function(){out.style.color='#b91c1c';out.textContent='できませんでした。';});
	}
	document.getElementById('voice-try-go').onclick=go;
	inp.onkeydown=function(e){if(e.key==='Enter'){e.preventDefault();go();}};
})();
</script>
	<?php
	return (string) ob_get_clean();
}

/** サイドバーの設定メニュー（KantanProEX 設定）の下、LAN 共有の次に置く。LAN から来た要求には出さない */
add_action(
	'admin_menu',
	function () {
		if ( ktp_dt_is_lan() ) {
			return;
		}
		add_submenu_page(
			'ktp-settings',
			'音声操作',
			'音声操作',
			'manage_options',
			KTP_DT_VOICE_PAGE,
			function () {
				if ( ! ktp_dt_lan_can_manage() ) {
					return;
				}
				echo '<div class="wrap">' . ktp_dt_voice_page_html() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);
	},
	PHP_INT_MAX
);
