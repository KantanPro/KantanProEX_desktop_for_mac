#!/usr/bin/env bash
# Windows 版に同梱するものを取得する（Mac / Windows どちらで実行してもよい）。
#   bin-win/        公式の PHP 8.3（NTS・x64）。拡張は ext/ の DLL で、lib/php.js が起動時に読み込む
#   bin-win/cacert.pem  メール送信（SMTP の TLS）で使う CA 証明書
#   build/vc_redist.x64.exe  PHP が必要とする VC++ ランタイム。インストーラーが、無い PC にだけ入れる
# （Windows 用の「静的ビルド」の PHP は拡張が足りない（mbstring・openssl・zip・pdo_sqlite が無い）ので使わない）
set -euo pipefail
cd "$(dirname "$0")/.."
VER="8.3.35"
mkdir -p work/dl bin-win build
[ -s "work/dl/php-${VER}-win.zip" ] || curl -fsSL -m 400 -o "work/dl/php-${VER}-win.zip" "https://downloads.php.net/~windows/releases/php-${VER}-nts-Win32-vs16-x64.zip"
rm -rf bin-win && mkdir -p bin-win
unzip -q -o "work/dl/php-${VER}-win.zip" -d bin-win
# 使わないものを外す（開発用ヘッダ・サンプル・CGI など）
rm -rf bin-win/dev bin-win/extras bin-win/php-cgi.exe bin-win/phar.phar.bat bin-win/pharcommand.phar bin-win/deplister.exe bin-win/README.md bin-win/news.txt
[ -s work/dl/cacert.pem ] || curl -fsSL -m 60 -o work/dl/cacert.pem https://curl.se/ca/cacert.pem
cp work/dl/cacert.pem bin-win/cacert.pem
[ -s build/vc_redist.x64.exe ] || curl -fsSL -m 300 -L -o build/vc_redist.x64.exe https://aka.ms/vs/17/release/vc_redist.x64.exe
test -f bin-win/php.exe && test -f bin-win/ext/php_pdo_sqlite.dll && test -f bin-win/ext/php_mbstring.dll
echo "PHP ${VER} (Windows) → bin-win/  ($(du -sh bin-win | cut -f1))"
echo "VC++ ランタイム → build/vc_redist.x64.exe ($(du -h build/vc_redist.x64.exe | cut -f1))"
