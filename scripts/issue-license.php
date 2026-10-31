<?php
/**
 * ライセンスキーを 1 本発行して、標準出力に出す。
 *
 * 使い方: bin/php scripts/issue-license.php <購入者のメールアドレス> [発行ID]
 *   秘密鍵は ~/.kantanpro/dt-license/private.pem（環境変数 KTP_DT_LICENSE_KEY_FILE で変えられる）。リポジトリには置かない。
 *   キーの形式: base64url(JSON {"p":"ktp-dt","e":メール,"i":発行ID,"t":発行時刻}) . base64url(署名 ECDSA P-256 + SHA-256 の DER)
 *   期限なし（買い切り）。確認は site/mu-plugins/ktp-desktop/license.php の ktp_dt_license_verify_key()。
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$email = $argv[1] ?? '';
$id    = $argv[2] ?? date( 'Ymd-His' );
if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
	fwrite( STDERR, "使い方: bin/php scripts/issue-license.php <購入者のメールアドレス> [発行ID]\n" );
	exit( 2 );
}
if ( ! function_exists( 'openssl_sign' ) ) {
	fwrite( STDERR, "この PHP には openssl がありません。同梱の bin/php で実行してください。\n" );
	exit( 3 );
}
$file = getenv( 'KTP_DT_LICENSE_KEY_FILE' ) ?: ( getenv( 'HOME' ) . '/.kantanpro/dt-license/private.pem' );
if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "秘密鍵が見つかりません: {$file}\n作るには: openssl ecparam -name prime256v1 -genkey -noout -out {$file} && chmod 600 {$file}\n（作り直すと、これまでに発行したキーはすべて無効になり、アプリ側の公開鍵も差し替えが要ります）\n" );
	exit( 4 );
}
$pem = openssl_pkey_get_private( (string) file_get_contents( $file ) );
if ( ! $pem ) {
	fwrite( STDERR, "秘密鍵を読めませんでした: {$file}\n" );
	exit( 5 );
}
$payload = json_encode( array( 'p' => 'ktp-dt', 'e' => $email, 'i' => $id, 't' => time() ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( ! openssl_sign( $payload, $sig, $pem, OPENSSL_ALGO_SHA256 ) ) {
	fwrite( STDERR, "署名できませんでした。\n" );
	exit( 6 );
}
$b64 = static function ( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
};
echo $b64( $payload ) . '.' . $b64( $sig ) . "\n";
