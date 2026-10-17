#!/usr/bin/env bash
# 使い方: scripts/auth-cookie.sh <WPのフォルダ>  → "名前=値" を1行で出力（テスト用ブラウザに注入する管理者のログインCookie）
# セッショントークンを正規に作る（作らないと数回のリクエストで無効になる）
cd "$1" && php -d memory_limit=-1 /Users/kantanpro/Desktop/KantanPro/wordpress/wp-content/plugins/KantanProEX/wp-cli.phar eval '$exp = time() + 86400; $tok = WP_Session_Tokens::get_instance(1)->create($exp); echo LOGGED_IN_COOKIE . "=" . wp_generate_auth_cookie(1, $exp, "logged_in", $tok);' 2>/dev/null | tail -1
