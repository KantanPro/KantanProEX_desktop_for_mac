<?php
/**
 * ライセンスキーを 1 本発行して、標準出力に出す（非常用）。
 *
 * 通常の発行は kantanpro.com が Google Cloud KMS（鍵番号 kms1）で自動で行う。これは KMS が使えないときの非常用で、予備の鍵 bk1 で発行する。
 *
 * 使い方: bin/php scripts/issue-license.php <購入者のメールアドレス> [発行ID] [差し替える前の発行ID] [--kid=bk1]
 *   秘密鍵は ~/.kantanpro/dt-license/backup-bk1-private.pem（環境変数 KTP_DT_LICENSE_KEY_FILE で変えられる）。リポジトリには置かない。
 *   --kid で payload の鍵番号を変えられる（アプリ側の license.php の KTP_DT_LICENSE_PUBKEYS にある番号だけ有効）。
 *   キーの形式: base64url(JSON {"p":"ktp-dt","k":鍵番号,"e":メール,"i":発行ID,"t":発行時刻,"c":引き継ぎの合言葉}) . base64url(署名 ECDSA P-256 + SHA-256 の DER)
 *   期限なし（買い切り）。確認は site/mu-plugins/ktp-desktop/license.php の ktp_dt_license_verify_key()。
 *
 * 発行するたびに、秘密鍵と同じフォルダの issued.csv（発行日時・メール・発行ID・差し替え前の発行ID・引き継ぎの合言葉）に 1 行足す。
 * メールアドレス・名義を変える人（本人のアドレス変更・事業の引き継ぎ）には、新しいメールで発行し直し、3 番目に前の発行ID を渡す（例: 19500 → 19500-r1）。
 * 前のキーは取り消せない（アプリはネットにつながずに確かめるため）ので、そのまま有効のまま残る。
 */
date_default_timezone_set( 'Asia/Tokyo' ); // 台帳の発行日時は日本時間
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$kid  = 'bk1';
$args = array();
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( 0 === strpos( $a, '--kid=' ) ) {
		$kid = substr( $a, 6 );
	} else {
		$args[] = $a;
	}
}
if ( ! preg_match( '/^[a-z0-9]{2,16}$/', $kid ) ) {
	fwrite( STDERR, "--kid が正しくありません。\n" );
	exit( 2 );
}
$email    = $args[0] ?? '';
$id       = $args[1] ?? date( 'Ymd-His' );
$replaces = $args[2] ?? '';
if ( '' === $email || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
	fwrite( STDERR, "使い方: bin/php scripts/issue-license.php <購入者のメールアドレス> [発行ID] [差し替える前の発行ID] [--kid=bk1]\n" );
	exit( 2 );
}
if ( ! function_exists( 'openssl_sign' ) ) {
	fwrite( STDERR, "この PHP には openssl がありません。同梱の bin/php で実行してください。\n" );
	exit( 3 );
}
$file = getenv( 'KTP_DT_LICENSE_KEY_FILE' ) ?: ( getenv( 'HOME' ) . '/.kantanpro/dt-license/backup-bk1-private.pem' );
if ( ! is_readable( $file ) ) {
	fwrite( STDERR, "秘密鍵が見つかりません: {$file}\n作るには: openssl ecparam -name prime256v1 -genkey -noout -out {$file} && chmod 600 {$file}\n（作り直すと、これまでに発行したキーはすべて無効になり、アプリ側の公開鍵も差し替えが要ります）\n" );
	exit( 4 );
}
$pem = openssl_pkey_get_private( (string) file_get_contents( $file ) );
if ( ! $pem ) {
	fwrite( STDERR, "秘密鍵を読めませんでした: {$file}\n" );
	exit( 5 );
}
$now = time();
// 引き継ぎの合言葉（8 文字・読み間違えやすい 0/O/1/I/L/U を除く）。キーの中に入れて署名し、台帳にも残す
$alphabet = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
$code     = '';
for ( $n = 0; $n < 8; $n++ ) {
	$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
}
$code    = substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
$payload = json_encode( array( 'p' => 'ktp-dt', 'k' => $kid, 'e' => $email, 'i' => $id, 't' => $now, 'c' => $code ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
if ( ! openssl_sign( $payload, $sig, $pem, OPENSSL_ALGO_SHA256 ) ) {
	fwrite( STDERR, "署名できませんでした。\n" );
	exit( 6 );
}
$b64 = static function ( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
};

// 発行の台帳（メールアドレスの変更の問い合わせのとき、前の発行ID を探すのに使う）
$ledger = dirname( $file ) . '/issued.csv';
$is_new = ! file_exists( $ledger );
$fh     = fopen( $ledger, 'ab' );
if ( $fh ) {
	if ( $is_new ) {
		fputcsv( $fh, array( '発行日時', 'メール', '発行ID', '差し替え前の発行ID', '引き継ぎの合言葉' ) );
	}
	fputcsv( $fh, array( date( 'Y-m-d H:i:s', $now ), $email, $id, $replaces, $code ) );
	fclose( $fh );
} else {
	fwrite( STDERR, "台帳に書けませんでした: {$ledger}（キーは発行しました）\n" );
}

echo $b64( $payload ) . '.' . $b64( $sig ) . "\n";
