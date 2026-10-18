<?php
/**
 * 受注書のタスクリスト（KantanBiz の「タスクリスト」機能の移植）。
 *
 * KantanBiz（SaaS）の order_tasks と同じ項目（タスク名・開始日・期限・担当者・完了・並び順）を持ち、
 * 開始日→期限の工程表（横棒をドラッグして期間を変更）と一覧を受注書画面に出す。
 * プラグイン本体（KantanProEX）には手を入れず、ショートコードの出力に差し込む。
 * 将来 KantanBiz のデータを取り込めるよう、KantanBiz の項目名に合わせてある。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const KTP_DT_TASKS_DB_OPTION = 'ktp_desktop_order_tasks_db';
const KTP_DT_TASKS_DB_VERSION = '1';
const KTP_DT_TASKS_NONCE      = 'ktp_desktop_order_tasks';
const KTP_DT_TASKS_RANGE_PAD  = 2;

function ktp_dt_tasks_table() {
	global $wpdb;
	return $wpdb->prefix . 'ktp_order_task';
}

function ktp_dt_tasks_order_table() {
	global $wpdb;
	return $wpdb->prefix . 'ktp_order';
}

/** テーブルを用意する（版が変わったときだけ dbDelta） */
function ktp_dt_tasks_install() {
	if ( get_option( KTP_DT_TASKS_DB_OPTION ) === KTP_DT_TASKS_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = ktp_dt_tasks_table();
	$charset = $wpdb->get_charset_collate();
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL,
			created_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			assignee_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			completed_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			title varchar(255) NOT NULL,
			start_date date NOT NULL,
			due_date date NOT NULL,
			completed_at datetime DEFAULT NULL,
			sort_order int(10) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id,sort_order)
		) {$charset};"
	);
	update_option( KTP_DT_TASKS_DB_OPTION, KTP_DT_TASKS_DB_VERSION, true );
}
add_action( 'init', 'ktp_dt_tasks_install', 20 );

/** 受注書が削除されて宙に浮いたタスクを、1日1回まとめて消す */
add_action(
	'init',
	function () {
		if ( get_transient( 'ktp_dt_tasks_cleanup' ) || get_option( KTP_DT_TASKS_DB_OPTION ) !== KTP_DT_TASKS_DB_VERSION ) {
			return;
		}
		set_transient( 'ktp_dt_tasks_cleanup', 1, DAY_IN_SECONDS );
		global $wpdb;
		$t = ktp_dt_tasks_table();
		$o = ktp_dt_tasks_order_table();
		$wpdb->query( "DELETE FROM `{$t}` WHERE order_id NOT IN (SELECT id FROM `{$o}`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	},
	30
);

function ktp_dt_tasks_can_edit() {
	return is_user_logged_in() && ( current_user_can( 'edit_posts' ) || current_user_can( 'ktpwp_access' ) || current_user_can( 'manage_options' ) );
}

function ktp_dt_tasks_order_exists( $order_id ) {
	global $wpdb;
	$o = ktp_dt_tasks_order_table();
	return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$o}` WHERE id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/** 担当者にできるスタッフ（管理者＋ktpwp_access を持つ人） */
function ktp_dt_tasks_staff() {
	$out = array();
	foreach ( get_users( array( 'orderby' => 'ID' ) ) as $u ) {
		if ( user_can( $u, 'manage_options' ) || user_can( $u, 'ktpwp_access' ) ) {
			$out[ (int) $u->ID ] = $u->display_name !== '' ? $u->display_name : $u->user_login;
		}
	}
	return $out;
}

function ktp_dt_tasks_valid_date( $s ) {
	$d = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $s, wp_timezone() );
	return ( $d && $d->format( 'Y-m-d' ) === $s ) ? $d : null;
}

function ktp_dt_tasks_get( $order_id ) {
	global $wpdb;
	$t = ktp_dt_tasks_table();
	return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE order_id = %d ORDER BY sort_order ASC, id ASC", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

function ktp_dt_tasks_is_overdue( $task, $today ) {
	return empty( $task->completed_at ) && $task->due_date < $today;
}

function ktp_dt_tasks_clamp( $v, $max = 100.0 ) {
	return round( max( 0.0, min( $max, $v ) ), 4 );
}

/**
 * 工程表のデータ（開始日→期限）。KantanBiz の OrderTaskSchedule::build と同じ考え方。
 */
function ktp_dt_tasks_schedule( array $tasks, array $staff ) {
	$tz    = wp_timezone();
	$today = new DateTimeImmutable( 'today', $tz );
	$pad   = new DateInterval( 'P' . KTP_DT_TASKS_RANGE_PAD . 'D' );
	$rows  = array();
	foreach ( $tasks as $task ) {
		$start = new DateTimeImmutable( $task->start_date, $tz );
		$end   = new DateTimeImmutable( $task->due_date, $tz );
		if ( $end < $start ) {
			$end = $start;
		}
		$aid    = (int) $task->assignee_user_id;
		$aname  = isset( $staff[ $aid ] ) ? $staff[ $aid ] : '';
		$rows[] = array(
			'task'       => $task,
			'start'      => $start,
			'end'        => $end,
			'assignee'   => $aname,
			'avatar'     => $aid ? get_avatar_url( $aid, array( 'size' => 48 ) ) : '',
			'completed'  => ! empty( $task->completed_at ),
			'overdue'    => ktp_dt_tasks_is_overdue( $task, $today->format( 'Y-m-d' ) ),
			'left_pct'   => 0.0,
			'width_pct'  => 0.0,
		);
	}
	if ( ! $rows ) {
		return array( 'rows' => array() );
	}
	$range_start = $rows[0]['start'];
	$range_end   = $rows[0]['end'];
	foreach ( $rows as $r ) {
		$range_start = min( $range_start, $r['start'] );
		$range_end   = max( $range_end, $r['end'] );
	}
	$range_start = $range_start->sub( $pad );
	$range_end   = $range_end->add( $pad );
	if ( $range_end < $today ) {
		$range_end = $today->add( $pad );
	}
	$total = max( 1, (int) $range_start->diff( $range_end )->days );
	foreach ( $rows as $i => $r ) {
		$offset = (int) $range_start->diff( $r['start'] )->days;
		$dur    = max( 1, (int) $r['start']->diff( $r['end'] )->days );
		$left   = ktp_dt_tasks_clamp( $offset / $total * 100 );
		$rows[ $i ]['left_pct']  = $left;
		$rows[ $i ]['width_pct'] = ktp_dt_tasks_clamp( max( 0.8, $dur / $total * 100 ), 100 - $left );
	}
	// 目盛り
	$step  = $total <= 14 ? 1 : ( $total <= 60 ? 7 : 14 );
	$ticks = array();
	for ( $c = $range_start; $c <= $range_end; $c = $c->modify( "+{$step} days" ) ) {
		$ticks[] = array(
			'label' => $c->format( 'n/j' ),
			'left'  => ktp_dt_tasks_clamp( (int) $range_start->diff( $c )->days / $total * 100 ),
		);
	}
	if ( $ticks && $ticks[ count( $ticks ) - 1 ]['left'] < 95 ) {
		$ticks[] = array( 'label' => $range_end->format( 'n/j' ), 'left' => 100.0 );
	}
	return array(
		'rows'        => $rows,
		'ticks'       => $ticks,
		'range_start' => $range_start->format( 'Y-m-d' ),
		'range_end'   => $range_end->format( 'Y-m-d' ),
		'total_days'  => $total,
	);
}

/**
 * タスクリストのセクション全体（<details>）を作る。操作のたびに丸ごと作り直して差し替える。
 */
function ktp_dt_tasks_render( $order_id ) {
	$order_id  = (int) $order_id;
	$tasks     = ktp_dt_tasks_get( $order_id );
	$staff     = ktp_dt_tasks_staff();
	$can_edit  = ktp_dt_tasks_can_edit();
	$total     = count( $tasks );
	$done      = 0;
	foreach ( $tasks as $t ) {
		if ( ! empty( $t->completed_at ) ) {
			++$done;
		}
	}
	$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
	$me    = get_current_user_id();
	$sched = ktp_dt_tasks_schedule( $tasks, $staff );

	$staff_options = static function ( $selected ) use ( $staff ) {
		$h = '<option value="0"' . selected( (int) $selected, 0, false ) . '>' . esc_html__( '未割当', 'ktpwp' ) . '</option>';
		foreach ( $staff as $id => $name ) {
			$h .= '<option value="' . (int) $id . '"' . selected( (int) $selected, $id, false ) . '>' . esc_html( $name ) . '</option>';
		}
		return $h;
	};

	$h  = '<details class="ktp-order-block ktp-order-details-toggle ktp-order-tasks-details" id="ktp-order-tasks" data-ktp-order-toggle="order_tasks" data-ktp-order-id="' . $order_id . '"';
	$h .= ' data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( KTP_DT_TASKS_NONCE ) ) . '" data-editable="' . ( $can_edit ? '1' : '0' ) . '">';
	$h .= '<summary class="ktp-order-details-summary">' . esc_html__( 'タスクリスト', 'ktpwp' );
	if ( $total > 0 ) {
		$h .= ' <span class="ktp-order-details-hint">' . esc_html( sprintf( '%d / %d 件完了', $done, $total ) ) . '</span>';
	}
	$h .= '</summary>';
	$h .= '<div class="ktp-tasks-body">';

	if ( $can_edit ) {
		$h .= '<p class="description ktp-tasks-desc">' . esc_html__( 'この案件を進めるための段取りをスタッフで共有します。', 'ktpwp' ) . '</p>';
		$h .= '<div class="ktp-tasks-add">';
		$h .= '<label class="ktp-tasks-field ktp-tasks-field--title"><span>' . esc_html__( 'タスク名', 'ktpwp' ) . '</span><input type="text" class="ktp-tasks-input" data-field="title" maxlength="255" /></label>';
		$h .= '<label class="ktp-tasks-field"><span>' . esc_html__( '開始日', 'ktpwp' ) . '</span><input type="date" class="ktp-tasks-input" data-field="start_date" value="' . esc_attr( $today ) . '" /></label>';
		$h .= '<label class="ktp-tasks-field"><span>' . esc_html__( '期限', 'ktpwp' ) . '</span><input type="date" class="ktp-tasks-input" data-field="due_date" /></label>';
		$h .= '<label class="ktp-tasks-field"><span>' . esc_html__( '担当者', 'ktpwp' ) . '</span><select class="ktp-tasks-input" data-field="assignee_user_id">' . $staff_options( isset( $staff[ $me ] ) ? $me : 0 ) . '</select></label>';
		$h .= '<button type="button" class="ktp-tasks-btn ktp-tasks-btn--primary" data-act="add">' . esc_html__( '追加', 'ktpwp' ) . '</button>';
		$h .= '</div>';
		$h .= '<p class="ktp-tasks-error" role="alert" hidden></p>';
	}

	if ( $total === 0 ) {
		$h .= '<p class="description">' . esc_html__( 'まだタスクがありません。', 'ktpwp' ) . '</p>';
		$h .= '</div></details>';
		return $h;
	}

	// 工程表
	$h .= '<div class="ktp-tasks-chart work-list-schedule-chart" data-range-start="' . esc_attr( $sched['range_start'] ) . '" data-total-days="' . (int) $sched['total_days'] . '">';
	$h .= '<div class="work-list-schedule-scroll"><div class="work-list-schedule-layout">';
	$h .= '<div class="work-list-schedule-gridlines" aria-hidden="true">';
	foreach ( $sched['ticks'] as $tick ) {
		$h .= '<span class="work-list-schedule-gridline" style="left:' . esc_attr( $tick['left'] ) . '%"></span>';
	}
	$h .= '</div><div class="work-list-schedule-grid">';
	$h .= '<div class="work-list-schedule-case-header">' . esc_html__( 'タスク', 'ktpwp' ) . '</div>';
	$h .= '<div class="work-list-schedule-timeline-header">';
	foreach ( $sched['ticks'] as $tick ) {
		$h .= '<span class="work-list-schedule-tick" style="left:' . esc_attr( $tick['left'] ) . '%">' . esc_html( $tick['label'] ) . '</span>';
	}
	$h .= '</div>';
	foreach ( $sched['rows'] as $i => $r ) {
		$task = $r['task'];
		if ( $i > 0 ) {
			$h .= '<div class="work-list-schedule-row-divider" aria-hidden="true"></div>';
		}
		$h .= '<div class="work-list-schedule-label-cell"><span class="ktp-tasks-chart-label' . ( $r['completed'] ? ' is-done' : '' ) . '" title="' . esc_attr( $task->title ) . '">' . esc_html( $task->title ) . '</span>';
		$h .= '<p class="work-list-schedule-sublabel">' . esc_html( $r['assignee'] !== '' ? '担当: ' . $r['assignee'] : '担当: 未割当' ) . '</p></div>';
		$h .= '<div class="work-list-schedule-track">';
		if ( $r['assignee'] !== '' ) {
			$h .= '<span class="ktp-tasks-avatar' . ( $r['completed'] ? ' is-done' : '' ) . '" data-avatar-for="' . (int) $task->id . '" style="left:' . esc_attr( $r['left_pct'] ) . '%" title="' . esc_attr( $r['assignee'] ) . '"><img src="' . esc_url( $r['avatar'] ) . '" alt="" /></span>';
		}
		$cls = 'work-list-schedule-bar ktp-tasks-bar ' . ( $r['completed'] ? 'is-done' : ( $r['overdue'] ? 'is-overdue' : 'is-open' ) );
		$drag = $can_edit && ! $r['completed'];
		if ( $drag ) {
			$cls .= ' is-editable';
		}
		$h .= '<div class="' . esc_attr( $cls ) . '" data-task-id="' . (int) $task->id . '" data-start="' . esc_attr( $task->start_date ) . '" data-due="' . esc_attr( $r['end']->format( 'Y-m-d' ) ) . '" style="left:' . esc_attr( $r['left_pct'] ) . '%;width:' . esc_attr( $r['width_pct'] ) . '%" title="' . esc_attr( '開始 ' . $r['start']->format( 'Y/n/j' ) . ' 〜 期限 ' . $r['end']->format( 'Y/n/j' ) ) . '">';
		if ( $drag ) {
			$h .= '<button type="button" class="ktp-tasks-handle ktp-tasks-handle--start" data-handle="start" aria-label="' . esc_attr__( '開始日をドラッグして調整', 'ktpwp' ) . '"></button>';
			$h .= '<button type="button" class="ktp-tasks-handle ktp-tasks-handle--end" data-handle="end" aria-label="' . esc_attr__( '期限をドラッグして調整', 'ktpwp' ) . '"></button>';
		}
		$h .= '</div></div>';
	}
	$h .= '</div></div></div>';
	$h .= '<p class="description">' . esc_html__( '横棒は開始日から期限までの期間です。グレーは完了済み、赤は期限超過の未完了。', 'ktpwp' );
	if ( $can_edit ) {
		$h .= esc_html__( '棒の左右端をドラッグすると開始日・期限を、棒全体をドラッグすると期間ごと動かせます。', 'ktpwp' );
	}
	$h .= '</p></div>';

	// 一覧
	$h .= '<div class="ktp-tasks-tablewrap"><table class="ktp-tasks-table"><thead><tr>';
	if ( $can_edit ) {
		$h .= '<th class="ktp-tasks-col-narrow"></th><th class="ktp-tasks-col-narrow">' . esc_html__( '完了', 'ktpwp' ) . '</th>';
	}
	$h .= '<th>' . esc_html__( 'タスク', 'ktpwp' ) . '</th><th>' . esc_html__( '開始日', 'ktpwp' ) . '</th><th>' . esc_html__( '期限', 'ktpwp' ) . '</th><th>' . esc_html__( '担当者', 'ktpwp' ) . '</th><th>' . esc_html__( '追加', 'ktpwp' ) . '</th>';
	if ( $can_edit ) {
		$h .= '<th class="ktp-tasks-col-narrow"></th>';
	}
	$h .= '</tr></thead><tbody id="ktp-tasks-tbody">';
	foreach ( $tasks as $task ) {
		$is_done    = ! empty( $task->completed_at );
		$overdue    = ktp_dt_tasks_is_overdue( $task, $today );
		$aid        = (int) $task->assignee_user_id;
		$creator    = (int) $task->created_by_user_id;
		$creator_nm = isset( $staff[ $creator ] ) ? $staff[ $creator ] : ( ( $u = $creator ? get_userdata( $creator ) : false ) ? $u->display_name : '（不明）' );
		$h .= '<tr class="ktp-tasks-row' . ( $is_done ? ' is-done' : '' ) . '" data-task-id="' . (int) $task->id . '">';
		if ( $can_edit ) {
			$h .= '<td><span class="ktp-tasks-drag" draggable="true" title="' . esc_attr__( 'ドラッグして並べ替え', 'ktpwp' ) . '">≡</span></td>';
			$h .= '<td><input type="checkbox" data-act="toggle" ' . checked( $is_done, true, false ) . ' aria-label="' . esc_attr__( '完了', 'ktpwp' ) . '" /></td>';
		}
		$h .= '<td class="ktp-tasks-title"><span class="' . ( $is_done ? 'is-done' : '' ) . '">' . esc_html( $task->title ) . '</span>';
		if ( $is_done ) {
			$cb = (int) $task->completed_by_user_id;
			$h .= '<div class="ktp-tasks-sub">' . esc_html( '完了: ' . mysql2date( 'Y-m-d', $task->completed_at ) . ( isset( $staff[ $cb ] ) ? ' — ' . $staff[ $cb ] : '' ) ) . '</div>';
		} elseif ( $overdue ) {
			$h .= '<div class="ktp-tasks-sub"><span class="ktp-tasks-badge">' . esc_html__( '期限超過', 'ktpwp' ) . '</span></div>';
		}
		$h .= '</td><td class="ktp-tasks-nowrap">' . esc_html( $task->start_date ) . '</td><td class="ktp-tasks-nowrap">' . esc_html( $task->due_date ) . '</td>';
		$h .= '<td class="ktp-tasks-nowrap">';
		if ( $can_edit ) {
			$h .= '<select class="ktp-tasks-assignee" data-act="assignee">' . $staff_options( $aid ) . '</select>';
		} else {
			$h .= esc_html( isset( $staff[ $aid ] ) ? $staff[ $aid ] : '未割当' );
		}
		$h .= '</td><td class="ktp-tasks-nowrap ktp-tasks-muted">' . esc_html( mysql2date( 'Y-m-d', $task->created_at ) ) . '<span class="ktp-tasks-sub">' . esc_html( $creator_nm ) . '</span></td>';
		if ( $can_edit ) {
			$h .= '<td><button type="button" class="ktp-tasks-btn ktp-tasks-btn--danger" data-act="delete">' . esc_html__( '削除', 'ktpwp' ) . '</button></td>';
		}
		$h .= '</tr>';
	}
	$h .= '</tbody></table></div></div></details>';
	return $h;
}

/** ショートコードの出力に、メール履歴の手前（無ければ削除ボタンの手前）へセクションを差し込む */
add_filter(
	'do_shortcode_tag',
	function ( $output, $tag ) {
		if ( ! in_array( $tag, array( 'kantanAllTab', 'ktpwp_all_tab', 'kantanpro_ex' ), true ) || ! ktp_dt_tasks_can_edit() ) {
			return $output;
		}
		if ( get_option( KTP_DT_TASKS_DB_OPTION ) !== KTP_DT_TASKS_DB_VERSION ) {
			return $output;
		}
		// 受注書の詳細画面にだけ出す（進捗フォームの hidden に受注書 ID がある）
		if ( ! preg_match( '/name="update_progress_id" value="(\d+)"/', $output, $m ) ) {
			return $output;
		}
		$markers = array(
			'<details class="ktp-order-block ktp-order-details-toggle ktp-order-mail-log-wrap',
			'<div class="ktp-order-delete-footer">',
		);
		foreach ( $markers as $marker ) {
			$pos = strpos( $output, $marker );
			if ( false !== $pos ) {
				return substr( $output, 0, $pos ) . ktp_dt_tasks_render( (int) $m[1] ) . substr( $output, $pos );
			}
		}
		return $output;
	},
	10,
	2
);

add_action(
	'wp_enqueue_scripts',
	function () {
		$base = WPMU_PLUGIN_URL . '/ktp-desktop/';
		$dir  = WPMU_PLUGIN_DIR . '/ktp-desktop/';
		wp_enqueue_style( 'ktp-desktop-order-tasks', $base . 'order-tasks.css', array(), (string) @filemtime( $dir . 'order-tasks.css' ) );
		wp_enqueue_script( 'ktp-desktop-order-tasks', $base . 'order-tasks.js', array(), (string) @filemtime( $dir . 'order-tasks.js' ), true );
	}
);

/** AJAX の共通の入口: ログイン・権限・nonce・受注書の存在を確認し、order_id を返す */
function ktp_dt_tasks_ajax_guard() {
	if ( ! ktp_dt_tasks_can_edit() ) {
		wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
	}
	check_ajax_referer( KTP_DT_TASKS_NONCE, 'nonce' );
	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	if ( ! $order_id || ! ktp_dt_tasks_order_exists( $order_id ) ) {
		wp_send_json_error( array( 'message' => '受注書が見つかりません。' ), 404 );
	}
	return $order_id;
}

function ktp_dt_tasks_post_task( $order_id ) {
	global $wpdb;
	$t  = ktp_dt_tasks_table();
	$id = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;
	$row = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE id = %d AND order_id = %d", $id, $order_id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ( ! $row ) {
		wp_send_json_error( array( 'message' => 'タスクが見つかりません。' ), 404 );
	}
	return $row;
}

function ktp_dt_tasks_reply( $order_id ) {
	wp_send_json_success( array( 'html' => ktp_dt_tasks_render( $order_id ) ) );
}

function ktp_dt_tasks_post_assignee() {
	$a = isset( $_POST['assignee_user_id'] ) ? absint( $_POST['assignee_user_id'] ) : 0;
	if ( $a && ! array_key_exists( $a, ktp_dt_tasks_staff() ) ) {
		wp_send_json_error( array( 'message' => '担当者が正しくありません。' ), 422 );
	}
	return $a;
}

add_action(
	'wp_ajax_ktp_dt_task_add',
	function () {
		global $wpdb;
		$order_id = ktp_dt_tasks_ajax_guard();
		$title    = isset( $_POST['title'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['title'] ) ) ) : '';
		$start    = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$due      = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		if ( $title === '' ) {
			wp_send_json_error( array( 'message' => 'タスク名を入力してください。' ), 422 );
		}
		$sd = ktp_dt_tasks_valid_date( $start );
		$dd = ktp_dt_tasks_valid_date( $due );
		if ( ! $sd || ! $dd ) {
			wp_send_json_error( array( 'message' => '開始日と期限を入力してください。' ), 422 );
		}
		if ( $dd < $sd ) {
			wp_send_json_error( array( 'message' => '期限は開始日以降にしてください。' ), 422 );
		}
		$assignee = ktp_dt_tasks_post_assignee();
		$t        = ktp_dt_tasks_table();
		$max      = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM `{$t}` WHERE order_id = %d", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->insert(
			$t,
			array(
				'order_id'           => $order_id,
				'created_by_user_id' => get_current_user_id(),
				'assignee_user_id'   => $assignee,
				'title'              => mb_substr( $title, 0, 255 ),
				'start_date'         => $start,
				'due_date'           => $due,
				'sort_order'         => $max === null ? 0 : (int) $max + 1,
				'created_at'         => current_time( 'mysql' ),
			)
		);
		ktp_dt_tasks_reply( $order_id );
	}
);

add_action(
	'wp_ajax_ktp_dt_task_toggle',
	function () {
		global $wpdb;
		$order_id = ktp_dt_tasks_ajax_guard();
		$task     = ktp_dt_tasks_post_task( $order_id );
		$done     = ! empty( $_POST['completed'] ) && '0' !== $_POST['completed'];
		$wpdb->update(
			ktp_dt_tasks_table(),
			array(
				'completed_at'         => $done ? current_time( 'mysql' ) : null,
				'completed_by_user_id' => $done ? get_current_user_id() : 0,
			),
			array( 'id' => (int) $task->id )
		);
		ktp_dt_tasks_reply( $order_id );
	}
);

add_action(
	'wp_ajax_ktp_dt_task_assignee',
	function () {
		global $wpdb;
		$order_id = ktp_dt_tasks_ajax_guard();
		$task     = ktp_dt_tasks_post_task( $order_id );
		$wpdb->update( ktp_dt_tasks_table(), array( 'assignee_user_id' => ktp_dt_tasks_post_assignee() ), array( 'id' => (int) $task->id ) );
		ktp_dt_tasks_reply( $order_id );
	}
);

add_action(
	'wp_ajax_ktp_dt_task_dates',
	function () {
		global $wpdb;
		$order_id = ktp_dt_tasks_ajax_guard();
		$task     = ktp_dt_tasks_post_task( $order_id );
		if ( ! empty( $task->completed_at ) ) {
			wp_send_json_error( array( 'message' => '完了済みタスクの期間は変更できません。' ), 422 );
		}
		$start = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : '';
		$due   = isset( $_POST['due_date'] ) ? sanitize_text_field( wp_unslash( $_POST['due_date'] ) ) : '';
		$sd    = ktp_dt_tasks_valid_date( $start );
		$dd    = ktp_dt_tasks_valid_date( $due );
		if ( ! $sd || ! $dd || $dd < $sd ) {
			wp_send_json_error( array( 'message' => '日付が正しくありません。' ), 422 );
		}
		$wpdb->update( ktp_dt_tasks_table(), array( 'start_date' => $start, 'due_date' => $due ), array( 'id' => (int) $task->id ) );
		ktp_dt_tasks_reply( $order_id );
	}
);

add_action(
	'wp_ajax_ktp_dt_task_delete',
	function () {
		global $wpdb;
		$order_id = ktp_dt_tasks_ajax_guard();
		$task     = ktp_dt_tasks_post_task( $order_id );
		$wpdb->delete( ktp_dt_tasks_table(), array( 'id' => (int) $task->id ) );
		ktp_dt_tasks_reply( $order_id );
	}
);

add_action(
	'wp_ajax_ktp_dt_task_reorder',
	function () {
		global $wpdb;
		$order_id = ktp_dt_tasks_ajax_guard();
		$ids      = isset( $_POST['task_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['task_ids'] ) ) : array();
		$existing = array_map( 'intval', wp_list_pluck( ktp_dt_tasks_get( $order_id ), 'id' ) );
		$a        = $ids;
		$b        = $existing;
		sort( $a );
		sort( $b );
		if ( $a !== $b ) {
			wp_send_json_error( array( 'message' => '並び替え対象のタスクが正しくありません。' ), 422 );
		}
		foreach ( $ids as $i => $id ) {
			$wpdb->update( ktp_dt_tasks_table(), array( 'sort_order' => $i ), array( 'id' => $id, 'order_id' => $order_id ) );
		}
		ktp_dt_tasks_reply( $order_id );
	}
);
