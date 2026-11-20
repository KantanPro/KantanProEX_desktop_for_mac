#!/usr/bin/env bash
# Linux 版に同梱する PHP（static-php-cli の common 版・静的ビルド）を取得して bin-linux/php に置く。
# 使い方: scripts/fetch-php-linux.sh [x86_64|aarch64]   既定は x86_64（Mac / Linux どちらで実行してもよい）
# 静的ビルドなので、利用者の Linux に PHP やライブラリが入っていなくても動く。
set -euo pipefail
cd "$(dirname "$0")/.."
ARCH="${1:-x86_64}"
VER="8.3.32"
mkdir -p work/dl
[ -s "work/dl/php-${VER}-linux-${ARCH}.tar.gz" ] || curl -fsSL -m 300 -L -o "work/dl/php-${VER}-linux-${ARCH}.tar.gz" "https://dl.static-php.dev/static-php-cli/common/php-${VER}-cli-linux-${ARCH}.tar.gz"
rm -rf bin-linux && mkdir -p bin-linux
tar xzf "work/dl/php-${VER}-linux-${ARCH}.tar.gz" -C bin-linux
chmod +x bin-linux/php
file bin-linux/php 2>/dev/null || true
echo "PHP ${VER} (Linux ${ARCH}) → bin-linux/php ($(du -h bin-linux/php | cut -f1))"
