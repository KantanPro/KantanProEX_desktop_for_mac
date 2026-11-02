#!/usr/bin/env bash
# 静的ビルドの PHP（static-php-cli の common 版）を取得して bin/php に置く。
# 使い方: scripts/fetch-php.sh [universal|x86_64|aarch64]   既定は universal
# universal は Intel 用と Apple シリコン用を lipo で1つにまとめる（配布はユニバーサル版。2026-11-03〜）
set -euo pipefail
cd "$(dirname "$0")/.."
ARCH="${1:-universal}"
VER="8.3.32"
URL="https://dl.static-php.dev/static-php-cli/common/php-${VER}-cli-macos-${ARCH}.tar.gz"
mkdir -p bin work/dl
if [ "$ARCH" = universal ]; then
  for a in x86_64 aarch64; do
    curl -fsSL -m 300 -o "work/dl/php-${a}.tar.gz" "https://dl.static-php.dev/static-php-cli/common/php-${VER}-cli-macos-${a}.tar.gz"
    mkdir -p "work/php-${a}" && tar xzf "work/dl/php-${a}.tar.gz" -C "work/php-${a}"
  done
  lipo -create work/php-x86_64/php work/php-aarch64/php -output bin/php
else
  curl -fsSL -m 300 -o "work/dl/php-${ARCH}.tar.gz" "$URL"
  tar xzf "work/dl/php-${ARCH}.tar.gz" -C bin
fi
chmod +x bin/php
# ダウンロードしたバイナリは Gatekeeper の隔離属性が付くことがあるので外す
xattr -d com.apple.quarantine bin/php 2>/dev/null || true
bin/php -v | head -1
bin/php -m | tr '\n' ' ' | head -c 900; echo
