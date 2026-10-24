<?php
/**
 * プラグインを削除したら、預かっている受付箱と設定を消す。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ktp_dt_bridge_inbox" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( array( 'ktp_dt_bridge_db_version', 'ktp_dt_bridge_key', 'ktp_dt_bridge_settings', 'ktp_dt_bridge_last_pull', 'ktp_dt_bridge_catalog', 'ktp_dt_bridge_images' ) as $option ) {
	delete_option( $option );
}
wp_clear_scheduled_hook( 'ktp_dt_bridge_cleanup' );

// 商品の画像
$ktp_uploads = wp_upload_dir( null, false );
$ktp_dir     = trailingslashit( $ktp_uploads['basedir'] ) . 'ktp-dt-bridge/';
if ( is_dir( $ktp_dir ) ) {
	foreach ( (array) glob( $ktp_dir . '*' ) as $ktp_file ) {
		if ( is_file( $ktp_file ) ) {
			@unlink( $ktp_file );
		}
	}
	@rmdir( $ktp_dir );
}
