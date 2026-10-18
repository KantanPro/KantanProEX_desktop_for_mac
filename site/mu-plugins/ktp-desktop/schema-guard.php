<?php
/**
 * データベースの構造を守る。
 *
 * デスクトップ版は、初回に「実運用と同じ構造」（assets/baseline-ddl.json）を直接作り、プラグインの
 * マイグレーションは流さない（流すと古い列を消す整理用マイグレーションが走り、受注書が作れなくなる）。
 * ところがプラグインは、版が上がると「実行済みの記録が無いマイグレーション」を全部流すため、
 * 0.1.0 → 0.2.0 の更新で整理用マイグレーションが走り、受注書の 17 列・協力会社の url 列が消えた。
 * プラグインの受注書作成はこれらの列に書き込むので、更新後は受注書が作れなくなっていた（画面は「追加しました」）。
 *
 * 1. 予防: 列を消すマイグレーションを、最初から「実行済み」として記録しておく（プラグインは記録があれば飛ばす）
 * 2. 修復: 版が変わるたびに 1 回、土台の構造と比べて、足りない表・列を足す（既に消えてしまった DB を直す）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 列を消す（up() の中で DROP COLUMN する）マイグレーション。プラグインの includes/migrations/ のファイル名
const KTP_DT_BLOCKED_MIGRATIONS = array(
	'20250703_cleanup_unused_columns.php',
	'20250703_strict_cleanup_unused_columns.php',
	'20250703_ultra_strict_cleanup_unused_columns.php',
	'20250703_sync_production_to_local_structure.php',
);

/** 1. 予防: プラグインが読み込まれる前（mu-plugin の読み込み時）に記録しておく */
function ktp_dt_block_destructive_migrations() {
	foreach ( KTP_DT_BLOCKED_MIGRATIONS as $file ) {
		$key = 'ktp_migration_' . md5( $file ); // プラグイン側の記録の名前（ktpwp_safe_run_migration_files と同じ）
		if ( ! get_option( $key ) ) {
			update_option( $key, 'skipped-by-desktop', false );
		}
	}
}
ktp_dt_block_destructive_migrations();

/**
 * 土台の CREATE TABLE から、列ごとの定義を取り出す。
 *
 * @return array<string, string> 列名 => 定義（型・既定値など）
 */
function ktp_dt_schema_columns_from_ddl( $create ) {
	$cols = array();
	foreach ( preg_split( '/\R/', $create ) as $line ) {
		if ( preg_match( '/^\s*`([^`]+)`\s+(.+?),?\s*$/', $line, $m ) ) {
			$cols[ $m[1] ] = $m[2];
		}
	}
	return $cols;
}

/** ADD COLUMN 用に定義を整える（SQLite は既定値の無い NOT NULL の列を後から足せないので NULL 可にする） */
function ktp_dt_schema_add_column_definition( $def ) {
	$def = preg_replace( "/\s+COMMENT\s+'(?:[^'\\\\]|\\\\.)*'/i", '', $def );
	$def = preg_replace( '/\s+AUTO_INCREMENT/i', '', $def );
	$def = preg_replace( '/\s+ON UPDATE CURRENT_TIMESTAMP/i', '', $def );
	if ( stripos( $def, 'NOT NULL' ) !== false && stripos( $def, 'DEFAULT' ) === false ) {
		$def = preg_replace( '/\s+NOT NULL/i', ' NULL', $def );
	}
	return trim( $def );
}

/**
 * 2. 修復: 足りない表・列を土台の構造から足す。
 *
 * @return array{tables: list<string>, columns: list<string>, errors: list<string>}
 */
function ktp_dt_schema_repair( $force = false ) {
	$file = ABSPATH . 'ktp-desktop/baseline-ddl.json';
	if ( ! is_readable( $file ) ) {
		return array( 'tables' => array(), 'columns' => array(), 'errors' => array( 'baseline-ddl.json が見つかりません' ) );
	}
	$plugin_ver = defined( 'KANTANPRO_PLUGIN_VERSION' ) ? KANTANPRO_PLUGIN_VERSION : '';
	$stamp      = md5_file( $file ) . '|' . $plugin_ver . '|2';
	if ( ! $force && get_option( 'ktp_desktop_schema_repair' ) === $stamp ) {
		return array( 'tables' => array(), 'columns' => array(), 'errors' => array() );
	}

	global $wpdb;
	$ddl    = json_decode( (string) file_get_contents( $file ), true );
	$result = array( 'tables' => array(), 'columns' => array(), 'errors' => array() );
	if ( ! is_array( $ddl ) ) {
		return $result;
	}
	$suppress = $wpdb->suppress_errors( true );
	foreach ( $ddl as $base_table => $create ) {
		$table  = preg_replace( '/^wp_/', $wpdb->prefix, $base_table );
		$create = str_replace( '`wp_ktp_', '`' . $wpdb->prefix . 'ktp_', $create );
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			if ( false === $wpdb->query( $create ) ) {
				$result['errors'][] = "$table: " . $wpdb->last_error;
			} else {
				$result['tables'][] = $table;
			}
			continue;
		}
		$have = array_map( 'strtolower', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$table}`", 0 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( ktp_dt_schema_columns_from_ddl( $create ) as $col => $def ) {
			if ( in_array( strtolower( $col ), $have, true ) ) {
				continue;
			}
			$sql = "ALTER TABLE `{$table}` ADD COLUMN `{$col}` " . ktp_dt_schema_add_column_definition( $def );
			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$result['errors'][] = "$table.$col: " . $wpdb->last_error;
			} else {
				$result['columns'][] = "$table.$col";
			}
		}
	}
	$wpdb->suppress_errors( $suppress );
	if ( class_exists( 'KTPWP_Schema_Cache' ) && method_exists( 'KTPWP_Schema_Cache', 'invalidate' ) ) {
		KTPWP_Schema_Cache::invalidate();
	}
	if ( ! $result['errors'] ) {
		update_option( 'ktp_desktop_schema_repair', $stamp, true );
	}
	if ( $result['tables'] || $result['columns'] || $result['errors'] ) {
		update_option( 'ktp_desktop_schema_repair_log', array( 'at' => current_time( 'mysql' ), 'result' => $result ), false );
	}
	return $result;
}

// プラグインの読み込み後、画面や取り込みが DB を使う前に直す（版が変わったときだけ実際に調べる）
add_action( 'plugins_loaded', 'ktp_dt_schema_repair', 1 );
