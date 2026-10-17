#!/usr/bin/env bash
# 静的ビルドの PHP（static-php-cli の common 版）を取得して bin/php に置く。
# 使い方: scripts/fetch-php.sh [x86_64|aarch64]   既定は x86_64（このMacはIntel）
set -euo pipefail
cd "$(dirname "$0")/.."
ARCH="${1:-x86_64}"
VER="8.3.32"
URL="https://dl.static-php.dev/static-php-cli/common/php-${VER}-cli-macos-${ARCH}.tar.gz"
mkdir -p bin work/dl
curl -fsSL -m 300 -o "work/dl/php-${ARCH}.tar.gz" "$URL"
tar xzf "work/dl/php-${ARCH}.tar.gz" -C bin
chmod +x bin/php
# ダウンロードしたバイナリは Gatekeeper の隔離属性が付くことがあるので外す
xattr -d com.apple.quarantine bin/php 2>/dev/null || true
bin/php -v | head -1
bin/php -m | tr '\n' ' ' | head -c 900; echo
