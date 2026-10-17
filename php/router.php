<?php
/**
 * PHP 内蔵サーバー用のルーター（php -S 127.0.0.1:PORT -t site php/router.php）。
 *
 * - 初回セットアップ用ファイル（/ktp-desktop/）・wp-config.php・隠しファイルは外から見せない
 * - 実在するファイル／フォルダはそのまま返し、それ以外は WordPress（index.php）に渡す
 */
$path = rawurldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

if ( preg_match( '#^/(ktp-desktop(/|$)|wp-config\.php$)#', $path ) || strpos( $path, '/.' ) !== false ) {
	http_response_code( 404 );
	exit;
}

$file = $_SERVER['DOCUMENT_ROOT'] . $path;
if ( $path !== '/' && file_exists( $file ) ) {
	return false;
}

// 無い静的ファイル（.js / .css / 画像など）は WordPress に渡さず 404 にする。
// 渡すと WordPress の HTML が返り、<script> として読まれて構文エラーになる
if ( preg_match( '#\.(js|css|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|json|txt|pdf)$#i', $path ) ) {
	http_response_code( 404 );
	exit;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
